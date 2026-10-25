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
 * Resolved addressing + identity for a notification recipient.
 *
 * Identity is persisted in Notify tables as (recipient_model, recipient_id).
 * Addressing fields are used by delivery channels: `email` by email, `phone`
 * (E.164, e.g. +351912345678) by sms.
 */
final class RecipientAddress {
    public function __construct(
        public readonly string $recipientModel,
        public readonly int $recipientId,
        public readonly ?string $email = null,
        public readonly ?string $locale = null,
        public readonly ?string $timezone = null,
        public readonly ?string $phone = null,
    ) {}
}

