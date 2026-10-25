<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Model\Entity;

/**
 * Entity representing a queued channel delivery.
 *
 * Outbox rows hold the request vars (as JSON), target channel, attempts, backoff, and last error.
 */
class NotificationOutboxItem extends App {}

