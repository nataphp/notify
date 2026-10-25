<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Core;

use Nata\Core\Configure;
use Throwable;

/**
 * Per-recipient delivery policy, supplied by the host application.
 *
 * The plugin has no opinion about what a recipient's preferences mean — whether
 * "team alerts" covers three notifications or thirty is application knowledge.
 * So it asks, via two optional hooks:
 *
 * - `Notify.recipientGate` — may this notification reach this recipient on this
 *   channel at all? Used for per-user opt-outs.
 * - `Notify.recipientSilentHours` — does this recipient have their own quiet
 *   window? Used for per-user "do not disturb".
 *
 * Both hooks fail **open**: an unconfigured hook, a wrong return type, or a
 * thrown exception all mean "no per-recipient opinion". A preference lookup that
 * breaks must never silently swallow notifications.
 *
 * @example
 * // in the app's Notify config file
 * 'recipientGate' => fn(RecipientAddress $to, NotificationDefinition $def, string $channel)
 *     => $preferences->allows($to->recipientId, $def->key),
 */
final class RecipientPolicy {
    /**
     * Ask the host app whether a channel is permitted for this recipient.
     *
     * @param RecipientAddress $to Resolved recipient.
     * @param NotificationDefinition $definition Definition being sent.
     * @param string $channel Channel about to be used.
     * @return bool False only when the app explicitly says no.
     */
    public static function allows(RecipientAddress $to, NotificationDefinition $definition, string $channel): bool {
        $gate = Configure::read('Notify.recipientGate');
        if (!is_callable($gate)) {
            return true;
        }

        try {
            return $gate($to, $definition, $channel) !== false;
        } catch (Throwable) {
            // Fail open — a broken preference lookup must not drop notifications.
            return true;
        }
    }

    /**
     * Ask the host app for this recipient's own quiet window.
     *
     * @param RecipientAddress $to Resolved recipient.
     * @return array<string,string>|false|null A window to apply, `false` when this
     *   recipient wants no quiet hours at all, or null for "no opinion — use the
     *   global setting".
     */
    public static function silentHoursFor(RecipientAddress $to): array|false|null {
        $hook = Configure::read('Notify.recipientSilentHours');
        if (!is_callable($hook)) {
            return null;
        }

        try {
            $window = $hook($to);
        } catch (Throwable) {
            return null;
        }

        if ($window === false) {
            return false;
        }

        return is_array($window) ? $window : null;
    }
}
