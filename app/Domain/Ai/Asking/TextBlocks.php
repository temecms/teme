<?php

/**
 * A paragraph's or heading's text measured as the editor measures it, so an
 * offset the editor sends means the same here (I-1305): a text node counts
 * its UTF-16 code units, any other inline node one. Finds a block by its
 * path, hashes its text for the staleness check, and slices and splices it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Asking;

final class TextBlocks
{
    /** A block's path: child indexes from the document down, joined by dots. */
    public const string PATH = '/^(0|[1-9][0-9]{0,4})(\.(0|[1-9][0-9]{0,4})){0,7}$/';

    /** The blocks a suggestion may target. */
    public const array TYPES = ['paragraph', 'heading'];

    /** U+FFFC, the object replacement character, in UTF-8: one inline node that isn't text. */
    private const string OBJECT = "\xEF\xBF\xBC";

    /**
     * The paragraph or heading at the path, or null when there is none.
     *
     * @param  array<string, mixed>  $document
     *
     * @return array<string, mixed>|null
     */
    public static function at(array $document, string $path): ?array
    {
        if (preg_match(self::PATH, $path) !== 1) {
            return null;
        }

        $node = $document;

        foreach (explode('.', $path) as $index) {
            $child = is_array($node['content'] ?? null) ? ($node['content'][(int) $index] ?? null) : null;

            if (! is_array($child)) {
                return null;
            }

            $node = $child;
        }

        return in_array($node['type'] ?? null, self::TYPES, true) ? $node : null;
    }

    /**
     * The block's text: text as written, a hard break as a line feed, and
     * any other inline node as U+FFFC, each one unit in the editor's measure.
     *
     * @param  array<string, mixed>  $block
     */
    public static function text(array $block): string
    {
        $text = '';

        foreach (is_array($block['content'] ?? null) ? $block['content'] : [] as $inline) {
            if (! is_array($inline)) {
                continue;
            }

            $text .= match ($inline['type'] ?? null) {
                'text' => is_string($inline['text'] ?? null) ? $inline['text'] : '',
                'hardBreak' => "\n",
                default => self::OBJECT,
            };
        }

        return $text;
    }

    /**
     * The SHA-256 of the block's text: what the staleness check compares.
     *
     * @param  array<string, mixed>  $block
     */
    public static function hash(array $block): string
    {
        return hash('sha256', self::text($block));
    }

    /**
     * The text's length in the editor's measure.
     */
    public static function length(string $text): int
    {
        return intdiv(strlen(self::units($text)), 2);
    }

    /**
     * The text between two offsets, or null when they don't fit it.
     */
    public static function slice(string $text, int $from, int $to): ?string
    {
        $units = self::units($text);

        return self::fits($units, $from, $to) ? self::utf8(substr($units, $from * 2, ($to - $from) * 2)) : null;
    }

    /**
     * The text with the range between two offsets replaced, or null when the
     * offsets don't fit it.
     */
    public static function splice(string $text, int $from, int $to, string $with): ?string
    {
        $units = self::units($text);

        if (! self::fits($units, $from, $to)) {
            return null;
        }

        return self::utf8(substr($units, 0, $from * 2)).$with.self::utf8(substr($units, $to * 2));
    }

    /**
     * Whether the editor's block is the stored block's kind: the same type
     * and attributes, nulls aside, so a suggestion changes only the text.
     * The editor's block may carry an `id` the stored one lacks, given when
     * the article was opened; an `id` the stored one has must stay (I-3362).
     *
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $edited
     */
    public static function sameKind(array $stored, array $edited): bool
    {
        $kept = self::attributes($stored);
        $made = self::attributes($edited);

        if (array_key_exists('id', $kept) && ($made['id'] ?? null) !== $kept['id']) {
            return false;
        }

        unset($kept['id'], $made['id']);

        return ($stored['type'] ?? null) === ($edited['type'] ?? null) && $kept === $made;
    }

    /**
     * The document with the block at the path replaced; the path is one
     * at() found.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $block
     *
     * @return array<string, mixed>
     */
    public static function replace(array $document, string $path, array $block): array
    {
        return self::replaceIn($document, array_map('intval', explode('.', $path)), $block);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<int>  $indexes
     * @param  array<string, mixed>  $block
     *
     * @return array<string, mixed>
     */
    private static function replaceIn(array $node, array $indexes, array $block): array
    {
        $index = array_shift($indexes) ?? 0;
        $children = is_array($node['content'] ?? null) ? $node['content'] : [];
        $child = is_array($children[$index] ?? null) ? $children[$index] : [];
        $children[$index] = $indexes === [] ? $block : self::replaceIn($child, $indexes, $block);
        $node['content'] = $children;

        return $node;
    }

    /**
     * @param  array<string, mixed>  $block
     *
     * @return array<string, mixed>
     */
    private static function attributes(array $block): array
    {
        $attributes = array_filter(is_array($block['attrs'] ?? null) ? $block['attrs'] : [], fn (mixed $value): bool => $value !== null);
        ksort($attributes);

        return $attributes;
    }

    /**
     * Whether the offsets lie inside the text, in order, and neither falls
     * between the halves of a surrogate pair.
     */
    private static function fits(string $units, int $from, int $to): bool
    {
        $length = intdiv(strlen($units), 2);

        if ($from < 0 || $to < $from || $to > $length) {
            return false;
        }

        foreach ([$from, $to] as $offset) {
            $unit = $offset < $length ? ord($units[$offset * 2]) | (ord($units[$offset * 2 + 1]) << 8) : 0;

            // A low surrogate here: the offset splits a character in two.
            if ($offset > 0 && $unit >= 0xDC00 && $unit <= 0xDFFF) {
                return false;
            }
        }

        return true;
    }

    private static function units(string $text): string
    {
        return (string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
    }

    private static function utf8(string $units): string
    {
        return (string) mb_convert_encoding($units, 'UTF-8', 'UTF-16LE');
    }
}
