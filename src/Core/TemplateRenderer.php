<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Core;

use Throwable;

/**
 * Minimal placeholder renderer for notification templates.
 *
 * Supports `{{ path }}` lookups into an input variable array/object graph and optional pipe modifiers:
 * - `{{ user.name }}`
 * - `{{ starts_at|date:'Y-m-d H:i' }}`
 * - `{{ nickname|default:'Friend'|upper }}`
 *
 * This renderer is intentionally forgiving: missing keys or modifier errors yield an empty string
 * rather than throwing, so notification delivery paths do not crash.
 *
 * @example
 * $r = new TemplateRenderer();
 * echo $r->render('Hello {{ user.name|default:\'friend\' }}', ['user' => ['name' => 'Sam']]);
 * // "Hello Sam"
 */
final class TemplateRenderer {
    /**
     * @param ModifierRegistry $modifiers Registry used to apply pipe modifiers.
     */
    public function __construct(
        private readonly ModifierRegistry $modifiers = new ModifierRegistry(),
    ) {}

    /**
     * Render a template string using Notify placeholders.
     *
     * Syntax:
     *  - {{ key }} or {{ parent.child }}
     *  - pipes: {{ key|modifier:'arg'|other }}
     *
     * @param array<string, mixed> $vars
     * @param bool $escapeHtml When true, applies the global "escape" modifier at the end, except to an
     *   expression ending in `|raw` (trusted fragment) or `|escape` (escaped once already).
     * @return string Rendered string (never throws).
     */
    public function render(string $template, array $vars, bool $escapeHtml = false): string {
        if ($template === '' || strpos($template, '{{') === false) {
            return $template;
        }

        return (string) preg_replace_callback('/\{\{\s*(.*?)\s*\}\}/', function (array $m) use ($vars, $escapeHtml): string {
            $expr = (string) ($m[1] ?? '');
            if ($expr === '') {
                return '';
            }

            try {
                return $this->evalExpression($expr, $vars, $escapeHtml);
            } catch (Throwable) {
                // Never crash rendering in prod paths; treat as empty.
                return '';
            }
        }, $template);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function evalExpression(string $expr, array $vars, bool $escapeHtml): string {
        $parts = array_map('trim', explode('|', $expr));
        $key = array_shift($parts);
        $value = $this->resolveValue((string) $key, $vars);

        $last = '';
        foreach ($parts as $pipe) {
            if ($pipe === '') {
                continue;
            }
            [$name, $args] = $this->parseModifier($pipe);
            $value = $this->modifiers->apply($name, $value, $args);
            $last = strtolower($name);
        }

        // HTML templates escape every value, unless the expression ends in |raw
        // (a trusted fragment, output as is) or in |escape (already escaped once).
        if ($escapeHtml && $last !== 'raw' && $last !== 'escape') {
            $value = $this->modifiers->apply('escape', $value);
        }

        return $this->stringify($value);
    }

    /**
     * @return array{0:string,1:array<string>}
     */
    private function parseModifier(string $pipe): array {
        $pipe = trim($pipe);

        // name:'arg1':'arg2'
        $name = $pipe;
        $args = [];
        if (strpos($pipe, ':') !== false) {
            $segments = explode(':', $pipe);
            $name = trim(array_shift($segments) ?? '');
            foreach ($segments as $seg) {
                $seg = trim($seg);
                $args[] = $this->unquote($seg);
            }
        }

        return [$name, $args];
    }

    private function unquote(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $first = $value[0] ?? '';
        $last = $value[strlen($value) - 1] ?? '';
        if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
            return stripcslashes(substr($value, 1, -1));
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function resolveValue(string $path, array $vars): mixed {
        $path = trim($path);
        if ($path === '') {
            return null;
        }
        $segments = explode('.', $path);
        $cur = $vars;
        foreach ($segments as $seg) {
            $seg = trim($seg);
            if ($seg === '') {
                return null;
            }

            if (is_array($cur) && array_key_exists($seg, $cur)) {
                $cur = $cur[$seg];
                continue;
            }
            if (is_object($cur)) {
                if (isset($cur->{$seg})) {
                    $cur = $cur->{$seg};
                    continue;
                }
                $getter = 'get' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $seg)));
                if (method_exists($cur, $getter)) {
                    $cur = $cur->{$getter}();
                    continue;
                }
            }
            return null;
        }
        return $cur;
    }

    private function stringify(mixed $value): string {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }
        return '';
    }
}

