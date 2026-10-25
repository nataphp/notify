<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push, SMS) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Sms\Http;

/**
 * The HTTP calls of the SMS gateways, behind an interface so the adapters
 * can be tested without the network (a fake transport records the requests
 * and answers with canned responses).
 */
interface HttpTransportInterface {

    /**
     * Send one HTTP request.
     *
     * @param string $method HTTP method (GET, POST).
     * @param string $url Absolute URL (a GET carries its query in it).
     * @param array<string,string> $headers Request headers.
     * @param string|null $body Encoded body (JSON or form), or null.
     * @return \Notify\Sms\Http\HttpResponse Response; status 0 when the request never got an answer.
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;

}
