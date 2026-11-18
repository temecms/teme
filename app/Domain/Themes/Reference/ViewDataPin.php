<?php

/**
 * The pin of everything a template can read, kept in docs/reference/theme-view-data.json:
 * what each value's type is, what it was, and what is gone, so a value is never
 * renamed, retyped or removed without a line in the changelog (I-3346).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Themes\Reference;

final class ViewDataPin
{
    /**
     * The pin after this generation: every current entry at its current type,
     * a changed type remembered under was, and an entry the code no longer has
     * kept and marked gone, never dropped (I-3346).
     *
     * @param  array<string, array{type: string, was?: list<string>, gone?: bool}>  $pinned
     * @param  array<string, string>  $current  entry => type
     * @return array<string, array{type: string, was?: list<string>, gone?: bool}>
     */
    public static function updated(array $pinned, array $current): array
    {
        $next = [];

        foreach ($current as $name => $type) {
            $before = $pinned[$name] ?? null;
            $entry = ['type' => $type];
            $was = $before['was'] ?? [];

            if ($before !== null && ($before['gone'] ?? false) === false && $before['type'] !== $type) {
                $was[] = $before['type'];
            }

            if ($was !== []) {
                $entry['was'] = array_values(array_unique($was));
            }

            $next[$name] = $entry;
        }

        foreach ($pinned as $name => $before) {
            if (! array_key_exists($name, $current)) {
                $next[$name] = [...$before, 'gone' => true];
            }
        }

        ksort($next, SORT_STRING);

        return $next;
    }

    /**
     * The entries that were changed or removed and are not named, in backticks,
     * under a `### Theme view data` heading of the changelog, in key order.
     *
     * @param  array<string, array{type: string, was?: list<string>, gone?: bool}>  $entries
     * @return list<string>
     */
    public static function unnoted(array $entries, string $changelog): array
    {
        $noted = self::notedText($changelog);
        $unnoted = [];

        ksort($entries, SORT_STRING);

        foreach ($entries as $name => $entry) {
            if (($entry['gone'] ?? false) === true || ($entry['was'] ?? []) !== []) {
                if (! str_contains($noted, '`'.$name.'`')) {
                    $unnoted[] = $name;
                }
            }
        }

        return $unnoted;
    }

    /** The text of every `### Theme view data` section, each running to the next `##` or `###` heading. */
    private static function notedText(string $changelog): string
    {
        $inside = false;
        $text = [];

        foreach (preg_split('/\R/', $changelog) ?: [] as $line) {
            if (preg_match('/^#{2,3}\s/', $line) === 1) {
                $inside = preg_match('/^###\s+Theme view data\b/', $line) === 1;

                continue;
            }

            if ($inside) {
                $text[] = $line;
            }
        }

        return implode("\n", $text);
    }
}
