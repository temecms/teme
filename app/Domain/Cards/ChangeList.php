<?php

/**
 * The words a card uses to say how it changed between two revisions: which
 * attributes differ, and the line the comparison shows. Each card lists its
 * own parts; this only compares values and joins the words.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards;

final class ChangeList
{
    /**
     * Whether an attribute differs between two versions. A missing value, null
     * and an empty string are the same: none.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function differs(array $before, array $after, string $key): bool
    {
        return self::value($before[$key] ?? null) !== self::value($after[$key] ?? null);
    }

    /**
     * The comparison's line: the label, a dash, and the parts joined as
     * "a, b and c". With no part named, the settings changed in some way the
     * card does not name.
     *
     * @param  list<string>  $parts
     */
    public static function line(string $label, array $parts): string
    {
        if ($parts === []) {
            return "{$label} — settings changed";
        }

        $last = array_pop($parts);

        return $label.' — '.($parts === [] ? $last : implode(', ', $parts).' and '.$last);
    }

    private static function value(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }
}
