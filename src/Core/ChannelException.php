<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Core;

use RuntimeException;

/**
 * Base exception for notification channel delivery failures.
 *
 * Channels throw subclasses of this exception to indicate whether a failure is retryable
 * (should be queued for later) or non-retryable (should be marked as failed).
 *
 * The `channel` and `reason` properties are intended to be machine-readable so callers can
 * persist them to the outbox or return them in delivery reports.
 */
abstract class ChannelException extends RuntimeException {
    /**
     * @param string $message Human-readable message (safe for logs; not necessarily user-facing).
     * @param string|null $channel Channel name (e.g. "email", "push", "in_app") if known.
     * @param string|null $reason Machine-readable reason key (e.g. "misconfigured", "mailer_error").
     * @param \Throwable|null $previous Previous exception for chaining.
     */
    public function __construct(
        string $message,
        public readonly ?string $channel = null,
        public readonly ?string $reason = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

