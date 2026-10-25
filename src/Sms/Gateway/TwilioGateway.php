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
use Notify\Sms\SmsMessage;
use Notify\Sms\SmsResult;
use Notify\Sms\SmsText;

/**
 * Twilio (Programmable Messaging).
 *
 * POST {baseUrl}/2010-04-01/Accounts/{accountSid}/Messages.json, form fields
 * To, Body and From (or MessagingServiceSid), Basic auth with the account SID
 * and the auth token. Answers 201 with the message "sid".
 *
 * Configuration:
 * - accountSid (required)
 * - authToken (required)
 * - messagingServiceSid (optional): sends through a Messaging Service instead of From
 * - baseUrl (optional, default https://api.twilio.com)
 */
class TwilioGateway extends AbstractHttpGateway {

    /**
     * @inheritDoc
     */
    public function name(): string {
        return 'twilio';
    }

    /**
     * @inheritDoc
     */
    public function send(SmsMessage $message): SmsResult {
        $accountSid = $this->_required('accountSid');
        $authToken = $this->_required('authToken');
        $baseUrl = rtrim((string)($this->config['baseUrl'] ?? 'https://api.twilio.com'), '/');

        $fields = ['To' => $message->to, 'Body' => $message->text];
        $messagingServiceSid = trim((string)($this->config['messagingServiceSid'] ?? ''));
        if ($messagingServiceSid !== '') {
            $fields['MessagingServiceSid'] = $messagingServiceSid;
        } else {
            $fields['From'] = $message->sender;
        }

        $response = $this->_postForm(
            $baseUrl . '/2010-04-01/Accounts/' . rawurlencode($accountSid) . '/Messages.json',
            $fields,
            ['Authorization' => $this->_basicAuth($accountSid, $authToken)]
        );
        $body = $response->json() ?? [];
        $this->_assertSuccess($response, isset($body['message']) ? ($body['code'] ?? '') . ' ' . $body['message'] : null);

        $sid = (string)($body['sid'] ?? '');
        if ($sid === '') {
            throw new NonRetryableChannelException('twilio: no message sid in the answer.', 'sms', 'gateway_rejected');
        }

        $parts = (int)($body['num_segments'] ?? 0);

        return new SmsResult($sid, $parts > 0 ? $parts : SmsText::segments($message->text), isset($body['status']) ? (string)$body['status'] : null);
    }

}
