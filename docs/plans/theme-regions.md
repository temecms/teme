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

### Commits on `themes/regions`

### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|

### Architecture baselines

| Baseline | Before | After |
|---|---|---|

### The maintainers' answer on the drawing (Task 5)

### Gate

### Drafts

- B-01 (draft)
- B-02 (draft)
- B-03 (draft)
- B-04 (draft)
- B-05 (draft)
- B-06 (draft)
- B-07 (draft)
- B-08 (draft)
- B-09 (draft)
- B-10 (draft)
- B-11 (draft)
- B-12 (draft)
- B-13 (draft)
- B-14 (draft)
- B-15 (draft)
- B-16 (draft)
- B-17 (draft)
- B-18 (draft)
- B-19 (draft)
- B-20 (draft)

### Contract changes

### STOP

### Changelog lines for the maintainers

### Try it
