<?php

/**
 * Core's site search, over each published item's `search_tsv` (1.5b), in the
 * rules of the language searched, and among its items alone (I-435): items of searchable types only, the newest
 * matches up to the cap ranked by ts_rank_cd among themselves (I-458), with
 * a count that stops at the cap, and snippets made for the page's rows alone,
 * since ts_headline is the query's dearest part.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Search;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Site\SiteLanguage;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Search\SearchHit;
use Teme\Contracts\Search\SearchHits;
use Teme\Contracts\Search\SearchRequest;
use Teme\Contracts\Search\SiteSearch;

final readonly class PostgresSiteSearch implements SiteSearch
{
    /** The longest query read, in characters. */
    private const int QUERY_LENGTH = 200;

    /**
     * What only published, live items of these types match, whose date has
     * come (the lists and addresses hide the rest until then): the same in
     * both queries. The time is bound from PHP, never SQL's now(), so a test's
     * travel() moves it too. The words are parsed in place, never read from a
     * CTE, so the planner sees them as a constant and knows how common they
     * are (I-458). The language's condition follows it (matches()).
     */
    private const string MATCHES = "c.search_tsv @@ websearch_to_tsquery(?::regconfig, ?) AND c.status = 'published' AND c.published_at <= ? AND c.deleted_at IS NULL AND c.visibility = 'public' AND c.type = ANY(?::text[])";

    /** Newest first, the order the candidates are taken in (I-458), which contents_published_order gives. */
    private const string NEWEST_FIRST = 'ORDER BY c.published_at DESC, c.id DESC';

    public function __construct(
        private ContentTypeRegistry $types,
        private SiteLanguage $language,
        private SiteLanguages $languages,
    ) {}

    public function search(SearchRequest $request): SearchHits
    {
        $query = mb_substr(trim($request->query), 0, self::QUERY_LENGTH);
        $types = $this->searchable($request->types);
        $none = new SearchHits([], 0, false);

        // A search lists what its types have published, whoever asks it (I-263).
        if ($query !== '') {
            // The counts cover every searchable type, so a page that shows them depends on each one's list, whichever section it is filtered to.
            app(RenderRecord::class)->tag(...array_map(PageTags::listOf(...), $request->counts ? $this->searchable(null) : $types));
        }

        if ($query === '' || $types === [] || $request->page < 1 || $request->perPage < 1) {
            return $none;
        }

        $config = $this->language->searchConfig($this->languages->of($request->language));
        [$matches, $languageBindings] = $this->matches($request->language);

        // Only stop words or punctuation parse to nothing: nothing to search for.
        if ((int) DB::scalar('SELECT numnode(websearch_to_tsquery(?::regconfig, ?))', [$config, $query]) === 0) {
            return $none;
        }

        // PDO can't bind an array: the keys go in as one array literal.
        $typeList = '{'.implode(',', $types).'}';
        $cap = (int) config('search.total_cap', 1000);
        $now = now();

        // Both walk the matches newest first, so a common word stops at the
        // cap instead of reading every match (I-458), and a rare word goes
        // through the full-text index, the planner choosing by how common.
        $counted = (int) DB::scalar(
            'SELECT count(*) FROM (SELECT 1 FROM contents c WHERE '.$matches.' '.self::NEWEST_FIRST.' LIMIT ?) t',
            [$config, $query, $now, $typeList, ...$languageBindings, $cap + 1],
        );
        $total = min($counted, $cap);
        $offset = ($request->page - 1) * $request->perPage;
        $counts = $request->counts ? $this->countsByType($config, $query, $now, $request->language, $this->searchable(null)) : [];

        if ($offset >= $total) {
            return new SearchHits([], $total, $counted > $cap, $counts);
        }

        // Only the newest matches, as many as the cap, are ranked, among
        // themselves (I-458): a closer match further back isn't shown.
        $rows = DB::select(
            'WITH q AS (SELECT websearch_to_tsquery(?::regconfig, ?) AS query), '
            .'candidates AS ('
            .'SELECT c.id, c.search_tsv, c.search_text, c.published_at FROM contents c '
            .'WHERE '.$matches.' '.self::NEWEST_FIRST.' LIMIT ?'
            .'), '
            .'page AS ('
            .'SELECT candidates.id, candidates.search_text, ts_rank_cd(candidates.search_tsv, q.query) AS rank, candidates.published_at FROM candidates, q '
            .'ORDER BY rank DESC, candidates.published_at DESC, candidates.id DESC LIMIT ? OFFSET ?'
            .') '
            .'SELECT page.id, ts_headline(?::regconfig, coalesce(page.search_text, \'\'), q.query, ?) AS snippet '
            .'FROM page, q ORDER BY page.rank DESC, page.published_at DESC, page.id DESC',
            [
                $config, $query, $config, $query, $now, $typeList, ...$languageBindings, $cap, min($request->perPage, $total - $offset), $offset,
                $config, 'StartSel='.Snippet::START.', StopSel='.Snippet::STOP.', MaxWords=35, MinWords=15, MaxFragments=2, FragmentDelimiter=" … "',
            ],
        );

        return new SearchHits(
            array_map(fn (object $row): SearchHit => new SearchHit((string) $row->id, Snippet::fromHeadline((string) $row->snippet)), $rows),
            $total,
            $counted > $cap,
            $counts,
        );
    }

    /**
     * How many matches each searchable type has, in one grouped statement
     * over the same newest-first candidates the search ranks, so each count
     * stops at the cap as the total does.
     *
     * @param  list<string>  $searchable  every searchable type, since a filter on one type still counts the others
     *
     * @return array<string, int>
     */
    private function countsByType(string $config, string $query, mixed $now, ?string $language, array $searchable): array
    {
        [$matches, $languageBindings] = $this->matches($language);
        $everyType = '{'.implode(',', $searchable).'}';
        $cap = (int) config('search.total_cap', 1000);
        $rows = DB::select(
            'SELECT type, count(*) AS matched FROM (SELECT c.type FROM contents c WHERE '.$matches.' '.self::NEWEST_FIRST.' LIMIT ?) t GROUP BY type',
            [$config, $query, $now, $everyType, ...$languageBindings, $cap],
        );
        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->type] = (int) $row->matched;
        }

        return $counts;
    }

    /**
     * What an item of the language searched matches, with its bindings: the
     * default language's rows are those storing none, read only while the
     * site has more than one language (SiteLanguages::defaultOnlySql()).
     *
     * @return array{0: string, 1: list<string>}
     */
    private function matches(?string $language): array
    {
        return $language === null
            ? [self::MATCHES.$this->languages->defaultOnlySql('c.language'), []]
            : [self::MATCHES.' AND c.language = ?', [$language]];
    }

    /**
     * The types to search: the ones asked for, or all, but only those the
     * registry marks searchable, read now, so a type registered later counts.
     *
     * @param  list<string>|null  $requested
     *
     * @return list<string>
     */
    private function searchable(?array $requested): array
    {
        $searchable = array_values(array_map(
            fn (ContentType $type): string => $type->key,
            array_filter($this->types->all(), fn (ContentType $type): bool => $type->searchable),
        ));

        return $requested === null ? $searchable : array_values(array_intersect($searchable, $requested));
    }
}
