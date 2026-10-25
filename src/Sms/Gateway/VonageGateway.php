<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push, SMS) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Sms\Gateway;

use Notify\Core\NonRetryableChannelException;
use Notify\Core\RetryableChannelException;
use Notify\Sms\SmsMessage;
use Notify\Sms\SmsResult;
use Notify\Sms\SmsText;

/**
 * Vonage (ex-Nexmo) SMS API.
 *
 * POST {baseUrl}/sms/json, form fields api_key, api_secret, from, to (digits
 * only), text and type=unicode when the text is not GSM-7. Answers 200 even
 * when it fails: each part in "messages" has a "status", "0" is success.
 *
 * Configuration:
 * - apiKey (required)
 * - apiSecret (required)
 * - baseUrl (optional, default https://rest.nexmo.com)
 */
class VonageGateway extends AbstractHttpGateway {

    /**
     * Statuses worth retrying: 1 throttled, 5 internal error.
     */
    const RETRYABLE_STATUSES = ['1', '5'];

    /**
     * Statuses that are a configuration problem: 2 missing parameters, 4 invalid credentials, 8 account barred, 9 partner quota exceeded (no balance).
     */
    const MISCONFIGURED_STATUSES = ['2', '4', '8', '9'];

    /**
     * @inheritDoc
     */
    public function name(): string {
        return 'vonage';
    }

    /**
     * @inheritDoc
     */
    public function send(SmsMessage $message): SmsResult {
        $fields = [
            'api_key' => $this->_required('apiKey'),
            'api_secret' => $this->_required('apiSecret'),
            'from' => $message->sender,
            'to' => $message->toDigits(),
            'text' => $message->text,
        ];
        if (!SmsText::isGsm7($message->text)) {
            $fields['type'] = 'unicode';
        }
        if ($message->reference !== null) {
            $fields['client-ref'] = substr($message->reference, 0, 100);
        }
        $baseUrl = rtrim((string)($this->config['baseUrl'] ?? 'https://rest.nexmo.com'), '/');

        $response = $this->_postForm($baseUrl . '/sms/json', $fields);
        $this->_assertSuccess($response);

        $parts = (array)(($response->json() ?? [])['messages'] ?? []);
        if ($parts === []) {
            throw new NonRetryableChannelException('vonage: no messages in the answer.', 'sms', 'gateway_rejected');
        }
        foreach ($parts as $part) {
            $status = (string)($part['status'] ?? '');
            if ($status === '0') {
                continue;
            }
            $detail = sprintf('vonage: status %s (%s).', $status, (string)($part['error-text'] ?? ''));
            if (in_array($status, self::RETRYABLE_STATUSES, true)) {
                throw new RetryableChannelException($detail, 'sms', 'gateway_error');
            }
            throw new NonRetryableChannelException($detail, 'sms', in_array($status, self::MISCONFIGURED_STATUSES, true) ? 'misconfigured' : 'gateway_rejected');
        }

        return new SmsResult((string)($parts[0]['message-id'] ?? '') ?: null, count($parts), 'accepted');
    }

}
