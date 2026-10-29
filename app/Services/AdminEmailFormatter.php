<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;

class AdminEmailFormatter
{
    private const ALLOWED_ELEMENTS = [
        'a',
        'blockquote',
        'br',
        'div',
        'em',
        'h1',
        'h2',
        'h3',
        'i',
        'li',
        'ol',
        'p',
        's',
        'strong',
        'u',
        'ul',
    ];

    private const REMOVED_ELEMENTS = [
        'iframe',
        'math',
        'noscript',
        'object',
        'script',
        'style',
        'svg',
        'template',
    ];

    public function sanitize(string $html): string
    {
        if (! class_exists(DOMDocument::class)) {
            throw new RuntimeException('The HTML parser extension is required to format outgoing mail safely.');
        }

        $marker = 'mail-'.bin2hex(random_bytes(12));
        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);

        try {
            $document->loadHTML(
                '<!doctype html><html><body><div id="'.$marker.'">'.$html.'</div></body></html>',
                LIBXML_HTML_NODEFDTD,
            );
            $root = (new \DOMXPath($document))->query('//*[@id="'.$marker.'"]')?->item(0);
            if (! $root instanceof DOMElement) {
                throw new RuntimeException('The outgoing email body could not be parsed.');
            }

            $this->sanitizeChildren($root);

            $output = '';
            foreach ($root->childNodes as $child) {
                $output .= $document->saveHTML($child);
            }

            return $output;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                if (! $child instanceof \DOMText) {
                    $parent->removeChild($child);
                }

                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::REMOVED_ELEMENTS, true)) {
                $parent->removeChild($child);

                continue;
            }

            $this->sanitizeChildren($child);
            if (! in_array($tag, self::ALLOWED_ELEMENTS, true)) {
                while ($child->firstChild !== null) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);

                continue;
            }

            $this->sanitizeAttributes($child, $tag);
        }
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $href = $tag === 'a' && $element->hasAttribute('href')
            ? $this->safeLink($element->getAttribute('href'))
            : null;
        $title = $tag === 'a' && $element->hasAttribute('title')
            ? mb_substr($element->getAttribute('title'), 0, 255)
            : null;

        while ($element->attributes->length > 0) {
            $element->removeAttributeNode($element->attributes->item(0));
        }

        if ($href !== null) {
            $element->setAttribute('href', $href);
            $element->setAttribute('rel', 'noopener noreferrer');
        }
        if ($title !== null) {
            $element->setAttribute('title', $title);
        }
    }

    private function safeLink(string $url): ?string
    {
        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return $url;
        }

        if ($scheme === 'mailto' && filter_var(substr($url, 7), FILTER_VALIDATE_EMAIL) !== false) {
            return $url;
        }

        return null;
    }
}
