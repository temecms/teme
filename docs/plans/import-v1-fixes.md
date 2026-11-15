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

(The builder fills this: commits; each test seen red; decisions as B-?? (draft) with options weighed; the gate's totals; changelog lines.)
