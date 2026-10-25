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
 * Entity representing a single browser/device push subscription.
 *
 * Typically contains:
 * - endpoint (string) and endpoint_hash (sha256)
 * - p256dh/auth keys
 * - recipient_model/recipient_id
 * - created/last_used_at timestamps
 */
class PushSubscription extends App {}

