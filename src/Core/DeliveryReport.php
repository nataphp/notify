<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Core;

/**
 * Aggregates per-channel delivery results for a single request.
 *
 * The bus uses this as an internal accumulator and returns the serialized array shape to callers.
 *
 * @example
 * $report = new DeliveryReport();
 * $report->set('email', 'sent');
 * $report->set('push', 'queued', 'silent_hours');
 * $data = $report->toArray();
 */
final class DeliveryReport {
    /** @var array<string,array{status:string,reason:?string}> */
    private array $channels = [];

    /**
     * Record a result for a single channel.
     *
     * @param string $channel Channel name (e.g. "email", "push", "in_app").
     * @param string $status Status string (e.g. "sent", "queued", "failed", "skipped").
     * @param string|null $reason Machine-readable reason key, when applicable.
     */
    public function set(string $channel, string $status, ?string $reason = null): void {
        $this->channels[$channel] = [
            'status' => $status,
            'reason' => $reason,
        ];
    }

    /**
     * Return all recorded channel results.
     *
     * @return array<string,array{status:string,reason:?string}>
     */
    public function toArray(): array {
        return $this->channels;
    }
}

