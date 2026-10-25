<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Service\Channel;

use DateTimeImmutable;
use Nata\ORM\TableRegistry;
use Nata\Utility\Text;
use Notify\Core\ChannelInterface;
use Notify\Core\NotificationDefinition;
use Notify\Core\RecipientAddress;

/**
 * In-app notification channel.
 *
 * This channel persists a delivery ledger row in the `Notify.NotificationDeliveries` table.
 *
 * Payload shape (rendered by `ChannelPayloadRenderer::renderInApp()`):
 * - title: string
 * - body: string
 * - data: array<string,mixed> (stored as JSON)
 * - dedupe_key: ?string
 *
 * Context keys:
 * - request_id: string (UUID shared across channels for one Bus dispatch; fallback generated if missing)
 */
final class InAppChannel implements ChannelInterface {
    /**
     * @inheritDoc
     */
    public function name(): string {
        return 'in_app';
    }

    /**
     * Persist the in-app notification in the database.
     *
     * Note: this method intentionally does not throw `ChannelException` variants; callers treat
     * DB failures as a hard failure for in-app and do not queue via outbox.
     *
     * @inheritDoc
     */
    public function sendNow(RecipientAddress $to, array $payload, NotificationDefinition $definition, array $context): ?string {
        $Deliveries = TableRegistry::get('Notify.NotificationDeliveries');
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $requestId = isset($context['request_id']) && is_string($context['request_id']) && trim($context['request_id']) !== ''
            ? trim($context['request_id'])
            : Text::uuid();

        $entity = $Deliveries->newEntity([
            'request_id' => $requestId,
            'recipient_model' => $to->recipientModel,
            'recipient_id' => $to->recipientId,
            'definition_key' => $definition->key,
            'channel' => 'in_app',
            'dedupe_key' => $payload['dedupe_key'] ?? null,

            'status' => 'sent',
            'reason' => null,
            'claimed_at' => null,
            'attempts' => 0,
            'available_at' => $now,

            'vars' => (isset($context['vars']) && is_array($context['vars'])) ? $context['vars'] : null,
            'delivery_opts' => null,
            'rendered' => $payload,

            'transport_id' => null,
            'last_error' => null,

            'created' => $now,
            'sent_at' => $now,
            'failed_at' => null,
            'processed_at' => $now,
            'read_at' => null,
        ]);

        $Deliveries->save($entity);
        return null;
    }
}

