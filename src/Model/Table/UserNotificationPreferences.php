<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Model\Table;

use Nata\ORM\Entity;

/**
 * Table for per-user notification preferences.
 *
 * This table is intended to store user-specific opt-in/out preferences per notification type
 * and/or channel, depending on application needs.
 *
 * Entities are represented by `Notify\Model\Entity\UserNotificationPreference`.
 */
class UserNotificationPreferences extends App {
    /**
     * Configure associations and entity class.
     *
     * @param mixed $config ORM initialization config passed by the framework.
     * @return void
     */
    public function initialize($config) {
        parent::initialize($config);
        // The physical table is notify_notification_preferences; without this the
        // class name would resolve to notify_user_notification_preferences, which
        // does not exist and makes every read/write throw SQLSTATE[42S02].
        $this->table('notification_preferences');
        $this->entityClass('Notify.UserNotificationPreference');
    }

/**
 * Upsert a preference for a user.
 *
 * @param Entity $user
 * @param string $key Notification key (e.g. 'swap_pool', 'approvals')
 * @param bool $enabled
 * @return bool
 */
    public function set(Entity $user, string $key, bool $enabled): bool {
        $existing = $this->find()
            ->where([
                'recipient_id' => $user->id,
                'recipient_model' => $user->source(),
                'definition_key' => $key,
            ])
            ->first();

        if ($existing) {
            $existing->enabled = $enabled;
            return (bool) $this->save($existing);
        }

        return (bool) $this->save($this->newEntity([
            'recipient_id' => $user->id,
            'recipient_model' => $user->source(),
            'definition_key' => $key,
            'enabled' => $enabled,
        ]));
    }
}

