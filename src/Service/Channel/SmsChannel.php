<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push, SMS) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Service\Channel;

use Nata\Core\Configure;
use Notify\Core\ChannelInterface;
use Notify\Core\NonRetryableChannelException;
use Notify\Core\NotificationDefinition;
use Notify\Core\RecipientAddress;
use Notify\Core\RetryableChannelException;
use Notify\Sms\GatewayRegistry;
use Notify\Sms\SmsGatewayInterface;
use Notify\Sms\SmsMessage;
use Notify\Sms\SmsText;
use Throwable;

/**
 * SMS delivery channel ("sms"), through the gateway adapter chosen in
 * `Notify.sms.gateway` (GatewayRegistry).
 *
 * Payload shape (rendered by `ChannelPayloadRenderer::renderSms()` from
 * `{event}/sms/{locale}/text.txt`):
 * - text: string
 *
 * Context keys (optional):
 * - sms_sender: sender ID for this send (default `Notify.sms.sender`)
 *
 * Configuration (`Notify.sms`):
 * - gateway: the adapter name (log, twilio, vonage, infobip, smsonline, http…); empty = off
 * - sender: default sender ID (up to 11 letters and digits, or a number)
 * - maxSegments: longest message allowed, in segments (default 3)
 * - gateways.{name}: each adapter's settings
 *
 * The recipient's phone comes from `RecipientAddress::$phone`, in E.164.
 *
 * Failure behavior:
 * - No gateway, an invalid sender, no or invalid phone, an empty or too long
 *   text throw `NonRetryableChannelException` (sending again changes nothing).
 * - The gateway classifies its own failures (SmsGatewayInterface); anything
 *   else it throws is treated as retryable.
 */
final class SmsChannel implements ChannelInterface {

    /**
     * Gateway, built from the configuration on first use.
     *
     * @var \Notify\Sms\SmsGatewayInterface|null
     */
    private ?SmsGatewayInterface $gateway;

    /**
     * Constructor.
     *
     * @param \Notify\Sms\SmsGatewayInterface|null $gateway Gateway (default: the configured one, built when first needed).
     */
    public function __construct(?SmsGatewayInterface $gateway = null) {
        $this->gateway = $gateway;
    }

    /**
     * @inheritDoc
     */
    public function name(): string {
        return 'sms';
    }

    /**
     * @inheritDoc
     *
     * @throws NonRetryableChannelException When the configuration, the phone or the text make sending impossible.
     * @throws RetryableChannelException When the gateway may accept it later.
     */
    public function sendNow(RecipientAddress $to, array $payload, NotificationDefinition $definition, array $context): ?string {
        $phone = trim((string)($to->phone ?? ''));
        if ($phone === '') {
            throw new NonRetryableChannelException('Missing recipient phone.', 'sms', 'missing_recipient');
        }
        if (!SmsText::isE164($phone)) {
            throw new NonRetryableChannelException(sprintf('Invalid recipient phone: %s.', $phone), 'sms', 'invalid_recipient');
        }

        $text = trim((string)($payload['text'] ?? ''));
        if ($text === '') {
            throw new NonRetryableChannelException('Empty SMS text.', 'sms', 'empty_message');
        }
        $maxSegments = (int)(Configure::read('Notify.sms.maxSegments') ?: 3);
        $segments = SmsText::segments($text);
        if ($segments > $maxSegments) {
            throw new NonRetryableChannelException(sprintf('SMS too long: %d segments (max %d).', $segments, $maxSegments), 'sms', 'too_long');
        }

        $sender = trim((string)($context['sms_sender'] ?? Configure::read('Notify.sms.sender')));
        if (!SmsText::isValidSender($sender)) {
            throw new NonRetryableChannelException(sprintf('Invalid SMS sender "%s".', $sender), 'sms', 'misconfigured');
        }

        $gateway = $this->gateway ??= GatewayRegistry::fromConfig();
        $message = new SmsMessage($phone, $text, $sender, $definition->key);
        try {
            return $gateway->send($message)->messageId;
        } catch (NonRetryableChannelException|RetryableChannelException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RetryableChannelException($exception->getMessage(), 'sms', 'gateway_error', $exception);
        }
    }

}
