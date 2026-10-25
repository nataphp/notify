<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Service;

use Nata\Core\Configure;
use Notify\Core\NotificationDefinition;
use RuntimeException;

/**
 * Loads notification definitions from configuration.
 *
 * `Configure::read('Notify.definitions')` is the only source. The host app
 * populates it from its Notify config file, which the plugin bootstrap loads
 * (see the `config` option on `Plugin::load('Notify', …)`).
 *
 * An empty or missing registry is treated as a configuration error rather than
 * an empty set: silently returning nothing would make every send fail with
 * `unknown_definition`, which is far harder to diagnose than failing here.
 *
 * @example
 * $registry = new DefinitionRegistry();
 * $def = $registry->get('shift.assigned');
 * if ($def !== null) {
 *     $channels = $def->defaultChannels();
 * }
 */
final class DefinitionRegistry {
    /**
     * Return all configured definition metadata keyed by definition key.
     *
     * @return array<string,array<string,mixed>>
     * @throws RuntimeException When `Notify.definitions` is missing, not an array, or empty.
     */
    public function all(): array {
        $definitions = Configure::read('Notify.definitions');

        if (!is_array($definitions) || $definitions === []) {
            throw new RuntimeException(
                'Notify.definitions is empty or unset. The host application must define its '
                . 'notification registry under the Notify.definitions Configure key — normally '
                . 'via the config file passed to Plugin::load(\'Notify\', [\'config\' => …]).'
            );
        }

        return $definitions;
    }

    /**
     * Resolve a single definition by key.
     *
     * @return NotificationDefinition|null Null when the key is unknown.
     */
    public function get(string $key): ?NotificationDefinition {
        $defs = $this->all();
        if (!array_key_exists($key, $defs)) {
            return null;
        }
        $meta = is_array($defs[$key]) ? $defs[$key] : [];
        return new NotificationDefinition($key, $meta);
    }
}

