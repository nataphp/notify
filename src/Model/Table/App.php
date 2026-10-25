<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Model\Table;

use Nata\ORM\Table;

/**
 * Base ORM table class for the Notify plugin.
 *
 * Provides a common inheritance point for Notify tables so shared behavior can be added later
 * without changing every table class.
 */
class App extends Table {
    public function initialize($config) {
        parent::initialize($config);
        $this->tablePrefix('notify_');
    }
}

