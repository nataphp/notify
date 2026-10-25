<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Service;

use InvalidArgumentException;
use Nata\Email\Address;
use Nata\Email\AddressCollection;
use Nata\I18n\Time;
use Nata\ORM\Entity;
use Nata\Routing\Router;
use Notify\Core\NotificationRequest;
use Notify\Core\SilentHours;

/**
 * Builder for a single notification operation created via `Notifier::using()`.
 *
 * This class accumulates:
 * - target recipient entity (single or multiple)
 * - template variables (merged)
 * - optional channel override
 * - optional action URL (string or Router-compatible array, resolved to full URL)
 * - optional logo URL
 * - optional locale (string or callable receiving the recipient entity)
 *
 * It can then produce a `NotificationRequest` and either send immediately or queue.
 *
 * @example
 * $result = Factory::notifier()
 *     ->using('shift.assigned')
 *     ->to($recipient)
 *     ->set(['shift' => ['id' => 123]])
 *     ->url(['controller' => 'Shifts', 'action' => 'view', 123])
 *     ->locale(fn(Entity $r) => $r->locale ?? 'en')
 *     ->channels(['email'])
 *     ->queue();
 */
final class PendingNotification {
    /** @var array<string,mixed> */
    private array $vars = [];
    /** @var array<int,string>|null */
    private ?array $channels = null;
    private ?Entity $recipient = null;
    /** @var iterable<Entity>|null */
    private ?iterable $recipients = null;
    private ?string $url = null;
    private ?string $logo = null;
    /** @var string|callable(Entity):(?string)|null */
    private mixed $locale = null;
    private string|Address|null $from = null;
    private string|Address|null $replyTo = null;
    private string|Address|null $sender = null;
    private string|Address|AddressCollection|null $cc = null;
    private string|Address|AddressCollection|null $bcc = null;
    private ?int $ttl = null;
    /** @var string|callable(array<string,mixed>):string|false|null */
    private mixed $dedupeKey = null;
    /** @var array<string,string>|false|null */
    private array|false|null $silentHours = null;
    private ?string $appName = null;
    /** @var array<string,string> */
    private array $headers = [];
    private ?string $smsSender = null;

    public function __construct(
        private readonly Bus $bus,
        private readonly string $definitionKey
    ) {}

    /**
     * Set the target recipient for this notification.
     */
    public function to(Entity $recipient): self {
        $this->recipient = $recipient;
        return $this;
    }

    /**
     * @param iterable<Entity> $recipients
     */
    public function toMany(iterable $recipients): self {
        $this->recipients = $recipients;
        return $this;
    }

    /**
     * @param array<string,mixed> $vars
     */
    public function set(array $vars): self {
        $this->vars = array_merge($this->vars, $vars);
        return $this;
    }

    /**
     * @param array<int,string> $channels
     */
    public function channels(array $channels): self {
        $this->channels = $channels;
        return $this;
    }

    /**
     * Set a clickable action URL for this notification.
     *
     * Accepts a full URL string or any array that Router::url() supports.
     * Always resolved to an absolute URL.
     *
     * @param string|array<string,mixed> $url
     */
    public function url(string|array $url): self {
        $this->url = Router::url($url, true);
        return $this;
    }

    /**
     * Set a logo/image URL for this notification (used by push and in-app channels).
     */
    public function logo(string $logo): self {
        $this->logo = $logo;
        return $this;
    }

    /**
     * Set the locale for template resolution.
     *
     * Accepts a fixed locale string or a callable receiving the recipient entity
     * and returning the locale string — useful with toMany() when recipients
     * may have different preferred languages.
     *
     * @param string|callable(Entity):(?string) $locale
     */
    public function locale(string|callable $locale): self {
        $this->locale = $locale;
        return $this;
    }

    public function from(string|Address $from): self {
        $this->from = $from;
        return $this;
    }

    public function replyTo(string|Address $replyTo): self {
        $this->replyTo = $replyTo;
        return $this;
    }

    public function sender(string|Address $sender): self {
        $this->sender = $sender;
        return $this;
    }

    public function cc(string|Address|AddressCollection $cc): self {
        $this->cc = $cc;
        return $this;
    }

    public function bcc(string|Address|AddressCollection $bcc): self {
        $this->bcc = $bcc;
        return $this;
    }

    /**
     * Override the push TTL (message lifetime at the push gateway) for this send.
     *
     * Omit to inherit the definition's `ttl`, then `Notify.push.ttlDefault`.
     *
     * @param int $ttl Seconds.
     */
    public function ttl(int $ttl): self {
        $this->ttl = $ttl;
        return $this;
    }

    /**
     * Override the dedupe key for this send.
     *
     * Use when the definition's key is not specific enough for one particular
     * send — e.g. adding a content hash so a repeat edit re-notifies.
     *
     * @param string|callable(array<string,mixed>):string $dedupeKey Literal key, a template
     *   string using `{{ }}` placeholders, or a callable receiving the resolved vars.
     */
    public function dedupeKey(string|callable $dedupeKey): self {
        $this->dedupeKey = $dedupeKey;
        return $this;
    }

    /**
     * Disable dedupe for this send, so it goes out even if an identical unread
     * notification already exists.
     *
     * Distinct from simply not calling `dedupeKey()`, which inherits the
     * definition's key.
     */
    public function noDedupe(): self {
        $this->dedupeKey = false;
        return $this;
    }

    /**
     * Override the silent-hours window for this send.
     *
     * Bounds are 24h times, either as "HH:MM" strings or as `Time` instances; a `Time`
     * contributes only its wall-clock time, never its date. The window may span midnight,
     * by giving an end earlier than the start.
     *
     * @example
     * ->silentHours('23:00', '06:00')
     * ->silentHours(start: '23:00', end: '06:00', timezone: 'Europe/Lisbon')
     * ->silentHours(new Time('23:00', 'Europe/Lisbon'), new Time('06:00', 'Europe/Lisbon'))
     *
     * @param string|Time $start Window start.
     * @param string|Time $end Window end.
     * @param string|null $timezone IANA zone the window is evaluated in. Overrides any
     *   timezone the bounds carry; when nothing supplies one, PHP's default timezone applies.
     * @return self
     * @throws InvalidArgumentException When a bound is not a valid 24h time, or when two
     *   Time bounds are in different timezones and no $timezone resolves them.
     */
    public function silentHours(string|Time $start, string|Time $end, ?string $timezone = null): self {
        $this->silentHours = SilentHours::window($start, $end, $timezone);
        return $this;
    }

    /**
     * Send now regardless of silent hours, as if the definition were marked
     * `silentMode => 'ignore'`.
     *
     * For a notification that is normally deferrable but is urgent this once.
     */
    public function ignoreSilentHours(): self {
        $this->silentHours = false;
        return $this;
    }

    /**
     * Extra email headers for this send (e.g. List-Unsubscribe and
     * List-Unsubscribe-Post, RFC 8058). Kept with the queued row; ignored by
     * the other channels.
     *
     * @param array<string,string> $headers Name => value.
     */
    public function headers(array $headers): self {
        foreach ($headers as $name => $value) {
            // Header injection guard: one line, a plain token as the name.
            if (!is_string($name) || preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1 || preg_match('/[\r\n]/', (string) $value) === 1) {
                throw new \InvalidArgumentException('Invalid email header: ' . $name);
            }
            $this->headers[$name] = (string) $value;
        }
        return $this;
    }

    /**
     * SMS sender ID for this send (up to 11 letters and digits, or a number),
     * overriding `Notify.sms.sender`. Most gateways only accept sender IDs
     * registered in the account. Kept with the queued row; ignored by the
     * other channels.
     *
     * @param string $sender Sender ID.
     */
    public function smsSender(string $sender): self {
        $this->smsSender = $sender;
        return $this;
    }

    /**
     * Set the fallback push title for this send, overriding `Notify.push.appName`.
     *
     * Useful in a multi-tenant app, where the name shown should be the client's
     * rather than the platform's. The value is resolved and stored with the
     * delivery, so a push deferred by silent hours still shows the right name when
     * the worker sends it later — which a runtime `Configure::write()` could not do.
     *
     * Note this is only a **fallback**: it appears solely when the push template
     * renders an empty title. To brand every notification, put the name in the
     * `push/title.txt` template instead, e.g. `{{ org_name }} — ...`.
     *
     * @param string $appName Name to show as the notification title.
     */
    public function appName(string $appName): self {
        $this->appName = $appName;
        return $this;
    }

    /**
     * Send immediately when possible (best-effort).
     *
     * @return array<string,array{status:string,reason:?string}>
     */
    public function send(): array {
        if ($this->recipients !== null) {
            $results = [];
            foreach ($this->recipients as $recipient) {
                $this->recipient = $recipient;
                $results[] = $this->bus->send($this->toRequest());
            }
            return $results;
        }
        return $this->bus->send($this->toRequest());
    }

    /**
     * Force queuing (write outbox) for supported channels; in-app is still immediate.
     *
     * @return array<string,array{status:string,reason:?string}>
     */
    public function queue(): array {
        if ($this->recipients !== null) {
            $results = [];
            foreach ($this->recipients as $recipient) {
                $this->recipient = $recipient;
                $results[] = $this->bus->queue($this->toRequest());
            }
            return $results;
        }
        return $this->bus->queue($this->toRequest());
    }

    /**
     * @throws \InvalidArgumentException When recipient has not been set.
     */
    private function toRequest(): NotificationRequest {
        if ($this->recipient === null) {
            throw new \InvalidArgumentException('Missing recipient (call to() or toMany()).');
        }
        return new NotificationRequest(
            $this->definitionKey,
            $this->recipient,
            $this->vars,
            $this->channels,
            $this->url,
            $this->logo,
            null,
            null,
            $this->resolveLocale(),
            $this->from,
            $this->replyTo,
            $this->sender,
            $this->cc,
            $this->bcc,
            $this->ttl,
            $this->dedupeKey,
            $this->silentHours,
            $this->appName,
            $this->headers,
            $this->smsSender,
        );
    }

    private function resolveLocale(): ?string {
        if ($this->locale === null) {
            return null;
        }
        if (is_callable($this->locale)) {
            $result = ($this->locale)($this->recipient);
            return ($result !== null && trim($result) !== '') ? $result : null;
        }
        return $this->locale;
    }
}
