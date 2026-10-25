<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with a delivery ledger.
 *
 * @package Notify
 */
namespace Notify\Model\Table;

/**
 * Table for notification delivery ledger rows.
 *
 * Entities are represented by `Notify\Model\Entity\NotificationDelivery`.
 */
class NotificationDeliveries extends App {

/**
 * Configure associations and entity class.
 *
 * @param mixed $config ORM initialization config passed by the framework.
 * @return void
 */
    public function initialize($config) {
        parent::initialize($config);
        $this->entityClass('Notify.NotificationDelivery');
    }

}

