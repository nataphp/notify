<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Service;

/**
 * Fluent notification API that starts a send/queue operation by definition key.
 *
 * This is a thin wrapper over `Bus` that provides an ergonomic builder (`PendingNotification`)
 * for setting variables, choosing channels, selecting the target user, and finally sending.
 *
 * @example
 * $result = Factory::notifier()
 *     ->using('shift.assigned')
 *     ->to($recipient)
 *     ->set(['shift' => ['id' => 123]])
 *     ->send();
 */
final class Notifier {
    public function __construct(
        private readonly Bus $bus
    ) {}

    /**
     * Start building a notification to be sent using the given definition key.
     *
     * @param string $definitionKey Registered definition key (e.g. "shift.assigned").
     * @return PendingNotification Builder used to configure user, vars, channels and send/queue.
     */
    public function using(string $definitionKey): PendingNotification {
        return new PendingNotification($this->bus, $definitionKey);
    }
}
