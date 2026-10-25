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
 * Entity representing a user's notification preference row.
 *
 * The schema/fields are application-defined; this plugin provides the persistence hook.
 */
class UserNotificationPreference extends App {}

