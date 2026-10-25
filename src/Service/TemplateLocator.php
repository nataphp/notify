<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Service;

use Nata\Core\Plugin;

/**
 * Resolves notification template file paths.
 *
 * Templates are stored by definition key (dot-separated) and channel, e.g.:
 * - `shift.assigned` + `email` + `subject` -> `shift/assigned/email/subject.txt`
 *
 * Locale-specific templates take precedence when available:
 * - `shift/assigned/email/pt/html.html` is used before `shift/assigned/email/html.html`
 *
 * The locator searches multiple roots in order (app override first, then plugin fallback).
 *
 * @example
 * $locator = TemplateLocator::default();
 * $path = $locator->find('shift.assigned', 'push', 'body', 'pt');
 */
final class TemplateLocator {
    /**
     * @param array<int,string> $roots Absolute directory roots to search, in order.
     */
    public function __construct(
        private readonly array $roots
    ) {}

    /**
     * Create a locator configured to search the host application templates first,
     * then fall back to this plugin templates.
     */
    public static function default(): self {
        $appRoot = ROOT . 'templates' . DS . 'notifications' . DS;
        return new self([$appRoot]);
    }

    /**
     * Resolve a notification content template file.
     *
     * For each root, tries the locale-specific path first (e.g. `email/pt/html.html`),
     * then falls back to the default path (e.g. `email/html.html`).
     *
     * @param string $definitionKey e.g. 'shift.assigned'
     * @param string $channel e.g. 'email'|'push'|'in_app'
     * @param string $name e.g. 'subject'|'html'|'text'|'title'|'body'|'url'|'tag'
     * @param string|null $locale Optional locale code (e.g. 'pt', 'en').
     * @return string|null Absolute path to the template file, or null if missing.
     */
    public function find(string $definitionKey, string $channel, string $name, ?string $locale = null): ?string {
        $localePath = ($locale !== null && trim($locale) !== '')
            ? $this->relativePath($definitionKey, $channel, $name, $locale)
            : null;
        $defaultPath = $this->relativePath($definitionKey, $channel, $name);

        foreach ($this->roots as $root) {
            if ($localePath !== null && is_file($root . $localePath)) {
                return $root . $localePath;
            }
            if (is_file($root . $defaultPath)) {
                return $root . $defaultPath;
            }
        }
        return null;
    }

    /**
     * Compute the on-disk relative path for a logical template identifier.
     *
     * @return string e.g. 'shift/assigned/email/html.html' or 'shift/assigned/email/pt/html.html'
     */
    private function relativePath(string $definitionKey, string $channel, string $name, ?string $locale = null): string {
        $parts = explode('.', $definitionKey);
        $parts = array_map(static fn($p) => trim((string) $p), $parts);
        $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));

        $ext = ($channel === 'email' && $name === 'html') ? 'html' : 'txt';
        $localePart = ($locale !== null) ? $locale . DS : '';

        return implode(DS, $parts) . DS . $channel . DS . $localePart . $name . '.' . $ext;
    }
}
