<?php

declare(strict_types=1);

namespace App\Domain\Taxonomy;

use App\Domain\Languages\SiteLanguages;
use App\Domain\Languages\TranslatedTypes;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

/**
 * The validation rule the content Operations share for a term an item is
 * given: it must exist, in a taxonomy the item's type uses (I-414), and in
 * the item's language (I-3360), unless its taxonomy isn't translated (D25). A type with no taxonomies, such as page
 * (I-417), can be given none.
 */
final class TermRules
{
    /**
     * @param  string|null  $language  the item's language as a row stores it: null for the default
     */
    public static function usableBy(string $type, ?string $language = null): Exists
    {
        $taxonomies = array_map(fn (Taxonomy $taxonomy): string => $taxonomy->key, app(TaxonomyRegistry::class)->forType($type));

        // A taxonomy that isn't translated shares its terms with every language (D25).
        $shared = TranslatedTypes::untranslatedTaxonomies();

        return Rule::exists('terms', 'id')->whereIn('taxonomy', $taxonomies)
            ->where(fn (Builder $query) => $query
                ->when($shared !== [], fn (Builder $q) => $q->whereIn('taxonomy', $shared))
                ->orWhere(fn (Builder $q) => $language === null ? $q->whereNull('language') : $q->where('language', $language)));
    }

    /**
     * Refuses, naming it, the first of these terms that is in another
     * language than the item's, so an editor learns which to change rather
     * than that one is invalid.
     *
     * @param  list<mixed>  $termIds
     *
     * @throws ValidationException
     */
    public static function refuseOtherLanguage(array $termIds, ?string $language, string $key = 'terms'): void
    {
        $ids = array_values(array_filter($termIds, is_string(...)));

        if ($ids === []) {
            return;
        }

        $shared = TranslatedTypes::untranslatedTaxonomies();
        $other = DB::table('terms')->whereIn('id', $ids)
            ->when($shared !== [], fn (Builder $query) => $query->whereNotIn('taxonomy', $shared))
            ->where(fn (Builder $query) => $language === null ? $query->whereNotNull('language') : $query->where(fn (Builder $q) => $q->whereNull('language')->orWhere('language', '<>', $language)))
            ->orderBy('name')->orderBy('id')
            ->first(['name', 'taxonomy', 'language']);

        if ($other === null) {
            return;
        }

        $languages = app(SiteLanguages::class);
        $taxonomies = app(TaxonomyRegistry::class);
        $singular = $taxonomies->has((string) $other->taxonomy) ? mb_strtolower($taxonomies->get((string) $other->taxonomy)->singular) : (string) $other->taxonomy;

        throw ValidationException::withMessages([$key => __('“:term” is in :termLanguage. Choose a :language :singular instead.', [
            'term' => (string) $other->name,
            'termLanguage' => $languages->label($languages->of($other->language === null ? null : (string) $other->language)),
            'language' => $languages->label($languages->of($language)),
            'singular' => $singular,
        ])]);
    }
}
