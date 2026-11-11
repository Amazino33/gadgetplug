<?php

declare(strict_types=1);

namespace App\Support\Assistant;

use Illuminate\Support\HtmlString;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * An assistant answer as safe HTML: Markdown in, a short allowlist of tags out.
 *
 * Two layers, so neither has to be perfect:
 *  1. CommonMark escapes any HTML in the text (html_input: escape) and drops
 *     unsafe link schemes. A product called <img src=x onerror=...> arrives in
 *     an answer as literal text, never as an element.
 *  2. Symfony's HtmlSanitizer then keeps only what an answer needs: paragraphs,
 *     bold, italics, lists, line breaks, inline code, simple tables, and links.
 *     It is configured here, strictly; not Filament's Str::sanitizeHtml(),
 *     whose allowlist is built for its rich editor. Anything else is dropped
 *     (script, style, iframe, img, ...) or reduced to its text (headings,
 *     quotes, code blocks).
 *
 * Links work only within this panel (relative, or this app's own host). A link
 * to anywhere else loses its href and shows as plain text.
 *
 * What it cannot do: tell a product name the model repeated from the model's
 * own Markdown. Markdown inside a name ("**bold**") may render as bold; it can
 * never become a script, an image or an off-site link. Answer cards (Phase 3)
 * show record names straight from tool data, as plain text.
 */
final class AnswerMarkdown
{
    private const MAX_INPUT = 20000;

    public static function toHtml(?string $markdown): HtmlString
    {
        $markdown = trim((string) $markdown);

        if ($markdown === '') {
            return new HtmlString('');
        }

        $html = (new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 10,
        ]))->convert(mb_substr($markdown, 0, self::MAX_INPUT))->getContent();

        return new HtmlString(self::sanitizer()->sanitize($html));
    }

    public static function sanitizer(): HtmlSanitizer
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        $config = (new HtmlSanitizerConfig)
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('em')
            ->allowElement('ul')
            ->allowElement('ol', ['start'])
            ->allowElement('li')
            ->allowElement('code')
            ->allowElement('table')
            ->allowElement('thead')
            ->allowElement('tbody')
            ->allowElement('tr')
            ->allowElement('th')
            ->allowElement('td')
            ->allowElement('a', ['href'])
            // Kept as text: an answer has no headings or quotes of its own.
            ->blockElement('h1')->blockElement('h2')->blockElement('h3')
            ->blockElement('h4')->blockElement('h5')->blockElement('h6')
            ->blockElement('blockquote')->blockElement('pre')->blockElement('del')
            ->allowLinkSchemes(['https', 'http'])
            ->allowLinkHosts($host ? [$host] : [])
            ->allowRelativeLinks()
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->withMaxInputLength(self::MAX_INPUT * 4);

        return new HtmlSanitizer($config);
    }
}
