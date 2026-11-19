<?php

/**
 * Cleans the HTML WordPress drew into the tree an Imported content card
 * holds (I-329): parsed by HTML5's rules with PHP's own parser, kept to
 * MarkupTree's list, and each removal a reader could notice counted by name
 * for the import's report.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Imported;

use App\Domain\Cards\DocumentSchema;
use Closure;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

final readonly class MarkupCleaner
{
    /** Removed with all they hold, and counted: a reader could notice each. */
    public const array REMOVED = ['script', 'style', 'noscript', 'template', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math', 'canvas', 'audio', 'video', 'map', 'dialog', 'hr'];

    /** Removed with all they hold, uncounted: they draw nothing a reader sees. */
    public const array SILENT = ['head', 'title', 'meta', 'link', 'base', 'source', 'track', 'param', 'col', 'colgroup', 'wbr'];

    /** Renamed to the listed element that means the same. */
    public const array RENAMED = ['b' => 'strong', 'i' => 'em', 'tt' => 'code', 'kbd' => 'code', 'samp' => 'code', 'h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4'];

    /** Blocks off the list: unwrapped, their edges kept as a paragraph's. */
    public const array WRAPPERS = ['div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'figure', 'figcaption', 'address', 'center', 'details', 'summary', 'fieldset', 'legend', 'hgroup', 'search'];

    /** The element a parent holding only certain elements wraps stray parts in. */
    private const array FILLS = ['ul' => 'li', 'ol' => 'li', 'dl' => 'dd', 'table' => 'tbody', 'thead' => 'tr', 'tbody' => 'tr', 'tfoot' => 'tr', 'tr' => 'td'];

    /** Elements kept even when empty, so a list or a table keeps its shape. */
    private const array KEPT_EMPTY = ['li', 'dt', 'dd', 'th', 'td'];

    /**
     * The tree for this HTML, and what was removed, by name.
     *
     * @param  (Closure(string): ?string)|null  $href  what Teme stores for a link's address, or null when it can't keep it; the address itself when not given
     * @param  (Closure(string, string): ?string)|null  $picture  the media ID for an image's `src` and `class`, or null when the library hasn't it; no image is kept when not given
     */
    public function clean(string $html, ?Closure $href = null, ?Closure $picture = null): CleanedMarkup
    {
        // PHP 8.4's own HTML5 parser, no library (I-1722).
        $document = HTMLDocument::createFromString('<!DOCTYPE html><body>'.$html, LIBXML_NOERROR, 'UTF-8');
        $removed = [];
        $parts = $document->body === null ? [] : $this->fit($this->children($document->body, false, $href, $picture, $removed), '');

        ksort($removed);

        return new CleanedMarkup($parts, $removed);
    }

    /**
     * @param  (Closure(string): ?string)|null  $href
     * @param  (Closure(string, string): ?string)|null  $picture
     * @param  array<string, int>  $removed
     *
     * @return list<array<string, mixed>>
     */
    private function children(Node $node, bool $inPre, ?Closure $href, ?Closure $picture, array &$removed): array
    {
        $parts = [];

        foreach ($node->childNodes as $child) {
            array_push($parts, ...$this->node($child, $inPre, $href, $picture, $removed));
        }

        return $parts;
    }

    /**
     * One node as parts: none, its own, or what it holds; a block off the
     * list as a group, which fit() turns into a paragraph or splices.
     *
     * @param  (Closure(string): ?string)|null  $href
     * @param  (Closure(string, string): ?string)|null  $picture
     * @param  array<string, int>  $removed
     *
     * @return list<array<string, mixed>>
     */
    private function node(Node $node, bool $inPre, ?Closure $href, ?Closure $picture, array &$removed): array
    {
        if ($node instanceof Text) {
            $text = $inPre ? $node->data : (string) preg_replace('/[ \t\n\r\f]+/', ' ', $node->data);

            return $text === '' ? [] : [['text' => $text]];
        }

        if (! $node instanceof Element) {
            return [];
        }

        $name = $node->localName;

        if (in_array($name, self::REMOVED, true)) {
            $removed[$name] = ($removed[$name] ?? 0) + 1;

            return [];
        }

        if (in_array($name, self::SILENT, true)) {
            return [];
        }

        $this->countAttributes($node, $removed);
        $name = self::RENAMED[$name] ?? $name;

        if ($name === 'br') {
            return [['element' => 'br']];
        }

        if ($name === 'img') {
            return $this->picture($node, $picture, $removed);
        }

        if ($name === 'a') {
            return $this->link($node, $inPre, $href, $picture, $removed);
        }

        if (array_key_exists($name, MarkupTree::CONTENT)) {
            return $this->element($name, $node, $inPre, $href, $picture, $removed);
        }

        $children = $this->children($node, $inPre, $href, $picture, $removed);

        return in_array($name, self::WRAPPERS, true) ? [['group' => $children]] : $children;
    }

    /**
     * @param  (Closure(string): ?string)|null  $href
     * @param  (Closure(string, string): ?string)|null  $picture
     * @param  array<string, int>  $removed
     *
     * @return list<array<string, mixed>>
     */
    private function element(string $name, Element $node, bool $inPre, ?Closure $href, ?Closure $picture, array &$removed): array
    {
        $children = $this->fit($this->children($node, $inPre || $name === 'pre', $href, $picture, $removed), $name);
        $isBlock = in_array($name, MarkupTree::BLOCKS, true) || $name === 'caption';

        if (! in_array($name, self::KEPT_EMPTY, true) && ($children === [] || ($isBlock && $this->blank($children)))) {
            return [];
        }

        $attrs = [];

        foreach (MarkupTree::ATTRIBUTES[$name] ?? [] as $attribute) {
            $value = trim((string) $node->getAttribute($attribute));
            $value = match ($attribute) {
                'start' => ctype_digit($value) ? (int) $value : null,
                'colspan', 'rowspan' => ctype_digit($value) ? min((int) $value, DocumentSchema::CELL_SPANS[$attribute]) : null,
                'scope' => strtolower($value),
                default => $value,
            };

            if (MarkupTree::accepts($attribute, $value)) {
                $attrs[$attribute] = $value;
            }
        }

        return [['element' => $name, ...($attrs === [] ? [] : ['attrs' => $attrs]), ...($children === [] ? [] : ['children' => $children])]];
    }

    /**
     * @param  (Closure(string): ?string)|null  $href
     * @param  (Closure(string, string): ?string)|null  $picture
     * @param  array<string, int>  $removed
     *
     * @return list<array<string, mixed>>
     */
    private function link(Element $node, bool $inPre, ?Closure $href, ?Closure $picture, array &$removed): array
    {
        $children = $this->fit($this->children($node, $inPre, $href, $picture, $removed), 'a');
        $address = trim((string) $node->getAttribute('href'));

        if ($address === '') {
            return $children;
        }

        $kept = $href === null ? $address : $href($address);

        if (! MarkupTree::accepts('href', $kept)) {
            $removed['link'] = ($removed['link'] ?? 0) + 1;

            return $children;
        }

        return $children === [] ? [] : [['element' => 'a', 'attrs' => ['href' => $kept], 'children' => $children]];
    }

    /**
     * @param  (Closure(string, string): ?string)|null  $picture
     * @param  array<string, int>  $removed
     *
     * @return list<array<string, mixed>>
     */
    private function picture(Element $node, ?Closure $picture, array &$removed): array
    {
        $id = $picture === null ? null : $picture(trim((string) $node->getAttribute('src')), (string) $node->getAttribute('class'));

        if (! MarkupTree::accepts('mediaId', $id)) {
            $removed['image'] = ($removed['image'] ?? 0) + 1;

            return [];
        }

        $alt = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $node->getAttribute('alt'))), 0, 1000);

        return [['element' => 'img', 'attrs' => $alt === '' ? ['mediaId' => $id] : ['mediaId' => $id, 'alt' => $alt]]];
    }

    /**
     * @param  array<string, int>  $removed
     */
    private function countAttributes(Element $node, array &$removed): void
    {
        foreach ($node->attributes as $attribute) {
            $key = match (true) {
                $attribute->localName === 'style' => 'style-attribute',
                str_starts_with($attribute->localName, 'on') => 'event-attribute',
                default => null,
            };

            if ($key !== null) {
                $removed[$key] = ($removed[$key] ?? 0) + 1;
            }
        }
    }

    /**
     * Parts made to fit what their parent may hold (`''` is the card's top
     * level): a group, or an element out of its place, becomes a paragraph
     * where one fits and is spliced where it doesn't; and at the top, in a
     * quote, or beside a block, loose inline parts are gathered into
     * paragraphs.
     *
     * @param  list<array<string, mixed>>  $parts
     *
     * @return list<array<string, mixed>>
     */
    private function fit(array $parts, string $parent): array
    {
        $holds = $parent === '' ? 'flow' : (MarkupTree::CONTENT[$parent] ?? 'flow');

        if (is_array($holds)) {
            return $this->fitList($parts, $holds, self::FILLS[$parent] ?? 'li');
        }

        $fitted = [];

        foreach ($parts as $part) {
            $element = $part['element'] ?? null;

            if (isset($part['text']) || (in_array($element, MarkupTree::INLINE, true) && ! ($element === 'a' && $parent === 'a'))) {
                $fitted[] = $part;
            } elseif ($holds === 'flow' && in_array($element, MarkupTree::BLOCKS, true)) {
                $fitted[] = $part;
            } else {
                $inner = $this->fit($part['group'] ?? $part['children'] ?? [], $parent === '' ? 'blockquote' : $parent);

                if ($holds === 'flow' && $this->allInline($inner)) {
                    if (! $this->blank($inner)) {
                        $fitted[] = ['element' => 'p', 'children' => $inner];
                    }
                } else {
                    if ($fitted !== [] && $inner !== [] && $holds !== 'flow') {
                        $fitted[] = ['text' => ' '];
                    }

                    array_push($fitted, ...$inner);
                }
            }
        }

        if ($holds === 'flow' && ($parent === '' || $parent === 'blockquote' || ! $this->allInline($fitted))) {
            return $this->paragraphs($fitted);
        }

        return $this->joinTexts($fitted);
    }

    /**
     * Parts made to fit a parent that holds only certain elements: a group
     * spliced in, and every run of anything else wrapped in the element the
     * parent wants.
     *
     * @param  list<array<string, mixed>>  $parts
     * @param  list<string>  $holds
     *
     * @return list<array<string, mixed>>
     */
    private function fitList(array $parts, array $holds, string $fill): array
    {
        $fitted = [];
        $run = [];

        foreach ([...$parts, null] as $part) {
            if ($part !== null && ! in_array($part['element'] ?? null, $holds, true) && ! isset($part['group'])) {
                $run[] = $part;

                continue;
            }

            $children = $this->blank($run) ? [] : $this->fit($run, $fill);

            if ($children !== []) {
                $fitted[] = ['element' => $fill, 'children' => $children];
            }

            $run = [];

            if (isset($part['group'])) {
                array_push($fitted, ...$this->fitList($part['group'], $holds, $fill));
            } elseif ($part !== null) {
                $fitted[] = $part;
            }
        }

        return $fitted;
    }

    /**
     * Loose inline parts gathered into paragraphs between the blocks, and
     * runs of nothing but spaces or line breaks dropped.
     *
     * @param  list<array<string, mixed>>  $parts
     *
     * @return list<array<string, mixed>>
     */
    private function paragraphs(array $parts): array
    {
        $blocks = [];
        $run = [];

        foreach ([...$parts, null] as $part) {
            if ($part !== null && (isset($part['text']) || in_array($part['element'] ?? null, MarkupTree::INLINE, true))) {
                $run[] = $part;

                continue;
            }

            if (! $this->blank($run)) {
                $blocks[] = ['element' => 'p', 'children' => $this->joinTexts($run)];
            }

            $run = [];

            if ($part !== null) {
                $blocks[] = $part;
            }
        }

        return $blocks;
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     *
     * @return list<array<string, mixed>>
     */
    private function joinTexts(array $parts): array
    {
        $joined = [];

        foreach ($parts as $part) {
            $last = array_key_last($joined);

            if ($last !== null && isset($part['text'], $joined[$last]['text'])) {
                $joined[$last] = ['text' => (string) $joined[$last]['text'].(string) $part['text']];
            } else {
                $joined[] = $part;
            }
        }

        return $joined;
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     */
    private function allInline(array $parts): bool
    {
        foreach ($parts as $part) {
            if (! isset($part['text']) && ! in_array($part['element'] ?? null, MarkupTree::INLINE, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether these parts hold nothing a reader sees: only spaces, a
     * no-break space among them, and line breaks.
     *
     * @param  list<array<string, mixed>>  $parts
     */
    private function blank(array $parts): bool
    {
        foreach ($parts as $part) {
            $space = isset($part['text']) && preg_match('/^[\s\x{00A0}]*$/u', (string) $part['text']) === 1;

            if (! $space && ($part['element'] ?? null) !== 'br') {
                return false;
            }
        }

        return true;
    }
}
