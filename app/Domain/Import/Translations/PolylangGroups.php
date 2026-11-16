<?php

declare(strict_types=1);

namespace App\Domain\Import\Translations;

/**
 * Polylang's translation maps joined into groups by union, never pairwise
 * (I-351): a map that lost a member joins its group rather than splitting
 * it. Posts and terms are grouped apart, a post by its WordPress ID, a term
 * by its term ID.
 */
final class PolylangGroups
{
    /**
     * Polylang's maps joined into groups: every WordPress ID a map names is one node, and each map joins
     * all of its IDs, so a map that lost a member joins rather than splits (docs/studies/2026-11-11-multilingual.md).
     *
     * @param  iterable<array{id: string, translations: array<string, int|string>}>  $maps  one row's own ID and its map
     *
     * @return list<list<string>> each group's WordPress IDs, sorted, groups of two or more only, sorted by their first ID
     */
    public static function groups(iterable $maps): array
    {
        $parent = [];

        foreach ($maps as $map) {
            $ids = [$map['id'], ...array_map(strval(...), array_values($map['translations']))];
            $first = self::root($parent, $ids[0]);

            foreach ($ids as $id) {
                $root = self::root($parent, $id);

                if ($root !== $first) {
                    $parent[$root] = $first;
                }
            }
        }

        $groups = [];

        foreach (array_keys($parent) as $id) {
            $groups[self::root($parent, (string) $id)][] = (string) $id;
        }

        $groups = array_values(array_filter($groups, fn (array $group): bool => count($group) > 1));
        array_walk($groups, fn (array &$group) => sort($group, SORT_STRING));
        usort($groups, fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return $groups;
    }

    /**
     * @param  array<string, string>  $parent
     */
    private static function root(array &$parent, string $id): string
    {
        $parent[$id] ??= $id;

        while ($parent[$id] !== $id) {
            $parent[$id] = $parent[$parent[$id]];
            $id = $parent[$id];
        }

        return $id;
    }
}
