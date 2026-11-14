<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Shortcodes;

/**
 * Reads WordPress's shortcodes out of a piece of HTML (I-336), any name,
 * as WordPress's own pattern reads a registered one: pieces of markup and
 * text kept as they are, and tags, paired as HTML pairs elements. Brackets
 * inside a tag, a comment, or a code, preformatted, script, style or
 * textarea element are text.
 */
final class ShortcodeParser
{
    /** An opening tag at the offset: its name, then anything but `]` (a `/` only when no `]` follows it), then `]` or `/]`. */
    private const string OPEN = '~\G\[([A-Za-z0-9_][A-Za-z0-9_-]*)(?![\w-])([^\]/]*+(?:/(?!\])[^\]/]*+)*+)(/?)\]~';

    /** A closing tag at the offset. */
    private const string CLOSE = '~\G\[/([A-Za-z0-9_][A-Za-z0-9_-]*)\]~';

    /** An HTML tag at the offset, its quoted attribute values read whole. */
    private const string MARKUP = '~\G</?([A-Za-z][A-Za-z0-9-]*)(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>?~';

    /** An escape's closing tag, `[/name]]`. */
    private const string ESCAPE_CLOSE = '~\[/([A-Za-z0-9_][A-Za-z0-9_-]*)\]\]~';

    /** Elements whose content is never read for shortcodes. */
    private const array VERBATIM = ['pre', 'code', 'script', 'style', 'textarea'];

    /** How deep shortcodes nest; an opening tag deeper than this is text. */
    private const int DEPTH = 64;

    /** WordPress's own pattern for one attribute (shortcodes.php, get_shortcode_atts_regex()). */
    private const string ATTRIBUTE = '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|\'([^\']*)\'(?:\s|$)|(\S+)(?:\s|$)/';

    /**
     * The HTML as pieces in order: strings (markup and text, as they are,
     * an escaped shortcode as its literal text) and tags.
     *
     * @return list<string|ShortcodeTag>
     */
    public static function pieces(string $html): array
    {
        $pieces = [];
        $text = '';
        $at = 0;
        $length = strlen($html);
        $closings = [];
        $cursors = [];

        // Every escape's closing tag, read once, so no search runs to the end per opening.
        preg_match_all(self::ESCAPE_CLOSE, $html, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($found as $match) {
            $closings[$match[1][0]][] = $match[0][1];
        }

        while ($at < $length) {
            $plain = strcspn($html, '[<', $at);

            if ($plain > 0) {
                $text .= substr($html, $at, $plain);
                $at += $plain;

                continue;
            }

            $skip = $html[$at] === '<' ? self::markup($html, $at) : 0;
            $tag = $skip === 0 && $html[$at] === '[' ? self::tag($html, $at, $closings, $cursors) : null;

            if ($skip > 0 || $tag === null) {
                $text .= substr($html, $at, max($skip, 1));
                $at += max($skip, 1);

                continue;
            }

            [$piece, $taken] = $tag;
            $at += $taken;

            if (is_string($piece)) {
                $text .= $piece;

                continue;
            }

            if ($text !== '') {
                $pieces[] = $text;
                $text = '';
            }

            $pieces[] = $piece;
        }

        if ($text !== '') {
            $pieces[] = $text;
        }

        return $pieces;
    }

    /**
     * The pieces as a tree: each opening tag holds what lies between it and
     * the nearest closing tag of its name, as HTML pairs elements; an
     * opening tag nothing closes holds nothing, and a closing tag nothing
     * opened stays a tag in its place. An opening tag waits for its closing
     * tag only while more of them remain than openings of its name already
     * wait, and at most 64 deep, so hostile markup stays linear.
     *
     * @param  list<string|ShortcodeTag>  $pieces
     *
     * @return list<string|ShortcodeTag|ShortcodeNode>
     */
    public static function tree(array $pieces): array
    {
        $closings = [];

        foreach ($pieces as $piece) {
            if ($piece instanceof ShortcodeTag && $piece->closing) {
                $closings[$piece->name] = ($closings[$piece->name] ?? 0) + 1;
            }
        }

        $root = [];
        $stack = [];
        $waiting = [];

        foreach ($pieces as $piece) {
            if (is_string($piece)) {
                self::add($root, $stack, $piece);

                continue;
            }

            $name = $piece->name;

            if ($piece->closing) {
                $closings[$name]--;
                $match = self::opened($stack, $name);

                if ($match === null) {
                    self::add($root, $stack, $piece);

                    continue;
                }

                while (count($stack) - 1 > $match) {
                    self::unclosed($root, $stack, $waiting);
                }

                $frame = array_pop($stack);

                if ($frame !== null) {
                    $waiting[$name]--;
                    self::add($root, $stack, new ShortcodeNode($frame['open'], $piece, $frame['children']));
                }

                continue;
            }

            $waits = ! $piece->selfClosing && ($closings[$name] ?? 0) > ($waiting[$name] ?? 0);

            if ($waits && count($stack) >= self::DEPTH) {
                self::add($root, $stack, $piece->raw);
            } elseif ($waits) {
                $stack[] = ['open' => $piece, 'children' => []];
                $waiting[$name] = ($waiting[$name] ?? 0) + 1;
            } else {
                self::add($root, $stack, new ShortcodeNode($piece, null, []));
            }
        }

        while ($stack !== []) {
            self::unclosed($root, $stack, $waiting);
        }

        return $root;
    }

    /**
     * A tag's attributes as WordPress's shortcode_parse_atts() reads them:
     * names lower-cased, values by place numbered, quotes and backslashes
     * taken off, no-break and zero-width spaces read as spaces, and a value
     * holding unclosed HTML emptied.
     *
     * @return array<int|string, string>
     */
    public static function attributes(string $text): array
    {
        $text = (string) preg_replace('/[\x{00a0}\x{200b}]+/u', ' ', $text);
        $attributes = [];

        if (preg_match_all(self::ATTRIBUTE, $text, $matches, PREG_SET_ORDER) < 1) {
            return [];
        }

        foreach ($matches as $found) {
            if (($found[1] ?? '') !== '') {
                $attributes[strtolower($found[1])] = stripcslashes($found[2]);
            } elseif (($found[3] ?? '') !== '') {
                $attributes[strtolower($found[3])] = stripcslashes($found[4]);
            } elseif (($found[5] ?? '') !== '') {
                $attributes[strtolower($found[5])] = stripcslashes($found[6]);
            } elseif (($found[7] ?? '') !== '') {
                $attributes[] = stripcslashes($found[7]);
            } elseif (($found[8] ?? '') !== '') {
                $attributes[] = stripcslashes($found[8]);
            } elseif (isset($found[9])) {
                $attributes[] = stripcslashes($found[9]);
            }
        }

        foreach ($attributes as $name => $value) {
            if (str_contains($value, '<') && preg_match('/^[^<]*+(?:<[^>]*+>[^<]*+)*+$/', $value) !== 1) {
                $attributes[$name] = '';
            }
        }

        return $attributes;
    }

    /**
     * How long the comment, the tag, or the verbatim element up to its
     * closing tag at this `<` is; 0 when it is text.
     */
    private static function markup(string $html, int $at): int
    {
        if (substr($html, $at, 4) === '<!--') {
            $end = strpos($html, '-->', $at + 4);

            return $end === false ? strlen($html) - $at : $end + 3 - $at;
        }

        if (preg_match(self::MARKUP, $html, $found, 0, $at) !== 1) {
            return 0;
        }

        $length = strlen($found[0]);
        $name = strtolower($found[1]);

        if (str_starts_with($found[0], '</') || ! in_array($name, self::VERBATIM, true)) {
            return $length;
        }

        $close = stripos($html, '</'.$name, $at + $length);

        return $close === false ? strlen($html) - $at : $close - $at;
    }

    /**
     * The tag at this `[`, or an escaped shortcode's literal text (`[[name]]`,
     * or `[[name]…[/name]]` through its first closing tag), with how many
     * bytes it takes; null when the bracket is text.
     *
     * @param  array<string, list<int>>  $closings  where each escape's closing tag starts, by name
     * @param  array<string, int>  $cursors  how far into each name's closings the scan has passed
     *
     * @return array{0: string|ShortcodeTag, 1: int}|null
     */
    private static function tag(string $html, int $at, array $closings, array &$cursors): ?array
    {
        if (($html[$at + 1] ?? '') === '[' && preg_match(self::OPEN, $html, $open, 0, $at + 1) === 1) {
            $after = $at + 1 + strlen($open[0]);

            if (($html[$after] ?? '') === ']') {
                return [$open[0], strlen($open[0]) + 2];
            }

            $positions = $closings[$open[1]] ?? [];
            $cursor = $cursors[$open[1]] ?? 0;

            while (isset($positions[$cursor]) && $positions[$cursor] < $after) {
                $cursor++;
            }

            $cursors[$open[1]] = $cursor;
            $close = $positions[$cursor] ?? false;

            if ($close === false) {
                return null;
            }

            $literal = substr($html, $at + 1, $close + strlen($open[1]) + 3 - ($at + 1));

            return [$literal, strlen($literal) + 2];
        }

        if (preg_match(self::OPEN, $html, $open, 0, $at) === 1) {
            return [new ShortcodeTag(false, $open[1], $open[2], $open[3] === '/', $open[0]), strlen($open[0])];
        }

        if (preg_match(self::CLOSE, $html, $close, 0, $at) === 1) {
            return [new ShortcodeTag(true, $close[1], '', false, $close[0]), strlen($close[0])];
        }

        return null;
    }

    /**
     * The place in the stack of the innermost opening tag of this name still
     * open, or null.
     *
     * @param  list<array{open: ShortcodeTag, children: list<string|ShortcodeTag|ShortcodeNode>}>  $stack
     */
    private static function opened(array $stack, string $name): ?int
    {
        for ($index = count($stack) - 1; $index >= 0; $index--) {
            if ($stack[$index]['open']->name === $name) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The innermost open tag, closed by nothing: it holds nothing, and what
     * followed it belongs to its parent.
     *
     * @param  list<string|ShortcodeTag|ShortcodeNode>  $root
     * @param  list<array{open: ShortcodeTag, children: list<string|ShortcodeTag|ShortcodeNode>}>  $stack
     * @param  array<string, int>  $waiting
     */
    private static function unclosed(array &$root, array &$stack, array &$waiting): void
    {
        $frame = array_pop($stack);

        if ($frame === null) {
            return;
        }

        $waiting[$frame['open']->name]--;
        self::add($root, $stack, new ShortcodeNode($frame['open'], null, []));

        foreach ($frame['children'] as $child) {
            self::add($root, $stack, $child);
        }
    }

    /**
     * @param  list<string|ShortcodeTag|ShortcodeNode>  $root
     * @param  list<array{open: ShortcodeTag, children: list<string|ShortcodeTag|ShortcodeNode>}>  $stack
     */
    private static function add(array &$root, array &$stack, string|ShortcodeTag|ShortcodeNode $item): void
    {
        if ($stack === []) {
            $root[] = $item;

            return;
        }

        $last = count($stack) - 1;
        $frame = $stack[$last];
        $frame['children'][] = $item;
        $stack[$last] = $frame;
    }
}
