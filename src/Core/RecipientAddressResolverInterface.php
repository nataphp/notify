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
 * Resolve delivery addressing details for an abstract recipient identity.
 *
 * Implementations are provided by the host application.
 */
interface RecipientAddressResolverInterface {
    public function resolve(string $recipientModel, int $recipientId): RecipientAddress;
}

