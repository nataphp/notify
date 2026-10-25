<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Controller;

use DateTimeImmutable;
use Nata\Controller\Controller;
use Nata\Core\Configure;
use Nata\ORM\TableRegistry;
use Notify\Core\RecipientIdentity;
use Throwable;

/**
 * Notify plugin UI/API endpoints.
 *
 * Provides:
 * - HTMX dropdown fragment for in-app notifications
 * - SSE stream for in-app notification changes (DB poll; see `stream()`)
 * - Endpoints to mark notifications as read
 * - Endpoint to register/update web push subscriptions
 *
 * All actions are guarded by `beforeFilter()` which redirects unauthenticated users to `/login`.
 */
class Notifications extends Controller {

/**
 * Controller initialization hook.
 *
 * Configures the view layout based on `Configure::read('Notify.layout')`.
 *
 * @return void
 */
    public function initialize() {
        parent::initialize();

        $layout = Configure::read('Notify.layout');
        $this->viewBuilder()->layout($layout !== null ? $layout : false);
    }

/**
 * Controller hook.
 *
 * Authentication/authorization is the host application's responsibility, but
 * CSRF is enforced here: the POST endpoints (read, read-all, push/subscribe)
 * change state for the logged-in user, and push/subscribe reads a raw JSON
 * body, which another site could send as `text/plain` without a preflight.
 * Without this, a malicious page could attach its own push endpoint to the
 * victim's account and receive their notifications.
 *
 * Csrf is loaded here and not in initialize(): Nata never calls a
 * controller's initialize(), and components are only started after the
 * `Controller.startup` event, which is where Csrf validates. beforeFilter()
 * runs on `Controller.initialize`, just before that event.
 *
 * Clients send the token as the `X-CSRF-Token` header (the dropdown sets it
 * through `hx-headers`; push-subscribe.js reads it from `data-csrf-token` or
 * the `csrfToken` cookie).
 *
 * @return void
 */
    public function beforeFilter() {
        if (!isset($this->Csrf)) {
            $this->loadComponent('Csrf');
        }
        parent::beforeFilter();
        $this->set('notify_push_vapid_public', Configure::read('Notify.push.vapidPublicKey'));
    }

/**
 * Expose the CSRF token to the dropdown template (minted by Csrf on startup).
 *
 * @return void
 */
    public function beforeRender() {
        parent::beforeRender();
        $this->set('notify_csrf_token', $this->request->params['_csrfToken'] ?? '');
    }

/**
 * Resolve the recipient identity.
 *
 * @return \Notify\Core\RecipientIdentity|null Recipient identity
 */
    private function resolveRecipientIdentity(): ?RecipientIdentity {
        $id = $this->Auth->user('id');
        $model = $this->Auth->user('model');
        if (!$id || !$model) {
            return null;
        }
        return new RecipientIdentity($model, (int) $id);
    }

/**
 * Respond with a 401 Unauthenticated error.
 *
 * @return void
 */
    private function respondUnauthenticated(): void {
        $this->autoRender = false;
        $this->response->statusCode(401);
        $this->response->type('json');
        $this->response->body(json_encode(['success' => false, 'error' => 'unauthenticated']));
    }

/**
 * HTMX fragment: dropdown content (list + unread count).
 *
 * Sets view variables:
 * - `notifications`: latest items (limited)
 * - `unread_count`: integer count of unread notifications
 * - `push_enabled`: whether the recipient already has a stored push subscription
 */
    public function dropdown() {
        $this->viewBuilder()->layout(false);
        $identity = $this->resolveRecipientIdentity();
        if (!$identity) {
            $this->respondUnauthenticated();
            return;
        }

        $PushSubscriptions = TableRegistry::get('Notify.PushSubscriptions');
        $pushEnabled = $PushSubscriptions->find()
            ->where([
                'recipient_model' => $identity->recipientModel,
                'recipient_id' => $identity->recipientId,
            ])
            ->count() > 0;
        $this->set('push_enabled', $pushEnabled);

        $Deliveries = TableRegistry::get('Notify.NotificationDeliveries');
        $items = $Deliveries->find()
            ->where([
                'recipient_model' => $identity->recipientModel,
                'recipient_id' => $identity->recipientId,
                'channel' => 'in_app',
                'status' => 'sent',
            ])
            ->order(['created' => 'DESC'])
            ->limit(12)
            ->all();

        // Back-compat with dropdown template: expose title/body fields derived from rendered payload.
        foreach ($items as $n) {
            $rendered = $n->rendered ?? null;
            if (is_string($rendered)) {
                $decoded = json_decode($rendered, true);
                $rendered = is_array($decoded) ? $decoded : null;
            } elseif (is_object($rendered)) {
                $rendered = json_decode(json_encode($rendered), true) ?: null;
            }
            if (is_array($rendered)) {
                $n->title = (string) ($rendered['title'] ?? '');
                $n->body = (string) ($rendered['body'] ?? '');
            }
        }

        $unread = $Deliveries->find()
            ->where([
                'recipient_model' => $identity->recipientModel,
                'recipient_id' => $identity->recipientId,
                'channel' => 'in_app',
                'status' => 'sent',
                'read_at IS' => null,
            ])
            ->count();

        $this->set('notifications', $items);
        $this->set('unread_count', (int) $unread);
    }

/**
 * Mark a single notification as read.
 *
 * @param mixed $id Notification id (route param).
 * @return void
 */
    public function read($id = null) {
        $this->autoRender = false;
        $identity = $this->resolveRecipientIdentity();
        if (!$identity) {
            $this->respondUnauthenticated();
            return;
        }
        $id = (int) $id;

        $Deliveries = TableRegistry::get('Notify.NotificationDeliveries');
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $Deliveries->updateAll(
            ['read_at' => $now],
            [
                'id' => $id,
                'recipient_model' => $identity->recipientModel,
                'recipient_id' => $identity->recipientId,
                'channel' => 'in_app',
            ]
        );

        $this->response->type('json');
        $this->response->body(json_encode(['success' => true]));
    }

/**
 * Mark all notifications as read.
 *
 * @return void
 */
    public function readAll() {
        $this->autoRender = false;
        $identity = $this->resolveRecipientIdentity();
        if (!$identity) {
            $this->respondUnauthenticated();
            return;
        }

        $Deliveries = TableRegistry::get('Notify.NotificationDeliveries');
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $Deliveries->updateAll(
            ['read_at' => $now],
            [
                'recipient_model' => $identity->recipientModel,
                'recipient_id' => $identity->recipientId,
                'channel' => 'in_app',
                'read_at IS' => null,
            ]
        );

        $this->response->type('json');
        $this->response->body(json_encode(['success' => true]));
    }

/**
 * Store/refresh web push subscription for current user.
 *
 * Expects JSON body:
 * - endpoint: string
 * - p256dh: string
 * - auth: string
 *
 * Responds with JSON `{ success: true }` or a 400 with `{ success: false, error: ... }`.
 *
 * @return void
 */
    public function pushSubscribe() {
        $this->autoRender = false;
        $identity = $this->resolveRecipientIdentity();
        if (!$identity) {
            $this->respondUnauthenticated();
            return;
        }

        $raw = file_get_contents('php://input');
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            $this->response->statusCode(400);
            $this->response->type('json');
            $this->response->body(json_encode(['success' => false, 'error' => 'invalid_json']));
            return;
        }

        $endpoint = (string) ($data['endpoint'] ?? '');
        $p256dh = (string) ($data['p256dh'] ?? '');
        $auth = (string) ($data['auth'] ?? '');
        if (trim($endpoint) === '' || trim($p256dh) === '' || trim($auth) === '') {
            $this->response->statusCode(400);
            $this->response->type('json');
            $this->response->body(json_encode(['success' => false, 'error' => 'missing_fields']));
            return;
        }

        $PushSubscriptions = TableRegistry::get('Notify.PushSubscriptions');
        $endpointHash = $this->_endpointHash($endpoint);
        $existing = $PushSubscriptions->find()
            ->where(['endpoint_hash' => $endpointHash])
            ->first();

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        if ($existing) {
            $existing->recipient_model = $identity->recipientModel;
            $existing->recipient_id = $identity->recipientId;
            $existing->p256dh = $p256dh;
            $existing->auth = $auth;
            $existing->last_used_at = $now;
            $PushSubscriptions->save($existing);
        } else {
            $entity = $PushSubscriptions->newEntity([
                'recipient_model' => $identity->recipientModel,
                'recipient_id' => $identity->recipientId,
                'endpoint' => $endpoint,
                'endpoint_hash' => $endpointHash,
                'p256dh' => $p256dh,
                'auth' => $auth,
                'created' => $now,
                'last_used_at'  => $now,
            ]);
            $PushSubscriptions->save($entity);
        }

        $this->response->type('json');
        $this->response->body(json_encode(['success' => true]));
    }

/**
 * Server-Sent Events stream: poll in-app notifications for the current recipient.
 *
 * Emits a `data` event when `MAX(id)` or unread count changes. Closes after
 * `Notify.sse.maxDurationSeconds` so workers are released; EventSource reconnects.
 *
 * @return void
 */
    public function stream() {
        $identity = $this->resolveRecipientIdentity();
        if (!$identity) {
            $this->respondUnauthenticated();
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $this->autoRender = false;
        $this->response->body('');

        $pollInterval = (int) Configure::read('Notify.sse.pollIntervalSeconds');
        if ($pollInterval < 1) {
            $pollInterval = 3;
        }
        $maxDuration = (int) Configure::read('Notify.sse.maxDurationSeconds');
        if ($maxDuration < 60) {
            $maxDuration = 300;
        }

        $this->_ssePrepareOutput();

        ignore_user_abort(true);
        set_time_limit(0);

        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $Deliveries = TableRegistry::get('Notify.NotificationDeliveries');

        $startedAt = time();
        $lastMaxId = null;
        $lastUnread = null;

        while (true) {
            if (connection_aborted()) {
                break;
            }
            if ((time() - $startedAt) >= $maxDuration) {
                break;
            }

            try {
                $snap = $this->_notificationSnapshot($Deliveries, $identity);
            } catch (Throwable $e) {
                echo ": db-error\n\n";
                $this->_sseFlush();
                sleep($pollInterval);
                continue;
            }

            if ($lastMaxId !== null && ($snap['max_id'] !== $lastMaxId || $snap['unread'] !== $lastUnread)) {
                $payload = json_encode(['refresh' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                echo 'data: ' . $payload . "\n\n";
                $this->_sseFlush();
            }

            $lastMaxId = $snap['max_id'];
            $lastUnread = $snap['unread'];

            echo ': ping ' . time() . "\n\n";
            $this->_sseFlush();

            sleep($pollInterval);
        }

        exit(0);
    }

/**
 * End output buffers and allow implicit flush for SSE.
 *
 * @return void
 */
    private function _ssePrepareOutput(): void {
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');

        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);
    }

/**
 * Flush PHP output buffers and the HTTP connection buffer.
 *
 * @return void
 */
    private function _sseFlush(): void {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

/**
 * Single-query snapshot: latest notification id and unread count for the recipient.
 *
     * @param \Notify\Model\Table\NotificationDeliveries $Deliveries Notification deliveries table
 * @param \Notify\Core\RecipientIdentity $identity Recipient identity
 * @return array{max_id: int, unread: int}
 */
    private function _notificationSnapshot($Deliveries, RecipientIdentity $identity): array {
        $table = $Deliveries->table();
        $conn = $Deliveries->connection();
        $sql = 'SELECT MAX(id) AS max_id, COALESCE(SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END), 0) AS unread'
            . ' FROM ' . $table
            . " WHERE recipient_model = ? AND recipient_id = ? AND channel = 'in_app' AND status = 'sent'";

        $result = $conn->executeQuery(
            $sql,
            [$identity->recipientModel, $identity->recipientId],
            [\PDO::PARAM_STR, \PDO::PARAM_INT]
        );
        $row = $result->fetchAssociative();
        if ($row === false) {
            return ['max_id' => 0, 'unread' => 0];
        }

        return [
            'max_id' => $row['max_id'] !== null ? (int) $row['max_id'] : 0,
            'unread' => (int) $row['unread'],
        ];
    }

/**
 * Hash a push endpoint to a stable value for indexing and deduplication.
 *
 * @param string $endpoint Raw subscription endpoint URL.
 * @return string sha256 hash of the endpoint.
 */
    private function _endpointHash(string $endpoint): string {
        return hash('sha256', $endpoint);
    }

}
