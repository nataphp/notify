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
use Notify\Sms\Http\HttpResponse;
use Notify\Sms\Http\HttpTransportInterface;
use Notify\Sms\Http\NataHttpTransport;
use Notify\Sms\SmsGatewayInterface;

/**
 * Base of the gateways that speak HTTP: configuration, encoding and the
 * common classification of failures (no answer, 408, 429 and 5xx are
 * retryable; any other non-2xx is not).
 */
abstract class AbstractHttpGateway implements SmsGatewayInterface {

    /**
     * HTTP transport.
     *
     * @var \Notify\Sms\Http\HttpTransportInterface
     */
    protected HttpTransportInterface $http;

    /**
     * Constructor.
     *
     * @param array<string,mixed> $config Gateway configuration (`Notify.sms.gateways.{name}`).
     * @param \Notify\Sms\Http\HttpTransportInterface|null $http HTTP transport (default: the Nata client).
     */
    public function __construct(
        protected readonly array $config = [],
        ?HttpTransportInterface $http = null,
    ) {
        $this->http = $http ?? new NataHttpTransport((int)($config['timeout'] ?? 15));
    }

    /**
     * A required configuration value.
     *
     * @param string $key Configuration key.
     * @return string Value.
     * @throws \Notify\Core\NonRetryableChannelException When it is missing.
     */
    protected function _required(string $key): string {
        $value = trim((string)($this->config[$key] ?? ''));
        if ($value === '') {
            throw new NonRetryableChannelException(sprintf('Missing "%s" in the %s SMS gateway configuration.', $key, $this->name()), 'sms', 'misconfigured');
        }

        return $value;
    }

    /**
     * POST a JSON body.
     *
     * @param string $url URL.
     * @param array<mixed> $data Body.
     * @param array<string,string> $headers Extra headers.
     * @return \Notify\Sms\Http\HttpResponse Response.
     */
    protected function _postJson(string $url, array $data, array $headers = []): HttpResponse {
        return $this->http->request('POST', $url, $headers + [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * POST a form body (application/x-www-form-urlencoded).
     *
     * @param string $url URL.
     * @param array<string,mixed> $data Fields.
     * @param array<string,string> $headers Extra headers.
     * @return \Notify\Sms\Http\HttpResponse Response.
     */
    protected function _postForm(string $url, array $data, array $headers = []): HttpResponse {
        return $this->http->request('POST', $url, $headers + [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], http_build_query($data, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * Basic authentication header value.
     *
     * @param string $user User.
     * @param string $password Password.
     * @return string "Basic …".
     */
    protected function _basicAuth(string $user, string $password): string {
        return 'Basic ' . base64_encode($user . ':' . $password);
    }

    /**
     * Throw for a response that is not a success.
     *
     * @param \Notify\Sms\Http\HttpResponse $response Response.
     * @param string|null $detail Provider error message, when the body has one.
     * @return void
     * @throws \Notify\Core\RetryableChannelException On no answer, 408, 429 and 5xx.
     * @throws \Notify\Core\NonRetryableChannelException On any other non-2xx.
     */
    protected function _assertSuccess(HttpResponse $response, ?string $detail = null): void {
        if ($response->isSuccess()) {
            return;
        }
        if ($response->status === 0) {
            throw new RetryableChannelException(sprintf('%s: no answer (%s).', $this->name(), (string)$response->error), 'sms', 'gateway_unreachable');
        }
        $message = sprintf('%s: HTTP %d%s', $this->name(), $response->status, $detail !== null && $detail !== '' ? ' (' . $detail . ')' : '');
        if ($response->status === 408 || $response->status === 429 || $response->status >= 500) {
            throw new RetryableChannelException($message, 'sms', 'gateway_error');
        }
        if ($response->status === 401 || $response->status === 403) {
            throw new NonRetryableChannelException($message, 'sms', 'misconfigured');
        }

        throw new NonRetryableChannelException($message, 'sms', 'gateway_rejected');
    }

}
