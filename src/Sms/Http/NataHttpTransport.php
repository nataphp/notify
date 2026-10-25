<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push, SMS) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Sms\Http;

use Nata\Http\Client;
use Throwable;

/**
 * HttpTransportInterface over the Nata HTTP client (cURL).
 */
final class NataHttpTransport implements HttpTransportInterface {

    /**
     * Constructor.
     *
     * @param int $timeout Seconds before giving up on a request.
     */
    public function __construct(
        private readonly int $timeout = 15,
    ) {}

    /**
     * @inheritDoc
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse {
        try {
            $request = Client::newRequest($url)
                ->method($method)
                ->timeout($this->timeout)
                ->connectionTimeout($this->timeout);
            foreach ($headers as $name => $value) {
                $request->addHeader($name, $value);
            }
            if ($body !== null) {
                $request->body($body);
            }
            $response = Client::send($request);
            if ($response->hasError()) {
                return new HttpResponse(0, '', (string)$response->getError());
            }

            return new HttpResponse((int)$response->getStatusCode(), (string)$response->getBody());
        } catch (Throwable $exception) {
            return new HttpResponse(0, '', $exception->getMessage());
        }
    }

}
