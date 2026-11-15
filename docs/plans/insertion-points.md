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

### Follow-up R4

Setup: `/tmp/teme-cloud-setup.log` absent at start (`none`); `pwd` `/home/user` (checkout `/home/user/teme`); PHP 8.3 and no PostgreSQL or Valkey, so `bash scripts/cloud-setup.sh` ran once; then PHP 8.4.27, `pg_isready` accepting, Valkey PONG. Branch `render/insertion-points` checked out from `origin/render/insertion-points` at `6c47d7b`.

#### Commits

- `8aae4c7` fix(insertions): clean scripts and unsafe addresses in every namespace — item 1
- `ce24b51` fix(insertions): keep plugin contributors for the whole process — item 2, first try (singleton); superseded by `992fcb4`
- `4e802b7` fix(insertions): hold everyParagraphs to ten insertions — item 3
- `992fcb4` fix(insertions): ask started plugins through LoadedPlugins, not a singleton — item 2
- `8b2a13a` fix(insertions): record a failing contributor as a plugin fault — item 4
- `110c65d` fix(insertions): purge insertions pages when an inserting plugin's settings are saved — item 5
- `9e440d6` fix(insertions): give the opt-out field to every type with search and social — item 6
- `2007520` fix(insertions): tag every article page that asks for insertions, and purge on plugin changes — item 7
- `864afe7` fix(insertions): count top-level nodes as the renderer does — item 8
- `4bd1583` fix(site-code): keep the ads.txt box editable while site code is disallowed — item 8

#### Tests seen red (first line of the failure)

| Item | Test | First failure |
|---|---|---|
| 1 | `InsertionSafetyTest` "removes a script without src in every namespace…" (5 cases) and "drops srcdoc and any address…" (11 cases) | `Expecting '<svg><script>alert("svg")</sc…</svg>' not to contain 'alert("svg")'` (and the same for each case). "keeps https addresses, script src…" passed from the start: it guards what the cleaner keeps. |
| 2 | `ArticleInsertionsTest` "draws the fixture plugin's insertion on a page warmed through PageWarmer…" | First `'BYPASS (degraded render)'`: a test fault, the `good` theme refuses the fixtures' Teme version once a fresh scope reads it again; the test now activates core's theme. Then, with InsertionPoints scoped and plugins bound at boot: the cached body lacked `<div data-test="insertion">Inserted</div>`. |
| 3 | `PlanBuilderTest` "draws everyParagraphs at most ten times…", case "a ceiling of 50" | `Failed asserting that two arrays are identical` (25 indexes). The case "the default" passed from the start: the default was already 10. |
| 4 | `InsertionPointsTest` "records a contributor that throws as a plugin fault…" | `RuntimeException: The ad server is down.` escaped `for()` |
| 5 | `ArticleInsertionsTest` "purges insertions pages after a plugin's settings are saved…" | `Failed asserting that an instance of class App\Domain\Cache\PageEntry is null` |
| 6 | `OptOutFieldTest` "gives the opt-out field to every type that supports search and social…" | `Failed asserting that an array contains '01K5Z0F1ED00000000000000XF'` |
| 7 | `ArticleInsertionsTest` "tags a published article page with insertions whenever contributors were asked…" | `Failed asserting that an array contains 'insertions'` |
| 7 | `ArticleInsertionsTest` "purges insertions pages after a plugin holding content.insert is disabled, and again after it is enabled" | `Failed asserting that an instance of class App\Domain\Cache\PageEntry is null` |
| 8 | `PlanBuilderTest` "counts top-level nodes as the renderer does…" | `Failed asserting that two arrays are identical` (index 2 for 3) |
| 8 | `tests/Browser/InsertionPointsTest.php` "keeps the ads.txt box editable while the host disallows site code…" | `Expected element [[data-test="site-code-ads-txt-save"]] to be present in the DOM` |
| 8 | `AdsTxtTest` "saves and serves ads.txt while the host disallows site code…" | Passed from the start: the server already accepted the save; it guards that half. |

#### B-?? (draft)

1. **B-?? (draft) Item 2, no new singleton.** R4 asked for `InsertionPoints` as a singleton. `tests/Architecture/NoStaticStateTest.php` fails for any singleton not on its list, and its rule says a new one needs the architect's reason there; a builder's edit to that list is refused. Options: (a) singleton plus one line in that list, by the architect; (b) keep `InsertionPoints` scoped and read the plugins started once a process from `LoadedPlugins` (already listed), asking those whose enabled row in `PluginRecords` grants `content.insert`. Chose (b) (`992fcb4`): the warmed page draws the insertion and no list widens. `PluginLoader` no longer binds contributors and is as on `main`; `InsertionPoints::bind()` stays for a contributor bound for one scope. A plugin disabled mid-process stops being asked at once, since its row is no longer enabled. If the architect prefers (a), it is the provider's one line and the list's one line.
2. **B-?? (draft) Item 3, clamp.** `Position::everyParagraphs()` holds a larger ceiling to the new `Position::EVERY_PARAGRAPHS_MAX` (10) rather than throwing, and `PlanBuilder` holds to it again. One contributor giving several everyParagraphs insertions under different keys can still draw more than 10 in all (left open).
3. **B-?? (draft) Item 4, owners.** A fault is recorded through `ListenerFaults` as `(plugin name, "{name}#insertions", InsertionContributor::class, failure)`; a tagged contributor's owner is its class. A `precedence()` that throws is caught and recorded too.
4. **B-?? (draft) Item 5, which plugins' groups.** Options: (a) every plugin's settings group carries `insertions`; (b) only a plugin whose class implements `InsertionContributor`. (a) makes `SettingsWriter` call `PublicChanges` for every plugin group, which fails `PluginSettingsReadTest` and `PluginSecretsTest` (they write plugin settings outside an Operation) and flips `DeclaredSettingsTest`'s `cacheTags` `[]`. Chose (b) (`110c65d`): `DeclaredSettings::group()` takes `$inserts`, which `Contributions::add()` sets. No other part's test changed.
5. **B-?? (draft) Item 6, which feature.** Options: (a) `seo`, which every public type with pages search engines read declares; (b) a new feature `insertions`, which every type would have to declare and which would need a new row, with new words, on the Types screen. Chose (a) (`9e440d6`): no new words, and code's and stored types that support search and social get the field. Effects: Fields lists it inside "Search and social" (`FieldsScreenTest` now "and 12 more", and the separate "No inserted content" row is gone), and `tests/Feature/Types/FieldsTest` lists `insertions_off` for a new `seo` type. The `CoreTypes::POST`/`PAGE` constants added for the old line are reverted. Where the editor draws the toggle is still the editor's (not this part).
6. **B-?? (draft) Item 7, which pages carry the tag.** Options: (a) only pages where at least one contributor was asked; (b) every published article page that isn't opted out. With (a), a site with no inserting plugin tags no page, so enabling the first one purges nothing and pages cached before never show its insertions. Chose (b) (`2007520`), which includes (a). Cost: saving an inserting plugin's settings purges every article page.
7. **B-?? (draft) Item 1, two additions.** The cleaner also removes SVG `animate`/`set` elements whose `attributeName` is `href` or `xlink:href` (they can set a `javascript:` href), and compares an address with every ASCII space and control character removed, as a browser reads `java\tscript:`.
8. **B-?? (draft) Item 8, ads.txt box.** Only the screen changed: the box and its Save button no longer follow `locked`. The host's notice above still says none of the code below can be changed; ads.txt isn't code, so the words were left as they are.

#### Contract changes

`Teme\Contracts\Insertions\Position` (named by the plan): a new constant `EVERY_PARAGRAPHS_MAX = 10` (`@since 0.8.0`), and `everyParagraphs()` holds its `$max` to it, as R4 asked. Nothing else.

#### Files outside the plan's Owns

As R4 named them: `app/Domain/Plugins/Contributions/DeclaredSettings.php`, `app/Domain/Plugins/Contributions/Contributions.php`, `app/Domain/Plugins/Operations/EnablePlugin.php`, `app/Domain/Plugins/Operations/DisablePlugin.php`. Tests whose expectations follow item 6: `tests/Feature/Admin/FieldsScreenTest.php`, `tests/Feature/Types/FieldsTest.php`. `app/Domain/Types/CoreTypes.php` and `app/Domain/Plugins/PluginLoader.php` are back as on `main`.

#### Architecture baselines

Unchanged: no baseline file touched, no line added, no count raised (design-system-baseline.txt 222, admin-words-baseline.txt 876, dsCounts() as before). `NoStaticStateTest`'s list unchanged.

#### Gate

`export PATH=/opt/node24/bin:$PATH COMPOSER_ALLOW_SUPERUSER=1 && composer check` at `4bd1583`: exit 0.

- Pint: passed; PHPStan: no errors; `types:check` and `vp check`: passed
- Architecture: **139 passed** (1316 assertions), as before
- Unit+Feature (parallel): **10654 passed**, 2 skipped (54863 assertions); before this follow-up, 10627 passed, 2 skipped (the records above)
- Browser, after `npm run build`, one file at a time: `tests/Browser/InsertionPointsTest.php` **3 passed** (1280, 375, and the ads.txt box); `tests/Browser/SiteCodeSettingsTest.php` (the screen this follow-up changed) **5 passed**

#### STOP

None. Item 2 is built another way than R4 named (B-?? 1).

#### Changelog lines for the maintainer

- Insertions: markup is cleaned in every namespace, SVG and MathML included (scripts without src, on* attributes, srcdoc, javascript: and data:text/html addresses).
- Insertions: warmed pages draw plugins' insertions; a failing contributor is recorded as its plugin's fault and skipped; everyParagraphs draws at most 10.
- Insertions: every published article page carries the `insertions` tag; enabling or disabling a plugin with `content.insert`, or saving its settings, purges those pages after commit.
- "No inserted content" is on every type that supports search and social.
- Site code: the ads.txt box stays editable while the host disallows site code.

#### Try it

Enable a plugin with `content.insert` (the `tests/inserts` fixture places a block after paragraph 2) and open a published post: the block is there, and stays there on a page `teme:deploy` warms. Disable the plugin and the page is drawn afresh. With `TEME_DISALLOW_SITE_CODE=true`, Settings › Site code still saves ads.txt, and `/ads.txt` serves it.
