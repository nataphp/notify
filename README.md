# Notify Plugin

Multi-channel notification delivery for NataPHP apps: **in-app bell**, **email**, **web push** and **SMS**, backed by a single delivery ledger that doubles as a retry queue and an audit trail.

---

## Installation

**1. Require the package** (`nataphp/notify`; the VCS repositories go next to `nataphp/core`'s in the app's `composer.json`):

```json
"repositories": [
    {"type": "vcs", "url": "https://github.com/nataphp/core"},
    {"type": "vcs", "url": "https://github.com/nataphp/notify"}
],
"require": {
    "nataphp/notify": "^1.0"
}
```

The package autoloads `Notify\`. Copy the browser script to `public/plugin/notify/` with `php bin/nata.php plugin assets Notify -c vendor/nataphp/notify` (or by hand) and version it with the app.

**2. Load the plugin** from `config/plugins.php`, pointing it at your config file:

```php
Plugin::load('Notify', [
    'path' => ROOT . 'vendor' . DS . 'nataphp' . DS . 'notify' . DS,
    'config' => ROOT . 'config' . DS . 'notify.php',
    'bootstrap' => true,
    'routes' => true,
]);
```

`config` is optional and defaults to `ROOT/config/notify.php`. The plugin's own
bootstrap loads that file into the `Notify.*` Configure key before applying its
built-in defaults, so anything the file omits falls back to a sensible default.

Loading it explicitly is what passes `config`; without it, Nata's Composer plugin discovery still loads the package, with the built-in defaults only.

**3. Run the schema** (`vendor/nataphp/notify/config/schema/notify.sql`).

Creates three tables (all prefixed `notify_`):
- `notify_notification_deliveries` — the delivery ledger: in-app bell rows, plus queued/sent email and push
- `notify_push_subscriptions` — web push endpoints per recipient
- `notify_notification_preferences` — per-recipient toggles

---

## Configuration

Everything lives under `Notify.*`, and there is exactly one place to author it:
the config file referenced from `Plugin::load` above. It returns one array, which
the plugin merges into `Configure` before applying its own defaults — so you only
list what you want to change.

Here is a complete, working file. Copy it and delete what you do not need;
every key except `definitions` is optional.

```php
<?php
/**
 * Notify plugin configuration — single source of truth for Notify.*
 */

use Nata\ORM\TableRegistry;
use Notify\Core\RecipientAddress;

return [

    // ── Web push (required for the `push` channel) ───────────────────────────
    'push' => [
        'vapidPublicKey' => env('VAPID_PUBLIC_KEY'),
        'vapidPrivateKey' => env('VAPID_PRIVATE_KEY'),
        'subject' => 'mailto:support@example.com',
        'appName' => 'MyApp',   // fallback push title (optional)
        'ttlDefault' => 3600,   // seconds; definitions may override (optional)
    ],

    // ── Email ────────────────────────────────────────────────────────────────
    'mailerProfile' => 'default',

    // ── Locale ───────────────────────────────────────────────────────────────
    // Last resort when neither the call site nor the recipient specifies one.
    'defaultLocale' => 'en',

    // ── Silent hours (optional) ──────────────────────────────────────────────
    // Email and push are deferred (queued with a later available_at) inside this
    // window; in-app is always immediate. Definitions marked
    // `silentMode => 'ignore'` break through. Omit the key to disable entirely.
    'silentHours' => [
        'start' => '20:00',            // 24h HH:MM
        'end' => '07:00',              // may span midnight
        'timezone' => 'Europe/Lisbon',
    ],

    // ── Resolvers ────────────────────────────────────────────────────────────
    // Delivery details for a recipient. Required for email and push; the locale
    // returned here is what feeds the middle of the locale chain.
    'recipientAddressResolver' => function (string $model, int $id): ?RecipientAddress {
        try {
            $user = TableRegistry::get($model)->get($id, ['select' => ['id', 'email', 'language']]);

            return new RecipientAddress($model, $id, $user->email, $user->language ?: null);
        } catch (\Throwable) {
            return null;
        }
    },

    // Who the current HTTP request belongs to, for the bell/subscribe endpoints.
    'recipientIdentityResolver' => fn($controller) => /* see below */ null,

    // ── Bell dropdown layout (optional) ──────────────────────────────────────
    'layout' => false,

    // ── Notification registry ────────────────────────────────────────────────
    'definitions' => [
        'shift.assigned' => [
            'defaultChannels' => ['in_app', 'email', 'push'],
            'dedupeKey' => fn(array $vars) => 'shift-assigned-' . ($vars['shift']['id'] ?? '0'),
        ],
    ],
];
```

Writing `Configure::write('Notify.…')` from the host app's own bootstrap also
works — the file is just written into the same keys — but it splits the settings
across two places. Prefer the file.

---

## How settings are resolved

Every setting follows the same chain, so there is no per-setting rule to memorise:

```
call-site override  ->  recipient  ->  definition  ->  global config  ->  built-in default
```

| Setting | Call site | Recipient | Definition | Global |
|---|---|---|---|---|
| `channels` | `->channels([...])` | `Notify.recipientGate` (per channel) | `defaultChannels` | — |
| `locale` | `->locale(...)` | `RecipientAddress::$locale` | — | `Notify.defaultLocale` |
| `ttl` | `->ttl(...)` | — | `ttl` | `Notify.push.ttlDefault` |
| `dedupeKey` | `->dedupeKey(...)` / `->noDedupe()` | — | `dedupeKey` | — (off) |
| silent hours | `->silentHours(...)` / `->ignoreSilentHours()` | `Notify.recipientSilentHours` | `silentMode` | `Notify.silentHours` |
| `appName` | `->appName(...)` | — | — | `Notify.push.appName` |

Anything a definition sets can be overridden for one send, and anything that can
be switched on can be switched off explicitly — `->noDedupe()` and
`->ignoreSilentHours()` exist precisely so "off" is never confused with
"unspecified".

Resolution happens once per send, in `Notify\Core\EffectiveSettings`, and the
resolved values are persisted into the ledger's `delivery_opts`. The delivery
worker replays those stored values rather than re-resolving, so a queued
notification cannot change language or TTL because config changed in between.

**You rarely need `->locale()`.** It defaults to the recipient's own language.
Pass it only to force a specific locale — or pass `''` to deliberately request
the locale-less fallback templates, which is useful for testing that a fallback
exists.

The reference below documents each key; set them as array entries in that file.

### Resolvers

```php
// Delivery details (email, locale, timezone) for a recipient.
// Required for email and push; in-app works without it.
// A RecipientAddressResolverInterface instance, or callable(string $model, int $id): ?RecipientAddress
'recipientAddressResolver' => $resolver,

// Who the current HTTP request belongs to (for the bell/dropdown endpoints).
// A RecipientIdentityResolverInterface instance, or callable(Controller $ctrl): ?RecipientIdentity
'recipientIdentityResolver' => $resolver,
```

### Web Push (required for `push` channel)

```php
'push' => [
    'vapidPublicKey' => 'base64-encoded-public-key',
    'vapidPrivateKey' => 'base64-encoded-private-key',
    'subject' => 'mailto:support@example.com',
    'appName' => 'MyApp',   // fallback push title (optional)
    'ttlDefault' => 3600,   // push TTL in seconds (optional, defaults to 3600)
],
```

### SMS (required for `sms` channel)

The `sms` channel sends through one gateway adapter (`Notify\Sms\SmsGatewayInterface`), picked by name:

| Name | Adapter | Settings |
|---|---|---|
| `log` | `LogGateway`: writes to `LOGS/sms.log` (development) | `path` |
| `twilio` | `TwilioGateway` | `accountSid`, `authToken`, `messagingServiceSid` |
| `vonage` | `VonageGateway` (SMS API) | `apiKey`, `apiSecret` |
| `infobip` | `InfobipGateway` | `baseUrl` (the account's own), `apiKey` |
| `smsonline` | `SmsOnlineGateway` (smsonline.pt; spec only given to customers, so configured like `http`) | as `http` |
| `http` | `GenericHttpGateway`: any HTTP API described by configuration | `url`, `method`, `format` (json/form/query), `fields`, `params`, `headers`, `basicAuth`, `toFormat` (e164/digits), `messageIdPath`, `successPath` + `successValues`, `errorPath` |

```php
'sms' => [
    'gateway' => 'twilio',      // empty = no SMS
    'sender' => 'MyApp',        // up to 11 letters and digits, or a number
    'maxSegments' => 3,         // longer texts are refused (default 3)
    'gateways' => [
        'twilio' => ['accountSid' => '…', 'authToken' => '…'],
        // Any other provider: your own class, or the generic one.
        'acme' => ['className' => App\Sms\AcmeGateway::class, 'key' => '…'],
    ],
],
```

The recipient's phone is `RecipientAddress::$phone`, in E.164 (`+351912345678`): return it from your resolver. A per-send sender: `->smsSender('Shop')` (most gateways only accept registered sender IDs). Adapters throw `RetryableChannelException` for what may work later (no answer, 408, 429, 5xx, throttling) and `NonRetryableChannelException` for what never will; the channel itself refuses, without calling the gateway, a missing or non-E.164 phone, an empty or too long text and an invalid sender. A custom adapter implements `SmsGatewayInterface` (extend `AbstractHttpGateway` for HTTP ones) and is created with `new $className($config, $http)`; `GatewayRegistry::register('acme', AcmeGateway::class)` does the same from code. Text with characters outside GSM-7 (ã, ç, á, emoji) goes as UCS-2: 70 characters per message, 67 per segment (`SmsText::segments()`).

### Silent Hours (optional)

Inside the configured window, email and push are **deferred, not dropped**: they
are written to the ledger as `queued` with `available_at` set to the end of the
window, and the worker sends them afterwards. In-app is always immediate.

```php
'silentHours' => [
    'start' => '22:00',            // 24h HH:MM
    'end' => '07:00',              // can span midnight
    'timezone' => 'Europe/Lisbon',
],
```

Two things to know. Omitting the key disables the feature entirely — every
definition's `silentMode` then has no effect. And deferral only works if the
delivery worker is actually scheduled; without it, queued rows never send.

Definitions that should cut through the window set `silentMode => 'ignore'` —
use it for genuine emergencies, not for everything.

### Layout (optional)

```php
'layout' => false,   // layout for the bell dropdown action (default: false)
```

---

## Implementing the Two Required Interfaces

### RecipientAddressResolverInterface

Provides delivery details for a given recipient. The bus calls this once per send,
before any channel work.

The `locale` you return is not decoration — it is the middle link of the locale
chain, so returning it correctly is what makes notifications arrive in each
recipient's own language without any caller passing `->locale()`.

```php
use Notify\Core\RecipientAddress;
use Notify\Core\RecipientAddressResolverInterface;
use Nata\ORM\TableRegistry;

class NotifyAddressResolver implements RecipientAddressResolverInterface
{
    public function resolve(string $recipientModel, int $recipientId): RecipientAddress
    {
        $Table = TableRegistry::get($recipientModel);
        $user  = $Table->get($recipientId, ['fields' => ['id', 'email', 'locale', 'timezone']]);

        return new RecipientAddress(
            recipientModel: $recipientModel,
            recipientId:    $recipientId,
            email:          $user->email,
            locale:         $user->locale ?? 'en',
            timezone:       $user->timezone ?? 'UTC',
        );
    }
}

// then, in your Notify config file:
'recipientAddressResolver' => new NotifyAddressResolver(),
```

Alternatively, use a callable — and return null rather than throwing, so one
unresolvable recipient does not break a fan-out to everyone else:

```php
'recipientAddressResolver' => function (string $model, int $id): ?RecipientAddress {
    try {
        $user = TableRegistry::get($model)->get($id, ['select' => ['id', 'email', 'language']]);

        return new RecipientAddress($model, $id, $user->email, $user->language ?: null);
    } catch (\Throwable) {
        return null;
    }
},
```

### RecipientIdentityResolverInterface

Tells the controller who the current HTTP request belongs to. Called by the dropdown/read/subscribe endpoints.

```php
use Notify\Core\RecipientIdentity;
use Notify\Core\RecipientIdentityResolverInterface;
use Nata\Controller\Controller;

class NotifyIdentityResolver implements RecipientIdentityResolverInterface
{
    public function resolve(Controller $controller): ?RecipientIdentity
    {
        $user = $controller->Auth->user();
        if (!$user) {
            return null;
        }
        return new RecipientIdentity('Users', (int) $user['id']);
    }
}

// then, in your Notify config file:
'recipientIdentityResolver' => new NotifyIdentityResolver(),
```

---

## Adding a Notification — Full Walkthrough

Three steps: register it, write its templates, send it. Say you want to tell a
staff member their shift was cancelled, in-app and by push.

### 1. Register the definition

In `config/notify.php`, under `definitions`:

```php
'shift.cancelled' => [
    'defaultChannels' => ['in_app', 'push'],
    'dedupeKey' => fn(array $vars) => 'shift-cancelled-' . ($vars['shift']['id'] ?? '0'),
],
```

The key is a dot path, and it doubles as the template folder path
(`shift.cancelled` → `shift/cancelled/`). Nothing else is mandatory:
`ttl`, `silentMode`, and `dedupeKey` all have sane defaults.

### 2. Write the templates

The **definition alone does not make a channel deliverable** — the template set
has to exist on disk. Each channel needs its own complete set, or that channel is
skipped with `missing_template`:

| Channel | Required files | Optional |
|---|---|---|
| `in_app` | `title.txt`, `body.txt` | — |
| `push` | `title.txt`, `body.txt` | `url.txt`, `tag.txt` |
| `email` | `subject.txt`, `html.html`, `text.txt` | — |

```
templates/notifications/shift/cancelled/
  in_app/
    title.txt      ->  Shift cancelled
    body.txt       ->  Hi {{ first_name }}, your shift on {{ shift.date|date:'d/m/Y' }} was cancelled.
  push/
    title.txt      ->  Shift cancelled
    body.txt       ->  {{ shift.date|date:'d/m/Y' }} — cancelled.
```

These are **not** Smarty. They use `{{ placeholders }}` — see
[Template Syntax](#template-syntax). Add a locale subfolder
(`in_app/pt/title.txt`) for each translation; the locale-less file is the
fallback, so write it in whichever language you want unmatched locales to get.

### 3. Send it

```php
use Notify\Service\Factory;

Factory::notifier()
    ->using('shift.cancelled')
    ->to($user)
    ->set([
        'first_name' => $user->first_name,
        'shift' => ['id' => $shift->id, 'date' => $shift->date],
    ])
    ->url(['controller' => 'Shifts', 'action' => 'index', 'prefix' => 'staff'])
    ->send();
```

Note what is *not* there: no `->channels()` (the definition already says
in-app + push) and no `->locale()` (it defaults to the recipient's language).
Reach for those only when this particular send genuinely differs.

Every variable your templates reference must appear in `->set()`. A missing one
renders as an empty string rather than erroring, so a typo shows up as a blank
in the message body, not an exception — and note that the same applies inside
`dedupeKey`, where a missing id silently collapses every send onto one key.

### 4. Check it

If the host app has an admin self-test page, run it: it renders every definition
on every channel that has templates, and reports missing templates, unregistered
keys, and channels that only an override can reach.

---

## Defining Notification Types

Definitions go under the `definitions` key of your config file. Every key that
can be sent must appear here: an unregistered key fails the whole send with
`unknown_definition`, and an empty registry throws at load time rather than
letting every send fail one by one.

```php
'definitions' => [
    'shift.assigned' => [
        'defaultChannels' => ['email', 'push', 'in_app'],
        'ttl' => 3600,           // push TTL in seconds; omit to inherit Notify.push.ttlDefault
        'dedupeKey' => fn(array $vars) => 'shift-assigned-' . ($vars['shift']['id'] ?? '0'),
        'silentMode' => 'respect', // 'respect' (default) | 'ignore'
    ],
    'shift.cancelled' => [
        'defaultChannels' => ['email', 'in_app'],
        'silentMode' => 'ignore',  // send even during silent hours
    ],
],
```

Only list what differs from the defaults — every key except `defaultChannels`
is optional.

### `defaultChannels`

Which channels are used when the caller does **not** pass `->channels([...])`.
It does not control whether a channel *can* deliver: that depends on whether a
complete template set exists on disk. A channel with templates but no entry
here is reachable only via an explicit override.

### `dedupeKey`

Prevents sending the same notification twice to the same recipient. Accepts a **callable** (recommended) or a **template string**:

```php
// callable — receives the resolved vars array, returns the key string
'dedupeKey' => fn(array $vars) => 'shift-assigned-' . ($vars['shift']['id'] ?? '0'),

// template string — rendered against vars before comparison
'dedupeKey' => 'shift-assigned-{{ shift.id|default:\'0\' }}',
```

Before sending, the bus looks in `notify_notification_deliveries` for the same
recipient and key with either an **unread** `in_app` row or a **queued/processing**
row on another channel. If one exists, all channels are marked `skipped/deduplicated`.

Note the window: dedupe suppresses a duplicate the recipient has not dealt with
yet. Once the bell row is read, or the email/push reaches `sent`, an identical
notification will send again. It is not a send-once-ever guarantee.

Omit `dedupeKey` entirely to disable dedupe for that notification.

**Alternative — Configure** (equivalent; the config file is just written into this key):

```php
Configure::write('Notify.definitions', [
    'shift.assigned' => [ /* ... */ ],
]);
```

---

## Per-Recipient Preferences (optional)

The plugin has no opinion about what a recipient's preferences mean — whether
"team alerts" covers three notifications or thirty is application knowledge. So it
asks, through two optional hooks in your config file. Both live in
`Notify\Core\RecipientPolicy`.

### `recipientGate` — per-user opt-outs

Called once per channel per send. Return `false` to block that channel.

```php
'recipientGate' => function (RecipientAddress $to, NotificationDefinition $definition, string $channel): bool {
    static $preferences = null;
    $preferences ??= new RecipientPreferences();

    return $preferences->allows($to->recipientId, $definition->key, $channel);
},
```

A blocked channel is reported as `skipped / user_opted_out`, **not** dropped from
the channel list — so "the user turned this off" is visible in the delivery report
instead of looking like a notification that vanished. The gate is re-checked by the
worker before a queued delivery goes out, so an opt-out made while a row was
waiting is still honoured.

### `recipientSilentHours` — per-user quiet hours

Called once per send. Three possible answers:

| Return | Meaning |
|---|---|
| `null` | No opinion — use the global `Notify.silentHours` window |
| `array` | This recipient's own window |
| `false` | This recipient has quiet hours switched **off** — reachable at any hour |

```php
'recipientSilentHours' => function (RecipientAddress $to): array|false|null {
    static $preferences = null;
    $preferences ??= new RecipientPreferences();

    // Opted in -> use the global window. Opted out -> never defer.
    return $preferences->isSilentModeEnabled($to->recipientId) ? null : false;
},
```

That `false` is what makes an *opt-in* quiet window possible: define the window
globally, and let the hook decide who it applies to. Without the hook, a configured
window applies to everybody.

### Both hooks fail open

An unconfigured hook, a wrong return type, or a thrown exception all mean "no
per-recipient opinion". A preference lookup that breaks must never silently
swallow notifications — the failure mode of a muted notification is far worse than
one unwanted push.

Two things worth deciding in your own implementation:

- **Which channels an opt-out suppresses.** Gating `in_app` as well means the
  recipient loses the record entirely; leaving the bell alone means opting out
  stops the interruption but not the information. The latter is usually kinder.
- **Never gate transactional mail.** Account activation and device authorization
  are how someone gets into the app at all. Keep an explicit always-allow list.

---

## Templates

Templates live in `templates/notifications/` at the project root. The definition key is converted to a directory path by replacing `.` with `/`.

```
templates/notifications/
  shift/
    assigned/
      in_app/
        title.txt         ← required
        body.txt          ← required
      email/
        subject.txt       ← required
        html.html         ← required
        text.txt          ← required
      push/
        title.txt         ← required
        body.txt          ← required
        url.txt           ← optional (default: "/")
        tag.txt           ← optional (default: definition key)
      sms/
        text.txt          ← required (plain text)
```

### Locale-specific templates

Place locale variants in a subfolder named after the locale code. The locator checks for the locale-specific file first, then falls back to the default:

```
templates/notifications/
  shift/
    assigned/
      email/
        html.html          ← default (English)
        pt/
          html.html        ← used when locale is 'pt'
        fr/
          html.html        ← used when locale is 'fr'
```

The locale is resolved per notification send — see `->locale()` in the Fluent API section below.

### Template Syntax

```
{{ var }}                      simple variable
{{ parent.child }}             dot-path into arrays
{{ var|upper }}                modifier
{{ var|default:'guest' }}      modifier with argument
{{ var|date:'d/m/Y' }}         format a date
{{ var|default:'–'|upper }}    chained modifiers
```

Built-in modifiers: `upper`, `lower`, `default`, `date`, `escape`, `raw`.

**Example** — `shift/assigned/in_app/title.txt`:
```
Shift assigned for {{ shift.date|date:'d/m/Y' }}
```

**Example** — `shift/assigned/email/html.html`:
```html
<p>Hello {{ user.name|default:'there' }},</p>
<p>You have been assigned a shift at <strong>{{ clinic.name }}</strong>.</p>
```

---

## Sending Notifications

### Fluent API (recommended)

```php
use Notify\Service\Factory;

$result = Factory::notifier()
    ->using('shift.assigned')
    ->to($userEntity)                      // any Nata ORM Entity
    ->set([
        'user'   => ['name' => $user->name],
        'shift'  => ['id' => 42, 'date' => $shift->date],
        'clinic' => ['name' => $clinic->name],
    ])
    ->send();                              // or ->queue() to force async delivery
```

#### Multiple recipients

`toMany()` sends the same notification to each recipient and returns an array of per-channel reports, one per recipient. The chain must still end with `->send()` or `->queue()`:

```php
Factory::notifier()
    ->using('shift.late_arrival')
    ->set(['shift' => ['id' => $shift->id]])
    ->toMany($shift->shared_with_colleagues)
    ->send();
```

#### Channel override

```php
Factory::notifier()
    ->using('shift.cancelled')
    ->to($userEntity)
    ->set($vars)
    ->channels(['email'])                  // override defaultChannels
    ->send();
```

#### Action URL

Pass any value that `Router::url()` accepts — a string or an array. Always resolved to a full absolute URL:

```php
Factory::notifier()
    ->using('shift.assigned')
    ->to($userEntity)
    ->set($vars)
    ->url(['controller' => 'Shifts', 'action' => 'view', $shift->id])
    ->send();
```

The resolved URL is available in the push payload as `url` and in the in-app payload's `data` as `url`. It overrides any `url.txt` template for push.

#### Logo

```php
->logo('https://example.com/icon.png')
```

Included in push and in-app payloads as `logo`. Useful for branded notifications.

#### Push TTL

```php
->ttl(300)   // this message is worthless after 5 minutes
```

Overrides the definition's `ttl`. Useful for time-critical alerts that should not
be delivered late to a device that was offline.

#### Dedupe

```php
->dedupeKey('shift-swap-' . $item->id . '-' . $signature)   // literal or {{ }} template
->dedupeKey(fn(array $vars) => 'custom-' . $vars['item']['id'])
->noDedupe()                                               // send even if a duplicate is unread
```

Overrides the definition's key. The common reason to override is adding a content
hash so a *changed* notification re-notifies while identical re-saves stay
suppressed. `->noDedupe()` is for sends that must always land — a manual "resend"
button, for instance.

#### Silent hours

```php
->ignoreSilentHours()                // urgent this once
->silentHours('23:00', '06:00')      // a different window for this send
```

`->ignoreSilentHours()` does for one send what `silentMode => 'ignore'` does for a
whole definition. Prefer the definition when a notification is *always* urgent.

`silentHours()` takes the bounds as `"HH:MM"` strings or as `Time` instances, plus an
optional IANA timezone. A `Time` contributes only its wall-clock time — the date is
ignored — and its own timezone becomes the window's, so these are equivalent:

```php
->silentHours('23:00', '06:00', 'Europe/Lisbon')
->silentHours(new Time('23:00', 'Europe/Lisbon'), new Time('06:00', 'Europe/Lisbon'))
```

An explicit third argument wins over any timezone the bounds carry; with none supplied
anywhere, the window is read in PHP's default timezone. Give an end earlier than the
start to span midnight. Malformed bounds — and two `Time` bounds in conflicting
timezones — throw `InvalidArgumentException` at the call site, rather than quietly
leaving the send unthrottled.

#### App name (push fallback title)

```php
->appName($organization->name)   // multi-tenant: show the client's name, not the platform's
```

Overrides `Notify.push.appName`. Resolved up front and stored with the delivery,
so a push deferred by silent hours still shows the right name when the worker
sends it later — which a runtime `Configure::write()` could not do, since the
worker is a different process with no per-request context.

**It is only a fallback.** The push title comes from the template first:

| Order | Source |
|---|---|
| 1 | `push/title.txt` (or its locale variant) |
| 2 | `->appName(...)` for this send |
| 3 | `Notify.push.appName` |
| 4 | none of the above → the `title` key is omitted, and your service worker's own default applies |

So if every push template defines a title — the normal case — `appName` never
appears. To brand *every* notification per client, put the name in the template
instead:

```
{{ org_name|default:'Proescala' }} — novo turno disponível
```

Note also that the small attribution line an OS shows next to a notification (the
site origin, or an installed PWA's name) comes from the browser and the web app
manifest, not from this plugin.

#### Locale

**Usually omit this.** The locale defaults to the recipient's own language via
`RecipientAddress::$locale`, then to `Notify.defaultLocale` — including for
`toMany()`, where the address is resolved per recipient, so mixed-language
audiences work with no callback of any kind.

Pass it only to override that:

```php
->locale('pt')   // force a specific locale for every recipient
->locale('')     // force the locale-less fallback templates (testing a fallback)

// per-recipient callable — still supported, but redundant with the default chain
->locale(fn(Entity $recipient) => $recipient->locale ?? 'en')
```

The locator tries the locale subfolder first (e.g. `email/pt/html.html`) and falls
back to the locale-less template when it is missing. That fallback is silent: the
delivery reports `sent`, and the recipient simply gets whichever language the
fallback file is written in. If a translation looks missing in production, check
for the locale subfolder before suspecting the locale chain.

### Delivery Report

Both `send()` and `queue()` return a per-channel report:

```php
[
    'in_app' => ['status' => 'sent',   'reason' => null],
    'email'  => ['status' => 'queued', 'reason' => 'silent_hours'],
    'push'   => ['status' => 'queued', 'reason' => 'silent_hours'],
]
```

| Status    | Meaning                                   |
|-----------|-------------------------------------------|
| `sent`    | Delivered immediately                     |
| `queued`  | Written to the ledger for the worker      |
| `skipped` | Deliberately not sent — see reason below  |
| `failed`  | Non-retryable error                       |

The `reason` matters as much as the status, because several very different things
land on `skipped`:

| Reason | Meaning |
|---|---|
| `missing_template` | The channel's template set is incomplete on disk — usually a bug |
| `deduplicated` | An identical notification is already unread or pending |
| `user_opted_out` | The recipient turned this category off |
| `unknown_channel` | The definition names a channel the bus has no implementation for |
| `silent_hours` | Deferred, not dropped — paired with `queued`, not `skipped` |

`missing_template` is the one to watch: it means a notification silently did not
send, and nothing else in the system will complain about it.

### Low-level API

```php
use Notify\Core\NotificationRequest;
use Notify\Service\Factory;

$request = new NotificationRequest('shift.assigned', $userEntity, $vars);
$result  = Factory::bus()->send($request);
```

---

## Delivery Worker

The worker processes `queued` rows from `notify_notification_deliveries` (email and
push only — in-app is always written immediately).

**Schedule it.** Anything the bus defers depends on the worker to actually go out:
silent-hours deferrals, retryable failures, and every `->queue()` call. Without a
scheduled worker those notifications are never delivered at all, and nothing in the
app will report a problem — the rows simply sit at `queued`.

```php
use Notify\Service\Worker;
use Notify\Service\DefinitionRegistry;
use Notify\Service\TemplateLoader;
use Notify\Service\TemplateLocator;

$worker = new Worker(
    new DefinitionRegistry(),
    new TemplateLoader(TemplateLocator::default()),
);

$stats = $worker->run(limit: 100, dryRun: false);
// [
//   'picked'  => 100,
//   'sent'    => 95,
//   'retry'   => 3,
//   'failed'  => 2,
//   'skipped' => 0,
// ]
```

Run this from a cron job or a shell command. A limit of `50`–`100` per run is a reasonable default.

**Retry backoff**: up to 5 attempts. Delay formula: `min(3600, 30 × 2^(n−1))` seconds → 30 s, 60 s, 120 s, 240 s, 480 s.

---

## HTTP Endpoints

Load the plugin routes in `Config/routes.php`:

```php
// somewhere inside your Router::scope('/') block:
include APP . '../Plugin/Notify/Config/routes.inc.php';
```

All endpoints are under `/notify/`. Authentication is the host app's responsibility — guard these routes with your existing auth middleware or controller hooks.

**CSRF is enforced by the plugin.** The controller loads Nata's `Csrf` component, so
every `POST` must carry the token as the `X-CSRF-Token` header (or a `_csrfToken`
field). The bundled pieces already do it: the dropdown sets `hx-headers` for its
buttons, and `push-subscribe.js` sends the button's `data-csrf-token` or, failing
that, the `csrfToken` cookie. If you build your own bell or push button, send the
header too — or the request fails with 403. (Without it, another site could post
its own push endpoint to `push/subscribe` as `text/plain` and receive the logged-in
user's notifications.)

If the host app serves the plugin's JS from a copy (e.g. `public/plugin/notify/js/`),
update that copy whenever `public/js/push-subscribe.js` changes.

| Method | Path | Purpose |
|--------|------|---------|
| `GET`  | `/notify/notifications/dropdown` | HTMX fragment: bell list + unread count |
| `POST` | `/notify/notifications/read/:id` | Mark a single notification read |
| `POST` | `/notify/notifications/read-all` | Mark all notifications read |
| `POST` | `/notify/notifications/push/subscribe` | Register / refresh a push subscription |

**Push subscribe** expects a JSON body:

```json
{ "endpoint": "https://...", "p256dh": "...", "auth": "..." }
```

All endpoints return `{ "success": true }` on success or `{ "success": false, "error": "reason_key" }` on failure.

---

## Garbage Collection (optional)

Removes rows in all three Notify tables for recipients that no longer exist in the host app.

```php
use Notify\Service\GarbageCollector;

$stats = (new GarbageCollector())->purgeOrphanRecipients(limit: 500);
// [
//   'Notify.NotificationDeliveries'      => 12,
//   'Notify.PushSubscriptions'           => 3,
//   'Notify.UserNotificationPreferences' => 1,
// ]
```

Run periodically (e.g. daily cron). Unknown or unregistered recipient models are treated as existing — no rows are deleted for them.
