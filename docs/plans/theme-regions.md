# Plan: custom HTML and featured-post lists in theme regions

**One part, one branch: `themes/regions`, from `origin/main` after the v1 join (`join/v1-wave2`) merges.** v1 scope item C12. Proposal: `docs/proposals/theme-regions.md` (its Settled section is binding). **Bound by:** I-183 (no raw-HTML card), I-3127 (named snippets placed by a card, option A), I-288 and I-3375 (site code and its page policy), I-865 (site code kept exactly as written), I-228 and I-395 (regions), I-263 (page-cache tags), I-3360 (a region's language versions), rule 1 (Operations), rule 6 (purge after commit), rule 7 (nothing rendered is stored), rule 9 (IDs, never addresses), I-569 (lighter plan), I-3439 (American English).

**Written 2026-11-16** against `join/v1-wave2` at `cd11c630`. Task 0 checks that what this plan relies on is still there on `main`.

## What exists (read from the code, not the documents)

- **Regions are built** (1.6e): a theme declares `regions` (slug, `label`, `cards`, `max`) in `teme-theme.json`; `ManifestValidator` and `packages/teme-contracts/schema/teme-theme.schema.json` check it; `RegionDeclarations` reads it.
- **A region's content is content** of type `region` (`CoreTypes::REGION`, abilities all `regions.manage`), one row per theme and region, slug `{theme}--{region}`, made by `CreateRegion`, saved and published through the ordinary `SaveDraft`, `SaveAutosave` and `PublishRevision`, held to its declaration by `RegionRules` (inside `DocumentValidator`, through `DraftChecks` and again at publish).
- **The screens are built:** Content › Regions (`RegionsController`, `resources/js/pages/regions/index.tsx`, `RegionScreens`), the editor's region mode (`ContentEditorController` passes `kind: 'region'` and `allowedCards`; `pages/content/edit.tsx` filters the insert and slash menus to them), and the Themes screen's overview. v9 draws no other regions or widgets screen.
- **Drawing is built:** `<x-teme::region name="…" />` (`App\View\Components\Teme\Region`) renders the published revision through `DocumentRenderer`, and `Regions::published()` records `PageTags::region($slug)`. Publishing a region purges that tag after commit.
- **What is missing:** no card holds custom HTML, and no card lists posts. `SnippetCard` (`snippet`, label "Code") **shows code as highlighted text**; it doesn't run it. The v1 needs study counts row 23 as done because of `SnippetCard.php`, and that is wrong: I-3127's snippet placement was never built. No site-code entry can be placed anywhere but `head`, `body-start` and `body-end` (a database CHECK constraint holds it to those three).
- **What can be reused:** `SiteCodeEntries::on()` (entries that are on, by placement, each with its policy, cached and forgotten after commit), `SiteCodePolicy` (hashes and origins), `SiteCode::disallowed()` (`TEME_DISALLOW_SITE_CODE`), `SiteCodeWithheld` (the signed-in preview), `ChangesSiteCode` (password confirmed, security log, purge of every page after commit), `ListingViews::archive()`, `::inLanguage()` and `::items()` (published, live, not private items as `ListingItem`, tagging `content:{id}`), `PageTags::listOf()`, `::filedUnder()`, `::term()`, `RenderRecord`, `PagePolicy`.

## Owns

- New: `app/Domain/Cards/RegionCard.php` (marker interface), `app/Domain/Cards/Types/SiteCodeCard.php`, `app/Domain/Cards/Types/FeaturedPostsCard.php`, `app/Domain/Regions/FeaturedPosts.php`, `app/Domain/Feeds/Cards/PlainSiteCode.php`, `app/Domain/Feeds/Cards/PlainFeaturedPosts.php`, `resources/views/teme/cards/featured-posts.blade.php`, `themes/default/views/cards/featured-posts.blade.php`, `resources/css/cards/featured-posts.css`, `resources/js/components/editor/cards/site-code-card.tsx`, `resources/js/components/editor/cards/featured-posts-card.tsx`, one migration `database/migrations/2027_xx_xx_100000_allow_placed_site_code.php`, `tests/Feature/Regions/SiteCodeCardTest.php`, `tests/Feature/Regions/FeaturedPostsCardTest.php`, `tests/Feature/Regions/RegionCardsTest.php`, `tests/Browser/RegionCardsTest.php`.
- Changed: `app/Domain/SiteCode/SiteCode.php` (one place), `app/Domain/SiteCode/Operations/SaveSiteCode.php` (none expected: it already checks against `SiteCode::PLACES`), `app/Domain/SiteCode/Queries/SiteCodeScreen.php` and `resources/js/pages/settings/site-code.tsx` (the fourth place shows), `app/Domain/Cards/DocumentValidator.php` (refuses a region card outside a region), `app/Domain/Cards/CardMenuEntry.php` and `CardManifest.php` (`regionOnly`), `app/Providers/CardServiceProvider.php` (two cards), the feed cards' registration, `app/Http/Admin/Contents/ContentEditorController.php` (one prop in region mode), `resources/js/components/editor/insert-entries.ts`, `resources/js/components/editor/cards/card-views.tsx` (two entries each map), `resources/js/types/editor.ts`, `vite.config.ts` (one card stylesheet), `themes/default/teme-theme.json` (its region's `cards`), `themes/default/README.md`, `docs/guides/editors/writing.md`, and in the records task only `docs/architecture/themes.md` and the generated cards reference.
- Not owned: `components/ui/**` and `components/patterns/**` (used, never edited), the Regions screen, the editor shell, the import (`WidgetsStep` keeps listing widgets; I-348 stays parked), plugin region cards (I-3365, parked).

## Names

- Card `site-code`, label **"Custom HTML"**, description "HTML an administrator keeps in Settings › Site code, placed here by name. Editors choose it; only administrators write or change it." Attributes: `id` (node ID), `entryId` (required, a ULID, lowercase, the same pattern `ButtonCard` uses for `contentId`). Menu: "Custom HTML", group "Cards", keywords `html, code, ad, embed, widget, snippet`, icon `code-xml`, `regionOnly: true`.
- Card `featured-posts`, label **"Featured posts"**, description "A short list of posts: ones you choose, or the latest of a type, optionally filed under one category or tag." Attributes: `id`; `heading` (nullable string, max 120); `source` (`in:chosen,latest`, default `latest`); `items` (array, list, max 12, each a content ULID; default `[]`); `type` (string, default `post`); `termId` (nullable ULID); `count` (integer 1 to 12, default 5); `images` (boolean, default true). Menu: "Featured posts", group "Cards", keywords `posts, list, latest, recent, featured, widget`, icon `list`, `regionOnly: true`.
- `App\Domain\Cards\RegionCard`: an empty interface a `CardType` also implements to say it belongs in theme regions only.
- `CardMenuEntry::$regionOnly` (bool, default false), sent as `regionOnly` by `CardManifest::forBrowser()`.
- Site code place `placed`: `['label' => 'Placed with a card', 'where' => 'Nowhere by itself. Editors place it in a theme region with the Custom HTML card.', 'at' => 'for placing with a card']`.
- `App\Domain\Regions\FeaturedPosts`: `items(array $attrs): list<ListingItem>` (the list a card shows, its tags recorded).
- Views: core `teme::cards.featured-posts`; a theme replaces it with `theme::cards.featured-posts`. Both are given `heading` (`?string`), `items` (`list<ListingItem>`), `images` (bool).
- Editor prop in region mode: `placedCode: list<{id: string, name: string, on: bool}>` (names only, never the code); `null` outside region mode.

## Decisions

- **D1. Custom HTML is site code placed by a card, never HTML typed into content** (I-183, I-3127 A). The HTML lives in a `site_code_entries` row with the new place `placed`; the card stores only `entryId`. So the code is written only by administrators (`site-code.manage`), with the password asked again, logged before and after, and switched off everywhere by `TEME_DISALLOW_SITE_CODE`, exactly as today. Editors (`regions.manage`) choose an entry by name; they never see or change its code. Changing an entry changes every place it is shown.
- **D2. The code is not cleaned; its scripts are allowed by hash, as site code's are** (I-865, I-3375). `InsertionCleaner` is for plugin markup and removes inline scripts, which would break the ad units the sites place in sidebars (an inline `push({})` after a `<script src>`). The card renders the entry's code exactly as written and gives the page's policy the entry's `policy` from `SiteCodeEntries::on()`: `allowScriptHashes`, `allowHandlerHashes`, `allowOrigins` per directive, the same three calls `SiteCodeFragments` makes. **`disallow_site_code` fits and is reused:** while the host disallows site code, the card draws nothing and no entry can be changed. **`SiteCodeWithheld` is reused:** where the signed-in preview withholds site code, the card draws nothing.
- **D3. A placed entry is drawn only where a card places it.** `SiteCodeFragments` reads only the `Placement` enum's three values, so `placed` entries never reach the head or body by themselves. The card draws nothing for an entry that is off, deleted, not `placed`, or unknown; it never throws.
- **D4. The featured list is stored as data and drawn at render time** (rule 7, rule 9): IDs, a type key, a term ID, a count. Nothing rendered is stored. Chosen items show in the order chosen, only those published, live and not private (`ListingViews::items()`); latest items are the newest published of the type, in the page's language, optionally filed under the term, `count` of them.
- **D5. Caching is the page cache's own tags; no new tag, no new purge, no second cache.** `FeaturedPosts` records: chosen, `content:{id}` for each ID (done by `items()`); latest, `list:{type}`, plus `term:{taxonomy}:{id}` and `term:{id}` when filtered. The existing after-commit purges then cover every change: publishing, unpublishing, trashing or editing a post (`Footprints` purges `content:` and `list:`), refiling it or renaming the term (`term:`), republishing the region (`region:`), changing a site code entry (every page, `ChangesSiteCode::purgeIfDrawn()`). List tags set pages aside to be redrawn rather than removing them (`PageTags::isList()`), which is right for a sidebar.
- **D6. Both cards are region cards only.** They implement `RegionCard`; `DocumentValidator::validate()` refuses one when `$rules === null` (the document isn't a region's), with "This card goes in a theme's region only." The editor offers a `regionOnly` card only in region mode, and only where the region's `cards` list names it (as today for every card). Consequences: they never reach feeds, the Markdown or API versions, search or an article's insertion plan, because regions are none of those. `FeedCardsTest` still needs a plain version for every card, so each gets one that writes nothing.
- **D7. Permissions: none new.** Placing either card is editing a region (`regions.manage`, editors and administrators). Writing code is `site-code.manage` (administrators). No new Operation: the region's existing save and publish Operations carry the cards, and `SaveSiteCode` accepts the new place because it checks against `SiteCode::PLACES`.
- **D8. One migration, additive:** drop and re-add `site_code_entries_placement_check` with `'placed'` added. The column is already `string(16)`. The down migration moves any `placed` row to off and `body-end` before restoring the old check (so it never fails on data).
- **D9. A theme may replace the list's markup.** `FeaturedPostsCard::renderHtml()` renders `theme::cards.featured-posts` when the active theme has it, else `teme::cards.featured-posts`. The default theme provides one built on its own `partials.card`. This is a new, small theme socket; `themes.md` documents it in the records task.
- **D10. The default theme offers both cards** in its `after-content` region (`"cards": ["callout", "button", "site-code", "featured-posts"]`, `max` stays 5). It declares no sidebar: its layout has none, and a sidebar belongs to the site themes being built separately.
- **D11. Words a reader of the admin sees** (all through `__()`, American English): the card's empty states "Choose the code to show", "No code is placed with a card yet. An administrator adds it in Settings › Site code.", "This code was deleted. Choose another or remove the card.", "This code is off, so readers don't see it.", "The host has switched site code off, so readers don't see this."; featured posts "Choose up to 12 posts", "Latest", "Chosen", "Type", "Filed under", "How many", "Show images", "Heading (optional)".
- **D12. Not in this part:** leaving out the item the page shows (Joomla's `exclude_current`; needs the page's item in the render context), lists of comments, categories or archives, mapping WordPress widgets into regions (I-348), plugin region cards (I-3365), the cards in articles. Each goes to `docs/ideas.md` as a Later entry in the records task.

## The delicate code

### The site code card

```php
public function renderHtml(array $attrs, RenderContext $context): string
{
    if ($context->target !== 'web' || SiteCode::disallowed() || app(SiteCodeWithheld::class)->isWithheld()) {
        return '';
    }

    $entryId = (string) ($attrs['entryId'] ?? '');

    foreach (SiteCodeEntries::on()[SiteCode::PLACED] ?? [] as $entry) {
        if ($entry['id'] !== $entryId) {
            continue;
        }

        $policy = app(PagePolicy::class);
        $policy->allowScriptHashes($entry['policy']['scripts']);
        $policy->allowHandlerHashes($entry['policy']['handlers']);

        foreach ($entry['policy']['origins'] as $directive => $origins) {
            $policy->allowOrigins($directive, $origins);
        }

        // The page shows this entry: a change to it purges every page already (ChangesSiteCode).
        return '<div class="card-site-code">'.$entry['code'].'</div>';
    }

    return '';
}
```

`PagePolicy::start()` runs in `PublicPage::render()` before the template renders, and a region is drawn inside the template, so the policy calls land on the started policy. Task 3's test proves it on a real response's `Content-Security-Policy` header and on a page served from the page cache.

### The featured list's query

```php
public function items(array $attrs): array
{
    $views = app(ListingViews::class);
    $record = app(RenderRecord::class);

    if (($attrs['source'] ?? 'latest') === 'chosen') {
        $ids = array_slice(array_values(array_filter((array) ($attrs['items'] ?? []), 'is_string')), 0, 12);

        return $views->items($ids); // tags content:{id} for each, shown or not
    }

    $type = (string) ($attrs['type'] ?? 'post');
    $types = app(ContentTypeRegistry::class);

    if (! $types->has($type) || ! $types->get($type)->public) {
        return [];
    }

    $record->tag(PageTags::listOf($type));
    $query = ListingViews::inLanguage(ListingViews::archive([$type]), app(PageLanguage::class)->stored());
    $termId = $attrs['termId'] ?? null;

    if (is_string($termId) && $termId !== '') {
        $taxonomy = DB::table('terms')->where('id', $termId)->value('taxonomy');

        if (! is_string($taxonomy)) {
            $record->tag(PageTags::term($termId));

            return [];
        }

        $record->tag(PageTags::filedUnder($taxonomy, $termId), PageTags::term($termId));
        $query->whereExists(fn (Builder $sub) => $sub->selectRaw('1')->from('content_terms')
            ->whereColumn('content_terms.content_id', 'contents.id')
            ->where('content_terms.scope', 'published')
            ->where('content_terms.term_id', $termId));
    }

    $count = max(1, min(12, (int) ($attrs['count'] ?? 5)));
    $ids = $query->orderByDesc('contents.published_at')->orderByDesc('contents.id')->limit($count)->pluck('contents.id')->map(fn ($id): string => (string) $id)->all();

    return $views->items($ids);
}
```

Check against `ListingViews::forTerm()` before writing: if it filters terms by language or by the type's taxonomies in a way this misses, follow it and record a `B-` draft. The query is one `SELECT … LIMIT n` on the archive's indexes plus `items()`'s fixed few queries; no query per item.

## Tasks (tests first; each seen red for the reason given)

0. **Check the ground.** On `main` after the join: `RegionRules`, `Regions::published()`, `SiteCodeEntries::on()`, `SiteCodePolicy`, `SiteCodeWithheld`, `ListingViews::archive()`, `::inLanguage()`, `::items()`, `PageTags::filedUnder()` exist as named above; the `site_code_entries_placement_check` constraint lists three places; every caller of `DocumentValidator::validate()` that may validate a region's document passes `rules:` (grep `->validate(`). Anything different: record a `B-` draft; STOP if a decision would change. No commit.

1. **A fourth site code place.** Tests in `tests/Feature/SiteCode/SiteCodeOperationsTest.php` (added cases): an administrator saves an entry with place `placed` (password confirmed) and it is stored; an editor's attempt is refused and **writes nothing** (no row, no security log line); a `placed` entry that is on appears on no public page's head or body. `SiteCodeScreenTest`: the screen offers the fourth place with its words. Red: the CHECK constraint refuses `placed` (`SQLSTATE[23514]`). Then the migration (D8), `SiteCode::PLACED` and its `PLACES` row, the screen's option. Commit `feat(site-code): a place for code that a card puts in a region (I-3127)`.

2. **Region cards stay in regions.** `tests/Feature/Regions/RegionCardsTest.php`: a post whose document holds a `site-code` or `featured-posts` node is refused by `SaveDraft` with "This card goes in a theme's region only." and **writes nothing** (no revision, working pointer unchanged); a region whose declaration lists the card saves it; a region whose declaration doesn't is refused by `RegionRules` as today; `CardManifest::forBrowser()` gives both `regionOnly: true` and every other card `false`. Red: cards not registered. Then `RegionCard`, `CardMenuEntry::$regionOnly`, both card classes with attributes, `validate()` (chosen needs at least one item; `count` 1 to 12; `type` a registered public type), `plainText()` (the heading; nothing for site code), `references()` (chosen items as `CardReference::content`), `describeChange()`, registration, the empty feed versions, the validator's check. Commit `feat(regions): custom HTML and featured posts cards, for regions only`.

3. **Custom HTML draws safely.** `tests/Feature/Regions/SiteCodeCardTest.php`, on the default theme's published `after-content` region: a `placed` entry holding `<script src="https://ads.example/a.js"></script><script>window.x=1</script><div onclick="go()">Ad</div>` is drawn exactly as written; the response's policy holds the inline script's and the handler's hashes and `https://ads.example` under `script-src`; an entry that is off, deleted, or placed `head` draws nothing and no error; with `teme.disallow_site_code` true nothing is drawn; on the signed-in preview (withheld) nothing is drawn; the page served again from the page cache still carries the hashes; switching the entry off purges the page after commit and not on rollback. Red: `renderHtml()` returns `''`. Commit `feat(regions): draw placed site code with its hashes and origins (I-3375)`.

4. **Featured posts list.** `tests/Feature/Regions/FeaturedPostsCardTest.php`, a dataset over both sources: chosen keeps the chosen order and leaves out a draft, a trashed and a private item; latest gives the newest `count` of the type, newest first, in the page's language on a multilingual site (I-3360), only those filed under the term when one is set, none for a deleted term; the page records `content:{id}` (chosen), `list:post` and `term:category:{id}` (latest); publishing a new post filed under the term sets that page aside after commit and the redraw shows it; republishing the region purges it; the theme's `cards/featured-posts` view is used when present, core's otherwise; core's view escapes titles. Also a query-count check: the list costs the same number of queries for 1 and 12 items. Red: the card draws nothing. Then `FeaturedPosts`, both views, the card stylesheet and its Vite entry. Commit `feat(regions): featured posts, chosen or latest, cached by the page's own tags`.

5. **The editor.** **Before this task is built, a drawing of the two card views is shown to the maintainers** (see the proposal's "What the drawing shows"); build only what they accept, and record their answer. Tests: `ContentEditorController` in region mode sends `placedCode` with names and on/off and never `code`; outside region mode it sends `null`; `EditorSchemaTest` and the editor guide's completeness test pass with both cards (the guide's `writing.md` gains **Custom HTML** and **Featured posts**). Then `insert-entries.ts` offers a `regionOnly` card only in region mode, the two views and settings in `cards/`, built only from `components/ui/**` (`select-field`, `combobox`, `segmented`, `input`, `switch`, `label`, `notice`), `components/term-picker.tsx` and the link dialog's content search (`content.link-targets`), none of them edited. Red: `placedCode` missing. Commit `feat(editor): place custom HTML and featured posts in a region`.

6. **The default theme.** `tests/Feature/DefaultTheme/FloorTest.php` still passes with a region holding both cards; `ManifestValidator` accepts the manifest. The manifest's `after-content` lists both cards; its `views/cards/featured-posts.blade.php` uses `partials.card` with `context: 'thumb'`, `level: 3`, `showMeta: false`; its README names both. Red: the region refuses `featured-posts` ("The card \"featured-posts\" can't be used in this region."). Commit `feat(theme): the default theme's after-content region takes both new cards`.

7. **One browser test** (`tests/Browser/RegionCardsTest.php`, 1280 and 375): an editor opens Content › Regions › After the article, inserts Featured posts (latest, 3) and Custom HTML (a placed entry), publishes, and the article page shows both after the article; axe passes; no console CSP violation. Commit `test(regions): place both cards and see them on the article page`.

8. **Records.** `docs/architecture/themes.md` § Regions gains the two cards, D6 and D9; regenerate the cards reference (`php artisan teme:docs:cards`); `docs/ideas.md` gains the Later entries of D12 and the correction to the v1 needs study's row 23. Commit `docs(regions): custom HTML and featured posts cards`.

## Quick check

`php artisan test --compact tests/Feature/Regions`, then `tests/Feature/SiteCode`, `tests/Feature/Feeds/FeedCardsTest.php`, `tests/Feature/Cms/Cards`, `tests/Feature/Guides/Editors/EditorCompletenessTest.php`, `tests/Feature/DefaultTheme` (one path per run), `php artisan test --testsuite=Architecture`, `vendor/bin/pint --dirty`, `npm run types:check`. `composer check` once at the end, and `tests/Browser/RegionCardsTest.php` plus `tests/Browser/SiteCodeSettingsTest.php`.

## Records (the builder's)

Built in a Claude cloud session on 2026-11-16, from `origin/main` at `e35f71b` (the v1 join, `join/v1-wave2`, merged). `scripts/cloud-setup.sh` ran three times (05:50 and 05:51 stopped on a network error and a restart; the run from 06:00 finished at 06:05). Task 0: every name in "What exists" is on `main` as described, and the placement constraint listed three places.

### Commits on `themes/regions`

- `ef876cf` feat(site-code): a place for code that a card puts in a region (I-3127)
- `9694a86` feat(regions): custom HTML and featured posts cards, for regions only
- `6161f56` feat(regions): draw placed site code with its hashes and origins (I-3375)
- `452c1c0` feat(regions): featured posts, chosen or latest, cached by the page's own tags
- `0ea8b67` feat(editor): place custom HTML and featured posts in a region
- `14e3a5d` feat(theme): the default theme's after-content region takes both new cards
- `44922a0` fix(regions): featured posts' titles at level 2 with no heading, and no toolbar switch on a phone
- `ff2fabc` test(regions): place both cards and see them on the article page
- `1090aae` docs(regions): custom HTML and featured posts cards
- `2784649` fix(regions): the featured posts card names the list settings it draws
- this commit: docs(plans): theme regions' records

### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|
| 1 | `tests/Feature/SiteCode/SiteCodeOperationsTest.php` | `Error: Undefined constant App\Domain\SiteCode\SiteCode::PLACED`, then a `ValidationException` (the place not in `PLACES`); with the `PLACES` row and no migration, `SQLSTATE[23514]` as the plan predicted (B-01) |
| 2 | `tests/Feature/Regions/RegionCardsTest.php` | "Teme doesn't know the "site-code" block, so it can't save it." |
| 3 | `tests/Feature/Regions/SiteCodeCardTest.php` | 3 failed: no `<div class="card-site-code">` in the page, the preview check, a page-cache hit without `window.x=1` |
| 4 | `tests/Feature/Regions/FeaturedPostsCardTest.php` | `BindingResolutionException` (`FeaturedPosts` missing), and the card drew nothing |
| 5 | `tests/Feature/Regions/RegionCardsTest.php` (editor props) | "Property [placedCode] does not exist." |
| 6 | `tests/Feature/Regions/FeaturedPostsCardTest.php` (manifest line reverted for the run) | "The card "featured-posts" can't be used in this region." |
| 7 | `tests/Browser/RegionCardsTest.php` | axe on the article page: "Heading levels should only increase by one" (the default theme's list titles at h3 with no heading, B-11); at 375, the card head's Latest / Chosen switch lay over its More button (B-12) |

### Architecture baselines

| Baseline | Before | After |
|---|---|---|
| `tests/Architecture/admin-words-baseline.txt` | 876 lines | 876 lines |
| `tests/Architecture/design-system-baseline.txt` | 222 lines (count 144) | 222 lines (count 144) |

### The maintainers' answer on the drawing (Task 5)

Task 5 built from the approved drawing. The maintainers approved the drawing of both card views (note R1b, 2026-11-16) and Task 5 was built exactly as it describes: the Custom HTML card's column, toolbar and panel with its five states, and the Featured posts card's column, toolbar switch and panel, full width with no sideways scroll at 375. The approved words replace D11's where they differ (B-14).

### Gate

Before (`e35f71b`): Pint and PHPStan pass; Architecture 139 passed; Unit and Feature 11,577 passed, 2 skipped, 1 failed (`Plugins/DiscoveryTest`, which passes alone).

After (`1090aae`, full `composer check`, about 31 minutes): Pint, PHPStan and the type and lint checks pass; Architecture 139 passed (1,377 assertions); Unit and Feature 11,616 passed, 2 skipped, 2 failed. `Api/Items/BodyTest` ("never gives an embed card's provider markup") was real: it reads each core card's drawing code for its attributes' names, and the featured posts card passed `$attrs` on whole. Fixed in `2784649` by passing the list's settings by name; the file then passed alone (11), as did `tests/Feature/Regions` (76). `Plugins/LockOutTest` passes alone (10), so it is a parallel-run flake, like the base's `DiscoveryTest`.

Browser, after `npm run build`, one file at a time: `tests/Browser/RegionCardsTest.php` 5 passed (83 assertions), `tests/Browser/SiteCodeSettingsTest.php` 5 passed (49 assertions).

### Drafts

- B-01 (draft) Task 1's first `SiteCodeOperationsTest` case lists every place and label, so adding "its `PLACES` row" changes what it expects. Options: leave it red, or add `placed` to its lists. Chose to add `SiteCode::PLACED` and "Placed with a card" to its expected lists; it still proves the same thing. The first red was an `Error` and a `ValidationException` before the predicted `23514`. `ef876cf`.
- B-02 (draft) The plan gives no `since` for the two cards. Chose `'0.9.0'`, the next minor of the contracts. `9694a86`.
- B-03 (draft) Task order. `EditorCompletenessTest` fails as soon as a card is registered without a line in `writing.md`, and the region test needs the default manifest to list the cards; so `writing.md`'s lines and the manifest's `cards` moved to Task 2, and the default theme's view to Task 4 (D9's test needs it). Task 6 kept its red by reverting the manifest line for the run. `9694a86`, `452c1c0`, `14e3a5d`.
- B-04 (draft) Tests outside Owns that registering two cards breaks, each changed without changing what it proves: `CardRegistryTest` (+2 names), `EditorSchemaTest` (a document holding a region card is validated as a region's, with every card allowed, since D6 refuses it otherwise), `EditorScreenTest` (manifest cards 9 to 11), and two fixtures `tests/Fixtures/Documents/site-code.json` and `featured-posts.json`, which `editor-schema.mjs` reads for every card. `9694a86`, `0ea8b67`.
- B-05 (draft) `tests/Feature/DefaultTheme/FloorTest.php` isn't owned. Chose to check the floor (one h1, headings in order, every picture sized and described) with both cards in `FeaturedPostsCardTest`; `FloorTest` itself passes unchanged. `452c1c0`.
- B-06 (draft) The approved drawing has a state for "The host has switched site code off", which `placedCode` can't tell. Added one more editor prop in region mode, `siteCodeLocked` (`SiteCode::disallowed()`, `null` outside region mode), and put the names query in `SiteCodeScreen::placed()`. `0ea8b67`.
- B-07 (draft) The drawing's "Choose…" and "Change code" open the panel, but `card-frame.tsx` (not owned) has no way to open its panel from outside. Chose to remount the frame open (`key`) from the Custom HTML view; no shared file changed. `0ea8b67`.
- B-08 (draft) The Featured posts column, in Latest, shows the summary line but no titles, and no thumbnails in either source: no route lists a type's latest published items or gives an item's featured image, and the plan adds none. A term whose name is unknown on reopening shows "Latest :count :items, filed under one category or tag". `0ea8b67`.
- B-09 (draft) Open: `SiteCodeController`'s toast reads "Every public page includes "X" for placing with a card." for a placed entry, from the plan's `at` words; the controller isn't owned, so it is left for the maintainers.
- B-10 (draft) `resources/js/types/settings.ts`'s place unions don't name `placed` (not owned; `npm run types:check` passes). `site-code.tsx` needed no change: it draws every place it is given.
- B-11 (draft) Task 6 says the default theme's view uses `level: 3`. With no heading that puts an h3 straight after the article's headings, which axe refuses (heading-order). Chose level 3 under a heading and level 2 without one. `44922a0`.
- B-12 (draft) At 375 the card head has no room for the Latest / Chosen switch beside its own buttons, and covered the More button. Chose to hide the toolbar's switch below the `sm` width; the panel holds the same switch. `44922a0`.
- B-13 (draft) Task 8's `docs/ideas.md` isn't in this repository. Chose not to create it; its entries are below for the maintainers to carry over.
- B-14 (draft) D11's empty-state words and the approved drawing's differ ("This code was deleted, so readers see nothing here.", "The host has switched site code off, so readers see nothing here."). The drawing is the later decision, so its words are built. `0ea8b67`.

Open, not this part's files: a region's publish confirmation asks "Publish this item?", not "Publish this region?"; the browser test waits for the dialog rather than its words.

For `docs/ideas.md` (B-13), Later: leaving the page's own item out of a featured list (Joomla's `exclude_current`; needs the page's item in the render context); region cards listing comments, categories or archives; mapping WordPress widgets into regions (I-348); plugin region cards (I-3365); either card inside articles. Correction: the v1 needs study's row 23 counted as done because of `SnippetCard.php`, which shows code as text; I-3127's placed code is built only by this part.

### Contract changes

One editor prop beyond the plan's: `siteCodeLocked` (`boolean | null`) beside `placedCode` (B-06). `CardMenuEntry::$regionOnly` and the `regionOnly` manifest field are as named. No change to `packages/teme-contracts`.

### STOP

None.

### Changelog lines for the maintainers

- Added: a **Custom HTML** card for theme regions, which shows site code an administrator marks "Placed with a card" in Settings › Site code, exactly as written, its scripts allowed by the page's policy.
- Added: a **Featured posts** card for theme regions: chosen posts in order, or the latest of a type, optionally under one category or tag, kept current by the page cache's own tags.
- Added: a theme may replace the featured list's markup with `views/cards/featured-posts.blade.php`; the default theme's after-the-article region offers both cards.

### Try it

As an administrator, add an entry in Settings › Site code with the place "Placed with a card". As an editor, open Content › Regions › After the article (default theme), insert Custom HTML and choose the entry, insert Featured posts (Latest, 3), and publish. Any article shows both after its text. `php artisan test --compact tests/Feature/Regions`; `./vendor/bin/pest tests/Browser/RegionCardsTest.php` after `npm run build`.
