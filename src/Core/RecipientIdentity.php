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
 * Abstract recipient identity.
 *
 * The host app defines how to determine this identity for HTTP requests and how to resolve
 * delivery addressing details from it.
 */
final class RecipientIdentity {
    public function __construct(
        public readonly string $recipientModel,
        public readonly int $recipientId,
    ) {}
}

