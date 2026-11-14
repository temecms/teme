<?php

declare(strict_types=1);

namespace App\Domain\Notices;

use Illuminate\Support\HtmlString;

/**
 * Puts words someone else wrote into a Markdown email as words, for every notice (1.8d's D10):
 * a commenter's name, an item's title, a moderator's reason and a comment's
 * text never become a link, a heading, a list or emphasis. Laravel's mail
 * lines are Markdown, and Blade's escaping stops HTML but not `[`, `*` or
 * `#`; CommonMark reads a backslash before ASCII punctuation as that
 * character, plainly.
 */
final class MailText
{
    /**
     * A one-line value, such as a name or a title, escaped for Markdown, for a
     * mail line, which Blade then escapes for HTML; its whitespace, line
     * breaks included, made single spaces.
     */
    public static function inline(string $text): string
    {
        return self::escape(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * A comment's words as a quoted block, line for line, escaped for Markdown
     * and then for HTML here: a quote's `>` must reach Markdown as it is, and
     * Blade passes an HtmlString through untouched. A blank line keeps the
     * quote going, so its paragraphs stay one block.
     */
    public static function quote(string $text): HtmlString
    {
        $lines = preg_split('/\R/u', trim($text)) ?: [];

        return new HtmlString(implode("\n", array_map(
            fn (string $line): string => trim($line) === ''
                ? '>'
                : '> '.htmlspecialchars(self::escape(trim($line)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $lines,
        )));
    }

    /**
     * A backslash before every character that starts Markdown anywhere in a
     * line (emphasis, code, links, images, tables, fences and the backslash
     * itself), and before what starts a block at its beginning (a heading, a
     * list item, a rule, an underline, a numbered item). `<`, `>`, `&` and
     * quotes are left to HTML escaping, which a backslash before them would
     * break.
     */
    private static function escape(string $text): string
    {
        $escaped = (string) preg_replace('/([\\\\`*_\[\]!|~])/u', '\\\\$1', $text);
        $escaped = (string) preg_replace('/^([#+=-])/u', '\\\\$1', $escaped);

        return (string) preg_replace('/^(\d+)([.)])/u', '$1\\\\$2', $escaped);
    }
}
