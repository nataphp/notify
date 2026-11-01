<?php
/**
 * Notify plugin (NataPHP).
 *
 * @package Notify
 */
namespace Notify\Test\Core;

use Notify\Core\TemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Placeholders, modifiers and the HTML escaping of email/html.html.
 */
final class TemplateRendererTest extends TestCase {
    /**
     * Text templates are never escaped.
     */
    public function testTextIsNotEscaped(): void {
        $renderer = new TemplateRenderer();

        $this->assertSame('Olá <b>Sam</b> & co', $renderer->render('Olá {{ name }} & co', ['name' => '<b>Sam</b>']));
        $this->assertSame('SAM', $renderer->render('{{ user.name|upper }}', ['user' => ['name' => 'sam']]));
        $this->assertSame('friend', $renderer->render("{{ missing|default:'friend' }}", []));
    }

    /**
     * HTML templates escape every value, after its modifiers.
     */
    public function testHtmlEscapesEveryValue(): void {
        $renderer = new TemplateRenderer();

        $this->assertSame('<p>&lt;b&gt;Sam&lt;/b&gt; &amp; &quot;co&quot;</p>', $renderer->render('<p>{{ name }}</p>', ['name' => '<b>Sam</b> & "co"'], true));
        $this->assertSame('&lt;B&gt;', $renderer->render('{{ tag|upper }}', ['tag' => '<b>'], true));
    }

    /**
     * |raw outputs a trusted fragment as is; |escape escapes once, not twice.
     */
    public function testRawAndEscapeInHtml(): void {
        $renderer = new TemplateRenderer();
        $vars = ['list' => '<ul><li>a &amp; b</li></ul>', 'name' => 'a & b'];

        $this->assertSame('<ul><li>a &amp; b</li></ul>', $renderer->render('{{ list|raw }}', $vars, true));
        $this->assertSame('a &amp; b', $renderer->render('{{ name|escape }}', $vars, true));
        // Only a final |raw counts: a modifier after it is escaped again.
        $this->assertSame('&lt;UL&gt;&lt;LI&gt;A &amp;AMP; B&lt;/LI&gt;&lt;/UL&gt;', $renderer->render('{{ list|raw|upper }}', $vars, true));
    }
}
