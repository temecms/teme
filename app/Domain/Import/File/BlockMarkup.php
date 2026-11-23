<?php

/**
 * Reads a post's stored block markup into the arrays WordPress's block
 * parser gives, the shape ParsedBlock::from() reads, written from WordPress's
 * published block grammar (developer.wordpress.org, "Block grammar").
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File;

final class BlockMarkup
{
    /**
     * A block delimiter: `<!-- wp:name {attrs} -->`, its void form ending
     * `/-->`, or a closer `<!-- /wp:name -->`. The attributes end at the
     * first `}` followed by whitespace and the comment's end, so `-->`
     * inside a JSON string doesn't end them.
     */
    private const string DELIMITER = '~<!--\s+(?<closer>/)?wp:(?<name>[a-z][a-z0-9_-]*+(?:/[a-z][a-z0-9_-]*+)?)\s+(?:(?<attrs>\{[^}]*+(?:\}(?!\s+/?-->)[^}]*+)*+\})\s+)?(?<void>/)?-->~';

    /**
     * The post's blocks in order: each with `blockName` (`core/` added to a
     * name without a namespace, null for text outside any block), `attrs`,
     * `innerBlocks`, `innerHTML` and `innerContent` (its own markup in
     * pieces, `null` where each inner block sits). No blocks for an empty post.
     *
     * @return list<array<string, mixed>>
     */
    public static function parse(string $html): array
    {
        /** @var list<array{name: string, attrs: array<mixed>, blocks: list<array<string, mixed>>, content: list<string|null>}> $open */
        $open = [];
        $blocks = [];
        $at = 0;
        $from = 0;

        while (preg_match(self::DELIMITER, $html, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $from) === 1) {
            [$token, $offset] = $match[0];
            $from = $offset + strlen((string) $token);
            $name = self::name((string) $match['name'][0]);

            if ($match['closer'][0] !== null) {
                $depth = self::openDepth($open, $name);

                if ($depth === null) {
                    continue;
                }

                self::text($open, $blocks, substr($html, $at, $offset - $at));

                while (count($open) > $depth) {
                    self::close($open, $blocks);
                }

                $at = $from;

                continue;
            }

            self::text($open, $blocks, substr($html, $at, $offset - $at));
            $at = $from;
            $attrs = self::attrs($match['attrs'][0]);

            if ($match['void'][0] !== null) {
                self::add($open, $blocks, self::block($name, $attrs, [], []));

                continue;
            }

            $open[] = ['name' => $name, 'attrs' => $attrs, 'blocks' => [], 'content' => []];
        }

        self::text($open, $blocks, substr($html, $at));

        while ($open !== []) {
            self::close($open, $blocks);
        }

        return $blocks;
    }

    /**
     * The name with `core/` added when it has no namespace.
     */
    private static function name(string $name): string
    {
        return str_contains($name, '/') ? $name : "core/{$name}";
    }

    /**
     * The delimiter's attributes, or none when absent or not a JSON object.
     *
     * @return array<mixed>
     */
    private static function attrs(?string $json): array
    {
        if ($json === null) {
            return [];
        }

        $attrs = json_decode($json, true);

        return is_array($attrs) ? $attrs : [];
    }

    /**
     * How many blocks stay open once the innermost open block of this name
     * closes, or null when no open block has the name.
     *
     * @param  list<array{name: string, attrs: array<mixed>, blocks: list<array<string, mixed>>, content: list<string|null>}>  $open
     */
    private static function openDepth(array $open, string $name): ?int
    {
        for ($i = count($open) - 1; $i >= 0; $i--) {
            if ($open[$i]['name'] === $name) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Puts markup between delimiters into the open block, or makes it a
     * freeform block when no block is open.
     *
     * @param  list<array{name: string, attrs: array<mixed>, blocks: list<array<string, mixed>>, content: list<string|null>}>  $open
     * @param  list<array<string, mixed>>  $blocks
     */
    private static function text(array &$open, array &$blocks, string $text): void
    {
        if ($text === '') {
            return;
        }

        if ($open === []) {
            $blocks[] = self::block(null, [], [], [$text]);

            return;
        }

        $frame = array_pop($open);
        $frame['content'][] = $text;
        $open[] = $frame;
    }

    /**
     * Closes the innermost open block and adds it where it sits.
     *
     * @param  list<array{name: string, attrs: array<mixed>, blocks: list<array<string, mixed>>, content: list<string|null>}>  $open
     * @param  list<array<string, mixed>>  $blocks
     */
    private static function close(array &$open, array &$blocks): void
    {
        $frame = array_pop($open);

        if ($frame !== null) {
            self::add($open, $blocks, self::block($frame['name'], $frame['attrs'], $frame['blocks'], $frame['content']));
        }
    }

    /**
     * Adds a finished block to the open block, marking its place in that
     * block's content, or to the post's blocks.
     *
     * @param  list<array{name: string, attrs: array<mixed>, blocks: list<array<string, mixed>>, content: list<string|null>}>  $open
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $block
     */
    private static function add(array &$open, array &$blocks, array $block): void
    {
        if ($open === []) {
            $blocks[] = $block;

            return;
        }

        $frame = array_pop($open);
        $frame['blocks'][] = $block;
        $frame['content'][] = null;
        $open[] = $frame;
    }

    /**
     * One block in parse_blocks()'s shape.
     *
     * @param  array<mixed>  $attrs
     * @param  list<array<string, mixed>>  $innerBlocks
     * @param  list<string|null>  $innerContent
     *
     * @return array<string, mixed>
     */
    private static function block(?string $name, array $attrs, array $innerBlocks, array $innerContent): array
    {
        return [
            'blockName' => $name,
            'attrs' => $attrs,
            'innerBlocks' => $innerBlocks,
            'innerHTML' => implode('', array_filter($innerContent, is_string(...))),
            'innerContent' => $innerContent,
        ];
    }
}
