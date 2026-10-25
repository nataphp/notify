<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * Bootstrap defaults are intentionally conservative; the host application can override any
 * value via `Configure::write()`.
 *
 * @package Notify
 */

use Nata\Core\Configure;

// Load the host application's Notify configuration, if it has one. The path
// comes from the `config` option passed to Plugin::load() (available here as
// $config, since Plugin::bootstrap() includes this file in its own scope), and
// falls back to the conventional location under the app's config directory.
//
// This runs before the defaults below so the "only if unset" checks let the app
// win on every key it defines.
$notifyAppConfigPath = $config['config'] ?? (ROOT . 'config' . DS . 'notify.php');
if (is_string($notifyAppConfigPath) && is_file($notifyAppConfigPath)) {
    $notifyAppConfig = require $notifyAppConfigPath;
    if (is_array($notifyAppConfig)) {
        Configure::write('Notify', array_replace_recursive(
            (array) Configure::read('Notify'),
            $notifyAppConfig
        ));
    }
    unset($notifyAppConfig);
}
unset($notifyAppConfigPath);

// Provide sensible defaults; host app can override via its Notify config file.
if (!Configure::read('Notify.push.ttlDefault')) {
    Configure::write('Notify.push.ttlDefault', 3600);
}

if (Configure::read('Notify.sse.pollIntervalSeconds') === null) {
    Configure::write('Notify.sse.pollIntervalSeconds', 3);
}

if (Configure::read('Notify.sse.maxDurationSeconds') === null) {
    Configure::write('Notify.sse.maxDurationSeconds', 300);
}

