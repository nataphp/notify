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
 * Table for queued notification deliveries (outbox pattern).
 *
 * The bus writes outbox rows for queued channels and retryable failures; the worker processes them.
 *
 * Entities are represented by `Notify\Model\Entity\NotificationOutboxItem`.
 */
class NotificationOutbox extends App {
    /**
     * Configure associations and entity class.
     *
     * @param mixed $config ORM initialization config passed by the framework.
     * @return void
     */
    public function initialize($config) {
        parent::initialize($config);
        $this->entityClass('Notify.NotificationOutboxItem');
    }
}

