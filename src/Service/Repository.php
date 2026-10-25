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
use Nata\ORM\TableRegistry;
use Notify\Core\EffectiveSettings;
use Notify\Core\NotificationRequest;
use Nata\Utility\Text;

/**
 * Persistence helper for Notify runtime concerns (dedupe and outbox enqueueing).
 *
 * This class encapsulates the ORM calls used by the bus so the dispatch logic stays focused
 * on delivery decisions.
 *
 * @example
 * $repo = new Repository();
 * if (!$repo->hasDedupe($recipientModel, $recipientId, 'shift:123')) {
 *     $repo->enqueueDeliveryLedger($requestId, $request, $settings, 'email', null, 'queued');
 * }
 */
final class Repository {
    private function nowString(): string {
        return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    }

    /**
     * Create a new delivery ledger row.
     *
     * @param array<string,mixed> $data
     * @return object|null Saved entity or null on failure
     */
    public function createDelivery(array $data): ?object {
        try {
            $Deliveries = TableRegistry::get('Notify.NotificationDeliveries');
            $entity = $Deliveries->newEntity($data);
            $saved = $Deliveries->save($entity);
            if ($saved === false) {
                $errors = method_exists($entity, 'getErrors') ? $entity->getErrors() : [];
                error_log('[Notify] NotificationDeliveries save failed: ' . json_encode($errors));
                return null;
            }
            return $saved;
        } catch (\Throwable $e) {
            error_log('[Notify] NotificationDeliveries exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Update a delivery ledger row by id.
     *
     * @param int $id
     * @param array<string,mixed> $fields
     */
    public function updateDelivery(int $id, array $fields): void {
        try {
            $Deliveries = TableRegistry::get('Notify.NotificationDeliveries');
            $Deliveries->updateAll($fields, ['id' => $id]);
        } catch (\Throwable) {
            // best-effort
        }
    }

    /**
     * Build delivery_opts array compatible with the ledger schema.
     *
     * Locale and TTL come from the resolved settings rather than the raw request,
     * so a row queued without an explicit call-site locale still replays in the
     * recipient's own language when the worker picks it up later. Persisting the
     * resolved values is what keeps the immediate and queued paths in step.
     *
     * @param NotificationRequest $request The originating request.
     * @param EffectiveSettings $settings Settings resolved for this send.
     * @return array<string,mixed>
     */
    public function buildDeliveryOpts(NotificationRequest $request, EffectiveSettings $settings): array {
        $options = [];
        if ($settings->locale !== null) {
            $options['locale'] = $settings->locale;
        }
        if ($settings->ttl !== null) {
            $options['ttl'] = $settings->ttl;
        }
        if ($settings->appName !== null) {
            $options['app_name'] = $settings->appName;
        }
        if ($request->url !== null) {
            $options['url'] = $request->url;
        }
        if ($request->logo !== null) {
            $options['logo'] = $request->logo;
        }
        if ($request->icon !== null) {
            $options['icon'] = $request->icon;
        }
        if ($request->badge !== null) {
            $options['badge'] = $request->badge;
        }
        if ($request->from !== null) {
            $options['from'] = (string) $request->from;
        }
        if ($request->replyTo !== null) {
            $options['reply_to'] = (string) $request->replyTo;
        }
        if ($request->sender !== null) {
            $options['sender'] = (string) $request->sender;
        }
        if ($request->cc !== null) {
            $options['cc'] = (string) $request->cc;
        }
        if ($request->bcc !== null) {
            $options['bcc'] = (string) $request->bcc;
        }
        if ($request->headers !== []) {
            $options['headers'] = $request->headers;
        }
        if ($request->smsSender !== null) {
            $options['sms_sender'] = $request->smsSender;
        }
        return $options;
    }

    /**
     * Enqueue a channel delivery into the ledger as queued work.
     *
     * @param string $requestId Groups the channels of one logical notification.
     * @param NotificationRequest $request The originating request.
     * @param EffectiveSettings $settings Settings resolved for this send; supplies the dedupe key.
     * @param string $channel Channel being queued ('email' or 'push').
     * @param DateTimeImmutable|null $availableAt When the worker may pick it up; null means immediately.
     * @param string $reason Why it was queued (e.g. 'silent_hours', 'retryable_error').
     * @param string|null $lastError Underlying error message, when queueing follows a failure.
     * @return void
     */
    public function enqueueDeliveryLedger(
        string $requestId,
        NotificationRequest $request,
        EffectiveSettings $settings,
        string $channel,
        ?DateTimeImmutable $availableAt,
        string $reason,
        ?string $lastError = null
    ): void {
        $recipientModel = (string) ($request->recipient->source() ?? '');
        $recipientId = (int) ($request->recipient->id ?? 0);

        $this->createDelivery([
            'request_id' => $requestId !== '' ? $requestId : Text::uuid(),
            'recipient_model' => $recipientModel,
            'recipient_id' => $recipientId,
            'definition_key' => $request->definitionKey,
            'channel' => $channel,
            'dedupe_key' => $settings->dedupeKey,
            'status' => 'queued',
            'reason' => $reason,
            'claimed_at' => null,
            'attempts' => 0,
            'available_at' => ($availableAt ?? new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'vars' => $request->vars ?: null,
            'delivery_opts' => ($this->buildDeliveryOpts($request, $settings) ?: null),
            'rendered' => null,
            'transport_id' => null,
            'last_error' => $lastError !== null && $lastError !== '' ? ($reason . ': ' . $lastError) : null,
            'created' => $this->nowString(),
            'sent_at' => null,
            'failed_at' => null,
            'processed_at' => null,
            'read_at' => null,
        ]);
    }

    /**
     * Check whether a given dedupe key is already present for a recipient.
     *
     * A match is considered present when either:
     * - an unread in-app notification exists with the same dedupe key, or
     * - an unprocessed outbox item exists with the same dedupe key
     */
    public function hasDedupe(string $recipientModel, int $recipientId, string $dedupeKey): bool {
        $Deliveries = TableRegistry::get('Notify.NotificationDeliveries');

        $existingInApp = $Deliveries->find()
            ->where([
                'recipient_model' => $recipientModel,
                'recipient_id' => $recipientId,
                'dedupe_key' => $dedupeKey,
                'channel' => 'in_app',
                'read_at IS' => null,
            ])
            ->limit(1)
            ->count();
        if ($existingInApp > 0) {
            return true;
        }

        $existingPending = $Deliveries->find()
            ->where([
                'recipient_model' => $recipientModel,
                'recipient_id' => $recipientId,
                'dedupe_key' => $dedupeKey,
                'channel !=' => 'in_app',
                'status IN' => ['queued', 'processing'],
            ])
            ->limit(1)
            ->count();

        return $existingPending > 0;
    }

}

