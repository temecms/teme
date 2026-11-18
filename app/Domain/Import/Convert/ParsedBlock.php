<?php

/**
 * One block of WordPress's parse_blocks() tree, as the companion plugin
 * sends it (I-334): its name, null for a freeform chunk of classic content;
 * its attributes; its own markup, with null where each inner block goes;
 * and, for a freeform chunk, the HTML wpautop() made of it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert;

use App\Domain\Import\Convert\Html\HtmlFragment;

final readonly class ParsedBlock
{
    /**
     * @param  array<string, mixed>  $attrs
     * @param  list<ParsedBlock>  $innerBlocks
     * @param  list<string|null>  $innerContent
     */
    public function __construct(
        public ?string $name,
        public array $attrs,
        public string $innerHtml,
        public array $innerBlocks,
        public array $innerContent,
        public ?string $autop,
    ) {}

    /**
     * The block from the companion's array, any missing or malformed key
     * read as empty.
     *
     * @param  array<mixed>  $block
     */
    public static function from(array $block): self
    {
        $name = $block['blockName'] ?? null;
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
        $content = is_array($block['innerContent'] ?? null) ? $block['innerContent'] : [];

        return new self(
            is_string($name) && $name !== '' ? strtolower($name) : null,
            array_filter($attrs, fn (int|string $key): bool => is_string($key), ARRAY_FILTER_USE_KEY),
            is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '',
            array_values(array_map(fn (mixed $child): self => self::from(is_array($child) ? $child : []), $inner)),
            array_values(array_map(fn (mixed $piece): ?string => is_string($piece) ? $piece : null, $content)),
            is_string($block['autop'] ?? null) ? $block['autop'] : null,
        );
    }

    /**
     * The block's own markup, without its inner blocks: innerContent's
     * strings, or innerHTML when innerContent holds none.
     */
    public function ownHtml(): string
    {
        $pieces = array_filter($this->innerContent, is_string(...));

        return $pieces === [] ? $this->innerHtml : implode('', $pieces);
    }

    /**
     * The block's whole markup: innerContent's strings, each `null` replaced
     * by the next inner block's own whole markup; innerHTML when
     * innerContent holds no string.
     */
    public function fullHtml(): string
    {
        if (array_filter($this->innerContent, is_string(...)) === []) {
            return $this->innerHtml;
        }

        $children = $this->innerBlocks;
        $html = '';

        foreach ($this->innerContent as $piece) {
            $html .= $piece ?? array_shift($children)?->fullHtml() ?? '';
        }

        return $html;
    }

    /**
     * Whether its own markup holds content: words, or an image, a table or
     * an embedded player. A layout's own styles and scripts aren't words.
     */
    public function hasOwnContent(): bool
    {
        $body = HtmlFragment::body($this->ownHtml());

        foreach (iterator_to_array($body->querySelectorAll('script, style, noscript, template')) as $e) {
            $e->remove();
        }

        return HtmlFragment::text($body) !== '' || $body->querySelector('img, table, video, audio, iframe, object, embed, svg') !== null;
    }

    /**
     * One attribute, or null.
     */
    public function attr(string $key): mixed
    {
        return $this->attrs[$key] ?? null;
    }

    /**
     * One attribute as an integer, written as a number or digits, or null.
     */
    public function intAttr(string $key): ?int
    {
        $value = filter_var($this->attrs[$key] ?? null, FILTER_VALIDATE_INT);

        return is_int($value) ? $value : null;
    }
}
