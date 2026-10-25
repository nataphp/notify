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
 * Table for web push subscriptions per user.
 *
 * Rows are created/updated by the push subscribe controller endpoint and used by `PushChannel`
 * to deliver notifications.
 *
 * Entities are represented by `Notify\Model\Entity\PushSubscription`.
 */
class PushSubscriptions extends App {
    /**
     * Configure associations and entity class.
     *
     * @param mixed $config ORM initialization config passed by the framework.
     * @return void
     */
    public function initialize($config) {
        parent::initialize($config);
        $this->entityClass('Notify.PushSubscription');
    }
}

