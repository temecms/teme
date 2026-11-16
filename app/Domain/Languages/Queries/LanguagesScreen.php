<?php

declare(strict_types=1);

namespace App\Domain\Languages\Queries;

use App\Domain\Languages\SiteLanguages;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Facades\DB;

/**
 * What Settings › Languages shows (docs/proposals/multilingual-screens.md):
 * the site's languages in the switcher's order, the default first, each
 * with its prefix, whether readers see it and what is in it; the languages
 * it could add; which types and taxonomies are translated; and the group's
 * version every form on the screen carries. It reads only.
 */
final class LanguagesScreen
{
    /**
     * The screen's props.
     *
     * @return array{
     *     languages: list<array{tag: string, name: string, native: string, prefix: string, shown: bool, isDefault: bool, items: int, terms: int}>,
     *     choices: list<array{tag: string, name: string, native: string}>,
     *     types: list<array{key: string, label: string, translated: bool}>,
     *     taxonomies: list<array{key: string, label: string, translated: bool}>,
     *     version: int
     * }
     */
    public static function data(): array
    {
        $languages = app(SiteLanguages::class);
        $items = self::counts('contents', live: true);
        $terms = self::counts('terms', live: false);
        $rows = [];

        foreach ($languages->all() as $tag) {
            $stored = $languages->stored($tag);
            $isDefault = $stored === null;
            // compact(): the key is a language's tag, never the taxonomy (NoTypeLiteralsTest).
            $rows[] = compact('tag') + [
                'name' => $languages->label($tag),
                'native' => $languages->native($tag),
                'prefix' => $languages->prefix($tag),
                'shown' => $languages->isShown($stored),
                'isDefault' => $isDefault,
                'items' => $items[$stored ?? ''] ?? 0,
                'terms' => $terms[$stored ?? ''] ?? 0,
            ];
        }

        $choices = [];

        foreach ((array) config('languages') as $tag => $entry) {
            if (is_string($tag) && ! $languages->has($tag)) {
                $choices[] = compact('tag') + ['name' => $languages->label($tag), 'native' => $languages->native($tag)];
            }
        }

        usort($choices, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        $types = [];

        foreach (app(ContentTypeRegistry::class)->all() as $type) {
            $types[] = ['key' => $type->key, 'label' => $type->plural, 'translated' => $languages->translates($type->key)];
        }

        $taxonomies = [];

        foreach (app(TaxonomyRegistry::class)->all() as $taxonomy) {
            $taxonomies[] = ['key' => $taxonomy->key, 'label' => $taxonomy->plural, 'translated' => $languages->translatesTaxonomy($taxonomy->key)];
        }

        return [
            'languages' => $rows,
            'choices' => $choices,
            'types' => $types,
            'taxonomies' => $taxonomies,
            'version' => (int) DB::table('settings')->where('group_key', SiteLanguages::GROUP)->value('version'),
        ];
    }

    /**
     * How many rows of the table are in each language, by the stored value
     * ('' for the default's NULL), in one statement; live ones only for a
     * table with a trash.
     *
     * @return array<string, int>
     */
    private static function counts(string $table, bool $live): array
    {
        $counts = [];

        foreach (DB::table($table)->when($live, fn ($query) => $query->whereNull('deleted_at'))->groupBy('language')->select('language', DB::raw('count(*) as total'))->get() as $row) {
            $counts[(string) ($row->language ?? '')] = (int) $row->total;
        }

        return $counts;
    }
}
