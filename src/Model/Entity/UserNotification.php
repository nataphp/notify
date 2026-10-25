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
 * Entity representing a single in-app notification row.
 *
 * Stored by the `InAppChannel` and read by the notifications controller for UI rendering.
 */
class UserNotification extends App {}

