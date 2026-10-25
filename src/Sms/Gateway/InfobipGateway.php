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
 * Infobip SMS.
 *
 * POST {baseUrl}/sms/2/text/advanced, JSON
 * {"messages":[{"destinations":[{"to":"351…"}],"from":"…","text":"…"}]},
 * header "Authorization: App {apiKey}". Answers with each message's
 * "messageId" and a "status" whose "groupName" is REJECTED when refused.
 *
 * Configuration:
 * - baseUrl (required: the account's own, e.g. https://xxxxx.api.infobip.com)
 * - apiKey (required)
 */
class InfobipGateway extends AbstractHttpGateway {

    /**
     * @inheritDoc
     */
    public function name(): string {
        return 'infobip';
    }

    /**
     * @inheritDoc
     */
    public function send(SmsMessage $message): SmsResult {
        $baseUrl = rtrim($this->_required('baseUrl'), '/');
        $apiKey = $this->_required('apiKey');

        $entry = [
            'destinations' => [['to' => $message->toDigits()]],
            'from' => $message->sender,
            'text' => $message->text,
        ];
        if ($message->reference !== null) {
            $entry['callbackData'] = substr($message->reference, 0, 4000);
        }
        $response = $this->_postJson($baseUrl . '/sms/2/text/advanced', ['messages' => [$entry]], [
            'Authorization' => 'App ' . $apiKey,
        ]);
        $body = $response->json() ?? [];
        $this->_assertSuccess($response, (string)($body['requestError']['serviceException']['text'] ?? ''));

        $sent = (array)($body['messages'][0] ?? []);
        $group = strtoupper((string)($sent['status']['groupName'] ?? ''));
        if ($group === 'REJECTED' || $group === 'UNDELIVERABLE') {
            throw new NonRetryableChannelException(sprintf('infobip: %s (%s).', $group, (string)($sent['status']['description'] ?? '')), 'sms', 'gateway_rejected');
        }

        return new SmsResult((string)($sent['messageId'] ?? '') ?: null, SmsText::segments($message->text), $group !== '' ? strtolower($group) : null);
    }

}
