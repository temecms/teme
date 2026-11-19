<?php

/**
 * Reads the terms of the public taxonomies for the API (D39): a page of
 * them by name, or one by ID, each with its current address and the count
 * of the published items of public types filed under it. Tags what it reads
 * before reading it, so a change to a term purges the answer.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Api\Catalogue;

use App\Domain\Api\Cursor;
use App\Domain\Api\Live;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Site\ListingViews;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class TermsQuery
{
    /** The columns a term is read with. */
    private const array COLUMNS = ['terms.id', 'terms.taxonomy', 'terms.name', 'terms.slug', 'terms.parent_id', 'terms.description', 'paths.path'];

    /**
     * One page of terms in the filters' order, with the cursor for the next.
     * Each row is a terms row with its `path` and its `count`.
     *
     * @return array{rows: list<object>, next: string|null}
     */
    public function page(TermFilters $filters): array
    {
        $taxonomies = $filters->taxonomy === null ? Live::taxonomies() : [$filters->taxonomy];

        app(RenderRecord::class)->tag(...array_map(PageTags::termsOf(...), $taxonomies));

        if ($taxonomies === []) {
            return ['rows' => [], 'next' => null];
        }

        // One language's terms, the default's unless one is asked for (I-3360).
        $query = $filters->language === null ? app(SiteLanguages::class)->whereDefault($this->terms(), 'terms.language') : $this->terms()->where('terms.language', $filters->language);
        count($taxonomies) === 1 ? $query->where('terms.taxonomy', $taxonomies[0]) : $query->whereIn('terms.taxonomy', $taxonomies);

        if ($filters->parent === 'none') {
            $query->whereNull('terms.parent_id');
        } elseif ($filters->parent !== null) {
            $query->where('terms.parent_id', $filters->parent);
        }

        if ($filters->slug !== null) {
            $query->where('terms.slug', $filters->slug);
        }

        if ($filters->after !== null) {
            $query->whereRaw('(terms.name, terms.id) > (?, ?)', [$filters->after->key, $filters->after->id]);
        }

        $rows = $query->orderBy('terms.name')->orderBy('terms.id')->limit($filters->limit + 1)->get(self::COLUMNS)->all();
        $more = count($rows) > $filters->limit;
        $rows = array_slice($rows, 0, $filters->limit);
        $rows = $this->counted($rows);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return [
            'rows' => $rows,
            'next' => $more && $last !== null ? Cursor::after(TermFilters::SORT, (string) $last->name, (string) $last->id) : null,
        ];
    }

    /**
     * The term with this ID, of a public taxonomy, with its `path` and
     * `count`; null for one of another taxonomy, of a hidden language
     * (I-3360), or none, which a reader cannot tell apart.
     */
    public function byId(string $id): ?object
    {
        app(RenderRecord::class)->tag(PageTags::term($id));

        $row = app(SiteLanguages::class)->whereShown($this->terms(), 'terms.language')
            ->where('terms.id', $id)->whereIn('terms.taxonomy', Live::taxonomies())->first(self::COLUMNS);

        return $row === null ? null : $this->counted([$row])[0];
    }

    /**
     * The terms with their current address joined, as text as every join of
     * paths is.
     */
    private function terms(): Builder
    {
        return DB::table('terms')->leftJoin('paths', fn ($join) => $join
            ->on('paths.target_id', '=', DB::raw('terms.id::text'))
            ->where('paths.target_type', 'term')
            ->where('paths.is_current', true));
    }

    /**
     * These rows with a `count` each, from one statement over term_counts,
     * and their tags: the term, and what is filed under it.
     *
     * @param  list<object>  $rows
     *
     * @return list<object>
     */
    private function counted(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $counts = DB::table('term_counts')
            ->whereIn('term_id', array_map(fn (object $row): string => (string) $row->id, $rows))
            ->whereIn('type', Live::types())
            ->groupBy('term_id')
            ->selectRaw('term_id, SUM(published) AS published')
            ->pluck('published', 'term_id');

        // A taxonomy that isn't translated counts what the default language's list holds (ListingViews::countsInDefault()).
        $shared = array_values(array_filter($rows, fn (object $row): bool => ListingViews::countsInDefault((string) $row->taxonomy)));
        $listed = ListingViews::listedInDefault(array_map(fn (object $row): string => (string) $row->id, $shared), Live::types());

        foreach ($rows as $row) {
            $row->count = ListingViews::countsInDefault((string) $row->taxonomy) ? ($listed[$row->id] ?? 0) : (int) ($counts[$row->id] ?? 0);
            app(RenderRecord::class)->tag(PageTags::term((string) $row->id), PageTags::filedUnder((string) $row->taxonomy, (string) $row->id));
        }

        return $rows;
    }
}
