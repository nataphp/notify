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
use DateTimeInterface;

/**
 * Registry for placeholder modifiers used by `TemplateRenderer`.
 *
 * Modifiers are applied using pipe syntax in templates:
 * - `{{ user.name|upper }}`
 * - `{{ starts_at|date:'Y-m-d H:i' }}`
 * - `{{ maybe|default:'(none)' }}`
 *
 * @example
 * $mods = new ModifierRegistry();
 * $value = $mods->apply('upper', 'hello'); // "HELLO"
 */
final class ModifierRegistry {
    /** @var array<string, callable(mixed, array<string>): mixed> */
    private array $modifiers = [];

    /**
     * Initialize the registry with default modifiers.
     */
    public function __construct() {
        $this->registerDefaults();
    }

    /**
     * Register a modifier function.
     *
     * The function receives the current value and an optional list of string arguments.
     *
     * @param string $name Modifier name as used in templates.
     * @param callable $fn Signature: `fn(mixed $value, array<string> $args): mixed`
     */
    public function register(string $name, callable $fn): void {
        $this->modifiers[strtolower($name)] = $fn;
    }

    /**
     * Check whether a modifier exists.
     */
    public function has(string $name): bool {
        return array_key_exists(strtolower($name), $this->modifiers);
    }

    /**
     * @param array<string> $args
     *
     * @return mixed The modified value (or the original value if the modifier is unknown).
     */
    public function apply(string $name, mixed $value, array $args = []): mixed {
        $key = strtolower($name);
        if (!$this->has($key)) {
            return $value;
        }
        return ($this->modifiers[$key])($value, $args);
    }

    /**
     * Register built-in modifiers used by notification templates.
     */
    private function registerDefaults(): void {
        $this->register('default', function (mixed $value, array $args): mixed {
            $fallback = $args[0] ?? '';
            if ($value === null) {
                return $fallback;
            }
            if (is_string($value) && trim($value) === '') {
                return $fallback;
            }
            if (is_array($value) && $value === []) {
                return $fallback;
            }
            return $value;
        });

        $this->register('upper', function (mixed $value): mixed {
            return is_string($value) ? mb_strtoupper($value) : $value;
        });

        $this->register('lower', function (mixed $value): mixed {
            return is_string($value) ? mb_strtolower($value) : $value;
        });

        $this->register('date', function (mixed $value, array $args): mixed {
            $format = $args[0] ?? 'Y-m-d';
            $dt = null;
            if ($value instanceof DateTimeInterface) {
                $dt = $value;
            } elseif (is_string($value) && trim($value) !== '') {
                $dt = new DateTimeImmutable($value);
            }
            return $dt ? $dt->format($format) : '';
        });

        // HTML escape for email html templates. The renderer will only apply this
        // if the caller enables it (template-type aware).
        $this->register('escape', function (mixed $value): string {
            $str = is_scalar($value) ? (string) $value : (is_object($value) && method_exists($value, '__toString') ? (string) $value : '');
            return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        });

        // No-op marker for trusted fragments (use sparingly).
        $this->register('raw', function (mixed $value): mixed {
            return $value;
        });
    }
}

