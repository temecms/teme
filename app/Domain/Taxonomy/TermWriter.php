<?php

/**
 * Writes a new term, as CreateTerm and Translate's missing terms (I-3360)
 * both make one: its slug unique within its taxonomy and language, its
 * address projected. Call it inside an Operation's perform(); the caller
 * purges the keys it answers after commit.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Taxonomy;

use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\Footprints;
use App\Domain\Contents\Slugs;
use App\Domain\Taxonomy\Models\Term;
use Teme\Contracts\Cache\CacheKeysFor;

final class TermWriter
{
    /**
     * The new term, and what to purge for it: a "not found" page may be
     * stored at the address it takes (I-263).
     *
     * @param  string|null  $language  as a row stores it: null for the default language
     *
     * @return array{0: Term, 1: CacheKeysFor}
     */
    public static function create(string $taxonomy, string $name, ?string $slug, ?string $parentId, ?string $description, ?string $language = null, ?string $group = null): array
    {
        // 190, not 200: room for a numeric suffix inside the 200-character column.
        $base = rtrim(mb_substr($slug ?? Slugs::fromTitle($name), 0, 190), '-');

        // refresh(): the columns the database defaults (published_count,
        // fields) are not on the model after an insert.
        $term = Term::query()->create([
            'taxonomy' => $taxonomy,
            'name' => trim($name),
            'slug' => TermSlugs::unique($taxonomy, $base, null, $language),
            'language' => $language,
            'translation_group' => $group,
            'parent_id' => $parentId,
            'description' => $description,
        ])->refresh();

        app(PathProjection::class)->syncTerm($term);

        return [$term, new CacheKeysFor(CacheKeysFor::TERM, (string) $term->id, Footprints::terms([(string) $term->id])[(string) $term->id] ?? [])];
    }
}
