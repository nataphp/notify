<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Model\Entity;

use Nata\ORM\Entity;

/**
 * Base ORM entity class for the Notify plugin.
 *
 * Provides a common inheritance point for Notify entities so shared behavior can be added later
 * without changing every entity class.
 */
class App extends Entity {}

