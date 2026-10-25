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
 * Indicates a channel failure that may succeed if retried later.
 *
 * Typical causes: transient network issues, provider downtime, timeouts.
 *
 * @example
 * throw new RetryableChannelException('Push gateway temporarily unavailable.', 'push', 'push_error');
 */
final class RetryableChannelException extends ChannelException {}

