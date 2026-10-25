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
use Notify\Sms\Http\HttpTransportInterface;
use Notify\Sms\SmsMessage;
use Notify\Sms\SmsResult;
use Notify\Sms\SmsText;

/**
 * Any HTTP SMS API described by configuration, for the providers without an
 * adapter of their own (most Portuguese resellers: a URL, a key and three or
 * four fields).
 *
 * Configuration (merged over the class DEFAULTS):
 * - url (required): the send endpoint.
 * - method: POST (default) or GET.
 * - format: json (default), form, or query (fields in the URL; implied by GET).
 * - fields: our names to the provider's: ['to' => …, 'text' => …,
 *   'sender' => …, 'reference' => …]; leave one out (or null) to not send it.
 * - params: fixed fields sent with every message (e.g. the API key, a type).
 * - headers: fixed headers (e.g. ['Authorization' => 'Bearer …']).
 * - basicAuth: ['user' => …, 'password' => …].
 * - toFormat: e164 (+351…, default) or digits (351…).
 * - messageIdPath: where the message ID is in the JSON answer, dot path
 *   (e.g. "id" or "messages.0.id").
 * - successPath + successValues: when the API answers 200 to failures too,
 *   the dot path of the status and the values that mean success.
 * - errorPath: dot path of the error message, for the logs.
 */
class GenericHttpGateway extends AbstractHttpGateway {

    /**
     * Defaults under the configuration.
     *
     * @var array<string,mixed>
     */
    const DEFAULTS = [
        'method' => 'POST',
        'format' => 'json',
        'fields' => ['to' => 'to', 'text' => 'text', 'sender' => 'from'],
        'params' => [],
        'headers' => [],
        'toFormat' => 'e164',
    ];

    /**
     * Configuration merged over the defaults.
     *
     * @var array<string,mixed>
     */
    protected array $settings;

    /**
     * Constructor.
     *
     * @param array<string,mixed> $config Gateway configuration (`Notify.sms.gateways.{name}`).
     * @param \Notify\Sms\Http\HttpTransportInterface|null $http HTTP transport (default: the Nata client).
     */
    public function __construct(array $config = [], ?HttpTransportInterface $http = null) {
        parent::__construct($config, $http);
        $this->settings = $config + static::DEFAULTS;
    }

    /**
     * @inheritDoc
     */
    public function name(): string {
        return (string)($this->config['name'] ?? 'http');
    }

    /**
     * @inheritDoc
     */
    public function send(SmsMessage $message): SmsResult {
        $url = $this->_required('url');
        $method = strtoupper((string)$this->settings['method']);
        $format = $method === 'GET' ? 'query' : (string)$this->settings['format'];

        $data = (array)$this->settings['params'];
        $values = [
            'to' => $this->settings['toFormat'] === 'digits' ? $message->toDigits() : $message->to,
            'text' => $message->text,
            'sender' => $message->sender,
            'reference' => $message->reference,
        ];
        foreach ((array)$this->settings['fields'] as $ourName => $theirName) {
            if ($theirName === null || $theirName === '' || !array_key_exists($ourName, $values) || $values[$ourName] === null) {
                continue;
            }
            $data[$theirName] = $values[$ourName];
        }

        $headers = array_map('strval', (array)$this->settings['headers']);
        if (!empty($this->settings['basicAuth'])) {
            $headers['Authorization'] = $this->_basicAuth((string)($this->settings['basicAuth']['user'] ?? ''), (string)($this->settings['basicAuth']['password'] ?? ''));
        }

        if ($format === 'query') {
            $separator = str_contains($url, '?') ? '&' : '?';
            $response = $this->http->request($method, $url . $separator . http_build_query($data, '', '&', PHP_QUERY_RFC3986), $headers + ['Accept' => 'application/json']);
        } elseif ($format === 'form') {
            $response = $this->_postForm($url, $data, $headers);
        } else {
            $response = $this->_postJson($url, $data, $headers);
        }

        $body = $response->json() ?? [];
        $errorPath = (string)($this->settings['errorPath'] ?? '');
        $detail = $errorPath !== '' ? (string)(self::path($body, $errorPath) ?? '') : null;
        $this->_assertSuccess($response, $detail);

        $successPath = (string)($this->settings['successPath'] ?? '');
        if ($successPath !== '') {
            $status = self::path($body, $successPath);
            $accepted = array_map('strval', (array)($this->settings['successValues'] ?? []));
            if (!is_scalar($status) || !in_array((string)$status, $accepted, true)) {
                throw new NonRetryableChannelException(sprintf('%s: status %s%s.', $this->name(), is_scalar($status) ? (string)$status : '?', $detail ? ' (' . $detail . ')' : ''), 'sms', 'gateway_rejected');
            }
        }

        $messageIdPath = (string)($this->settings['messageIdPath'] ?? '');
        $messageId = $messageIdPath !== '' ? self::path($body, $messageIdPath) : null;

        return new SmsResult(is_scalar($messageId) && (string)$messageId !== '' ? (string)$messageId : null, SmsText::segments($message->text), 'accepted');
    }

    /**
     * Value at a dot path of a decoded JSON answer.
     *
     * @param array<mixed> $data Decoded answer.
     * @param string $path Dot path, e.g. "messages.0.id".
     * @return mixed Value, or null when missing.
     */
    public static function path(array $data, string $path): mixed {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

}
