<?php

declare(strict_types=1);

namespace App\Domain\Comments;

use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\Exceptions\InvalidDocumentException;

/**
 * What a stored comment may hold (I-267): paragraphs and quotes, line breaks,
 * and text that may be bold, italic, inline code or a link. Everything else is
 * refused, naming the part that failed. A link goes to an item by its ID or to
 * an http, https or mailto address (rule 9), and carries its address alone.
 */
final class CommentDocument
{
    /** @var list<string> */
    public const array BLOCKS = ['paragraph', 'blockquote'];

    /** @var list<string> */
    public const array MARKS = ['bold', 'italic', 'code', 'link'];

    /** Parts in one comment, all levels together: an imported one runs to WordPress's 65,525 characters. */
    public const int MAX_NODES = 2000;

    /** How deep quotes may sit inside quotes. */
    public const int MAX_QUOTE_DEPTH = 3;

    /**
     * @param  array<mixed>  $document
     *
     * @throws InvalidDocumentException
     */
    public static function validate(array $document): void
    {
        if (($document['type'] ?? null) !== 'doc' || array_diff(array_keys($document), ['type', 'content']) !== []) {
            throw InvalidDocumentException::at('', 'A comment is a document of paragraphs and quotes.');
        }

        $nodes = 0;
        self::blocks($document['content'] ?? null, 'content', 0, $nodes);
    }

    private static function blocks(mixed $blocks, string $path, int $quotes, int &$nodes): void
    {
        if (! is_array($blocks) || ! array_is_list($blocks) || $blocks === []) {
            throw InvalidDocumentException::at($path, 'A comment, and each quote in it, holds one or more paragraphs.');
        }

        foreach ($blocks as $index => $block) {
            $at = "{$path}.{$index}";

            if (++$nodes > self::MAX_NODES) {
                throw InvalidDocumentException::at($at, 'A comment holds at most '.self::MAX_NODES.' parts.');
            }

            if (! is_array($block) || ! in_array($block['type'] ?? null, self::BLOCKS, true) || array_diff(array_keys($block), ['type', 'content']) !== []) {
                throw InvalidDocumentException::at($at, 'A comment holds paragraphs and quotes, with no settings.');
            }

            if ($block['type'] === 'blockquote') {
                if ($quotes >= self::MAX_QUOTE_DEPTH) {
                    throw InvalidDocumentException::at($at, 'Quotes sit at most '.self::MAX_QUOTE_DEPTH.' deep.');
                }

                self::blocks($block['content'] ?? null, "{$at}.content", $quotes + 1, $nodes);

                continue;
            }

            self::inline($block['content'] ?? [], "{$at}.content", $nodes);
        }
    }

    private static function inline(mixed $children, string $path, int &$nodes): void
    {
        if (! is_array($children) || ! array_is_list($children)) {
            throw InvalidDocumentException::at($path, 'A paragraph holds text and line breaks.');
        }

        foreach ($children as $index => $child) {
            $at = "{$path}.{$index}";

            if (++$nodes > self::MAX_NODES) {
                throw InvalidDocumentException::at($at, 'A comment holds at most '.self::MAX_NODES.' parts.');
            }

            if (! is_array($child)) {
                throw InvalidDocumentException::at($at, 'A paragraph holds text and line breaks.');
            }

            if (($child['type'] ?? null) === 'hardBreak' && array_keys($child) === ['type']) {
                continue;
            }

            if (($child['type'] ?? null) !== 'text' || ! is_string($child['text'] ?? null) || $child['text'] === '' || array_diff(array_keys($child), ['type', 'text', 'marks']) !== []) {
                throw InvalidDocumentException::at($at, 'A paragraph holds text and line breaks, and nothing else.');
            }

            self::marks($child['marks'] ?? [], "{$at}.marks");
        }
    }

    private static function marks(mixed $marks, string $path): void
    {
        if (! is_array($marks) || ! array_is_list($marks)) {
            throw InvalidDocumentException::at($path, 'Text carries a list of marks.');
        }

        $seen = [];

        foreach ($marks as $index => $mark) {
            $at = "{$path}.{$index}";
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;

            if (! is_array($mark) || ! is_string($type) || ! in_array($type, self::MARKS, true) || isset($seen[$type])) {
                throw InvalidDocumentException::at($at, 'Text in a comment may be bold, italic, code or a link, each once.');
            }

            $seen[$type] = true;

            if ($type !== 'link') {
                if (array_keys($mark) !== ['type']) {
                    throw InvalidDocumentException::at($at, 'Only a link carries anything more.');
                }

                continue;
            }

            $attrs = $mark['attrs'] ?? null;

            if (array_diff(array_keys($mark), ['type', 'attrs']) !== [] || ! is_array($attrs) || array_keys($attrs) !== ['href'] || ! is_string($attrs['href']) || strlen($attrs['href']) > 2048) {
                throw InvalidDocumentException::at("{$at}.attrs", 'A link in a comment carries its address and nothing else.');
            }

            if (! self::allowsHref($attrs['href'])) {
                throw InvalidDocumentException::at("{$at}.attrs.href", 'A link goes to an item by its ID, or to an http, https or mailto address.');
            }
        }
    }

    /**
     * An item by its ID (rule 9), or a web or email address: never a script,
     * a file or a phone number.
     */
    private static function allowsHref(string $href): bool
    {
        if (preg_match(DocumentSchema::CONTENT_LINK, $href) === 1) {
            return true;
        }

        $scheme = parse_url($href, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https', 'mailto'], true);
    }
}
