<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Cleans announcement HTML on the server. Only a small set of formatting tags
 * is kept (bold, italic, underline, lists, headings, quotes, links). Every
 * attribute is dropped except a safe href (http, https, mailto) on links.
 * Scripts, styles, iframes, event handlers and comments are removed entirely.
 */
final class HtmlSanitizer
{
    private const ALLOWED = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'a', 'h3', 'h4', 'blockquote', 'div', 'span'];

    /** Removed together with everything inside them. */
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'svg', 'math', 'head', 'title', 'meta', 'link', 'form', 'input', 'button', 'textarea', 'select', 'noscript'];

    /** Tags normalised to a simpler equivalent. */
    private const RENAME = ['b' => 'strong', 'i' => 'em', 'div' => 'p'];

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $out = '';
        foreach (self::bodyChildren($document) as $child) {
            $out .= self::render($child);
        }

        $out = preg_replace('#(<p>(\s|&nbsp;|<br>)*</p>)+$#u', '', $out) ?? $out;

        return trim($out);
    }

    /** Plain text version (for search, the outbox payload and CSV). */
    public static function text(string $cleanHtml): string
    {
        $withBreaks = preg_replace('#<(br|/p|/li|/h3|/h4|/blockquote)>#i', "$0\n", $cleanHtml) ?? $cleanHtml;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n\s*\n+/", "\n", $text) ?? $text;

        return trim($text);
    }

    /** @return list<DOMNode> */
    private static function bodyChildren(DOMDocument $document): array
    {
        $body = $document->getElementsByTagName('body')->item(0);
        $root = $body ?? $document;

        return iterator_to_array($root->childNodes, false);
    }

    private static function render(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return htmlspecialchars((string) $node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
        }
        if (!$node instanceof DOMElement) {
            return '';
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, self::DROP, true)) {
            return '';
        }

        $inner = '';
        foreach (iterator_to_array($node->childNodes, false) as $child) {
            $inner .= self::render($child);
        }

        if (!in_array($tag, self::ALLOWED, true) || $tag === 'span') {
            return $inner;
        }
        $tag = self::RENAME[$tag] ?? $tag;
        if ($tag === 'br') {
            return '<br>';
        }
        if ($tag === 'a') {
            $href = self::safeHref($node->getAttribute('href'));
            if ($href === null) {
                return $inner;
            }

            return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . $inner . '</a>';
        }

        return "<{$tag}>{$inner}</{$tag}>";
    }

    private static function safeHref(string $href): ?string
    {
        $href = trim(preg_replace('/[\x00-\x20]+/', '', $href) ?? '');
        if ($href === '' || mb_strlen($href) > 500) {
            return null;
        }

        return preg_match('#^(https?://|mailto:)#i', $href) ? $href : null;
    }
}