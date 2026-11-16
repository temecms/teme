# Import v1 fixes: implementation plan

> **For agentic workers:** build task by task, tests first, each seen red for the reason given. Lighter plan (I-569): decisions, schema, names, permissions and tests are written out; the rest is described.

**Goal:** a site's other old addresses convert like its home host (#305), and tagDiv's `tdm_`/`tdc_` families are read; proved on real markup.

**Proposal:** [`docs/proposals/import-v1-fixes.md`](../proposals/import-v1-fixes.md) — its Settled section is binding.

**Branch:** `import/v1-fixes`, from `origin/main`.

**Bound by:** rule 1 (every change through an Operation), rule 9 (never the site's own absolute URLs), I-1702 (links by ID), I-336 (shortcodes), I-1704 (an active plugin's output kept), I-569, I-3317 (quick check per task, full gate once).

## Owns

- `app/Domain/Import/**`
- `app/Http/Admin/Tools/Import/**`
- `resources/js/pages/tools/import/**`, `resources/js/components/import/**`
- one migration `database/migrations/<date>_add_other_hosts_to_import_sources.php`
- `tests/Feature/Import/**`, `tests/Fixtures/Import/**`, and the import browser tests you touch

Nothing else. Another part (plugins writing content, plugin jobs) builds at the same time and owns `app/Domain/Plugins/**`.

## What main does today (confirm each with its line in Task 1)

- `ConversionSite::pageOf()` and `ownPath()` accept only `$this->host` (the home host, `www.`-less) and the uploads host.
- `ConversionSite` is made in three places: `ContentSite::for()`, `CommentsPhase` (about line 329) and `MenusPhase` (about line 169).
- `StartImportRun::OPTIONS` holds only `pause_ms`; run options are per run.
- `ShortcodePass::FAMILIES` is `['vc_', 'td_', 'tdb_', 'et_pb_', 'fusion_']`.
- The companion's analysis (`whole('analyze')['report']`) carries `sections.content.html.links.external_hosts` (a host → count map) in companion 0.2.x; older versions may not.

## Schema

```php
Schema::table('import_sources', function (Blueprint $table): void {
    $table->jsonb('other_hosts')->default('[]');
});
DB::statement("ALTER TABLE import_sources ADD CONSTRAINT import_sources_other_hosts_array CHECK (jsonb_typeof(other_hosts) = 'array')");
```

Model: `other_hosts` cast to `array` (a list of lower-case, `www.`-less host names).

## Names

- `ConversionSite::__construct(string $home, ?string $uploads = null, array $shortcodes = [], array $otherHosts = [])`; private `list<string> $ownHosts` = home host plus the bare other hosts. `pageOf()` and `ownPath()` test `in_array($host, $this->ownHosts)` (uploads host kept as now). New `public function hostKind(string $host): ?string` returning `'home'`, `'other'` or null, for the report's counts.
- Operation `App\Domain\Import\Operations\SetImportSourceHosts`, input `SetImportSourceHostsInput(string $sourceId, array $hosts, ?string $actorId)`; `RequiresElevation` when an actor is set (as `StartImportRun`); authorized by `AuthorizesImports::authorizeImporter`.
- Query `App\Domain\Import\Queries\SuggestedHosts::for(ImportSource $source): list<array{host: string, links: int}>`.
- Report counts: `ConversionReport` keys `links.other-host` and `images.other-host`, each counted by host.

## Validation (SetImportSourceHosts)

Each host: trimmed, lower-cased, `www.` removed, a valid host name (`filter_var($h, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)` and at least one dot), no scheme, path, port or user. Refused: the home host itself (same bare form); duplicates; more than 10. Sentences: "Enter a host name only, such as stage.example.com.", "That is this site's own address already.", "Ten other addresses at most." An empty list clears them.

## Tasks

### Task 1: confirm today's behaviour
Read each "What main does today" line and record its file:line in the plan's Records. Write a lasting test in `tests/Feature/Import/ConversionSiteTest.php` (or the existing site test): a link to `https://stage.example.com/a-post/` on a site whose home is `https://example.com` is not the site's page today (`pageOf` returns null). It goes green in Task 3 with the host listed.

### Task 2: the column and the Operation
Tests first (`tests/Feature/Import/SetImportSourceHostsTest.php`): an importer sets `['Stage.Example.com', 'www.old-example.com']` and the source holds `['stage.example.com', 'old-example.com']`; each refusal above by its sentence; the home host refused; an actor without the import permission is refused and **nothing is written** (the row unchanged); with an actor the password is asked (elevation), from the console it isn't. Then the migration, the model cast, the input and the Operation.

### Task 3: conversion reads the other hosts
Tests first (`ConversionSiteTest`, and a `ContentPhase`/converter feature test): with `other_hosts = ['stage.example.com']`, a link to `https://stage.example.com/a-post/` becomes a link by ID to the imported post; an image at `https://stage.example.com/wp-content/uploads/2026/01/a.jpg` becomes the library's file; `http://`, `www.stage.example.com` and a relative link behave as on the home host; a link to `https://example.org/` (not listed) stays an outside link; the second pass (I-333) resolves a not-yet-imported target on the other host. Pass `$source->other_hosts` into all three places `ConversionSite` is made. Count each conversion by host in the report.

### Task 4: suggestions and the screen
Tests first (`SuggestedHostsTest`): from an analysis whose `external_hosts` holds `stage.example.com: 143`, `cdn.example.com: 3`, `other.org: 50`, for a home `example.com`, the suggestions are `stage.example.com` (143) and `cdn.example.com` (3), most links first, never `other.org`; a host already listed is not suggested again; an analysis without that section gives none (older companion), with no error. Then, on the site's import page (`pages/tools/import/show.tsx`), a section "Other addresses of this site": the listed hosts, the suggestions as ticks with their link counts, a field to add one by hand, and Save (password asked). Every word through `__()`, American English. Browser test: tick a suggestion, save, it shows as listed; axe in light and dark at 1280 and 375.

### Task 5: tagDiv's other families
Test first (shortcode feature test): a post with `[tdm_block_column_title title_text="Hi"][/tdm_block_column_title]` and `[tdc_zone][vc_row]…[/vc_row][/tdc_zone]` converts as the existing tagDiv rows do (content kept, wrappers gone, said by name in the report); `[State]` and `[mg]` stay text. Then add `'tdm_', 'tdc_'` to `ShortcodePass::FAMILIES`.

### Task 6: real markup, in one test
Fixture `tests/Fixtures/Import/real-markup/` with short anonymized samples taken from the reports' markup examples (no site names, no personal data): a Yoast FAQ block, a Rank Math FAQ block, `simpletoc/toc` and `yoast-seo/table-of-contents`, two Canvas blocks, a WPBakery row with an image, a tagDiv row, a `core/shortcode` holding a registered app-store shortcode, and a paragraph with `[State]`. `RealMarkupTest` converts each and asserts its outcome (headings and paragraphs; dropped with its report line; flattened; unwrapped; Imported content; text kept) and that fidelity (`Fidelity::compare`) counts no loss for the dropped ones.

### Task 7: records and the gate
Fill Records; `composer check` once; the import browser tests you touched after `npm run build`.

## Quick check (after each task)

`php artisan test --compact tests/Feature/Import` and `vendor/bin/pint --dirty`.

## Records

### Commits (`import/v1-fixes`)

1. `f28e0e6` test(import): lasting proof a staging host is not the home today
2. `ee6e998` feat(import): SetImportSourceHosts and other_hosts column
3. `8948de0` feat(import): convert links and images on other hosts
4. `c280ccd` feat(import): SuggestedHosts and other addresses screen
5. `e0014dc` feat(import): recognize tagDiv tdm_ and tdc_ shortcode families
6. `fd2bc2b` test(import): real-markup fixtures prove FAQ, TOC, Canvas, shortcodes
7. `9b9b7ec` fix(import): clear PHPStan findings on other_hosts wiring
8. `6c00f58` fix(import): keep other hosts across password confirmation
9. `743e676` style(import): format OtherHostsForm after elevation remember
10. `76f8eb9` docs(plans): Import fixes's records
11. `7761842` fix(import): refuse SetImportSourceHosts while a run is running
12. `7483dc0` test(import): lasting proof a sibling host stays outside
13. `da50e3f` fix(import): let people untick a saved other address
14. `85ebdc5` fix(import): drop duplicate other hosts; remember on report
15. `f4533df` style(import): format OtherHostsForm; drop unused InputError
16. (this) `docs(plans): Import v1 fixes' records, follow-up R1`

### Follow-up R1

1. Refuse while running + form on plan/report only — first red: `Exception [Illuminate\Validation\ValidationException] not thrown.` (after fixing a missing `ImportRecords` import that failed first as `Class "ImportRecords" not found`).
2. Sibling host stays outside — lasting test green as written (home `example.com`, listed `stage.example.com`, `dev.example.com` link/image unchanged, no other-host counts).
3. Untick a listed host — feature omit-save green; form uses checkboxes for saved addresses; browser test added for unticking one.
4. Drop duplicates (B-05); hosts error once on the Add a host field; `rememberedHosts` on the show page for the report step — first reds: `Enter a host name only, such as stage.example.com.` (duplicate refusal) and `Property [rememberedHosts] does not exist.`

### Task 1 — what main did today (file:line at confirm)

- `ConversionSite::pageOf` / `ownPath` only home + uploads: `ConversionSite.php` (now `pageOf` :143, `ownPath` :176; then host checks used `$this->host`)
- `ConversionSite` made in `ContentSite.php:59`, `CommentsPhase.php:334`, `MenusPhase.php:174`
- `StartImportRun::OPTIONS` only `pause_ms`: `StartImportRun.php:42`
- `ShortcodePass::FAMILIES` was without `tdm_`/`tdc_`: now `ShortcodePass.php:21` includes them
- Analysis `external_hosts`: `SiteAnalysis.php:159` (images); links path in the companion report `sections.content.html.links.external_hosts`

### Each test seen red (first failure line)

1. Lasting `pageOf` null proof — green as written (today’s behaviour); goes green with other hosts in Task 3
2. `Target class [App\Domain\Import\Operations\SetImportSourceHosts] does not exist.`
3. `Unknown named parameter $otherHosts`
4. `Failed asserting that two arrays are identical.` (`SuggestedHostsTest` expected suggestions, got `[]`)
5. `Failed asserting that two arrays are identical.` (tdm_/tdc_ left as bracketed text)
6. `file_get_contents(.../real-markup/yoast-faq.json): Failed to open stream: No such file or directory`
7. Gate — PHPStan then formatting then full suite (see below)

### B-?? (draft)

- **B-01 (draft):** migration dated `2027_06_22_100000` so it runs after `create_import_sources` (`2026_11_21_…`). Weighed vs a same-day stamp that could sort before the create.
- **B-02 (draft):** SuggestedHosts / browser fixtures use `stage.old.example` (FakeCompanion home is `old.example`), not the plan’s illustrative `example.com`. Settled meaning kept: subdomain of home.
- **B-03 (draft):** `SuggestedHosts::for` reads cache only; `ImportAnalysis::read` calls `remember()`. Weighed vs re-calling analyze on the show page (would hit the old site twice / consume scripted failures).
- **B-04 (draft):** remember submitted hosts across elevation and click Save again after password confirm — same pattern as starting a run. Weighed vs in-place password (header) for this form.
- **B-05 (draft):** drop duplicate hosts after normalizing rather than refusing them (Follow-up R1). Weighed vs the plan’s original “duplicates refused” sentence.

### Architecture baselines

- admin-words: **876 → 876** (unchanged)
- design-system: **222 → 222** (unchanged)
- No baseline lines or pinned counts raised.

### Gate (`composer check`)

- Pint: passed
- PHPStan: OK (0 errors)
- types / `vp check`: passed (after format fix)
- Architecture: **139 passed**
- Unit+Feature (parallel): **10624 passed**, **2 skipped**, **1 failed** — `Tests\Feature\Media\MediaFiltersTest` › `it finds each month that has a live file uses the created index` (EXPLAIN expects `media_live_created`; passes alone, fails under `--parallel`; outside owns, unchanged by this follow-up). Assertions **54858**.
- Browser: other-hosts axe/save from Part C; untick browser test added (not re-run in this follow-up gate).

### Changelog lines (for the maintainer; CHANGELOG.md not edited)

- Import: other old addresses of a site (`other_hosts`) convert links and images like the home host; set via elevated Operation; suggestions from analysis subdomains on the site’s import page.
- Import: tagDiv `tdm_` and `tdc_` shortcode families unwrap like existing tagDiv rows.
- Import: anonymized real-markup fixtures prove FAQ, TOC drop (no fidelity loss), Canvas flatten, WPBakery/tagDiv unwrap, registered app-store shortcode as Imported content, and `[State]` kept as text.
- Import (R1): refuse other-hosts save while a run is running; listed hosts untickable; drop normalized duplicates; remember hosts on the report step.

### STOP

None. Parallel-only flake in `MediaFiltersTest` (media months EXPLAIN) noted above; not owned by this part.
