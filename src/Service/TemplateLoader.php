<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Service;

/**
 * Loads notification templates from disk.
 *
 * This service converts a logical template identifier (definition + channel + name) into a raw
 * template string by consulting `TemplateLocator` and reading the file content.
 *
 * @example
 * $loader = new TemplateLoader(TemplateLocator::default());
 * $tpl = $loader->load('shift.assigned', 'email', 'subject'); // "Shift assigned: {{ shift.id }}"
 */
final class TemplateLoader {
    public function __construct(
        private readonly TemplateLocator $locator
    ) {}

    /**
     * Load a template file and return its content (trimmed), or null when missing/unreadable.
     *
     * @param string $definitionKey Notification definition key (e.g. "shift.assigned").
     * @param string $channel Channel name (e.g. "email", "push", "in_app").
     * @param string $name Template name (e.g. "subject", "html", "text", "title", "body").
     * @param string|null $locale Optional locale for locale-specific template resolution.
     */
    public function load(string $definitionKey, string $channel, string $name, ?string $locale = null): ?string {
        $path = $this->locator->find($definitionKey, $channel, $name, $locale);
        if ($path === null) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        return rtrim($raw);
    }
}
