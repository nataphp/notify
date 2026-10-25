<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Core;

use DateTimeImmutable;
use Nata\Core\Configure;

/**
 * The settings a single notification send actually runs with, resolved once.
 *
 * Every value here follows the same chain, so no setting has its own special
 * rule to remember:
 *
 *   call-site override -> recipient -> definition -> global config -> built-in default
 *
 * Not every setting has a meaningful step at every level (a recipient has no
 * opinion about TTL, for instance), but the order never changes, and the whole
 * set is resolved in one place so the immediate send path and the queued worker
 * path cannot drift apart — the resolved values are persisted into the delivery
 * ledger's `delivery_opts` and replayed by the worker verbatim.
 *
 * @example
 * $settings = EffectiveSettings::resolve($definition, $request, $address, $renderer);
 * $payload = $renderer->renderChannel($key, 'push', $vars, locale: $settings->locale);
 */
final class EffectiveSettings {
    /**
     * @param array<int,string> $channels Lower-cased, de-duplicated channel names.
     * @param string|null $locale Locale for template resolution. An empty string means
     *   "explicitly no locale" — the caller asked for the locale-less fallback templates —
     *   and is deliberately distinct from null, which means "nothing specified anywhere".
     * @param int|null $ttl Push message lifetime in seconds, or null for none.
     * @param string|null $dedupeKey Fully resolved and rendered dedupe key, or null when dedupe is off.
     * @param DateTimeImmutable|null $silentUntil When set, email/push must be deferred until then.
     * @param string|null $appName Fallback push title, used only when the template renders none.
     */
    public function __construct(
        public readonly array $channels,
        public readonly ?string $locale,
        public readonly ?int $ttl,
        public readonly ?string $dedupeKey,
        public readonly ?DateTimeImmutable $silentUntil,
        public readonly ?string $appName = null,
    ) {}

    /**
     * Resolve every setting for one send.
     *
     * @param NotificationDefinition $definition Definition being sent.
     * @param NotificationRequest $request The request, carrying any call-site overrides.
     * @param RecipientAddress $to Resolved recipient, consulted for locale.
     * @param TemplateRenderer $renderer Used to render a dedupe key expressed as a template string.
     * @param DateTimeImmutable|null $now Clock override, for tests.
     * @return self The values this send will actually use.
     */
    public static function resolve(
        NotificationDefinition $definition,
        NotificationRequest $request,
        RecipientAddress $to,
        TemplateRenderer $renderer,
        ?DateTimeImmutable $now = null
    ): self {
        return new self(
            self::channelsFor($definition, $request),
            self::resolveLocale($request, $to),
            self::resolveTtl($definition, $request),
            self::resolveDedupeKey($definition, $request, $renderer),
            self::resolveSilentUntil($definition, $request, $to, $now),
            self::resolveAppName($request),
        );
    }

    /**
     * Resolve the fallback push title: call-site override, then global config.
     *
     * Resolved here rather than read from Configure inside the channel so that a
     * per-client name survives being queued — the worker replays the stored value
     * instead of re-reading a global it has no per-request context for.
     *
     * @param NotificationRequest $request The request, carrying any call-site override.
     * @return string|null The name to fall back to, or null when none is set anywhere.
     */
    private static function resolveAppName(NotificationRequest $request): ?string {
        $appName = $request->appName;
        if ($appName === null) {
            $appName = Configure::read('Notify.push.appName');
        }

        $appName = trim((string) $appName);

        return $appName !== '' ? $appName : null;
    }

    /**
     * Resolve the channel list.
     *
     * Exposed separately because the bus needs the channel names before it has a
     * recipient address, so it can report a per-channel failure when the
     * recipient cannot be resolved at all.
     *
     * @param NotificationDefinition $definition Definition being sent.
     * @param NotificationRequest $request The request, carrying any call-site override.
     * @return array<int,string> Lower-cased, de-duplicated channel names.
     */
    public static function channelsFor(NotificationDefinition $definition, NotificationRequest $request): array {
        $channels = $request->channels ?? $definition->defaultChannels();
        return array_values(array_unique(array_map('strtolower', $channels)));
    }

    /**
     * Resolve the locale used to pick locale-specific templates.
     *
     * A call-site locale always wins, including an empty string — that is how a
     * caller asks for the locale-less fallback templates on purpose. Only when
     * the call site said nothing at all do we fall back to the recipient's own
     * language and then to the application default.
     *
     * @param NotificationRequest $request The request, carrying any call-site override.
     * @param RecipientAddress $to Resolved recipient.
     * @return string|null Locale code, '' for the deliberate fallback, or null when unspecified.
     */
    private static function resolveLocale(NotificationRequest $request, RecipientAddress $to): ?string {
        if ($request->locale !== null) {
            return $request->locale;
        }

        $recipientLocale = trim((string) ($to->locale ?? ''));
        if ($recipientLocale !== '') {
            return $recipientLocale;
        }

        $default = trim((string) Configure::read('Notify.defaultLocale'));

        return $default !== '' ? $default : null;
    }

    /**
     * Resolve the push TTL: call-site override, then definition, then global default.
     *
     * @param NotificationDefinition $definition Definition being sent.
     * @param NotificationRequest $request The request, carrying any call-site override.
     * @return int|null Seconds, or null when nothing sets one.
     */
    private static function resolveTtl(NotificationDefinition $definition, NotificationRequest $request): ?int {
        if ($request->ttl !== null) {
            return $request->ttl;
        }

        $ttl = $definition->ttl();
        if ($ttl !== null) {
            return $ttl;
        }

        $default = Configure::read('Notify.push.ttlDefault');

        return is_numeric($default) ? (int) $default : null;
    }

    /**
     * Resolve the dedupe key and render it.
     *
     * A call-site key wins; `false` from the call site disables dedupe outright,
     * which is why it is distinguishable from "not specified".
     *
     * Both callable and string configs produce a string, and both are passed
     * through the renderer — harmless for callables, since the renderer returns
     * its input untouched when there are no `{{ }}` placeholders in it.
     *
     * @param NotificationDefinition $definition Definition being sent.
     * @param NotificationRequest $request The request, whose vars feed the key.
     * @param TemplateRenderer $renderer Renderer for template-string keys.
     * @return string|null The key, or null when dedupe is disabled for this send.
     */
    private static function resolveDedupeKey(
        NotificationDefinition $definition,
        NotificationRequest $request,
        TemplateRenderer $renderer
    ): ?string {
        if ($request->dedupeKey === false) {
            return null;
        }

        if ($request->dedupeKey !== null) {
            $override = $request->dedupeKey;
            $dedupeKey = is_callable($override) ? (string) $override($request->vars) : (string) $override;
        } else {
            $dedupeKey = $definition->resolveDedupeKey($request->vars);
        }

        if (!is_string($dedupeKey)) {
            return null;
        }

        $dedupeKey = trim($renderer->render($dedupeKey, $request->vars, false));

        return $dedupeKey !== '' ? $dedupeKey : null;
    }

    /**
     * Resolve the silent-hours deferral point, if any.
     *
     * The full chain, first match wins:
     *
     * 1. `->ignoreSilentHours()` at the call site — send now, no matter what.
     * 2. `->silentHours(...)` at the call site — use exactly that window.
     * 3. `silentMode => 'ignore'` on the definition — genuine emergencies still
     *    reach a phone at 3am.
     * 4. The recipient's own window, via `Notify.recipientSilentHours`; `false`
     *    there means this recipient has quiet hours switched off.
     * 5. The global `Notify.silentHours` window, or nothing if unconfigured.
     *
     * @param NotificationDefinition $definition Definition being sent.
     * @param NotificationRequest $request The request, carrying any call-site override.
     * @param RecipientAddress $to Resolved recipient, consulted for a personal window.
     * @param DateTimeImmutable|null $now Clock override, for tests.
     * @return DateTimeImmutable|null When email/push must wait until, or null to send now.
     */
    private static function resolveSilentUntil(
        NotificationDefinition $definition,
        NotificationRequest $request,
        RecipientAddress $to,
        ?DateTimeImmutable $now
    ): ?DateTimeImmutable {
        $now = $now ?? new DateTimeImmutable('now');

        if ($request->silentHours === false) {
            return null;
        }

        if (is_array($request->silentHours)) {
            return SilentHours::nextAllowedAt($request->silentHours, $now);
        }

        if ($definition->silentMode() !== 'respect') {
            return null;
        }

        $recipientWindow = RecipientPolicy::silentHoursFor($to);
        if ($recipientWindow === false) {
            return null;
        }
        if (is_array($recipientWindow)) {
            return SilentHours::nextAllowedAt($recipientWindow, $now);
        }

        $config = Configure::read('Notify.silentHours');

        return SilentHours::nextAllowedAt(is_array($config) ? $config : null, $now);
    }
}
