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
 * One SMS to one phone, ready for a gateway.
 *
 * The SmsChannel validates it before any gateway sees it: `to` is E.164
 * (+351912345678), `text` is not empty and fits the configured segments, and
 * `sender` is a valid sender ID (SmsText::isValidSender()).
 */
final class SmsMessage {

    /**
     * Constructor.
     *
     * @param string $to Recipient phone in E.164 (with the leading +).
     * @param string $text Message text.
     * @param string $sender Sender ID: up to 11 letters and digits, or a phone number.
     * @param string|null $reference Our own reference (e.g. the definition key), for gateways that keep one.
     */
    public function __construct(
        public readonly string $to,
        public readonly string $text,
        public readonly string $sender,
        public readonly ?string $reference = null,
    ) {}

    /**
     * Recipient without the leading +, for the gateways that want only digits.
     *
     * @return string Digits, e.g. 351912345678.
     */
    public function toDigits(): string {
        return ltrim($this->to, '+');
    }

}
