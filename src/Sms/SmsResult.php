<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push, SMS) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Sms;

/**
 * What a gateway answered to an accepted SMS. A refused one is an exception
 * (RetryableChannelException or NonRetryableChannelException), never a result.
 */
final class SmsResult {

    /**
     * Constructor.
     *
     * @param string|null $messageId The provider's message ID (for delivery reports), or null when it gives none.
     * @param int $parts Segments billed (SmsText::segments() unless the provider says otherwise).
     * @param string|null $status The provider's own status word (e.g. "queued", "accepted"), when it gives one.
     */
    public function __construct(
        public readonly ?string $messageId,
        public readonly int $parts,
        public readonly ?string $status = null,
    ) {}

}
