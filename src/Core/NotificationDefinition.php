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
 * Immutable notification definition metadata resolved from configuration.
 *
 * A definition configures defaults and delivery behaviors used by the bus/worker, such as:
 * - default channels (email/push/in_app)
 * - TTL (push); omit to inherit `Notify.push.ttlDefault`
 * - dedupe key template
 * - silent-hours behavior
 *
 * @example
 * $def = new NotificationDefinition('shift.assigned', [
 *     'defaultChannels' => ['email', 'push', 'in_app'],
 *     'ttl' => 3600,
 *     'dedupeKey' => 'shift:{{ shift.id }}',
 *     'silentMode' => 'respect',
 * ]);
 */
final class NotificationDefinition {
    /**
     * @param array{defaultChannels?:array<int,string>,ttl?:int,dedupeKey?:string|callable(array<string,mixed>):string|null,silentMode?:string} $meta
     */
    public function __construct(
        public readonly string $key,
        public readonly array $meta = [],
    ) {}

    /**
     * Return default channels for this notification.
     *
     * @return array<int,string>
     */
    public function defaultChannels(): array {
        $channels = $this->meta['defaultChannels'] ?? [];
        return is_array($channels) ? array_values($channels) : [];
    }

    /**
     * Return the configured TTL in seconds, or null if unset.
     *
     * Used by push delivery to configure message lifetime.
     */
    public function ttl(): ?int {
        $ttl = $this->meta['ttl'] ?? null;
        return is_int($ttl) ? $ttl : (is_numeric($ttl) ? (int) $ttl : null);
    }

    /**
     * Resolve the dedupe key for a given set of vars, or null if dedupe is disabled.
     *
     * Accepts either a template string (rendered by the bus) or a callable (called with vars).
     * The bus uses the resolved key to skip sending when a matching unread/pending record exists.
     *
     * @param array<string,mixed> $vars
     */
    public function resolveDedupeKey(array $vars): ?string {
        $cfg = $this->meta['dedupeKey'] ?? null;
        if ($cfg === null) {
            return null;
        }
        if (is_callable($cfg)) {
            $key = trim((string) $cfg($vars));
            return $key !== '' ? $key : null;
        }
        if (is_string($cfg) && trim($cfg) !== '') {
            return trim($cfg);
        }
        return null;
    }

    /**
     * Control whether this notification respects configured silent hours.
     *
     * @return string "respect" (default) or "ignore"
     */
    public function silentMode(): string {
        $m = (string) ($this->meta['silentMode'] ?? 'respect');
        return in_array($m, ['respect', 'ignore'], true) ? $m : 'respect';
    }
}

