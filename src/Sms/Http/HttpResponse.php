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
 * An HTTP response as the SMS gateways see it.
 */
final class HttpResponse {

    /**
     * Constructor.
     *
     * @param int $status HTTP status, 0 when there was no answer (network error, timeout).
     * @param string $body Raw body.
     * @param string|null $error Transport error (cURL), when there was no answer.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly ?string $error = null,
    ) {}

    /**
     * Body decoded as JSON.
     *
     * @return array<mixed>|null Decoded array, or null when the body is not a JSON object or list.
     */
    public function json(): ?array {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Whether the status is 2xx.
     *
     * @return bool True on success.
     */
    public function isSuccess(): bool {
        return $this->status >= 200 && $this->status < 300;
    }

}
