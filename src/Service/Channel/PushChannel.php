<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Service\Channel;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Nata\Core\Configure;
use Nata\ORM\TableRegistry;
use Notify\Core\ChannelInterface;
use Notify\Core\NonRetryableChannelException;
use Notify\Core\NotificationDefinition;
use Notify\Core\RecipientAddress;
use Notify\Core\RetryableChannelException;

/**
 * Web push delivery channel (VAPID).
 *
 * Payload shape (rendered by `ChannelPayloadRenderer::renderPush()`):
 * - title: string
 * - body: string
 * - url: string (optional; defaults to "/")
 * - tag: string (optional; defaults to definition key)
 * - icon: string (optional; URL passed through to the SW, falls back to SW default)
 * - badge: string (optional; URL passed through to the SW, falls back to SW default)
 *
 * Requires configuration:
 * - `Notify.push.vapidPublicKey`
 * - `Notify.push.vapidPrivateKey`
 * - `Notify.push.subject`
 *
 * Failure behavior:
 * - Missing VAPID config or missing subscriptions throws `NonRetryableChannelException`.
 * - A subscription answering 404/410 is deleted; when every one did, it throws
 *   `NonRetryableChannelException` with reason "subscription_gone".
 * - Reaching at least one subscription is a success.
 * - Otherwise transient push gateway issues throw `RetryableChannelException`.
 */
final class PushChannel implements ChannelInterface {
    /**
     * Hash a subscription endpoint for stable lookup/cleanup.
     */
    private function endpointHash(string $endpoint): string {
        return hash('sha256', $endpoint);
    }

    /**
     * @inheritDoc
     */
    public function name(): string {
        return 'push';
    }

    /**
     * @inheritDoc
     *
     * @throws NonRetryableChannelException When VAPID config is missing or user has no subscriptions.
     * @throws RetryableChannelException When one or more deliveries fail in a retryable way.
     */
    public function sendNow(RecipientAddress $to, array $payload, NotificationDefinition $definition, array $context): ?string {
        $vapidPublic = (string) Configure::read('Notify.push.vapidPublicKey');
        $vapidPrivate = (string) Configure::read('Notify.push.vapidPrivateKey');
        $subject = (string) Configure::read('Notify.push.subject');
        if (trim($vapidPublic) === '' || trim($vapidPrivate) === '' || trim($subject) === '') {
            throw new NonRetryableChannelException('Missing VAPID configuration.', 'push', 'misconfigured');
        }

        $PushSubscriptions = TableRegistry::get('Notify.PushSubscriptions');
        $subs = $PushSubscriptions->find()
            ->where([
                'recipient_model' => $to->recipientModel,
                'recipient_id' => $to->recipientId,
            ])
            ->all();

        if ($subs->isEmpty()) {
            throw new NonRetryableChannelException('No push subscriptions for recipient.', 'push', 'no_subscription');
        }

        // Prefer the TTL resolved by EffectiveSettings and passed in the context —
        // for a queued delivery that is the value captured when it was enqueued.
        // The definition/config lookup remains as a fallback for direct callers.
        $ttl = $context['ttl'] ?? null;
        if (!is_numeric($ttl)) {
            $ttl = $definition->ttl();
            if ($ttl === null) {
                $ttlDefault = Configure::read('Notify.push.ttlDefault');
                $ttl = is_numeric($ttlDefault) ? (int) $ttlDefault : null;
            }
        }
        $ttl = is_numeric($ttl) ? (int) $ttl : null;

        $auth = [
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $vapidPublic,
                'privateKey' => $vapidPrivate,
            ],
        ];

        try {
            $webPush = new WebPush($auth);
            if ($ttl !== null && $ttl > 0) {
                $webPush->setDefaultOptions(['TTL' => $ttl]);
            }

            // Title falls back to the app name resolved for this delivery (a
            // per-send ->appName(), else Notify.push.appName). When neither the
            // template nor that yields anything, the key is omitted entirely so
            // the service worker's own default can apply — emitting an empty
            // string here would override it and show a blank heading.
            $title = trim((string) ($payload['title'] ?? ''));
            if ($title === '') {
                $title = trim((string) ($context['app_name'] ?? Configure::read('Notify.push.appName')));
            }

            $msg = [
                'body' => (string) ($payload['body'] ?? ''),
                'url' => (string) ($payload['url'] ?? '/'),
                'tag' => (string) ($payload['tag'] ?? $definition->key),
            ];
            if ($title !== '') {
                $msg['title'] = $title;
            }
            if (isset($payload['icon'])) {
                $msg['icon'] = (string) $payload['icon'];
            }
            if (isset($payload['badge'])) {
                $msg['badge'] = (string) $payload['badge'];
            }
            $message = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            foreach ($subs as $sub) {
                $subscription = Subscription::create([
                    'endpoint' => (string) $sub->endpoint,
                    'publicKey' => (string) $sub->p256dh,
                    'authToken' => (string) $sub->auth,
                ]);

                $webPush->queueNotification($subscription, $message);
            }

            $hadRetryable = false;
            $delivered = 0;
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $delivered++;
                    continue;
                }
                // 404/410 typically means subscription is gone – treat as non-retryable for that sub.
                $status = $report->getResponse() ? $report->getResponse()->getStatusCode() : null;
                if ($status === 404 || $status === 410) {
                    // Best effort cleanup.
                    try {
                        $endpoint = (string) $report->getRequest()->getUri();
                        $PushSubscriptions->deleteAll(['endpoint_hash' => $this->endpointHash($endpoint)]);
                    } catch (\Throwable) {
                        // ignore
                    }
                    continue;
                }
                $hadRetryable = true;
            }

            // One device reached is a delivery; a retry would repeat it there.
            if ($delivered > 0) {
                return null;
            }
            if ($hadRetryable) {
                throw new RetryableChannelException('One or more push deliveries failed.', 'push', 'push_error');
            }
            // Every subscription answered 404/410 and was removed: nothing
            // left to retry (callers may fall back to another channel).
            throw new NonRetryableChannelException('Every push subscription is gone.', 'push', 'subscription_gone');
        } catch (NonRetryableChannelException|RetryableChannelException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RetryableChannelException($e->getMessage(), 'push', 'push_error', $e);
        }
        return null;
    }
}

