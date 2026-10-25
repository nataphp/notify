<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Core;

use Nata\Email\Address;
use Nata\Email\AddressCollection;
use Nata\ORM\Entity;

/**
 * Value object representing a single notification send/queue request.
 *
 * A request selects a notification definition (by key), a target user, a set of template variables,
 * and optionally any per-send overrides of the definition's own settings.
 *
 * Every override distinguishes "not specified" (null, so the definition and then
 * the global config decide) from an explicit value — including the explicit
 * disabling of a feature, which is why `dedupeKey` and `silentHours` accept
 * `false` rather than treating null as "off".
 *
 * @example
 * $request = new NotificationRequest(
 *     'shift.assigned',
 *     $recipient,
 *     ['shift' => ['id' => 123], 'clinic' => ['name' => 'Downtown Clinic']],
 *     ['email', 'push']
 * );
 */
final class NotificationRequest {
    /**
     * @param array<string,mixed> $vars
     * @param array<int,string>|null $channels
     * @param int|null $ttl Push TTL override in seconds; null defers to the definition.
     * @param string|callable(array<string,mixed>):string|false|null $dedupeKey Key override;
     *   `false` disables dedupe for this send, null defers to the definition.
     * @param array{start?:string,end?:string,timezone?:string}|false|null $silentHours Window
     *   override; `false` ignores silent hours for this send, null defers to the definition
     *   and global config.
     * @param string|null $appName Fallback push title for this send — used only when the push
     *   template renders no title. Null defers to `Notify.push.appName`.
     * @param array<string,string> $headers Extra email headers (e.g. List-Unsubscribe); email only.
     * @param string|null $smsSender SMS sender ID for this send; null defers to `Notify.sms.sender`. sms only.
     */
    public function __construct(
        public readonly string $definitionKey,
        public readonly Entity $recipient,
        public readonly array $vars = [],
        public readonly ?array $channels = null,
        public readonly ?string $url = null,
        public readonly ?string $logo = null,
        public readonly ?string $icon = null,
        public readonly ?string $badge = null,
        public readonly ?string $locale = null,
        public readonly string|Address|null $from = null,
        public readonly string|Address|null $replyTo = null,
        public readonly string|Address|null $sender = null,
        public readonly string|Address|AddressCollection|null $cc = null,
        public readonly string|Address|AddressCollection|null $bcc = null,
        public readonly ?int $ttl = null,
        public readonly mixed $dedupeKey = null,
        public readonly array|false|null $silentHours = null,
        public readonly ?string $appName = null,
        public readonly array $headers = [],
        public readonly ?string $smsSender = null,
    ) {}
}
