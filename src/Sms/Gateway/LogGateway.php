<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push, SMS) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Sms\Gateway;

use DateTimeImmutable;
use Notify\Core\RetryableChannelException;
use Notify\Sms\SmsGatewayInterface;
use Notify\Sms\SmsMessage;
use Notify\Sms\SmsResult;
use Notify\Sms\SmsText;

/**
 * Development gateway: writes each SMS to a file instead of sending it (like
 * the Log email transport).
 *
 * Configuration:
 * - path (optional, default LOGS/sms.log)
 */
class LogGateway implements SmsGatewayInterface {

    /**
     * Constructor.
     *
     * @param array<string,mixed> $config Gateway configuration.
     */
    public function __construct(
        protected readonly array $config = [],
    ) {}

    /**
     * @inheritDoc
     */
    public function name(): string {
        return 'log';
    }

    /**
     * @inheritDoc
     */
    public function send(SmsMessage $message): SmsResult {
        $path = (string)($this->config['path'] ?? (defined('LOGS') ? LOGS . 'sms.log' : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sms.log'));
        $messageId = uniqid('sms', true);
        $entry = sprintf(
            "==== %s ====\nTo: %s\nFrom: %s\nSegments: %d\nId: %s\n%s\n\n",
            (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            $message->to,
            $message->sender,
            SmsText::segments($message->text),
            $messageId,
            $message->text
        );
        if (file_put_contents($path, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new RetryableChannelException('log: could not write ' . $path . '.', 'sms', 'gateway_error');
        }

        return new SmsResult($messageId, SmsText::segments($message->text), 'logged');
    }

}
