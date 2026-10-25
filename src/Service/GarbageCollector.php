<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Service;

use Nata\ORM\TableRegistry;

/**
 * Best-effort orphan cleanup for polymorphic recipients.
 *
 * This is intentionally conservative:
 * - It catches unknown recipient models and skips them.
 * - It treats missing recipients as orphans and deletes notify rows for them.
 *
 * Host apps can run this periodically (cron) if they want to keep notify tables tidy.
 */
final class GarbageCollector {
    /**
     * Purge orphaned recipient rows from Notify tables.
     *
     * @param int $limit Max distinct recipient pairs to inspect per table.
     * @return array<string,int> Deleted row counts keyed by table alias.
     */
    public function purgeOrphanRecipients(int $limit = 500): array {
        // The pre-ledger tables (notify_user_notifications, notify_notification_outbox)
        // no longer exist — everything was consolidated into notify_notification_deliveries
        // — so purging them would only throw SQLSTATE[42S02].
        $targets = [
            'Notify.NotificationDeliveries',
            'Notify.PushSubscriptions',
            'Notify.UserNotificationPreferences',
        ];

        $deleted = [];
        foreach ($targets as $alias) {
            $Table = TableRegistry::get($alias);

            $pairs = $Table->find()
                ->select(['recipient_model', 'recipient_id'])
                ->where(['recipient_model IS NOT' => null, 'recipient_id >' => 0])
                ->groupBy(['recipient_model', 'recipient_id'])
                ->limit($limit)
                ->all();

            $count = 0;
            foreach ($pairs as $pair) {
                $model = (string) ($pair->recipient_model ?? '');
                $id = (int) ($pair->recipient_id ?? 0);
                if ($model === '' || $id < 1) {
                    continue;
                }

                $exists = $this->recipientExists($model, $id);
                if ($exists) {
                    continue;
                }

                $count += (int) $Table->deleteAll([
                    'recipient_model' => $model,
                    'recipient_id' => $id,
                ]);
            }

            $deleted[$alias] = $count;
        }

        return $deleted;
    }

    private function recipientExists(string $recipientModel, int $recipientId): bool {
        try {
            $Recipients = TableRegistry::get($recipientModel);
            return $Recipients->exists(['id' => $recipientId]);
        } catch (\Throwable) {
            // Unknown model / not registered / errors: do not delete.
            return true;
        }
    }
}

