<?php

declare(strict_types=1);

namespace App\Domain\Languages\Queries;

use App\Domain\Languages\SiteLanguages;
use Illuminate\Support\Facades\DB;

/**
 * Which languages each term of a page of the terms list has a version in
 * (docs/proposals/multilingual-screens.md, 4): its group's terms, or itself
 * alone, in one statement for the page. It reads only.
 */
final class TermVersions
{
    /**
     * By term ID, the language tags it has a version in, each 'there';
     * null when the site has one language or the taxonomy isn't
     * translated (D25), and no statement is run.
     *
     * @param  list<string>  $termIds
     *
     * @return array<string, array<string, 'there'>>|null
     */
    public static function of(string $taxonomy, array $termIds): ?array
    {
        $languages = app(SiteLanguages::class);

        if (! $languages->isMultilingual() || ! $languages->translatesTaxonomy($taxonomy)) {
            return null;
        }

        if ($termIds === []) {
            return [];
        }

        // Terms are deleted outright, never trashed, so every row joined here is live.
        $rows = DB::table('terms as own')
            ->leftJoin('terms as versions', fn ($join) => $join->on('versions.translation_group', '=', 'own.translation_group'))
            ->whereIn('own.id', $termIds)
            ->get(['own.id', 'own.language as own_language', 'versions.language as version_language', 'versions.id as version_id']);
        $found = [];

        foreach ($rows as $row) {
            $id = (string) $row->id;
            $found[$id][$languages->of($row->own_language === null ? null : (string) $row->own_language)] = 'there';

            if ($row->version_id !== null) {
                $found[$id][$languages->of($row->version_language === null ? null : (string) $row->version_language)] = 'there';
            }
        }

        return $found;
    }

    /**
     * The site's languages, each with its tag and English name, when the
     * taxonomy has versions in them; none otherwise.
     *
     * @return list<array{tag: string, name: string}>
     */
    public static function languages(string $taxonomy): array
    {
        $languages = app(SiteLanguages::class);

        if (! $languages->isMultilingual() || ! $languages->translatesTaxonomy($taxonomy)) {
            return [];
        }

        // compact(): the key is a language's tag, never the taxonomy (NoTypeLiteralsTest).
        return array_map(fn (string $tag): array => compact('tag') + ['name' => $languages->label($tag)], $languages->all());
    }
}
