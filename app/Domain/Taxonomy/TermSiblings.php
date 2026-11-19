<?php

/**
 * A term's version in another language (I-3360, D13): found through the
 * terms' translation group, or made as a copy in that language, parent
 * first, linked into the source term's group (minted on it when it has
 * none), through the code CreateTerm writes with. Call it inside an
 * Operation's perform(), after the languages' lock and any content row's.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Taxonomy;

use App\Domain\Languages\TranslatedTypes;
use App\Domain\Languages\TranslationGroups;
use App\Domain\Taxonomy\Models\Term;
use Teme\Contracts\Cache\CacheKeysFor;

final class TermSiblings
{
    /**
     * Each term mapped to its sibling in $language (as a row stores it),
     * the IDs of the terms made, parents first, and what to purge for them.
     *
     * @param  list<string>  $termIds
     *
     * @return array{map: array<string, string>, made: list<string>, purge: list<CacheKeysFor>}
     */
    public static function inLanguage(array $termIds, ?string $language): array
    {
        $result = ['map' => [], 'made' => [], 'purge' => []];

        if ($termIds === []) {
            return $result;
        }

        // The source terms and their ancestors, locked in ID order before their groups are read.
        $terms = Term::query()->whereIn('id', self::withAncestors($termIds))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        // Read under the languages' lock the caller holds, not from the request's settings.
        $shared = TranslatedTypes::sharedTaxonomies();

        foreach ($termIds as $id) {
            if ($terms->has($id)) {
                self::sibling($terms->all(), $id, $language, $result, $shared);
            }
        }

        return $result;
    }

    /**
     * The term's sibling, made with its parent's first when missing.
     *
     * @param  array<string, Term>  $terms
     * @param  array{map: array<string, string>, made: list<string>, purge: list<CacheKeysFor>}  $result
     * @param  list<string>  $shared  the taxonomies that aren't translated (D25)
     */
    private static function sibling(array $terms, string $id, ?string $language, array &$result, array $shared): string
    {
        if (isset($result['map'][$id])) {
            return $result['map'][$id];
        }

        $term = $terms[$id];

        // A taxonomy that isn't translated shares its terms with every language (D25).
        if ($term->language === $language || in_array((string) $term->taxonomy, $shared, true)) {
            return $result['map'][$id] = $id;
        }

        $group = $term->translation_group;

        if ($group !== null) {
            $found = Term::query()->where('translation_group', $group)
                ->when($language === null, fn ($query) => $query->whereNull('language'), fn ($query) => $query->where('language', $language))
                ->value('id');

            if ($found !== null) {
                return $result['map'][$id] = (string) $found;
            }
        } else {
            $group = TranslationGroups::newId();
            $term->update(['translation_group' => $group]);
        }

        $parentId = $term->parent_id !== null && isset($terms[(string) $term->parent_id])
            ? self::sibling($terms, (string) $term->parent_id, $language, $result, $shared)
            : null;

        [$made, $keys] = TermWriter::create((string) $term->taxonomy, (string) $term->name, (string) $term->slug, $parentId, $term->description, $language, $group);
        $result['made'][] = (string) $made->id;
        $result['purge'][] = $keys;

        return $result['map'][$id] = (string) $made->id;
    }

    /**
     * The terms and every ancestor of theirs.
     *
     * @param  list<string>  $termIds
     *
     * @return list<string>
     */
    private static function withAncestors(array $termIds): array
    {
        $ids = $termIds;
        $next = $termIds;

        while ($next !== []) {
            $next = array_values(array_diff(
                Term::query()->whereIn('id', $next)->whereNotNull('parent_id')->pluck('parent_id')->map(fn (mixed $id): string => (string) $id)->all(),
                $ids,
            ));
            $ids = [...$ids, ...$next];
        }

        return $ids;
    }
}
