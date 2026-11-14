<?php

declare(strict_types=1);

namespace App\Domain\Api;

use App\Domain\Contents\Visibility;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What a reader can see, once (D19): the one predicate every item query of
 * the API goes through, matching the public site's own, with the type's
 * public flag added; and the public types and taxonomies.
 */
final class Live
{
    /**
     * Narrows a query on `contents` to the items a reader can see: published,
     * not in the trash, live by now, not private, of a public type (I-1700),
     * in a shown language (I-3360).
     *
     * @param  list<string>|null  $types  public type keys to keep; null keeps every public type
     */
    public static function apply(Builder $query, ?array $types = null): Builder
    {
        $public = self::types();
        $types = $types === null ? $public : array_values(array_intersect($types, $public));

        if ($types === []) {
            return $query->whereRaw('false');
        }

        // One type with `=`, as ListingViews::archive() does, so its indexes serve it.
        count($types) === 1 ? $query->where('contents.type', $types[0]) : $query->whereIn('contents.type', $types);

        $query
            ->where('contents.status', 'published')
            ->whereNull('contents.deleted_at')
            ->whereNotNull('contents.published_revision_id')
            ->where('contents.published_at', '<=', now())
            ->where('contents.visibility', '<>', Visibility::Private->value);

        return app(SiteLanguages::class)->whereShown($query, 'contents.language');
    }

    /**
     * Narrows a query joined to `contents` to the items open to anyone: not
     * protected by a password either, which a reader who knows no password
     * sees the title of but never the words or files. Applied after apply(),
     * for what only a fully public item may give out, such as a media file.
     */
    public static function publicOnly(Builder $query): Builder
    {
        return $query->where('contents.visibility', Visibility::Public->value);
    }

    /**
     * Adds `locked` to a query on `contents`: whether the item is anything
     * but public, behind a password today or a visibility a later release
     * adds, so its card is given without its words (D30); the exact opposite
     * of publicOnly(), failing safe. The one place the API tells a locked
     * item from a public one.
     */
    public static function withLocked(Builder $query): Builder
    {
        return $query->selectRaw('contents.visibility <> ? as locked', [Visibility::Public->value]);
    }

    /**
     * Adds each of these columns, such as `revision.document`, to a query on
     * `contents` under its own name, or null for an item withLocked() calls
     * locked, so a locked item's words are never read.
     */
    public static function unlessLocked(Builder $query, string ...$columns): Builder
    {
        $grammar = $query->getGrammar();

        foreach ($columns as $column) {
            $name = Str::afterLast($column, '.');
            $query->selectRaw('case when contents.visibility = ? then '.$grammar->wrap($column).' end as '.$grammar->wrap($name), [Visibility::Public->value]);
        }

        return $query;
    }

    /**
     * A query on `contents` of the items a reader can see, of these public
     * types or of every public type.
     *
     * @param  list<string>|null  $types
     */
    public static function items(?array $types = null): Builder
    {
        return self::apply(DB::table('contents'), $types);
    }

    /**
     * The public types' keys, in the registry's order.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return array_values(array_map(fn (ContentType $type): string => $type->key, array_filter(app(ContentTypeRegistry::class)->all(), fn (ContentType $type): bool => $type->public)));
    }

    /**
     * The public taxonomies' keys, in the registry's order.
     *
     * @return list<string>
     */
    public static function taxonomies(): array
    {
        return array_values(array_map(fn (Taxonomy $taxonomy): string => $taxonomy->key, array_filter(app(TaxonomyRegistry::class)->all(), fn (Taxonomy $taxonomy): bool => $taxonomy->public)));
    }
}
