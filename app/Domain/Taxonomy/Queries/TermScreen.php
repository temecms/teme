<?php

declare(strict_types=1);

namespace App\Domain\Taxonomy\Queries;

use App\Domain\Types\ContentType;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What a taxonomy's screen shows around its rows: the taxonomies filed with
 * it under one content type, as tabs, each with how many terms it has, and
 * the listed types whose items its counts count (I-225).
 */
final class TermScreen
{
    /**
     * The taxonomies filed under the same content type as this one, in
     * registry order, each with how many terms it has: those $viewer may
     * manage, and always this one. The menu files a taxonomy under the first
     * listed type that uses it, so these are its children there; a taxonomy
     * no listed type uses stands alone.
     *
     * @return list<array{segment: string, label: string, count: int}>
     */
    public function tabs(string $taxonomyKey, ?User $viewer): array
    {
        $owner = $this->owner($taxonomyKey);
        $tabs = array_values(array_filter(
            app(TaxonomyRegistry::class)->all(),
            fn (Taxonomy $taxonomy): bool => $taxonomy->key === $taxonomyKey
                || ($owner !== null && $this->owner($taxonomy->key) === $owner && $viewer?->can($taxonomy->ability) === true),
        ));

        $sizes = DB::table('terms')
            ->whereIn('taxonomy', array_map(fn (Taxonomy $taxonomy): string => $taxonomy->key, $tabs))
            ->groupBy('taxonomy')
            ->selectRaw('taxonomy, count(*) AS size')
            ->pluck('size', 'taxonomy')
            ->all();

        return array_map(fn (Taxonomy $taxonomy): array => [
            'segment' => $taxonomy->segment,
            'label' => $taxonomy->plural,
            'count' => (int) ($sizes[$taxonomy->key] ?? 0),
        ], $tabs);
    }

    /**
     * The listed content types that use this taxonomy, in registration
     * order: one count column each, linking to that type's list.
     *
     * @return list<array{key: string, segment: string, singular: string, plural: string}>
     */
    public function countedTypes(string $taxonomyKey): array
    {
        $listed = array_values(array_filter(
            app(TaxonomyRegistry::class)->typesUsing($taxonomyKey),
            fn (ContentType $type): bool => $type->listed,
        ));

        return array_map(fn (ContentType $type): array => [
            'key' => $type->key,
            'segment' => $type->segment,
            'singular' => $type->singular,
            'plural' => $type->plural,
        ], $listed);
    }

    /**
     * The key of the content type the menu files a taxonomy under: the first
     * listed type that uses it, or null.
     */
    private function owner(string $taxonomyKey): ?string
    {
        foreach (app(TaxonomyRegistry::class)->typesUsing($taxonomyKey) as $type) {
            if ($type->listed) {
                return $type->key;
            }
        }

        return null;
    }
}
