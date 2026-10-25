<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Service;

use DateTimeImmutable;
use Nata\Core\Configure;
use Nata\Utility\Text;
use Notify\Core\ChannelInterface;
use Notify\Core\DeliveryReport;
use Notify\Core\EffectiveSettings;
use Notify\Core\NotificationRequest;
use Notify\Core\RecipientAddress;
use Notify\Core\RecipientAddressResolverInterface;
use Notify\Core\RecipientPolicy;
use Notify\Core\RetryableChannelException;
use Notify\Core\NonRetryableChannelException;
use Notify\Core\TemplateRenderer;

/**
 * Notification dispatch bus.
 *
 * Responsibilities:
 * - Resolve definitions and pick channels (default or request override)
 * - Enforce dedupe before fan-out (optional, definition-controlled)
 * - Respect silent hours by queueing email/push when configured
 * - Send immediately when possible, queueing on retryable failures
 *
 * This bus returns a best-effort per-channel report instead of throwing for delivery failures.
 *
 * @example
 * $bus = Factory::bus();
 * $bus->send(new NotificationRequest('shift.assigned', $recipient, ['shift' => ['id' => 123]]));
 */
final class Bus {
    /** @var array<string,ChannelInterface> */
    private array $channels = [];

    public function __construct(
        private readonly DefinitionRegistry $definitions,
        private readonly TemplateLoader $templates,
        private readonly TemplateRenderer $renderer = new TemplateRenderer(),
        private readonly Repository $repo = new Repository(),
        private readonly ?RecipientAddressResolverInterface $recipientResolver = null,
    ) {}

    /**
     * Register a channel implementation by its `ChannelInterface::name()`.
     *
     * @param ChannelInterface $channel Channel implementation to register.
     */
    public function registerChannel(ChannelInterface $channel): void {
        $this->channels[$channel->name()] = $channel;
    }

    /**
     * Best-effort immediate send with per-channel outbox fallback.
     *
     * This method will attempt to deliver immediately for supported channels. For retryable failures,
     * it will enqueue outbox rows and mark those channels as "queued" in the returned report.
     *
     * @return array<string,array{status:string,reason:?string}>
     */
    public function send(NotificationRequest $request): array {
        return $this->dispatch($request, false);
    }

    /**
     * Always queue (write outbox) except in_app which is always immediate.
     *
     * This method is useful when the caller wants to guarantee asynchronous delivery.
     *
     * @return array<string,array{status:string,reason:?string}>
     */
    public function queue(NotificationRequest $request): array {
        return $this->dispatch($request, true);
    }

    /**
     * @return array<string,array{status:string,reason:?string}>
     */
    private function dispatch(NotificationRequest $request, bool $forceQueue): array {
        $report = new DeliveryReport();
        $requestId = Text::uuid();

        $def = $this->definitions->get($request->definitionKey);
        if ($def === null) {
            // Definition key is required/registered.
            $report->set('email', 'failed', 'unknown_definition');
            $report->set('push', 'failed', 'unknown_definition');
            $report->set('in_app', 'failed', 'unknown_definition');
            return $report->toArray();
        }

        $to = $this->resolveRecipientAddress($request);
        if ($to === null) {
            // No address means nothing can be resolved per-recipient, so report
            // against the channels the definition/override alone imply.
            foreach (EffectiveSettings::channelsFor($def, $request) as $ch) {
                $report->set($ch, 'failed', 'missing_recipient_resolver');
            }
            return $report->toArray();
        }

        // Every setting for this send is resolved exactly once, here, and the
        // resolved values are what get persisted for the worker to replay.
        $settings = EffectiveSettings::resolve($def, $request, $to, $this->renderer);
        $channels = $settings->channels;
        $dedupeKey = $settings->dedupeKey;

        // Dedupe is enforced before fan-out.
        if ($dedupeKey !== null) {
            if ($this->repo->hasDedupe($to->recipientModel, $to->recipientId, $dedupeKey)) {
                foreach ($channels as $ch) {
                    $report->set($ch, 'skipped', 'deduplicated');
                }
                return $report->toArray();
            }
        }

        // Silent hours only gate email/push/sms; in_app is always immediate.
        $nextAllowed = $settings->silentUntil;

        foreach ($channels as $channelName) {
            if (!isset($this->channels[$channelName])) {
                $report->set($channelName, 'skipped', 'unknown_channel');
                continue;
            }

            // Per-recipient opt-out. Reported explicitly rather than dropped from
            // the channel list, so "the user turned this off" is visible in the
            // delivery report instead of looking like a notification that vanished.
            if (!RecipientPolicy::allows($to, $def, $channelName)) {
                $report->set($channelName, 'skipped', 'user_opted_out');
                continue;
            }

            // in_app is always immediate.
            if ($channelName === 'in_app') {
                $payload = $this->payloadRenderer()->renderInApp($def->key, $request->vars, $dedupeKey, $request->url, $request->logo, $settings->locale);
                if ($payload === null) {
                    $report->set('in_app', 'skipped', 'missing_template');
                    continue;
                }
                try {
                    $this->channels['in_app']->sendNow($to, $payload, $def, [
                        'definition_key' => $def->key,
                        'vars' => $request->vars,
                        'dedupe_key' => $dedupeKey,
                        'request_id' => $requestId,
                    ]);
                    $report->set('in_app', 'sent', null);
                } catch (\Throwable) {
                    $report->set('in_app', 'failed', 'db_error');
                }
                continue;
            }

            // Silent hours gate email/push by queueing.
            if ($nextAllowed !== null) {
                $this->repo->enqueueDeliveryLedger($requestId, $request, $settings, $channelName, $nextAllowed, 'silent_hours');
                $report->set($channelName, 'queued', 'silent_hours');
                continue;
            }

            // Force queue for non in_app.
            if ($forceQueue) {
                $this->repo->enqueueDeliveryLedger($requestId, $request, $settings, $channelName, null, 'queued');
                $report->set($channelName, 'queued', 'queued');
                continue;
            }

            $payload = $this->payloadRenderer()->renderChannel($def->key, $channelName, $request->vars, $request->url, $request->logo, $settings->locale, $request->icon, $request->badge);
            if ($payload === null) {
                $report->set($channelName, 'skipped', 'missing_template');
                continue;
            }

            $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $deliveryId = null;
            $delivery = $this->repo->createDelivery([
                'request_id' => $requestId,
                'recipient_model' => $to->recipientModel,
                'recipient_id' => $to->recipientId,
                'definition_key' => $def->key,
                'channel' => $channelName,
                'dedupe_key' => $dedupeKey,
                'status' => 'processing',
                'reason' => null,
                'claimed_at' => $now,
                'attempts' => 0,
                'available_at' => $now,
                'vars' => $request->vars ?: null,
                'delivery_opts' => ($this->repo->buildDeliveryOpts($request, $settings) ?: null),
                'rendered' => $payload,
                'transport_id' => null,
                'last_error' => null,
                'created' => $now,
                'sent_at' => null,
                'failed_at' => null,
                'processed_at' => null,
                'read_at' => null,
            ]);
            if ($delivery && isset($delivery->id)) {
                $deliveryId = (int) $delivery->id;
            }

            try {
                $transportId = $this->channels[$channelName]->sendNow($to, $payload, $def, [
                    'definition_key' => $def->key,
                    'vars' => $request->vars,
                    'dedupe_key' => $dedupeKey,
                    'ttl' => $settings->ttl,
                    'app_name' => $settings->appName,
                    'from' => $request->from,
                    'reply_to' => $request->replyTo,
                    'sender' => $request->sender,
                    'cc' => $request->cc,
                    'bcc' => $request->bcc,
                    'headers' => $request->headers,
                    'sms_sender' => $request->smsSender,
                ]);

                if ($deliveryId !== null) {
                    $this->repo->updateDelivery($deliveryId, [
                        'status' => 'sent',
                        'reason' => null,
                        'sent_at' => $now,
                        'processed_at' => $now,
                        'transport_id' => $transportId !== null ? (string) $transportId : null,
                        'last_error' => null,
                        'claimed_at' => null,
                    ]);
                }
                $report->set($channelName, 'sent', null);
            } catch (RetryableChannelException $e) {
                if ($deliveryId !== null) {
                    $this->repo->updateDelivery($deliveryId, [
                        'status' => 'queued',
                        'reason' => $e->reason ?? 'retryable_error',
                        'last_error' => ($e->reason ? ($e->reason . ': ') : '') . $e->getMessage(),
                        'claimed_at' => null,
                    ]);
                } else {
                    // Ledger insert may have failed (e.g. rendered JSON); worker reads ledger only — enqueue a queued row without rendered.
                    $this->repo->enqueueDeliveryLedger($requestId, $request, $settings, $channelName, null, $e->reason ?? 'retryable_error', $e->getMessage());
                }
                $report->set($channelName, 'queued', $e->reason ?? 'retryable_error');
            } catch (NonRetryableChannelException $e) {
                if ($deliveryId !== null) {
                    $this->repo->updateDelivery($deliveryId, [
                        'status' => 'failed',
                        'reason' => $e->reason ?? 'non_retryable_error',
                        'failed_at' => $now,
                        'processed_at' => $now,
                        'last_error' => ($e->reason ? ($e->reason . ': ') : '') . $e->getMessage(),
                        'claimed_at' => null,
                    ]);
                }
                $report->set($channelName, 'failed', $e->reason ?? 'non_retryable_error');
            } catch (\Throwable $e) {
                if ($deliveryId !== null) {
                    $this->repo->updateDelivery($deliveryId, [
                        'status' => 'queued',
                        'reason' => 'retryable_error',
                        'last_error' => 'exception: ' . $e->getMessage(),
                        'claimed_at' => null,
                    ]);
                } else {
                    $this->repo->enqueueDeliveryLedger($requestId, $request, $settings, $channelName, null, 'retryable_error', $e->getMessage());
                }
                $report->set($channelName, 'queued', 'retryable_error');
            }
        }

        return $report->toArray();
    }

    private function resolveRecipientAddress(NotificationRequest $request): ?RecipientAddress {
        $recipientModel = (string) ($request->recipient->source() ?? '');
        $recipientId = (int) ($request->recipient->id ?? 0);
        if ($recipientModel === '' || $recipientId < 1) {
            return null;
        }

        // Fast path: entity already carries addressing fields — no extra DB query needed.
        $entityEmail = $request->recipient->email ?? null;
        if ($entityEmail !== null) {
            $entityPhone = $request->recipient->phone ?? null;
            return new RecipientAddress(
                $recipientModel,
                $recipientId,
                $entityEmail,
                $request->recipient->language ?? null,
                phone: is_string($entityPhone) && $entityPhone !== '' ? $entityPhone : null,
            );
        }

        $resolver = $this->recipientResolver;
        if ($resolver === null) {
            $cfg = Configure::read('Notify.recipientAddressResolver');
            if ($cfg instanceof RecipientAddressResolverInterface) {
                $resolver = $cfg;
            } elseif (is_callable($cfg)) {
                $resolved = $cfg($recipientModel, $recipientId);
                return $resolved instanceof RecipientAddress ? $resolved : null;
            } else {
                // No resolver configured: identity-only address is enough for in_app.
                return new RecipientAddress($recipientModel, $recipientId);
            }
        }

        try {
            return $resolver->resolve($recipientModel, $recipientId);
        } catch (\Throwable) {
            return null;
        }
    }

    private ?ChannelPayloadRenderer $payloadRenderer = null;

    /**
     * Lazily construct the payload renderer.
     */
    private function payloadRenderer(): ChannelPayloadRenderer {
        if ($this->payloadRenderer) {
            return $this->payloadRenderer;
        }
        return $this->payloadRenderer = new ChannelPayloadRenderer($this->templates, $this->renderer);
    }
}

