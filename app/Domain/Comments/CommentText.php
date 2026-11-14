<?php

declare(strict_types=1);

namespace App\Domain\Comments;

use App\Domain\Addresses\Paths;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Addresses\TypeArchives;

/**
 * Turns what a reader typed into a comment's document (I-267): paragraphs,
 * line breaks and linked web addresses, nothing else read as formatting; and
 * reads a document back as its words and its links, for the moderator, and
 * says whether a stored document is only what a reader could type (1.8c′).
 * An address on this site is linked by its item's ID or not at all (rule 9).
 */
final class CommentText
{
    /**
     * A web address: `http://` or `https://`, in any case, or `www.`, not
     * straight after a letter, a digit, `@`, `.` or `/`, running to the next
     * space, `<`, `>` or `"`.
     */
    private const string ADDRESS = '/(?<![\p{L}\p{N}@.\/])(?:(?i:https?:\/\/)|www\.)[^\s<>"]+/u';

    /** What an address never ends with: it is the sentence's, not the link's. */
    private const string TRAILING = '.,;:!?\'"';

    /**
     * The restricted document for a comment as its writer typed it: a blank
     * line, or one holding only spaces, ends a paragraph, and every other
     * line break is a line break; web addresses are linked.
     *
     * @return array<string, mixed>
     */
    public static function toDocument(string $text): array
    {
        $paragraphs = [];
        $lines = [];

        foreach (explode("\n", trim(str_replace(["\r\n", "\r"], "\n", $text))) as $line) {
            if (trim($line) === '') {
                if ($lines !== []) {
                    $paragraphs[] = self::paragraph($lines);
                    $lines = [];
                }

                continue;
            }

            $lines[] = $line;
        }

        if ($lines !== []) {
            $paragraphs[] = self::paragraph($lines);
        }

        return ['type' => 'doc', 'content' => $paragraphs === [] ? [['type' => 'paragraph']] : $paragraphs];
    }

    /**
     * The document's words as plain text: its paragraphs, and its quotes'
     * paragraphs, joined by a blank line, and each line break a line break.
     *
     * @param  array<mixed>  $document
     */
    public static function plain(array $document): string
    {
        return implode("\n\n", self::blockTexts($document['content'] ?? []));
    }

    /**
     * How many links the document's text carries.
     *
     * @param  array<mixed>  $document
     */
    public static function links(array $document): int
    {
        $links = 0;

        foreach (is_array($document['content'] ?? null) ? $document['content'] : [] as $node) {
            if (! is_array($node)) {
                continue;
            }

            foreach (is_array($node['marks'] ?? null) ? $node['marks'] : [] as $mark) {
                $links += is_array($mark) && ($mark['type'] ?? null) === 'link' ? 1 : 0;
            }

            $links += self::links($node);
        }

        return $links;
    }

    /**
     * Whether a stored document is plain (1.8c′'s D10, C3): its text, made
     * into a document again, gives the same document, so it holds nothing a
     * reader couldn't type: paragraphs, line breaks and linked addresses.
     * Keys are compared in any order, since JSONB keeps an object's keys in
     * its own.
     *
     * @param  array<mixed>  $document
     */
    public static function isPlain(array $document): bool
    {
        return self::sorted(self::toDocument(self::plain($document))) === self::sorted($document);
    }

    /**
     * One paragraph of the lines between blank lines, a line break between
     * each two.
     *
     * @param  non-empty-list<string>  $lines
     *
     * @return array<string, mixed>
     */
    private static function paragraph(array $lines): array
    {
        $content = [];

        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $content[] = ['type' => 'hardBreak'];
            }

            array_push($content, ...self::inline($line));
        }

        return ['type' => 'paragraph', 'content' => $content];
    }

    /**
     * One line's text nodes: its web addresses linked, the words between
     * them plain, and plain text next to plain text one node.
     *
     * @return list<array<string, mixed>>
     */
    private static function inline(string $line): array
    {
        $nodes = [];
        $at = 0;

        preg_match_all(self::ADDRESS, $line, $found, PREG_OFFSET_CAPTURE);

        foreach ($found[0] as [$typed, $offset]) {
            $address = self::withoutTrailing($typed);
            $href = self::isAddress($address) ? self::href($address) : null;

            // Left in the words around it.
            if ($href === null) {
                continue;
            }

            self::words($nodes, substr($line, $at, $offset - $at));
            $nodes[] = ['type' => 'text', 'text' => $address, 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]];
            $at = $offset + strlen($address);
        }

        self::words($nodes, substr($line, $at));

        return $nodes;
    }

    /**
     * Adds words to the nodes: to the plain text before them, if the last
     * node is plain text, and never as an empty node.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    private static function words(array &$nodes, string $words): void
    {
        if ($words === '') {
            return;
        }

        $last = array_key_last($nodes);

        if ($last !== null && ! isset($nodes[$last]['marks']) && ($nodes[$last]['type'] ?? null) === 'text' && is_string($nodes[$last]['text'] ?? null)) {
            $nodes[$last]['text'] .= $words;

            return;
        }

        $nodes[] = ['type' => 'text', 'text' => $words];
    }

    /**
     * The address without what ends the sentence: a trailing full stop,
     * comma, semicolon, colon, exclamation or question mark or quote, and a
     * closing bracket the address didn't open.
     */
    private static function withoutTrailing(string $address): string
    {
        while ($address !== '') {
            $last = substr($address, -1);

            if (str_contains(self::TRAILING, $last)
                || ($last === ')' && substr_count($address, ')') > substr_count($address, '('))
                || ($last === ']' && substr_count($address, ']') > substr_count($address, '['))) {
                $address = substr($address, 0, -1);

                continue;
            }

            break;
        }

        return $address;
    }

    /**
     * Whether anything follows the address's opening, `https://` or `www.`.
     */
    private static function isAddress(string $address): bool
    {
        return preg_match('/^(?:(?i:https?:\/\/)|www\.)\S/u', $address) === 1;
    }

    /**
     * The link an address in a comment keeps, or null for none (rule 9): an
     * address on this site becomes the item its path shows, current or old,
     * by its ID, or no link at all; any other web address stays as typed.
     */
    private static function href(string $address): ?string
    {
        $href = preg_match('/^https?:\/\//i', $address) === 1 ? $address : 'https://'.$address;
        $url = parse_url($href);
        $site = parse_url((string) config('app.url'));

        if (! is_array($url) || ! isset($url['host'])) {
            return null;
        }

        $onSite = is_array($site) && isset($site['host'])
            && strcasecmp($url['host'], $site['host']) === 0
            && ($url['port'] ?? null) === ($site['port'] ?? null);

        if (! $onSite) {
            return $href;
        }

        $path = Paths::find(PublicRequestPath::normalise($url['path'] ?? '/'));

        // An old row under an archive page's address is not where a link there leads (I-3304).
        if ($path !== null && ! $path->is_current && TypeArchives::at($path->path) !== null) {
            return null;
        }

        return $path !== null && $path->target_type === 'content' ? 'teme:content/'.$path->target_id : null;
    }

    /**
     * Each paragraph's text, quotes' paragraphs in their places.
     *
     * @return list<string>
     */
    private static function blockTexts(mixed $blocks): array
    {
        $texts = [];

        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'blockquote') {
                array_push($texts, ...self::blockTexts($block['content'] ?? []));

                continue;
            }

            $text = '';

            foreach (is_array($block['content'] ?? null) ? $block['content'] : [] as $node) {
                if (is_array($node)) {
                    $text .= ($node['type'] ?? null) === 'hardBreak' ? "\n" : (is_string($node['text'] ?? null) ? $node['text'] : '');
                }
            }

            $texts[] = $text;
        }

        return $texts;
    }

    /**
     * A document with every object's keys in order, its lists as they are.
     *
     * @param  array<mixed>  $value
     *
     * @return array<mixed>
     */
    private static function sorted(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => is_array($item) ? self::sorted($item) : $item, $value);
    }
}
