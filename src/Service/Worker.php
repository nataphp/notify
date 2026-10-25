<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Service;

use DateInterval;
use DateTimeImmutable;
use Nata\Core\Configure;
use Nata\ORM\TableRegistry;
use Notify\Core\NonRetryableChannelException;
use Notify\Core\RecipientAddress;
use Notify\Core\RecipientAddressResolverInterface;
use Notify\Core\RecipientPolicy;
use Notify\Core\RetryableChannelException;
use Notify\Core\TemplateRenderer;
use Notify\Service\Channel\EmailChannel;
use Notify\Service\Channel\PushChannel;
use Notify\Service\Channel\SmsChannel;

/**
 * Delivery worker that processes queued email/push/sms deliveries from the ledger.
 *
 * The bus queues retryable failures and forced-queue operations into `Notify.NotificationDeliveries`.
 * This worker claims queued rows, renders the channel payload, attempts delivery, and updates
 * the ledger row with backoff, attempts, and final status.
 *
 * @example
 * $worker = new Worker(new DefinitionRegistry(), new TemplateLoader(TemplateLocator::default()));
 * $stats = $worker->run(limit: 100, dryRun: false);
 */
final class Worker {
    private const MAX_ATTEMPTS = 5;
    private const STUCK_PROCESSING_MINUTES = 10;

    private readonly EmailChannel $email;
    private readonly PushChannel $push;
    private readonly SmsChannel $sms;

    public function __construct(
        private readonly DefinitionRegistry $definitions,
        private readonly TemplateLoader $templates,
        private readonly TemplateRenderer $renderer = new TemplateRenderer(),
        private readonly ?RecipientAddressResolverInterface $recipientResolver = null,
    ) {
        $this->email = new EmailChannel();
        $this->push = new PushChannel();
        $this->sms = new SmsChannel();
    }

    /**
     * Process outbox items up to a limit.
     *
     * @param int $limit Max rows to pick per run.
     * @param bool $dryRun When true, do not persist DB changes or call external providers.
     * @return array{picked:int,sent:int,retry:int,failed:int,skipped:int} Summary stats for the run.
     */
    public function run(int $limit = 50, bool $dryRun = false): array {
        $Deliveries = TableRegistry::get('Notify.NotificationDeliveries');
        $now = new DateTimeImmutable('now');

        if (!$dryRun) {
            // Crash recovery: re-queue rows that were claimed but appear stuck.
            $Deliveries->updateAll(
                [
                    'status' => 'queued',
                    'claimed_at' => null,
                    'available_at' => $now->format('Y-m-d H:i:s'),
                ],
                [
                    'status' => 'processing',
                    'claimed_at <' => $now->modify('-' . self::STUCK_PROCESSING_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
                ]
            );
        }

        $stats = ['picked' => 0, 'sent' => 0, 'retry' => 0, 'failed' => 0, 'skipped' => 0];

        $picked = 0;
        while ($picked < $limit) {
            $item = $this->claimOne($Deliveries, $now, $dryRun);
            if ($item === null) {
                break;
            }
            $picked++;
            $stats['picked']++;

            $channel = (string) ($item->channel ?? '');
            $key = (string) ($item->definition_key ?? '');
            $recipientModel = (string) ($item->recipient_model ?? '');
            $recipientId = (int) ($item->recipient_id ?? 0);

            $vars = $item->vars;
            if (is_string($vars)) {
                $decoded = json_decode($vars, true);
                $vars = is_array($decoded) ? $decoded : [];
            } elseif (is_object($vars)) {
                $vars = json_decode(json_encode($vars), true) ?: [];
            } elseif (!is_array($vars)) {
                $vars = [];
            }

            $opts = $item->delivery_opts ?? null;
            if (is_string($opts)) {
                $decoded = json_decode($opts, true);
                $opts = is_array($decoded) ? $decoded : [];
            } elseif (is_object($opts)) {
                $opts = json_decode(json_encode($opts), true) ?: [];
            } elseif (!is_array($opts)) {
                $opts = [];
            }

            $def = $this->definitions->get($key);
            if ($def === null) {
                $stats['failed']++;
                if (!$dryRun) {
                    $item->status = 'failed';
                    $item->failed_at = $now->format('Y-m-d H:i:s');
                    $item->processed_at = $now->format('Y-m-d H:i:s');
                    $item->last_error = 'unknown_definition';
                    $item->claimed_at = null;
                    $Deliveries->save($item);
                }
                continue;
            }

            if (!in_array($channel, ['email', 'push', 'sms'], true)) {
                $stats['skipped']++;
                if (!$dryRun) {
                    $item->status = 'skipped';
                    $item->processed_at = $now->format('Y-m-d H:i:s');
                    $item->last_error = 'unknown_channel';
                    $item->claimed_at = null;
                    $Deliveries->save($item);
                }
                continue;
            }

            $payload = $this->payloadRenderer()->renderChannel($key, $channel, $vars, $opts['url'] ?? null, $opts['logo'] ?? null, $opts['locale'] ?? null, $opts['icon'] ?? null, $opts['badge'] ?? null);
            if ($payload === null) {
                $stats['skipped']++;
                if (!$dryRun) {
                    $item->status = 'skipped';
                    $item->processed_at = $now->format('Y-m-d H:i:s');
                    $item->last_error = 'missing_template';
                    $item->claimed_at = null;
                    $Deliveries->save($item);
                }
                continue;
            }

            try {
                if ($dryRun) {
                    $stats['sent']++;
                    continue;
                }

                $to = $this->resolveRecipientAddress($recipientModel, $recipientId);
                if (!$to) {
                    throw new NonRetryableChannelException('Missing recipient.', $channel, 'missing_recipient');
                }

                // Re-check the opt-out: the recipient may have turned this channel
                // off while the row was sitting in the queue.
                if (!RecipientPolicy::allows($to, $def, $channel)) {
                    $stats['skipped']++;
                    $item->status = 'skipped';
                    $item->reason = 'user_opted_out';
                    $item->processed_at = $now->format('Y-m-d H:i:s');
                    $item->claimed_at = null;
                    $Deliveries->save($item);
                    continue;
                }

                if ($channel === 'email') {
                    $transportId = $this->email->sendNow($to, $payload, $def, [
                        'definition_key' => $key,
                        'vars' => $vars,
                        'from' => $opts['from'] ?? null,
                        'reply_to' => $opts['reply_to'] ?? null,
                        'sender' => $opts['sender'] ?? null,
                        'cc' => $opts['cc'] ?? null,
                        'bcc' => $opts['bcc'] ?? null,
                        'headers' => (array) ($opts['headers'] ?? []),
                    ]);
                } elseif ($channel === 'sms') {
                    $transportId = $this->sms->sendNow($to, $payload, $def, [
                        'definition_key' => $key,
                        'vars' => $vars,
                        'sms_sender' => $opts['sms_sender'] ?? null,
                    ]);
                } else {
                    $transportId = $this->push->sendNow($to, $payload, $def, [
                        'definition_key' => $key,
                        'vars' => $vars,
                        // Replay the values resolved when the row was queued, so a
                        // later config change cannot alter an in-flight delivery —
                        // and so a per-client app name survives the wait.
                        'ttl' => $opts['ttl'] ?? null,
                        'app_name' => $opts['app_name'] ?? null,
                    ]);
                }

                $item->status = 'sent';
                $item->reason = null;
                $item->sent_at = $now->format('Y-m-d H:i:s');
                $item->processed_at = $now->format('Y-m-d H:i:s');
                $item->transport_id = $transportId !== null ? (string) $transportId : null;
                $item->rendered = $payload;
                $item->last_error = null;
                $item->claimed_at = null;
                $Deliveries->save($item);
                $stats['sent']++;
            } catch (RetryableChannelException $e) {
                $stats['retry']++;
                if ($dryRun) {
                    continue;
                }
                $item->attempts = (int) ($item->attempts ?? 0) + 1;
                $item->last_error = $e->reason ? ($e->reason . ': ' . $e->getMessage()) : $e->getMessage();
                if ($item->attempts >= self::MAX_ATTEMPTS) {
                    $item->status = 'failed';
                    $item->failed_at = $now->format('Y-m-d H:i:s');
                    $item->processed_at = $now->format('Y-m-d H:i:s');
                } else {
                    $item->status = 'queued';
                    $item->available_at = $this->nextBackoffAt($now, (int) $item->attempts)->format('Y-m-d H:i:s');
                }
                $item->claimed_at = null;
                $Deliveries->save($item);
            } catch (NonRetryableChannelException $e) {
                $stats['failed']++;
                if ($dryRun) {
                    continue;
                }
                $item->attempts = (int) ($item->attempts ?? 0) + 1;
                $item->status = 'failed';
                $item->failed_at = $now->format('Y-m-d H:i:s');
                $item->processed_at = $now->format('Y-m-d H:i:s');
                $item->last_error = $e->reason ? ($e->reason . ': ' . $e->getMessage()) : $e->getMessage();
                $item->claimed_at = null;
                $Deliveries->save($item);
            } catch (\Throwable $e) {
                $stats['retry']++;
                if ($dryRun) {
                    continue;
                }
                $item->attempts = (int) ($item->attempts ?? 0) + 1;
                $item->last_error = 'exception: ' . $e->getMessage();
                if ($item->attempts >= self::MAX_ATTEMPTS) {
                    $item->status = 'failed';
                    $item->failed_at = $now->format('Y-m-d H:i:s');
                    $item->processed_at = $now->format('Y-m-d H:i:s');
                } else {
                    $item->status = 'queued';
                    $item->available_at = $this->nextBackoffAt($now, (int) $item->attempts)->format('Y-m-d H:i:s');
                }
                $item->claimed_at = null;
                $Deliveries->save($item);
            }
        }

        return $stats;
    }

    /**
     * Claim a single queued row for processing.
     *
     * This uses a select-then-update with a status guard to avoid double-claims under concurrency.
     *
     * @return object|null Entity or null when none available.
     */
    private function claimOne($Deliveries, DateTimeImmutable $now, bool $dryRun): ?object {
        if ($dryRun) {
            // In dryRun, read-only pick is enough.
            return $Deliveries->find()
                ->where([
                    'status' => 'queued',
                    'available_at <=' => $now->format('Y-m-d H:i:s'),
                    'attempts <' => self::MAX_ATTEMPTS,
                ])
                ->order(['available_at' => 'ASC', 'id' => 'ASC'])
                ->limit(1)
                ->first();
        }

        $candidate = $Deliveries->find()
            ->where([
                'status' => 'queued',
                'available_at <=' => $now->format('Y-m-d H:i:s'),
                'attempts <' => self::MAX_ATTEMPTS,
            ])
            ->order(['available_at' => 'ASC', 'id' => 'ASC'])
            ->limit(1)
            ->first();

        if (!$candidate || empty($candidate->id)) {
            return null;
        }

        $claimedAt = $now->format('Y-m-d H:i:s');
        $affected = $Deliveries->updateAll(
            ['status' => 'processing', 'claimed_at' => $claimedAt],
            ['id' => (int) $candidate->id, 'status' => 'queued']
        );
        if ($affected < 1) {
            return null;
        }

        return $Deliveries->get((int) $candidate->id);
    }

    private function resolveRecipientAddress(string $recipientModel, int $recipientId): ?RecipientAddress {
        if ($recipientModel === '' || $recipientId < 1) {
            return null;
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
                return null;
            }
        }

        try {
            return $resolver->resolve($recipientModel, $recipientId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Compute the next available time for a retry using exponential backoff.
     */
    private function nextBackoffAt(DateTimeImmutable $now, int $attempts): DateTimeImmutable {
        $seconds = min(3600, (int) (30 * (2 ** max(0, $attempts - 1))));
        return $now->add(new DateInterval('PT' . $seconds . 'S'));
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

