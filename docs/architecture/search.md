# Site search

Readers search the site's published text at `/?s=`, WordPress's own address, so links from an imported site still work (slice 1.6h). The admin's searches — the command palette and the list's **In text** search — are separate, and are described in `content-management.md`.

## The contract, and why it returns IDs

Search sits behind `Teme\Contracts\Search\SiteSearch` (spec §5, *Search stays in PostgreSQL*: "moves behind the same interface; nothing calls it directly").

```php
interface SiteSearch
{
    public function search(SearchRequest $request): SearchHits;
}
```

- `SearchRequest(query, page, perPage, ?types)`: `types` null means every type the registry marks `searchable`. A given list is narrowed to searchable types, never widened. A default list in the published contract couldn't change without breaking plugins, and a type registered later would never be searched. `language` (0.6.0) is the language searched, as an item stores it: null for the site's default. Core searches that language's items alone, in its own text-search rules (I-3360).
- `SearchHits(hits, total, totalCapped)`, each hit a `SearchHit(contentId, snippetHtml)`.

**It returns content IDs and snippets, never models,** so a plugin can bind another engine without knowing Teme's view models. Core turns the IDs into 1.6c's `ListingItem`s with `ListingViews::itemsById()`, in hit order.

Core binds `App\Domain\Search\PostgresSiteSearch` in `AppServiceProvider`.

## The query

Over `contents.search_tsv` (1.5b), which holds **published text only**: the title and the published revision's plain text, stemmed by the site language's rules (`SiteLanguage::searchConfig()`, I-435). The query **also** filters on `status = 'published'`, `deleted_at IS NULL` and the searchable types, so a stale row can't leak.

- **`websearch_to_tsquery`** reads what readers type: `"exact phrase"`, `-without`, `or`. Two words must both match.
- **A query of only stop words or punctuation** (`numnode(query) = 0`) returns no hits without searching. A blank query shows the form and runs nothing.
- The query is trimmed and cut to 200 characters.
- **Only the newest matches are ranked (I-458).** The candidates are the newest matches, as many as the total's cap (1,000), in published order; they're ranked by `ts_rank_cd` among themselves, then the newest first, then the ID. A closer match more than 1,000 matches back doesn't outrank newer ones: the trade-off accepted so that a search costs the same however large the site grows. Ranking every match would make a search cost more as the site grows.
- **A common word walks, a rare word looks up.** `contents_published_order` (published order across every type, published and live items; migration `2026_10_11_100200`) lets a common word walk its matches newest first and stop at the cap; a rare word goes through the GIN index on `search_tsv`. The planner chooses by how common the words are, so **the words are parsed in the match itself**, `websearch_to_tsquery(config, query)` written into the condition: read from a CTE the statement refers to twice, they'd be planned blind, and a word every item holds took the full-text index (B-312).
- **`ts_headline` runs on the page's rows only,** never on every match: it's the query's most expensive part.

### The total, capped

`count(*)` of the matches stops at `config('search.total_cap')`, 1,000: a precise count of a common word over a million rows is wasted work. It walks the matches newest first, as the ranking does. Past the cap the page says "More than 1,000 results", and pages end at `ceil(min(total, cap) / perPage)`; a later page is a 404.

## The snippet: escaped, then marked

`ts_headline` marks each match with two private-use characters, U+E000 and U+E001. `Snippet::fromHeadline()` **escapes the whole text first**, and only then turns the markers into `<mark>` and `</mark>`, so nothing in an article's text is ever markup. When the match was only in the title (`search_text` holds the text alone), it shows the text's first 35 words.

The snippet is the one raw output of search: the default theme's card partial prints it with `{!! !!}`, commented as such, and so does core's fallback `search` view.

## The page

- **`PathResolver::front()` checks for `s`**, after its canonical-form redirect and before the home-page rule, so a site whose home page is a page searches at `/?s=` too, and `/page/2/?s=` isn't the 404 that page's `/page/2/` is. `s` present, even empty, means a search; its intent is `search`, never the home page.
- **The view is 1.6c's `ListingView`**, the class every archive receives: a `Subject` of kind `search` ("Search results for “…”", or "Search"), the items in hit order, and pages at `/page/N/?s=…` (`Pagination` keeps the query on every page address). Only a search fills its last argument, `SearchDetails`: the query, each result with its snippet, the capped total, and whether the query was blank.
- A theme without a `search` template draws a search with its `archive` (study B §3d), which meets the one shape it always meets. The default theme's `search` template and core's fallback `search` read `$page->search`.
- The status is 200, even when nothing matched.

## Not indexed

A search page answers `X-Robots-Tag: noindex, follow`, and the document shell writes `<meta name="robots" content="noindex, follow">`, always: the robots meta is core's `RobotsMeta` entry in the head, and a search has no setting to show it (I-1034; `docs/architecture/themes.md`, *The head*). While the site discourages search engines the meta says `noindex, nofollow`, and outside production the header does, replacing the page's own (I-1022).

## Rate limited

Public search is the most expensive thing an anonymous reader can ask for. The `site-search` limiter allows **30 searches a minute per address**, and counts only requests with `s`; both public routes carry `throttle:site-search`, and an unlimited answer adds no headers. The public group has no session, so the cache store keeps the tally. Past the limit the answer is **429** with `Retry-After`, drawn by the theme's `error-429` template ("Too many searches. Please wait a minute and try again.") or its `error`.

## What isn't searched

Drafts, scheduled and trashed items; items of types that aren't `searchable`, regions among them; terms, authors and media. Searching terms or media, synonyms, "did you mean" and facets aren't built.

## At a million articles

`v0.1.0-beta.6`'s benchmark (`docs/benchmarks/v0.1.0-beta.6.md`) searches the public site through the HTTP kernel, with the default theme and no page cache. The p95s below are provisional (I-154): that run's machine was busy, and on the quiet machine just after, a common word's whole page took 240 ms (median), 108 ms of it in the database.

| Scenario | Matches | p95 (ms) | Budget |
|---|---|---|---|
| `public: search, rare word` (`kittiwake`) | 100 | 239.1 | 300 |
| `public: search, mid-frequency words` (five words together) | 5,444 | 404.1 | 300 |
| `public: search, common word` (`tidewater`) | 70,131 | 390.1 | 300 |
| `public: search, two words` (`tidewater harbour`) | 37,913 | 440.4 | 300 |

Before I-458 the common word took 2,781 ms, ranking every match. How much each search reads, swept on the same data (EXPLAIN ANALYZE of the ranking statement, warm):

| Matches | How the matches are found | Rows read | ms |
|---|---|---|---|
| 100 | the full-text index | 204 | 2.3 |
| 944 to 10,386 | the full-text index; the newest 1,000 kept | 1,890 to 20,795 | 147 to 471 |
| 19,841 | walked newest first to the cap | 30,928 | 65.7 |
| 37,913 to 376,649 | walked newest first to the cap | 17,959 to 1,800 | 25.7 to 50.9 |

No search reads more than about 31,000 rows, where the planner switches from the index to the walk. **What still grows with the site** is a search of many common words together: nine or ten of the seeder's words (944 to 1,897 matches) take 440 to 470 ms to rank, since the full-text index intersects one long list per word (B-313). WordPress searches a query of ten or more terms as one phrase instead (`class-wp-query.php`, `parse_search()`).
