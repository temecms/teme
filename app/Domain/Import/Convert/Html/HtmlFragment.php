<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Html;

use App\Domain\Import\Convert\ConversionReport;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use LogicException;

/**
 * Reads a piece of WordPress's HTML with PHP's own HTML5 parser (I-1722), so
 * broken or unclosed markup is repaired as a browser repairs it before
 * anything else reads it. Text outside preformatted elements has its runs
 * of spaces and line breaks collapsed, as a browser shows them.
 */
final class HtmlFragment
{
    /** Elements an article never keeps: left out, and counted by name. */
    public const array REMOVED = [
        'script', 'style', 'template', 'iframe', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select',
        'textarea', 'svg', 'canvas', 'video', 'audio', 'map', 'frame', 'frameset', 'dialog',
    ];

    /** Left out without a word: fallbacks and metadata. A lazy-loading plugin's noscript repeats its image. */
    public const array SILENT = ['noscript', 'link', 'meta', 'base', 'title'];

    /** Elements whose text keeps its spaces and line breaks. */
    private const array PREFORMATTED = ['pre', 'textarea', 'listing', 'plaintext', 'xmp'];

    /**
     * The body of a new document holding this HTML, its whitespace collapsed.
     */
    public static function body(string $html): Element
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html.'</body></html>', LIBXML_NOERROR, 'UTF-8');
        $body = $document->body;

        if ($body === null) {
            throw new LogicException('An HTML5 document always has a body.');
        }

        self::collapse($body);

        return $body;
    }

    /**
     * The words inside a node, its whitespace collapsed and trimmed.
     */
    public static function text(Node $node): string
    {
        return self::words((string) $node->textContent);
    }

    /**
     * The text with each run of spaces, tabs and line breaks one space, and
     * none at its ends. No-break spaces are kept.
     */
    public static function words(string $text): string
    {
        return trim((string) preg_replace('/[ \t\n\f\r]+/', ' ', $text), " \t\n\f\r");
    }

    /**
     * Takes out every element an article never keeps, counting each by name;
     * an element inside another taken out isn't counted again.
     */
    public static function prune(Element $root, ConversionReport $report): void
    {
        $selector = implode(', ', [...self::REMOVED, ...self::SILENT]);

        foreach (iterator_to_array($root->querySelectorAll($selector)) as $element) {
            if ($element->parentElement?->closest($selector) !== null) {
                continue;
            }

            if (in_array($element->localName, self::REMOVED, true)) {
                $report->count('html.removed', $element->localName);
            }

            $element->remove();
        }

        foreach (iterator_to_array($root->querySelectorAll('*')) as $element) {
            foreach ($element->getAttributeNames() as $name) {
                if (str_starts_with(strtolower($name), 'on')) {
                    $report->count('html.event-attributes');

                    break;
                }
            }
        }
    }

    private static function collapse(Node $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof Text) {
                $child->data = (string) preg_replace('/[ \t\n\f\r]+/', ' ', $child->data);
            } elseif ($child instanceof Element && ! in_array($child->localName, self::PREFORMATTED, true)) {
                self::collapse($child);
            }
        }
    }
}
