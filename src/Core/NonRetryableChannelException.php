<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Core;

/**
 * Indicates a channel failure that should not be retried automatically.
 *
 * Typical causes: missing recipient, invalid configuration, invalid payload, or hard provider errors.
 *
 * @example
 * throw new NonRetryableChannelException('Missing user email.', 'email', 'missing_recipient');
 */
final class NonRetryableChannelException extends ChannelException {}

