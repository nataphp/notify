<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Core;

use Notify\Core\RecipientAddress;

/**
 * Contract for notification delivery channels.
 *
 * A channel implementation is responsible for taking a rendered channel payload and attempting
 * immediate delivery to a single user.
 *
 * Implementations should:
 * - Throw `RetryableChannelException` for transient failures that should be queued/retried.
 * - Throw `NonRetryableChannelException` for permanent failures.
 * - Avoid returning error states; callers decide whether to queue or fail based on exceptions.
 */
interface ChannelInterface {
    /**
     * Return the canonical channel name used in definitions and delivery reports.
     *
     * @return string e.g. "email", "push", "in_app"
     */
    public function name(): string;

    /**
     * Attempt immediate delivery for this channel.
     *
     * @param array<string,mixed> $payload Rendered payload for the channel
     * @param NotificationDefinition $definition Notification metadata (priority/TTL/dedupe/silent mode).
     * @param array<string,mixed> $context Extra context (definition key, vars, etc.)
     * @throws RetryableChannelException
     * @throws NonRetryableChannelException
     * @return string|null Opaque transport ID for tracing (e.g. SMTP message ID), or null if unavailable.
     */
    public function sendNow(RecipientAddress $to, array $payload, NotificationDefinition $definition, array $context): ?string;
}

