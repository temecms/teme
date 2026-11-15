# Plan: insertion points inside an article, and ads.txt

**One part, one branch: `render/insertion-points`, from `origin/main`.** Wave 2 of v1. Proposal: `docs/proposals/insertion-points.md` (its Settled section is binding). **Bound by:** I-064, I-697, I-3375 (site code's policy), rule 6 (purge after commit), rule 7 (nothing rendered is stored), I-569 (lighter plan), I-3439 (American English).

## Owns

- New: `app/Domain/Site/Insertions/**` (`InsertionPoints`, `InsertionPlan`, `PlanBuilder`, `InsertionCleaner`), `packages/teme-contracts/src/Insertions/**` (`InsertionContributor`, `Insertion`, `Position`, `ArticleFacts`, `InsertionsChanged`), `app/Http/Site/AdsTxtController.php`, `tests/Feature/Site/Insertions/**`, `tests/Feature/Site/AdsTxtTest.php`, `tests/Browser/InsertionPointsTest.php`, a fixture plugin `tests/Fixtures/plugins/inserts/`.
- Changed: `app/Domain/Cards/DocumentRenderer.php`, `app/Domain/Cards/RenderContext.php`, `app/Domain/Site/ArticleViews.php`, `app/Domain/Cache/PageTags.php` (one constant), `app/Domain/Site/Policy/PagePolicy.php` (insertion origins), `app/Domain/Fields/BuiltInFields.php` (one field), the Site code settings definition and `resources/js/pages/settings/site-code.tsx` (the ads.txt box).
- **Shared, additions only:** `routes/public.php` (one route; the plugin public routes part also adds lines there), `app/Domain/Plugins` Capability enum and `PluginServices::OFFERED` (one case each; parts A and B add theirs). Never edit or remove another part's lines.
- Not owned: the editor's JS, regions, feeds, the import.

## Names

- Contract (no `Illuminate` or `App\` types, rule 5): `Teme\Contracts\Insertions\InsertionContributor { precedence(): string; insertions(ArticleFacts $article): list<Insertion> }`; `Insertion { __construct(string $key, Position $at, string $html) }` (key as `Fragment::KEY_PATTERN`); `Position` (a final class with named constructors: `beforeContent()`, `afterContent()`, `afterParagraph(int $n)`, `everyParagraphs(int $n, int $max = 10)`, `beforeHeading(int $level, int $nth = 1)`, `afterHeading(int $level, int $nth = 1)`, `nearEnd()`); `ArticleFacts` (readonly: `contentId`, `type`, `termIdsByTaxonomy`, `language`, `authorIds`, `words`, `paragraphs`); `InsertionsChanged { notify(): void }`.
- Registry tag: `InsertionPoints::CONTRIBUTORS = 'teme.document.insertions'`, resolved like `Placements` (Precedence order, each key once).
- Capability: `content.insert` (consent label "Add content inside your articles, such as ads").
- Cache tag: `PageTags::INSERTIONS = 'insertions'`.
- Field: built-in `insertions_off` (boolean, label "No inserted content", settings rail).
- Setting: `site_code.ads_txt` (text, up to 64 KB, administrators only). Route name `ads-txt`.

## The delicate code: where points fall

`PlanBuilder::for(array $document, ArticleFacts $facts, list<Insertion> $insertions): InsertionPlan` maps each insertion to **the index of the top-level node it is drawn before** (`count($content)` means after the last). Count only top-level `paragraph` nodes for paragraphs and top-level `heading` nodes (by `attrs.level`) for headings:

```php
$paragraphs = []; $headings = [];            // top-level indexes, in order
foreach ($document['content'] ?? [] as $i => $node) {
    match ($node['type'] ?? null) {
        'paragraph' => $paragraphs[] = $i,
        'heading'   => $headings[(int) ($node['attrs']['level'] ?? 2)][] = $i,
        default     => null,
    };
}
$end = count($document['content'] ?? []);
// beforeContent → 0; afterContent → $end
// afterParagraph(n) → ($paragraphs[n-1] ?? null) + 1, none if missing
// everyParagraphs(n, max) → for k = n, 2n, … while k <= count($paragraphs) and drawn < max: $paragraphs[k-1] + 1
// beforeHeading(l, nth) → $headings[l][nth-1]; afterHeading(l, nth) → $headings[l][nth-1] + 1
// nearEnd → count($paragraphs) >= 3 ? end($paragraphs) : none
```

An index never falls inside a node, so cards, lists, tables, quotes and code blocks are never split.

`DocumentRenderer::html()` takes the plan through `RenderContext::$insertions` (nullable, default null). When it is set and `$context->target === 'web'`, the renderer flushes the prose run before each planned index (so a point between two paragraphs splits the run there) and writes the plan's cleaned HTML for that index; with no plan it behaves exactly as today. `ArticleViews` builds the plan only for a published article page (not a preview), skips it when `insertions_off` is on, records `PageTags::INSERTIONS` on the page when anything was drawn, and adds the insertions' origins to the page's policy through `PagePolicy`.

`InsertionCleaner` reuses site code's parsing (`SiteCodePolicy`): drop inline `<script>` bodies and every `on*` attribute; keep `<script src>`, `<iframe>`, `<img>` and their https origins for `script-src`, `frame-src` and `img-src`.

## Tasks (tests first; each seen red for the reason given)

1. **Contract and registry.** Tests: a contributor registered under the tag is asked with the article's facts; two contributors' insertions with one key keep the first by precedence; a plugin not granted `content.insert` is never asked (its contributor isn't bound). Red: classes missing.
2. **Where points fall.** `PlanBuilderTest`, a dataset: each `Position` against a fixed document (5 paragraphs, an h2, a list, an image card, a table) gives the expected index; a point never lands inside the list, card or table; missing positions draw nothing; `everyParagraphs` stops at its ceiling. Red: builder missing.
3. **Drawing.** `DocumentRenderer` tests: with a plan, the HTML holds the insertion exactly between the right blocks and is otherwise byte-identical to today's; with target `feed` or no plan, identical to today; the editor's JSON is untouched. Red: no plan support.
4. **Article pages.** Feature tests on a published post: the fixture plugin's insertion appears after paragraph 2; not on a draft preview, not in the feed, not in the `.md` version or the API; not when `insertions_off` is on; the page carries the `insertions` tag. Red.
5. **Safety and cache.** An insertion with an inline script and `onclick` is drawn without them; its `<script src="https://ads.example/…">` origin is in the page's `script-src`; `InsertionsChanged::notify()` inside an Operation purges `insertions` pages after commit and not on rollback. Red.
6. **ads.txt.** `/ads.txt` answers 404 while empty, then the saved text as `text/plain; charset=utf-8`, cached; an editor can't save it (refused, writes nothing); the Site code page shows the box. Red.
7. **One browser test** (`InsertionPointsTest`, 1280 and 375): the inserted block sits between the right paragraphs and passes axe.

## Quick check

`php artisan test --compact tests/Feature/Site/Insertions tests/Feature/Site/AdsTxtTest.php` and `vendor/bin/pint --dirty`; `composer check` once at the end.

## Records

### Commits on `render/insertion-points`

- `f4a631b` feat(insertions): contract, registry and content.insert capability — Task 1
- `94e1dea` feat(insertions): PlanBuilder maps positions to top-level indexes — Task 2
- `961d196` feat(insertions): DocumentRenderer draws a plan between top-level blocks — Task 3
- `3fe03d3` feat(insertions): draw plugin insertions on published article pages — Task 4
- `a5107e3` feat(insertions): strip unsafe markup and purge on InsertionsChanged — Task 5
- `eae62ea` feat(site-code): serve ads.txt from Settings › Site code — Task 6
- `55e5c57` test(insertions): browser check that insertions sit between paragraphs — Task 7
- `8e98961` fix(insertions): drop redundant array_values for author IDs — PHPStan
- `578ad93` fix(insertions): satisfy Architecture checks for ads.txt UI
- `0386408` test(fields): raise built-in field counts for insertions_off

### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|
| 1 | `tests/Feature/Site/Insertions/InsertionPointsTest.php` | `Target class [App\Domain\Site\Insertions\InsertionPoints] does not exist` |
| 2 | `tests/Feature/Site/Insertions/PlanBuilderTest.php` | `Class "App\Domain\Site\Insertions\PlanBuilder" not found` |
| 3 | `tests/Feature/Site/Insertions/DocumentRendererInsertionsTest.php` | `Unknown named parameter $insertions` |
| 4 | `tests/Feature/Site/Insertions/ArticleInsertionsTest.php` | `Class "App\Domain\Security\Elevation" not found` (wrong namespace while wiring; then missing draw path) |
| 5 | `tests/Feature/Site/Insertions/InsertionSafetyTest.php` | `Call to undefined method App\Domain\Cache\PageCache::get()` then CSP origin assert |
| 6 | `tests/Feature/Site/AdsTxtTest.php` | `Class "App\Domain\SiteCode\SiteCodeSettings" not found` |
| 7 | `tests/Browser/InsertionPointsTest.php` | `1 Accessibility issues found` (`landmark-complementary-is-top-level` on `<aside>`) |

### Architecture baselines

Unchanged (no lines added, no counts raised):

| Baseline | Before | After |
|---|---|---|
| design-system-baseline.txt | 222 | 222 |
| dsCounts() | R1 43, R2 71, R3 26, R5 33, R6 10, R7 2, R8 14, R9 3, R10 11, R14 5, R15 4 | same |
| admin-words-baseline.txt | 876 | 876 |

### Gate

`export PATH=/opt/node24/bin:$PATH && composer check` — exit 0

- Pint: passed
- PHPStan: OK, no errors
- Architecture: **139 passed** (1316 assertions)
- Unit+Feature (parallel): **10627 passed**, 2 skipped (54814 assertions)
- Browser (this part): `InsertionPointsTest` **2 passed** (1280 and 375)
- Note: an earlier full run once failed a flaky planner assert in `ListingViewTest` (`paths_one_current` vs `paths_target_type_target_id_index`); it passed twice alone and the gate re-run is green — unrelated to this part

### B-?? (draft)

1. **B-?? (draft)** Plan omitted `InsertionsServiceProvider` and binding contributors only when `content.insert` is granted; built like Placements / PluginLoader doors.
2. **B-?? (draft)** Plan said PagePolicy takes insertion origins; PathResolver builds the article before `PublicPage::start()`, so origins are deferred via `InsertionOrigins` and applied in `PublicPage` after policy start.
3. **B-?? (draft)** Plan named Capability / `PluginServices::OFFERED` only; also needed schema enum, PluginDoors/PluginWords, EnablePluginCommand, ManifestValidator, and PluginContractsTest updates so `content.insert` is a real granted capability.
4. **B-?? (draft)** Plan said Paths / reserved addresses keep `ads.txt`; added `ads.txt` to `Paths::SITE_ROUTES` (additions only).
5. **B-?? (draft)** Groups with `screen` refuse `UpdateSettings`; ads.txt saves through a dedicated `UpdateAdsTxt` Operation (as Discussion / Backups do).
6. **B-?? (draft)** Fixture used `<aside>` which axe flags inside `article`; switched to `<div data-test="insertion">` so Task 7 passes without changing Settled meaning.
7. **B-?? (draft)** `insertions_off` types use `CoreTypes::POST` / `CoreTypes::PAGE` (constants added) so NoTypeLiterals stays clean; editor/Fields/stored-field count tests raised by one for the new built-in.

### STOP

None.

### Changelog lines for the maintainer

- Insertion points inside published articles: plugins with `content.insert` contribute cleaned HTML between top-level blocks; `insertions_off` opts an item out; `InsertionsChanged` purges the `insertions` page-cache tag after commit.
- `/ads.txt` from Settings › Site code (administrators); 404 while empty; plain UTF-8 when set.
