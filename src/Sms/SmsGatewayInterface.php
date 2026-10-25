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
 * An SMS gateway adapter (Twilio, Vonage, Infobip, smsonline.pt…).
 *
 * The SmsChannel hands it an already validated message. An adapter only
 * speaks its provider's API and classifies the failures:
 * - `RetryableChannelException` (channel "sms") for what may work later:
 *   network errors, timeouts, 429, 5xx, throttling. The outbox retries it.
 * - `NonRetryableChannelException` for what never will: bad credentials,
 *   missing configuration, a number the provider rejects, no balance.
 *
 * Adapters are created by GatewayRegistry from `Notify.sms.gateways.{name}`.
 */
interface SmsGatewayInterface {

    /**
     * Gateway name, as used in `Notify.sms.gateway`.
     *
     * @return string e.g. "twilio".
     */
    public function name(): string;

    /**
     * Send one SMS.
     *
     * @param \Notify\Sms\SmsMessage $message Validated message.
     * @return \Notify\Sms\SmsResult What the provider accepted.
     * @throws \Notify\Core\RetryableChannelException When it may work later.
     * @throws \Notify\Core\NonRetryableChannelException When it never will.
     */
    public function send(SmsMessage $message): SmsResult;

}
