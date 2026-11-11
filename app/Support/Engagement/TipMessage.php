<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * A tip's message as safe HTML: paragraphs, line breaks, bold and links only.
 *
 * The admin editor already offers only bold and link, but what reaches every
 * vendor's screen is filtered again here: anything else is reduced to its
 * text, attributes other than a link's href are dropped, and a link must be
 * http(s), mailto, tel or a site-relative path.
 */
final class TipMessage
{
    private const KEEP = ['p', 'br', 'strong', 'b', 'em', 'i', 'a'];

    public static function html(?string $message): string
    {
        $message = trim((string) $message);

        if ($message === '') {
            return '';
        }

        // Plain text from a seeder or an old row: escape it, keep line breaks.
        if ($message === strip_tags($message)) {
            return nl2br(e($message), false);
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div>'.$message.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementsByTagName('div')->item(0);

        return $root ? self::children($root) : '';
    }

    private static function children(DOMNode $node): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            $out .= self::node($child);
        }

        return $out;
    }

    private static function node(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return e($node->textContent);
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);
        $inner = self::children($node);

        if (! in_array($tag, self::KEEP, true)) {
            return $inner;
        }

        return match ($tag) {
            'br' => '<br>',
            'b' => "<strong>{$inner}</strong>",
            'i' => "<em>{$inner}</em>",
            'a' => self::link($node, $inner),
            default => "<{$tag}>{$inner}</{$tag}>",
        };
    }

    private static function link(DOMElement $node, string $inner): string
    {
        $href = trim($node->getAttribute('href'));

        $safe = preg_match('#^(https?://|mailto:|tel:)#i', $href) === 1
            || (str_starts_with($href, '/') && ! str_starts_with($href, '//'));

        if (! $safe) {
            return $inner;
        }

        return '<a href="'.e($href).'" rel="noopener">'.$inner.'</a>';
    }
}
