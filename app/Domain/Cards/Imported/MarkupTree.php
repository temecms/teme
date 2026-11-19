<?php

/**
 * The markup an Imported content card may hold (I-329): a tree of parts
 * from one list, stored as JSON and never as a string of HTML. It checks a
 * tree, draws it, and reads its words, pictures and links, all by the same
 * list.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Imported;

use App\Domain\Cards\CardReference;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\References;
use App\Domain\Cards\RenderContext;
use App\Domain\Cards\Tiptap\LinkMark;
use Closure;

final class MarkupTree
{
    /** The most parts one card may hold. */
    public const int MAX_PARTS = 2000;

    /** The deepest a part may sit, the top level being 1. */
    public const int MAX_DEPTH = 12;

    /** The elements that stand as blocks. */
    public const array BLOCKS = ['p', 'h2', 'h3', 'h4', 'ul', 'ol', 'dl', 'blockquote', 'pre', 'table'];

    /** The elements that sit inside a line. */
    public const array INLINE = ['a', 'strong', 'em', 'code', 'sup', 'sub', 'br', 'img'];

    /**
     * What each element may hold: `flow` (blocks and inline parts),
     * `inline` (text and inline elements), `none`, or the only elements it
     * holds. The card's top level holds flow.
     *
     * @var array<string, string|list<string>>
     */
    public const array CONTENT = [
        'p' => 'inline', 'h2' => 'inline', 'h3' => 'inline', 'h4' => 'inline',
        'ul' => ['li'], 'ol' => ['li'], 'li' => 'flow',
        'dl' => ['dt', 'dd'], 'dt' => 'inline', 'dd' => 'flow',
        'blockquote' => 'flow', 'pre' => 'inline',
        'table' => ['caption', 'thead', 'tbody', 'tfoot'], 'caption' => 'inline',
        'thead' => ['tr'], 'tbody' => ['tr'], 'tfoot' => ['tr'], 'tr' => ['th', 'td'],
        'th' => 'flow', 'td' => 'flow',
        'a' => 'inline', 'strong' => 'inline', 'em' => 'inline', 'code' => 'inline', 'sup' => 'inline', 'sub' => 'inline',
        'br' => 'none', 'img' => 'none',
    ];

    /**
     * The attributes each element may carry, in the order they're drawn.
     *
     * @var array<string, list<string>>
     */
    public const array ATTRIBUTES = [
        'a' => ['href'],
        'img' => ['mediaId', 'alt'],
        'ol' => ['start', 'type'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
    ];

    /**
     * The attributes an element can't be without.
     *
     * @var array<string, list<string>>
     */
    public const array NEEDED = ['a' => ['href'], 'img' => ['mediaId']];

    /** A header cell's scopes, as HTML has them. */
    public const array SCOPES = ['row', 'col', 'rowgroup', 'colgroup'];

    /**
     * The first thing in this tree the list doesn't allow, in words led by
     * its place, or null when all of it fits.
     */
    public static function problem(mixed $parts): ?string
    {
        if (! is_array($parts) || ! array_is_list($parts)) {
            return 'a part is an element or a run of text.';
        }

        $count = 0;

        return self::check($parts, 'flow', 'the card', '', 1, false, $count);
    }

    /**
     * Whether an attribute's value is one the list accepts.
     */
    public static function accepts(string $attribute, mixed $value): bool
    {
        return match ($attribute) {
            'href' => is_string($value) && strlen($value) <= 2048 && DocumentSchema::allowsHref($value),
            'mediaId' => is_string($value) && strlen($value) === 26,
            'alt' => is_string($value) && mb_strlen($value) <= 1000,
            'start' => is_int($value) && $value >= 1,
            'type' => is_string($value) && in_array($value, DocumentSchema::LIST_TYPES, true),
            'colspan', 'rowspan' => is_int($value) && $value >= 1 && $value <= DocumentSchema::CELL_SPANS[$attribute],
            'scope' => is_string($value) && in_array($value, self::SCOPES, true),
            default => false,
        };
    }

    /**
     * The tree as HTML: each listed element with its listed attributes and
     * every value escaped, a link drawn by the link mark's own rules, and a
     * picture by $picture, given its media ID and alt text. A part off the
     * list is left out with all it holds, so what's drawn never rests on the
     * tree having been checked.
     *
     * @param  Closure(string, string): string  $picture
     */
    public static function html(mixed $parts, RenderContext $context, Closure $picture): string
    {
        return is_array($parts) ? self::draw($parts, new LinkMark($context), $picture, 1) : '';
    }

    /**
     * The tree's words on one line: each block's edges and a line break are
     * spaces, inline parts run together, and a picture gives its alt text.
     */
    public static function text(mixed $parts): string
    {
        return is_array($parts) ? trim((string) preg_replace('/\s+/u', ' ', self::words($parts, 1))) : '';
    }

    /**
     * Each library picture the tree shows and whatever its links lead to by
     * ID (I-1702), once each, in the order they appear: a link's reference
     * is linked, so a file it leads to isn't counted as a picture the card
     * shows.
     *
     * @return list<CardReference>
     */
    public static function references(mixed $parts): array
    {
        $found = [];

        if (is_array($parts)) {
            self::refs($parts, 1, $found);
        }

        return array_values($found);
    }

    /**
     * What the tree mostly is, for its label: `table`, `definition-list`,
     * `list` or `text`, the first of these that any part of it is.
     */
    public static function holds(mixed $parts): string
    {
        $elements = is_array($parts) ? self::elements($parts, 1) : [];

        return match (true) {
            isset($elements['table']) => 'table',
            isset($elements['dl']) => 'definition-list',
            isset($elements['ul']) || isset($elements['ol']) => 'list',
            default => 'text',
        };
    }

    /**
     * @param  array<mixed>  $parts
     * @param  string|list<string>  $holds
     */
    private static function check(array $parts, string|array $holds, string $parent, string $path, int $depth, bool $inLink, int &$count): ?string
    {
        foreach ($parts as $index => $part) {
            $place = ltrim("{$path}.{$index}", '.');

            if (++$count > self::MAX_PARTS) {
                return 'It holds more than 2,000 parts.';
            }

            if ($depth > self::MAX_DEPTH) {
                return "{$place}: it nests deeper than 12 levels.";
            }

            if (! is_array($part)) {
                return "{$place}: a part is an element or a run of text.";
            }

            if (array_keys($part) === ['text']) {
                if (! is_string($part['text'])) {
                    return "{$place}: a part is an element or a run of text.";
                }

                if (is_array($holds) || $holds === 'none') {
                    return "{$place}: {$parent} can’t hold text.";
                }

                continue;
            }

            $element = $part['element'] ?? null;

            if (! is_string($element) || array_diff(array_keys($part), ['element', 'attrs', 'children']) !== []) {
                return "{$place}: a part is an element or a run of text.";
            }

            if (! array_key_exists($element, self::CONTENT)) {
                return "{$place}: [{$element}] isn’t an element this card may hold.";
            }

            if (! self::fits($element, $holds)) {
                return "{$place}: {$parent} can’t hold [{$element}].";
            }

            if ($element === 'a' && $inLink) {
                return "{$place}: a link can’t hold another link.";
            }

            $problem = self::attributes($element, $part['attrs'] ?? [], $place);

            if ($problem !== null) {
                return $problem;
            }

            $children = $part['children'] ?? [];

            if (! is_array($children) || ! array_is_list($children)) {
                return "{$place}: a part is an element or a run of text.";
            }

            if (self::CONTENT[$element] === 'none' && $children !== []) {
                return "{$place}: [{$element}] holds nothing.";
            }

            $problem = self::check($children, self::CONTENT[$element], "[{$element}]", "{$place}.children", $depth + 1, $inLink || $element === 'a', $count);

            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /**
     * @param  string|list<string>  $holds
     */
    private static function fits(string $element, string|array $holds): bool
    {
        return match (true) {
            is_array($holds) => in_array($element, $holds, true),
            $holds === 'flow' => in_array($element, self::BLOCKS, true) || in_array($element, self::INLINE, true),
            $holds === 'inline' => in_array($element, self::INLINE, true),
            default => false,
        };
    }

    private static function attributes(string $element, mixed $attrs, string $place): ?string
    {
        if (! is_array($attrs) || ($attrs !== [] && array_is_list($attrs))) {
            return "{$place}: a part is an element or a run of text.";
        }

        foreach ($attrs as $name => $value) {
            if (! in_array($name, self::ATTRIBUTES[$element] ?? [], true)) {
                return "{$place}: [{$element}] can’t carry [{$name}].";
            }

            if (! self::accepts((string) $name, $value)) {
                return "{$place}: [{$element}]’s [{$name}] isn’t a value it accepts.";
            }
        }

        foreach (self::NEEDED[$element] ?? [] as $name) {
            if (! array_key_exists($name, $attrs)) {
                return "{$place}: [{$element}] needs its [{$name}].";
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $parts
     * @param  Closure(string, string): string  $picture
     */
    private static function draw(array $parts, LinkMark $links, Closure $picture, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '';
        }

        $html = '';

        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }

            if (array_key_exists('text', $part)) {
                $html .= is_string($part['text']) ? e($part['text']) : '';

                continue;
            }

            $element = $part['element'] ?? null;

            if (! is_string($element) || ! array_key_exists($element, self::CONTENT)) {
                continue;
            }

            $attrs = is_array($part['attrs'] ?? null) ? $part['attrs'] : [];
            $inner = is_array($part['children'] ?? null) ? self::draw($part['children'], $links, $picture, $depth + 1) : '';

            $html .= match ($element) {
                'br' => '<br>',
                'img' => is_string($attrs['mediaId'] ?? null) ? $picture($attrs['mediaId'], is_string($attrs['alt'] ?? null) ? $attrs['alt'] : '') : '',
                'a' => self::link($attrs, $inner, $links),
                default => "<{$element}".self::attributeHtml($element, $attrs).">{$inner}</{$element}>",
            };
        }

        return $html;
    }

    /**
     * A link as the document's link mark draws one, or its words alone when
     * the mark draws none (an article that has gone, an address not allowed).
     *
     * @param  array<mixed>  $attrs
     */
    private static function link(array $attrs, string $inner, LinkMark $links): string
    {
        $href = $attrs['href'] ?? null;
        $anchor = self::accepts('href', $href) ? $links->renderHTML((object) ['attrs' => ['href' => $href]]) : null;

        if ($anchor === null) {
            return $inner;
        }

        $html = '';

        foreach ($anchor[1] as $name => $value) {
            $html .= ' '.$name.'="'.e($value).'"';
        }

        return "<a{$html}>{$inner}</a>";
    }

    /**
     * @param  array<mixed>  $attrs
     */
    private static function attributeHtml(string $element, array $attrs): string
    {
        $html = '';

        foreach (self::ATTRIBUTES[$element] ?? [] as $name) {
            $value = $attrs[$name] ?? null;

            if ((is_int($value) || is_string($value)) && self::accepts($name, $value)) {
                $html .= ' '.$name.'="'.e((string) $value).'"';
            }
        }

        return $html;
    }

    /**
     * @param  array<mixed>  $parts
     */
    private static function words(array $parts, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '';
        }

        $text = '';

        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }

            if (is_string($part['text'] ?? null)) {
                $text .= $part['text'];

                continue;
            }

            $element = is_string($part['element'] ?? null) ? $part['element'] : '';
            $attrs = is_array($part['attrs'] ?? null) ? $part['attrs'] : [];
            $inner = is_array($part['children'] ?? null) ? self::words($part['children'], $depth + 1) : '';

            $text .= match (true) {
                $element === 'br' => ' ',
                $element === 'img' => ' '.(is_string($attrs['alt'] ?? null) ? $attrs['alt'] : '').' ',
                in_array($element, self::INLINE, true) => $inner,
                default => " {$inner} ",
            };
        }

        return $text;
    }

    /**
     * @param  array<mixed>  $parts
     * @param  array<string, CardReference>  $found
     */
    private static function refs(array $parts, int $depth, array &$found): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }

            $attrs = is_array($part['attrs'] ?? null) ? $part['attrs'] : [];
            $element = $part['element'] ?? null;

            if ($element === 'img' && is_string($attrs['mediaId'] ?? null) && $attrs['mediaId'] !== '') {
                $found["media:{$attrs['mediaId']}"] = CardReference::media($attrs['mediaId']);
            }

            $link = $element === 'a' && is_string($attrs['href'] ?? null) ? References::link($attrs['href']) : null;

            // A link's own key, so a picture shown and a link to it stay two references (I-1702).
            if ($link !== null) {
                $found["link:{$link->kind}:{$link->id}"] = $link;
            }

            if (is_array($part['children'] ?? null)) {
                self::refs($part['children'], $depth + 1, $found);
            }
        }
    }

    /**
     * @param  array<mixed>  $parts
     *
     * @return array<string, true>
     */
    private static function elements(array $parts, int $depth): array
    {
        $found = [];

        if ($depth > self::MAX_DEPTH) {
            return $found;
        }

        foreach ($parts as $part) {
            if (is_array($part) && is_string($part['element'] ?? null)) {
                $found[$part['element']] = true;

                if (is_array($part['children'] ?? null)) {
                    $found += self::elements($part['children'], $depth + 1);
                }
            }
        }

        return $found;
    }
}
