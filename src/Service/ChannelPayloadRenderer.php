<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Service;

use Notify\Core\TemplateRenderer;

/**
 * Renders channel-specific payload arrays from notification templates.
 *
 * This service is responsible for selecting the correct templates and producing the payload shape
 * expected by each channel implementation (email/push/in_app).
 *
 * @example
 * $payload = (new ChannelPayloadRenderer($loader))->renderEmail('shift.assigned', ['shift' => ['id' => 123]]);
 * // ['subject' => '...', 'html' => '...', 'text' => '...'] or null when templates are missing
 */
final class ChannelPayloadRenderer {
    public function __construct(
        private readonly TemplateLoader $templates,
        private readonly TemplateRenderer $renderer = new TemplateRenderer(),
    ) {}

    /**
     * @param array<string,mixed> $vars
     * @return array<string,mixed>|null
     */
    public function renderInApp(string $definitionKey, array $vars, ?string $dedupeKey, ?string $url = null, ?string $logo = null, ?string $locale = null): ?array {
        $titleTpl = $this->templates->load($definitionKey, 'in_app', 'title', $locale);
        $bodyTpl = $this->templates->load($definitionKey, 'in_app', 'body', $locale);
        if ($titleTpl === null || $bodyTpl === null) {
            return null;
        }
        return [
            'title' => $this->renderer->render($titleTpl, $vars, false),
            'body' => $this->renderer->render($bodyTpl, $vars, false),
            'data' => $vars,
            'dedupe_key' => $dedupeKey,
            'url' => $url,
            'logo' => $logo,
        ];
    }

    /**
     * @param array<string,mixed> $vars
     * @return array<string,mixed>|null
     */
    public function renderEmail(string $definitionKey, array $vars, ?string $locale = null): ?array {
        $subjectTpl = $this->templates->load($definitionKey, 'email', 'subject', $locale);
        $htmlTpl = $this->templates->load($definitionKey, 'email', 'html', $locale);
        $textTpl = $this->templates->load($definitionKey, 'email', 'text', $locale);
        if ($subjectTpl === null || $htmlTpl === null || $textTpl === null) {
            return null;
        }
        return [
            'subject' => $this->renderer->render($subjectTpl, $vars, false),
            'html' => $this->renderer->render($htmlTpl, $vars, true),
            'text' => $this->renderer->render($textTpl, $vars, false),
        ];
    }

    /**
     * @param array<string,mixed> $vars
     * @return array<string,mixed>|null
     */
    public function renderPush(string $definitionKey, array $vars, ?string $url = null, ?string $logo = null, ?string $locale = null, ?string $icon = null, ?string $badge = null): ?array {
        $titleTpl = $this->templates->load($definitionKey, 'push', 'title', $locale);
        $bodyTpl = $this->templates->load($definitionKey, 'push', 'body', $locale);
        if ($titleTpl === null || $bodyTpl === null) {
            return null;
        }
        $urlTpl = $this->templates->load($definitionKey, 'push', 'url', $locale);
        $tagTpl = $this->templates->load($definitionKey, 'push', 'tag', $locale);
        $payload = [
            'title' => $this->renderer->render($titleTpl, $vars, false),
            'body' => $this->renderer->render($bodyTpl, $vars, false),
            'url' => $url ?? ($urlTpl !== null ? $this->renderer->render($urlTpl, $vars, false) : '/'),
            'tag' => $tagTpl !== null ? $this->renderer->render($tagTpl, $vars, false) : $definitionKey,
            'logo' => $logo,
        ];
        if ($icon !== null) {
            $payload['icon'] = $icon;
        }
        if ($badge !== null) {
            $payload['badge'] = $badge;
        }
        return $payload;
    }

    /**
     * SMS payload, from `{event}/sms/{locale}/text.txt` (plain text, no HTML escaping).
     *
     * @param array<string,mixed> $vars
     * @return array{text:string}|null Null when the template is missing.
     */
    public function renderSms(string $definitionKey, array $vars, ?string $locale = null): ?array {
        $textTpl = $this->templates->load($definitionKey, 'sms', 'text', $locale);
        if ($textTpl === null) {
            return null;
        }
        return [
            'text' => trim($this->renderer->render($textTpl, $vars, false)),
        ];
    }

    /**
     * @param array<string,mixed> $vars
     * @return array<string,mixed>|null
     */
    public function renderChannel(string $definitionKey, string $channel, array $vars, ?string $url = null, ?string $logo = null, ?string $locale = null, ?string $icon = null, ?string $badge = null): ?array {
        if ($channel === 'email') {
            return $this->renderEmail($definitionKey, $vars, $locale);
        }
        if ($channel === 'push') {
            return $this->renderPush($definitionKey, $vars, $url, $logo, $locale, $icon, $badge);
        }
        if ($channel === 'sms') {
            return $this->renderSms($definitionKey, $vars, $locale);
        }
        return null;
    }
}
