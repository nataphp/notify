<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Model\Table;

/**
 * Table for persisted in-app notifications (bell dropdown, unread counts).
 *
 * Entities are represented by `Notify\Model\Entity\UserNotification`.
 */
class UserNotifications extends App {
    /**
     * Configure associations and entity class.
     *
     * @param mixed $config ORM initialization config passed by the framework.
     * @return void
     */
    public function initialize($config) {
        parent::initialize($config);
        $this->entityClass('Notify.UserNotification');
    }
}

