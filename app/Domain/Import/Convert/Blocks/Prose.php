<?php

/**
 * Core's text blocks (I-335): paragraphs, headings, verse, quotes and
 * pullquotes, code and preformatted text, and separators, each read from
 * its own markup.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert\Blocks;

use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\BlockMapping;
use App\Domain\Import\Convert\Html\Nodes;
use App\Domain\Import\Convert\ParsedBlock;
use Dom\Element;
use Dom\Node;
use Dom\Text;

final readonly class Prose implements BlockMapping
{
    public function blocks(): array
    {
        return ['core/paragraph', 'core/heading', 'core/verse', 'core/quote', 'core/pullquote', 'core/code', 'core/preformatted', 'core/separator'];
    }

    public function convert(ParsedBlock $block, BlockContext $context): array
    {
        return match ($block->name) {
            'core/paragraph' => $this->textblock($block, $context, 'p'),
            'core/heading' => $this->textblock($block, $context, 'h1, h2, h3, h4, h5, h6'),
            'core/verse' => $this->verse($block, $context),
            'core/quote' => $this->quote($block, $context),
            'core/separator' => [Nodes::rule()],
            default => $context->html($block->innerHtml),
        };
    }

    /**
     * A paragraph, or a heading at its block's level, with its alignment.
     *
     * @return list<array<string, mixed>>
     */
    private function textblock(ParsedBlock $block, BlockContext $context, string $selector): array
    {
        $body = $context->fragment($block->innerHtml);
        $element = $body->querySelector($selector);

        if ($element === null) {
            return $context->html($block->innerHtml);
        }

        if ($block->name !== 'core/heading') {
            // A block start tag inside the paragraph's <p> closes it, so the parser leaves the rest beside it (I-1722).
            return self::alone($body, $element)
                ? $context->textblock('paragraph', $element, Nodes::alignment($element))
                : $context->read($body);
        }

        $level = $block->intAttr('level') ?? (int) substr($element->localName, 1);

        return $context->textblock('heading', $element, Nodes::headingAttrs($level, $context->newId(), $element));
    }

    /**
     * Whether the element is all the parsed piece holds, but for spaces.
     */
    private static function alone(Element $body, Element $element): bool
    {
        foreach ($body->childNodes as $child) {
            if (! $child->isSameNode($element) && ! ($child instanceof Text && trim($child->data) === '')) {
                return false;
            }
        }

        return true;
    }

    /**
     * A verse as one paragraph: each line break a hard break, its marks kept.
     *
     * @return list<array<string, mixed>>
     */
    private function verse(ParsedBlock $block, BlockContext $context): array
    {
        $pre = $context->element($block->innerHtml, 'pre');

        if ($pre === null) {
            return $context->html($block->innerHtml);
        }

        self::breakLines($pre);

        return $context->textblock('paragraph', $pre);
    }

    /**
     * A quote: the 6.0-and-later format's inner blocks, then its citation as
     * a last paragraph inside it; the older format from its HTML.
     *
     * @return list<array<string, mixed>>
     */
    private function quote(ParsedBlock $block, BlockContext $context): array
    {
        if ($block->innerBlocks === []) {
            return $context->html($block->innerHtml);
        }

        $cite = $context->element($block->ownHtml(), 'cite');

        return Nodes::blockquote([...$context->children($block), ...($cite === null ? [] : $context->textblock('paragraph', $cite))]);
    }

    /**
     * Each line break in a preformatted element's text becomes a `<br>`, and
     * other runs of spaces one space.
     */
    private static function breakLines(Node $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof Element) {
                self::breakLines($child);

                continue;
            }

            $document = $child->ownerDocument;

            if (! $child instanceof Text || $document === null) {
                continue;
            }

            $parts = [];

            foreach (explode("\n", (string) preg_replace(['/\r\n?/', '/[ \t\f]+/'], ["\n", ' '], $child->data)) as $index => $line) {
                if ($index > 0) {
                    $parts[] = $document->createElement('br');
                }

                if ($line !== '') {
                    $parts[] = $line;
                }
            }

            $child->replaceWith(...$parts);
        }
    }
}
