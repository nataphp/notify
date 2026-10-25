<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */

use Nata\Routing\Router;

/**
 * Notify plugin — canonical routes under /notify/
 *
 * All actions are auth-guarded in Notify\Controller\Notifications::beforeFilter().
 * Each host area (staff, management, …) can point its own bell/dropdown to these
 * same endpoints — no area-specific routes needed in the plugin.
 *
 * Endpoints:
 *   GET  /notify/notifications/dropdown        HTMX fragment
 *   GET  /notify/notifications/stream          SSE (in-app notification updates)
 *   POST /notify/notifications/read/:id        Mark one as read
 *   POST /notify/notifications/read-all        Mark all as read
 *   POST /notify/notifications/push/subscribe  Register push subscription
 */
Router::scope('/notify', function () {
    Router::connect('/notifications/dropdown', [
        'plugin' => 'Notify',
        'controller' => 'notifications',
        'action' => 'dropdown',
    ]);

    Router::connect('/notifications/stream', [
        'plugin' => 'Notify',
        'controller' => 'notifications',
        'action' => 'stream',
    ]);

    Router::connect('/notifications/read/:id', [
        'plugin' => 'Notify',
        'controller' => 'notifications',
        'action' => 'read',
    ], ['pass' => ['id']]);

    Router::connect('/notifications/read-all', [
        'plugin' => 'Notify',
        'controller' => 'notifications',
        'action' => 'readAll',
    ]);

    Router::connect('/notifications/push/subscribe', [
        'plugin' => 'Notify',
        'controller' => 'notifications',
        'action' => 'pushSubscribe',
    ]);
});
