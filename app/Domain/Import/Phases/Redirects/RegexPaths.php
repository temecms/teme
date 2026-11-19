<?php

/**
 * A WordPress plugin's regex rule in Teme's own form (I-287): read, never
 * run, so a catastrophic pattern costs nothing and nothing of PCRE's dialect
 * reaches PostgreSQL. It converts when anchored at its start, each part
 * between slashes plain text or one group matching that part, a group taking
 * the rest only last, and nothing after its end; its groups become [p1],
 * [p2], [...p3], in the target too. Otherwise, the reason in words.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Redirects;

final class RegexPaths
{
    /** Group bodies that match one part of an address; counted digits (COUNTED) too. */
    public const array PART = ['[^/]+', '[^/]+?', '[^/]*', '[^\/]+', '[^\/]+?', '[^\/]*', '\d+', '[0-9]+', '\w+', '[\w-]+', '[-\w]+', '[a-z0-9-]+', '[a-z0-9_-]+', '[a-zA-Z0-9-]+', '[a-zA-Z0-9_-]+', '[A-Za-z0-9-]+', '[A-Za-z0-9_-]+'];

    /** Group bodies that match the rest of an address, slashes and all: only at its end. */
    public const array REST = ['.+', '.*', '.+?', '.*?'];

    /** Why a rule that is only a placeholder at either end is refused. */
    public const string ANYWHERE = 'it sends readers on to any address at all, which Teme can’t check for two steps';

    /** Counted digits, as dated rules write them: \d{4}, [0-9]{2}, \d{1,5}. */
    private const string COUNTED = '/^(?:\\\\d|\[0-9\])\{\d{1,2}(?:,\d{0,2})?\}$/';

    /** Group bodies Teme's [name] matches exactly, so nothing widens. */
    private const array EXACT = ['[^/]+', '[^/]+?', '[^/]*', '[^\/]+', '[^\/]+?', '[^\/]*'];

    /** Characters that mean something outside a group. */
    private const string SPECIAL = '^$|*+?{}[]()#';

    /**
     * Teme's source and target for a regex rule and its target, and whether
     * a group now takes more than it did; or why it can't be written so.
     *
     * @return array{from: string, to: string, widened: bool}|string
     */
    public static function convert(string $regex, string $target): array|string
    {
        if (mb_strlen($regex) > 500) {
            return 'it is longer than 500 characters';
        }

        if (! str_starts_with($regex, '^')) {
            return 'it can match anywhere in an address, not only from the start';
        }

        $body = substr($regex, 1);
        // An unescaped $ ends it, after an optional slash and its ?.
        $anchored = preg_match('/(?<!\\\\)\$$/', $body) === 1;

        if ($anchored) {
            $body = (string) preg_replace('#(?:\\\\?/\??)?\$$#', '', $body);
        }

        $parts = [''];
        $groups = [];
        $widened = false;
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];
            $last = array_key_last($parts);

            if ($char === '\\') {
                $next = $body[$i + 1] ?? '';
                $i++;

                if ($next === '/') {
                    $parts[] = '';

                    continue;
                }

                if ($next === '' || ctype_alnum($next)) {
                    return 'it uses \\'.$next.' outside a group';
                }

                if ($next === '?' || $next === '#') {
                    return 'it holds a ? or a #';
                }

                $char = $next;
            } elseif ($char === '/') {
                $parts[] = '';

                continue;
            } elseif ($char === '(') {
                $close = strpos($body, ')', $i);

                if ($close === false) {
                    return 'its brackets don’t close';
                }

                if ($parts[$last] !== '') {
                    return 'a group shares a part of the address with other text';
                }

                $inner = substr($body, $i + 1, $close - $i - 1);

                if (in_array($inner, self::PART, true) || preg_match(self::COUNTED, $inner) === 1) {
                    $groups[] = 'part';
                    $widened = $widened || ! in_array($inner, self::EXACT, true);
                } elseif (in_array($inner, self::REST, true)) {
                    $groups[] = 'rest';
                } else {
                    return 'a group in it matches in a way Teme can’t';
                }

                $parts[$last] = "\0".count($groups);
                $i = $close;

                continue;
            } elseif (str_contains(self::SPECIAL, $char)) {
                return "it uses {$char} outside a group";
            }

            if (str_starts_with($parts[$last], "\0")) {
                return 'a group shares a part of the address with other text';
            }

            $parts[$last] .= $char;
        }

        $parts = array_values(array_filter($parts, fn (string $part): bool => $part !== ''));
        $from = [];

        foreach ($parts as $index => $part) {
            if (! str_starts_with($part, "\0")) {
                $from[] = $part;

                continue;
            }

            $n = (int) substr($part, 1);

            if ($groups[$n - 1] === 'rest' && $index !== array_key_last($parts)) {
                return 'it matches the rest of an address before its end';
            }

            $from[] = $groups[$n - 1] === 'rest' ? "[...p{$n}]" : "[p{$n}]";
        }

        $last = $parts === [] ? '' : $parts[array_key_last($parts)];

        if (! $anchored && ! (str_starts_with($last, "\0") && $groups[(int) substr($last, 1) - 1] === 'rest')) {
            return 'it matches the start of longer addresses too';
        }

        $missing = null;
        $to = (string) preg_replace_callback('/\$\{(\d+)\}|\$(\d+)|\\\\(\d+)/', function (array $match) use ($groups, &$missing): string {
            $n = (int) ($match[1] ?? $match[2] ?? $match[3]);

            if ($n < 1 || $n > count($groups)) {
                $missing ??= $match[0];

                return $match[0];
            }

            return $groups[$n - 1] === 'rest' ? "[...p{$n}]" : "[p{$n}]";
        }, $target, -1, $count, PREG_UNMATCHED_AS_NULL);

        if ($missing !== null) {
            return "it sends readers to {$missing}, which it doesn’t have";
        }

        $source = '/'.implode('/', $from);

        // A target with no host is on this site; one on the old site's own host is judged once RuleTargets makes it a path.
        if (self::onlyPlaceholder($source) || (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $to) !== 1 && self::onlyPlaceholder($to))) {
            return self::ANYWHERE;
        }

        return ['from' => $source, 'to' => $to, 'widened' => $widened];
    }

    /**
     * Whether a path is a placeholder and nothing else, slashes aside:
     * `/[p1]`, `[...p1]/`. Such a source matches every address, and such a
     * target can be any address on the site.
     */
    public static function onlyPlaceholder(string $path): bool
    {
        return preg_match('/^\[(?:\.\.\.)?[a-z][a-z0-9_]*\]$/i', trim($path, '/')) === 1;
    }
}
