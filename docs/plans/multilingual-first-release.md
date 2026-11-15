> **Status (2026-11-15):** the 2026-11-11 plan for multilingual's first release. Part 6.1a is built and on `main`. Its parked parts are now built for v1 by two newer plans that point at its tasks: `docs/plans/multilingual-readers.md` (6.1c, 6.1e, 6.1f's G tasks) and `docs/plans/polylang-import.md` (6.1h, changed). Where they differ, the newer plans win.

# Slice 6.1: One Site in Several Languages, the First Release

**Re-checked for 6.1a on 2026-11-12 against slice/4.5 `09f3b6e9`: 21 corrections**, each marked *(re-check)*. **BLOCKING: none.** The most serious: page parents' fast address path builds a stub item with no `language` (`SubtreeAddresses.php:251`), so a French page's children would lose their prefix when it moves (A1); the lifecycle Operations take the tree lock through `FollowsTree::takeTree()`, not `TreeLock::take` (C0's grep would have stopped the builder); a fifth per-row language caller (`ItemQuery.php:157`, the API's `ancestors`); the trail's Home (A3); the shared files with privacy, notices, public CSP and block IDs (new section after *Merge order*). One question for the maintainers, not blocking: the CSP plan says 6.1a owns `PublicPage.php` and merges first, the brief for this re-check says 6.4a goes first there; either order works (*Shared files with the parts built now*).

**Cut to what ships, 2026-11-11 (I-3372; I-3373 (1) to (3) and (6)): only Part 6.1a ships, with Task A5's guards.** There is **no form to add a language** (Task C2's test proves it; the screen is stage 3). A page with a parent or pages under it can't change language: refused, no longer provisional. An untranslated taxonomy's archive lists only default-language items. **Parked for stage 3:** 6.1c, 6.1e, 6.1f, 6.1h, 6.1i and 6.1z, each with a one-line note at its head; **6.1f's admin-words tasks (F1 to F4, with D21) moved to the admin-words plan** (I-3373 (6)), the rest of 6.1f is parked. Moved into 6.1a from parked parts: search per language and its rebuild (6.1c's S1, S2, already), the default-language predicates (from 6.1c's H3, S3, S4 and 6.1f's G4), the changelog (6.1z's Z5, as T2) and the tag. **Merge order:** page parents (4.5) merges into `main` first; 6.1a builds after 4.5a merges, or beside 4.5b (*With page parents*).

**Reviewed for 6.1a on 2026-11-11 (the parallel parts not reviewed: the maintainers parked 6.1c, 6.1e, 6.1f, 6.1h, 6.1i and 6.1z mid-review): 24 corrections**, each marked *(review)*. **The three blocking points are settled (2026-11-11):** (1) page parents (4.5) merges first and owns the shared files: *With page parents* below and the C0 lines say what 6.1a adds after it and the lock order; (2) with the other parts parked, 6.1a carries the guards in *While the other parts are parked* (search, its reindex, sitemaps, `TermsSitemap`, `llms.txt`, `.md`, feeds, the API, regions, menus) as Task A5, listed and confirmed there; (3) the maintainers decided 6.1a ships with no screen to add a language (I-3373 (1); *Maintainers' decisions*, at the end of that section).

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Re-cut again 2026-11-11 under I-3371 (the part that goes first carries what the others build on or extend): 7 parts, was 10.**
- 6.1b (translations) folded into 6.1a, which now holds the languages and the schema, addresses and routing, the core of translations, the content and term queries by language, and the new per-type switch (D25, T1).
- 6.1g (settings, menus and regions per language) into 6.1f (the admin's words foundation): both are what the screens' words are built on, and the screens are their one consumer.
- 6.1j (editor and list screens) into 6.1i: one screens part, waiting for the six pictures.
- After 6.1a, 6.1c, 6.1e, 6.1f and 6.1h run side by side; 6.1i follows its pictures; 6.1z stays, for the seams between 6.1c, 6.1e and 6.1f.
- The maintainers' answers are recorded under *Questions* (I-3370): Q7 changed to an AI check; the per-type switch added.

**Re-cut 2026-11-11 under I-3367 (parts only where the work is truly separate): 10 parts, was 12.**
- 6.1-0 (the contract) and 6.1a (addresses and site languages) into one first part, 6.1a: 6.1b, 6.1d, 6.1f, 6.1g and 6.1h read prefixed addresses or `/fr/` routing in their own tests, so addresses aren't separate from what every part reads; the stand-ins for routing and for prefixed addresses are dropped.
- 6.1d (search, feeds and the API) into 6.1c (head, view data and sitemaps), as 6.1c: the API's `translations` and `.md` pages read 6.1c's `Alternates` and `ListedItems` statements.

**Written 2026-11-11** by the planner (Opus, I-3330: this release changes how content is stored and addressed). Eight questions for the maintainers (Q1 to Q8), each planned on its recommendation. **Not yet reviewed.**

**Written against** `teme` `origin/main` at `525c51b5`, read in `teme-review` with `git show` and `git grep` only, and three branches planned as if merged: `origin/slice/4.1` at `de5237a3` (marked `[4.1]`), `origin/slice/3.1` at `82e7355f` (`[3.1]`), `origin/slice/5.1` at `efdb06ee` (`[5.1]`); *(review)* **and page parents, `slice/4.5`, merged before it** (`[4.5]`, not built when this was written: read `[A] docs/superpowers/plans/2026-11-11-page-parents.md`, its D2, D5 to D9 and D22, and, at C0, its merged code). `[A]` is `teme-architect` on `docs/architect`; `[R]` is `teme/Reference/`. **The integration branch `slice/6.1` is made from `main` only after 4.1, 3.1 and 5.1 (with the plugin join) have merged into `main`, and from page parents' code: `main` once page parents has merged, or `slice/4.5` once 4.5a has merged into it** (*With page parents*, *Merge order*). Each part's Task 0 re-reads every cited place by its text and STOPS if it differs.

**Bound by:**
- **I-3360** (the maintainers, 2026-11-11): each translation is its own item with its own ID, address, revisions and comments, carrying a language and linked to its siblings by a translation group; a `/xx/` prefix for other languages and none for the default; a domain per language later; the admin in other languages gets **only its foundation**, followed by new screens from now on, existing screens converted later, no admin language translated until a site owner needs one; right-to-left fixed for readers, the admin's parked; shared fields later; comments per version; content in any language a site adds; Teme's own reader-facing words in English, Spanish, French, Brazilian Portuguese and German.
- **The study** `[A] docs/studies/2026-11-11-multilingual.md`: §4 (a1) decided; §4.4 (add only), §4.6 (the importer's groups), §5.1 (addresses A, fallback), §5.2 (one catalogue keyed by English), §5.3, §5.4 (core, plugin, theme), §6 (what the promises left room for), §7.1 and §7.2, §8's decisions, §9's flags.
- I-3365 (multilingual is the first core foundation after plugins), I-3362 (block IDs: a translation keeps its source's block IDs), I-3363 and I-3368 (page parents: *(review)* merged **before** this slice, owning the files both touch; a French page's parent is the French version of its parent; an address holds only parents a reader can see; page parents' D22 lists what this slice adds), I-042, I-151 (sort language: later), I-162 (admin translation: its foundation now), I-306 (`hreflang`), I-324 (machine translation: a plugin later), I-331 and I-351 (imported groups), I-435 (Site language), I-3187 (theme and hook contracts are a preview until 1.0), I-3323 (small releases), I-3367 (parts only where the work is truly separate) and I-3345 (side by side), I-569 (lighter plans), I-797 (comments cite one I-number or path), I-749 (the WordPress audit), I-468 (design pass first), I-3355 (v9 first).
- `AGENTS.md` rules 1, 2, 3, 4, 5, 6, 7, 9; `.ai/rules/{operations,migrations,models,tests,http,contracts,builders}.md` (contracts: a value class grows only at its end, with a default, `[5.1]`).

**Drafts:** `I-?? (draft)` and `B-?? (draft)`; the architect numbers them. **New dependencies: none** (`intl` is on the image, `Dockerfile:34`). **Permissions: none new** (Q4). **`.ai/` edits: none.** **Contract additions** (`teme/contracts`, `@since 0.6.0`, each at the end with a default): `SearchRequest::$language` (6.1a, Task A5); *parked with 6.1f:* `Setting::$translatable` and `Setting::translatable()`, `SettingsChanged::$language` `[5.1]`.

---

## What this slice does

Today a Teme site has one language, `general.language` (I-435): it sets the page's `lang`, dates and search. `SiteLanguage::forContent()` answers every item with the site's tag (`app/Domain/Site/SiteLanguage.php:46-52`). `App::setLocale` is never called, so core's own reader-facing words stay English whatever the language (#368); the default theme's labels are written in English (#369); the page has no `dir` (#367) and no `og:locale` (#370).

**What ships now (6.1a only, I-3372):** the foundation. Items and terms carry a language and a translation group; other languages' addresses carry a prefix and are routed; a hidden language answers nothing; the Operations to add, change and remove a language, to translate, link, unlink and move an item, and to choose which types are translated; and every reader-facing answer no shipped part teaches about languages gives the default language only (A5). **On a site with one language nothing a reader sees changes, and no screen adds a second** (Settings › Languages is a placeholder; a language is added only through A4's routes or Operations by hand). The list below is the whole slice; everything past 6.1a's share is parked for stage 3.

**After the whole slice** (6.1a with the parked parts) a site publishes an article in English at `/about/` and in French at `/fr/a-propos/`:
- each version is an ordinary item: its own ID, slug, address, status, schedule, revisions, comments and search words, carrying `language` (NULL for the default language) and a `translation_group`;
- **Settings › Languages** adds French with the prefix `/fr/`, shown to readers or hidden while it is translated; nothing at an existing address moves;
- in the editor, **Translate into French** makes a French draft copied from the English, its categories and links pointed at their French versions; the **Translations** panel lists every version with its state and an **Out of date** mark worked out from the revision it came from;
- `/fr/` is the French home, `/fr/category/actualites/` a French archive, `/fr/?s=` searches French with French stemming, `/fr/feed/` the French feed;
- each page carries `<html lang="fr" dir="ltr">`, `hreflang` alternates with `x-default`, `og:locale` and `og:locale:alternate`; the sitemap carries `xhtml:link` alternates;
- the site title, tagline and title templates, the menus in each theme slot and each region have a French version, falling back to the default language's where none is written;
- core's reader-facing words and the default theme's labels are French on a French page, in five shipped languages;
- the read API adds `?language=`, `translations` on an item and `languages` on the site, as additions under its D14; its lists keep holding the default language's items;
- an imported Polylang site's remembered groups are linked by one action, each translation taking back its old address where it is free;
- the admin gets its translation foundation: one catalogue for PHP and React words, `__()` in TypeScript, a person's admin language (English only for now), a pseudo-locale and a check that **new** screens' words go through it; this release's new screens are the first to use it.

**How the architect tries 6.1a** (no screen; tinker as the operator, or the test fixture): `AddSiteLanguage` French with prefix `fr`, shown; `TranslateContent` a published post into French; `SaveDraft` and `PublishRevision` the draft: `/fr/{slug}/` answers in French with `Content-Language: fr`; `/` and the default language's lists, search, feeds, sitemaps and API answer exactly as before; `ChangeSiteLanguage` hides French: `/fr/{slug}/` answers 404. `GET /admin/settings/languages` shows the placeholder, with no form.

**How a person tries it, after 6.1z** (parked with 6.1i and 6.1z; an administrator):
1. Settings › Languages › **Add a language**: French, prefix `fr`, *Show it to readers now* ticked.
2. Open a published post › Translations › **Translate into French**: a French draft opens, title and body in English, ready to translate.
3. Translate it, **Publish**: `/fr/{slug}/` answers in French; the English page's head lists it under `hreflang="fr"`.
4. Edit and publish the English post again: the French row in the panel says **Out of date**.
5. Settings › General › Language: **French**: write the French tagline; `/fr/` shows it.
6. Visit `/fr/`: the comment form's words are French; `/` stays English.

**Goal:** a publication in several languages whose every version is a first-class item, addressed, searched and announced in its own language, built by adding to what stands, never by changing what a stored row or a promise means.

**Tech stack** (from the locks at `525c51b5`; restate at each Task 0): Laravel 13.39.0, PHP 8.4 with `intl`, Pest 5, PostgreSQL 18, Inertia 3.9 with React 19.3, Tiptap 3.32.

**Written the lighter way (I-569):** decisions, names, signatures, every migration, the permissions, the tests as `it('…')` sentences and the delicate code (the migrations, how a NULL language is read, the routing order, the lock order of a group, the out-of-date query, the search's per-row configuration, the settings overlay, the union of imported maps) are written out; the rest is described and is the builder's.

---

## The part map

The phase number: **6** (Q8 A): roadmap phase 6 is Distribution (`v1.0.0`); multilingual comes before 1.0 (I-3360), so it takes phase 6 and `v0.6.0`, and Distribution becomes phase 7, still `v1.0.0`. Slice `6.1` is free (`docs/roadmap.md` on `main` lists no phase 6 slice as started). Tags: `v0.6.0-alpha.1` after 6.1a merges into `main` (6.1z, which was to tag, is parked; the architect's call at the merge).

| Part | Branch, from | What it delivers | Why it is separate from the others | Waits for | Runs beside | Model |
|---|---|---|---|---|---|---|
| **6.1a** the foundation (C0 to C5, A1 to A5, B1 to B6, T1, T2; A5 added in review) | `slice/6.1a-foundation`, from `slice/6.1` | The migrations; `SiteLanguages`, `PageLanguage`, the `languages` settings group and its placeholder screen; `forContent()` reading the row; slugs per language; `GroupLock`; `PageTags::translations()`; `config/languages.php`'s `native`, `direction` and `og`; the route files; the fixture; the prefix in addresses; routing `/xx/`; listings, related posts and the front page and 404 per language; links to hidden languages; `AddSiteLanguage`, `ChangeSiteLanguage`, `RemoveSiteLanguage`, the conflict check, the banner, the default-language rule; drafts and terms in a language; `TranslateContent`, `LinkTranslations`, `UnlinkTranslation`, `ChangeContentLanguage`, `MarkTranslationUpToDate`; the lifecycle Operations' group rules and purges; the out-of-date query; the editor session's, the lists' and the export's data; which types are translated (D25); *(I-3372)* the default-language guards (A5); the changelog (T2) | The first part (I-3371), and the only one that ships now (I-3372): every other part reads or extends it. The content and term queries the reading side reads by language (listings, terms per language, a group's members) are here; `Alternates` stays in 6.1c, the only part that reads it; `ListedItems` takes A5's predicate here | 4.1, 3.1 and 5.1 merged into `main`; page parents' 4.5a merged (*With page parents*) | page parents' 4.5b, if started after 4.5a | **Opus** (the schema, addresses, the group's locks) |
6.1a, 6.1d, 6.1f and 6.1i are merged into 6.1a, 6.1c, 6.1f and 6.1i; the other letters are kept so earlier references hold.

**Order:** 6.1a only, once 4.5a has merged (*With page parents*, *Merge order*); page parents merges into `main` before it. Nothing else runs in this slice now.

**Parked for stage 3** (I-3372, 2026-11-11; each part keeps its text below, with a one-line note at its head; when stage 3 builds them, they run side by side after 6.1a as this plan first cut them, 6.1i once its pictures are adopted, then 6.1z):

| Part | Trigger | What 6.1a took from it |
|---|---|---|
| **6.1c** what readers get in each language | a site turns on a second language and needs its pages' `hreflang`, `og:locale`, alternates, and its sitemaps, search, feeds and API in that language | S1 and S2 whole (A5, C3); H3's, S3's and S4's default-language predicates (A5) |
| **6.1e** reader words | a site's readers need Teme's own words in a language other than English (a second language, or a one-language site in Spanish, French, Portuguese or German), or a right-to-left site launches (#367, #368, #369) | nothing |
| **6.1f** words per language | its F1 to F4 (and D21) **moved to the admin-words plan** (I-3373 (6)); G1 to G5 parked, trigger: a site turns on a second language and needs its title, tagline, title templates, menus or regions in it | G4's default-language region predicate (A5) |
| **6.1h** import: linking remembered translations | a Polylang site is imported, or one imported before turns on a second language, and its remembered translations need linking | nothing |
| **6.1i** the screens | a site owner needs to add a language or translate from the admin (stage 3's screens, with their pictures P1 to P6) | nothing; 6.1a's placeholder stays a placeholder (C2's test) |
| **6.1z** the join | the parked parts are built | Z5's changelog, as 6.1a's T2; the leak checks run with the page cache on in A3 and A5 until Z2 |

About **12 to 13 builder days** for the whole slice (6.1a, by its tasks, is about half), against the study's 12 to 15 plus 2 for the admin (§7.1, §7.2): the API is a day's work, not half (it carries the musts the study did not count, below); the admin foundation is one day because the existing screens are not converted (I-3360). In calendar time, now: 6.1a alone, its length set by its size; the side-by-side parts, the screens and the join wait for stage 3.

**The order, the seams and what stays in order:** *Building side by side*, after *Global Constraints*.

---

## Where the documents and the code disagree

1. **The study's index is `(type, language, slug)`** (§4.4). This plan builds `(type, slug, language)`: uniqueness is the same, and the previous image's slug search (`Slugs::unique()`, `WHERE type = ? AND (slug = ? OR slug LIKE ?)`, `app/Domain/Contents/Slugs.php`) still walks it, so the image before this one runs at full speed while both are live. The same for terms.
2. **The study sets a new group's ID to the source's own ID** (§4.3, after EmDash). This plan mints a fresh lower-case ULID: a group ID never names an item, so deleting the first version leaves no group named after a missing row, and nothing mistakes a group for an item.
3. **The study adds `navigations.language`** (§4.3, §7.1). Menus here carry no language: a theme slot names a menu **per language**, through the slot's setting made translatable (D14). That is Polylang's own model (`P/src/Options/Business/Nav_Menus.php:20-30`: theme → location → language → menu, the menu itself languageless), and it needs no column, no screen for a menu's language, and no rule tying a menu's items to it.
4. **The study's §4.4 point 3 has a job write the tag into existing rows when a second language arrives.** This plan never does: **NULL is the default language**, permanently in this release (D1), so no row is rewritten, and changing the default while other languages hold rows is refused (Q1).
5. **The study's prefix is the tag.** This plan makes each language's prefix its own setting, the lower-case tag by default (`pt-br`), changeable (D6): an imported Polylang site's French is often `/fr/` but its Brazilian Portuguese `/pt/` or `/br/`, and keeping the old prefix keeps its addresses.
6. **`[4.1]` `ComposerRegistry::compose()` passes `SiteLanguage::tag()` to `ThemeContext`** (`app/Domain/Themes/ComposerRegistry.php:154`), while `ViewContext::locale()`'s docblock says "the locale of the page being drawn". 6.1c makes the code say what the contract says.
7. **`[3.1]` `/items` and `/terms` have no language predicate** (`app/Domain/Api/Live.php`, `Catalogue/TermsQuery.php`): every item is in the default language today, so D5's promise holds by accident. ~~6.1c~~ *(review)* **6.1a** adds the predicate (Task A5), before any translation can exist on a released site: 6.1c is parked. It is no change of meaning but the enforcement of one: `[3.1]`'s D14 lists "a list in the site's default language" among what never changes within v1, and its D5 says a list holds the default language's items. `/items/{id}` and `by-path` keep answering any shown language (3.1's own words), with the hidden-language predicate in `Live::apply()`.
8. **The study's §7.1 files a translation under "the target language's sibling terms where they exist".** A term with no French version leaves the French post unfiled; Q6 asks whether Translate makes the missing French terms.
9. **The study counts 47 `__()` in `resources/views`** (§2.5). Reader-facing words are more: 109 distinct keys across core's views and the PHP a reader meets (the comment form's refusals, the respond page, the password form, the head's titles), plus about 45 in the default theme and its fallbacks. 6.1e's list is the measured one.
10. **`ReindexSearch` compares every row with the site's one configuration** (`app/Domain/Contents/Operations/ReindexSearch.php`, `waiting()`). With languages each row is compared with its own language's (~~6.1c~~ *(review)* **6.1a, Task C3**: C3 makes `SearchText::sync()` build a French row with `french` through `forContent()`, so the old `waiting()` would count it as waiting for ever, and `ReleaseRebuilds` runs the reindex until `remaining` is 0; the two changes ship together).
11. **The study's `source_revision_id` has no index.** A foreign key `ON DELETE SET NULL` without one makes every permanent delete of an item scan `content_revisions` once for each of its revisions; M3 indexes it.
12. **`CreateDraft` refuses a second item of a single type** (`refuseSecondItem()`, `[5.1] app/Domain/Contents/Operations/CreateDraft.php`). A single type's item in French is that type's one French item: the rule becomes one item per language (6.1a).

### The three branches' files this release touches

`slice/6.1` is made from `main` after all three have merged; these are their files this plan changes (C = changed, R = read only, relied on). *(I-3372: files marked 6.1c, 6.1e or 6.1f wait with those parts, except those 6.1a now changes for its guards: `[3.1]` `ListedItems.php`, `Live.php`, `ItemsQuery.php`, `TermsQuery.php`, and `ReindexSearch.php`; see A5 and C3.)*
- **`[4.1]`:** `app/Domain/Themes/ComposerRegistry.php` (C, 6.1c), `app/Domain/Themes/Reference/ViewDataCatalogue.php` (C, 6.1c), `docs/reference/theme.md` and `docs/reference/theme-view-data.json` (C, regenerated, 6.1c), `app/Domain/Site/ListingViews.php` (C, 6.1a), `app/Domain/Site/FieldViews.php` (C, 6.1a), `packages/teme-contracts/src/Themes/ViewContext.php` (R: `locale()`'s docblock already says "the page being drawn"), `app/Domain/Site/ViewModels/SiteView.php` (R: the default language), `app/Domain/Site/ArticleViews.php` and `app/Domain/Site/SiteDates.php` (R: dates already in the item's language through `forContent()`).
- **`[3.1]`:** `app/Domain/Addresses/Paths.php` (C, 6.1a: `taken()`), `app/Domain/Api/{Live,ApiWords}.php`, `app/Domain/Api/Items/{ItemsQuery,ItemFilters,ItemShapes,FieldValues}.php`, `app/Domain/Api/Catalogue/{TermsQuery,TermFilters,SiteShape}.php`, `app/Domain/Api/Llms/MarkdownPages.php`, `app/Http/Api/V1/{ItemsController,TermsController}.php` (C, 6.1c; `FieldValues` and `ItemShapes` first by 6.1a), `app/Domain/Sitemaps/Queries/ListedItems.php` (C, 6.1c), 3.1a's and 3.1b's exact-shape tests (C, by addition only, 6.1c), `app/Domain/Cache/{PageKey,StoreRules}.php` (R: unchanged, the address decides).
- **`[5.1]`:** `packages/teme-contracts/src/Settings/SettingsChanged.php` (C, 6.1f, a last member), `app/Domain/Settings/SettingsWriter.php` (C, 6.1f), `app/Domain/Contents/Operations/{CreateDraft,SaveDraft,PublishRevision,UnpublishContent,TrashContent,RestoreContent,ChangeVisibility,DeleteContentPermanently}.php` (C, 6.1a), `bootstrap/providers.php` (C, 6.1a), `bootstrap/app.php` and `config/teme.php` (C, 6.1f), the hook catalogue's test of `SettingsChanged`'s members (C, 6.1f), `packages/teme-contracts/src/Content/ItemRef.php` and `app/Domain/Hooks/Payloads.php` (R: `language` from `forContent()`, now the row's), `.ai/rules/contracts.md` (R: the growth rule).

---

## Questions for the maintainers

Each is planned on its recommendation; the tasks that change if the answer differs are named.

**Decided 2026-11-11 by the maintainers (I-3370):** Q1 to Q6 and Q8: **A**, as recommended; each is a default that may become a site setting later. **Q7 changed:** the four reader-word files are checked by AI, not by a fluent person, and ship marked as AI-checked in `CHANGELOG.md` (6.1e's Task W4, 6.1z's Z5). **Added:** the site owner chooses, per content type and per taxonomy, which are translated (D25).

**Q1. The default language, and changing it.** An existing site's default language is its current Site language (`general.language`); its items keep `language` NULL, which means "the default language". **May the default change later?**
- **A (recommended): yes while the site has one language; no while other languages hold items or terms.** Settings › General refuses with words that say what to do first. For: no row is rewritten, ever, in this release (D1); the case is rare (a site changes its default once, usually before translating). Against: a site that wants to swap English and French later waits for an update (a background swap, *For later* 7).
- **B: allowed at any time, with a background job** that writes the old default's tag into every NULL row and NULL into the new default's rows. Against: about a day more, a million-row rewrite while the site runs, and a window in which addresses and searches are half-moved.
- *If B:* a part of its own after 6.1a; D1's rule "never store the default's tag" gains a migration window.

**Q2. May a language be added and hidden while its translations are written?**
- **A (recommended): yes: each language is Shown or Hidden.** A hidden language's items can be drafted, previewed and even published, but every reader-facing way answers as for a missing item: its addresses 404, it is in no list, sitemap, feed, search, `hreflang`, API answer or link. **Show** makes everything published appear at once, which is how a site launches a language. For: a launch is one switch; Craft and Statamic sites can be disabled the same way. Against: one more predicate in eight named places, each held by the leak matrix (6.1z).
- **B: no; a language is live once added.** Its items stay drafts until ready; launching means publishing each. Against: a launch is many publishes, and readers see the first French page before the menu or tagline are French.
- *If B:* D7 and the *hidden* parts of 6.1a, 6.1c go; the Languages screen has no Readers column.

**Q3. When is a translation "out of date"?** Each version remembers the revision of the version it was translated from (D9).
- **A (recommended): when the source has published a newer revision since**, until someone marks it up to date (**Mark as up to date** writes nothing but the new mark) or translates again. For: never forgotten (Drupal's flag must be set by hand; MultilingualPress's "Translation completed" is hand-set too, study §3.3); one query. Against: a change to the source's tags or SEO title alone also marks it.
- **B: only when the source's title, body or excerpt changed**, compared by their text. Against: a new featured image, a changed field or a re-filed category never marks it; the query compares two text columns.
- **C: set by hand**, as Drupal. Against: forgotten.
- *If B:* the query in D9 compares `md5(title || plain_text)` too; one test more. *If C:* `content_revisions.source_revision_id` still records where a version came from; the mark becomes a checkbox.

**Q4. Who may translate?**
- **A (recommended): the existing permissions.** Whoever may create items of the type may translate one, from its published version (anyone can read that), or from its unpublished one if they may edit it; linking and unlinking need edit rights on every item involved. For: a contributor translates as they write, a translation is reviewed and published as any draft is; Drupal's `translate {bundle}` is per type too. Against: no way to let someone translate into French only.
- **B: a new `content.translate` permission**, editors and administrators by default. Against: a new permission and its add-only migration for a need nobody has stated.
- **C: per-language permissions** (Strapi, Craft, Statamic). *For later* 6.

**Q5. Where a language has no words of its own, what do its readers see?** A French page whose theme slot has no French menu, whose region has no French version, whose tagline has no French words.
- **A (recommended): the default language's.** For: a French page never loses its header, footer or tagline; adding a language never empties a page; each can be translated when ready. Against: some English shows on French pages until then.
- **B: nothing.** Against: an empty header the day French is shown.
- *If B:* D14's and D15's fallbacks become empty values; 6.1f's tests flip.

**Q6. When a post is translated, its categories and tags may have no French version yet.**
- **A (recommended): Translate makes the missing French terms** as copies (the same name and slug, French, linked to the English term), and says so: "Made French versions of 2 categories: Sport, Local. Rename them in Categories." For: the French post is filed and its archive links work at once; terms are translated where they are listed. Against: a French archive shows an English name until someone renames it.
- **B: the French post is left unfiled** under terms that have no French version, and the editor is told which. Against: a published French post can lose its category; its archive and address token (`{category}`) change.
- *If B:* `TermSiblings::inLanguage()` (6.1a) returns the found ones only, and the toast lists the rest.

**Q7. The four reader-word files: who checks them before they ship?** The builder prepares Spanish, French, Brazilian Portuguese and German from the English (6.1e, about 150 keys each).
- **A (recommended): a fluent reader checks each before the tag** (EmDash's rule, study §3.3); the code merges at once, the tag waits on the four checks. For: these words face every reader of a client's site. Against: the tag waits on people.
- **B: ship them marked machine-prepared** in `CHANGELOG.md`, and correct them as reports come. Against: a wrong word on a public site until someone reports it.

**Q8. The phase number.** Roadmap phase 6 is Distribution (`v1.0.0`, slices 6.1 to 6.5, named in four built plans).
- **A (recommended): multilingual is phase 6 (`v0.6.0`); Distribution becomes phase 7, still `v1.0.0`.** For: the scheme's rule, "Minor, before 1.0: the phase", holds. Against: the four built plans that say "phase 6's browser restore" keep their old words (they are records).
- **B: slices of phase 5, tagged `v0.5.x`.** Against: multilingual is not part of the plugin API's promise.

---

## The decisions, written out

These are the release's contract; every part builds to them.

**D1. A language, as stored.**
- A language is its canonical BCP 47 tag, a key of `config/languages.php` (`en`, `en-GB`, `pt-BR`; the language lower-case, the region upper-case). Content may be in any language the file lists and the site adds (I-3360: languages are data).
- **`contents.language` and `terms.language` hold NULL for the site's default language and the tag for any other. The default's tag is never written.** `SiteLanguages::stored(string $tag): ?string` is the only way a writer turns a tag into a column value (null for the default; an `InvalidArgumentException` for a tag that is not one of the site's). `SiteLanguages::of(?string $stored): string` is the only way a reader turns it back (`$stored ?? defaultTag()`).
- **Why NULL, permanently:** no row is rewritten when the release is installed, or when a second language is added (§4.4's backfill is not needed), and the NULLS NOT DISTINCT indexes (D4) treat every default row as one language. The price: the default can't change while another language holds rows (Q1).
- **How a NULL is read, everywhere:**

| Where | A NULL `language` reads as |
|---|---|
| `SiteLanguage::forContent()`, the page's `lang`, `ItemRef::$language` `[5.1]`, the API's `language` `[3.1]`, structured data's `inLanguage` | the default's tag |
| `contents_type_slug_language_live_unique`, `terms_taxonomy_slug_language_unique` (NULLS NOT DISTINCT) | one value: two default-language items of a type can't share a slug |
| `contents_translation_group_language_unique` | one value: a group holds one default-language item |
| `SearchText::sync()`, `ReindexSearch` | the default language's text-search configuration |
| `Addresses` | no prefix |
| Queries for one language | the default's: `language IS NULL`; another's: `language = ?`. **Never `COALESCE(language, ?)`**, which no index serves |
| `SiteLanguages::isShown()` | always shown |
| The export (`ContentsKind`, `TermsKind`) | `"language": null`, described as "Null for the site's default language" |

- A test in 6.1a plants a row with the default's tag and shows `LanguageColumnsTest` failing on it; the same test then runs every writer this release adds (6.1a, 6.1h) and asserts none writes the default's tag.

**D2. The site's languages.** A settings group, so every change is versioned, logged in `settings_changes`, purged and exported as settings already are; it has its own screen, so `UpdateSettings` refuses it (`UpdateSettings::validate()`, "Change these settings on their own screen.") and only D10's three Operations write it, through `SettingsWriter`.
- **Group** `languages` (`SiteLanguages::GROUP`): label "Languages", description "The language your site is written in, and the other languages it publishes in.", section `general`, ability `settings.general.manage`, screen `settings.languages`, cache tags `[PageTags::SETTINGS]`, since `0.6.0`. Settings:
  - `others`: `Setting::multiselect('others', 'Other languages', 'The languages beside the default one, in the order they were added.', '0.6.0', <tag => label of every config language>)`, default `[]`;
  - `prefixes`: `Setting::pairs('prefixes', 'Address prefixes', 'Each other language's first part of every address.', '0.6.0', 'Language', 'Prefix')`, default `[]`, as `[{"name": "fr", "value": "fr"}]`;
  - `hidden`: `Setting::multiselect('hidden', 'Hidden from readers', 'Other languages readers get nothing in, while they are translated.', '0.6.0', <the same options>)`, default `[]`;
  - `untranslated_types` and `untranslated_taxonomies` (D25): `Setting::multiselect('untranslated_types', 'Content types not translated', 'Content types whose items are always in the default language.', '0.6.0', <key => plural label of every registered type>)` and `Setting::multiselect('untranslated_taxonomies', 'Taxonomies not translated', 'Taxonomies whose terms are always in the default language, shared by every language.', '0.6.0', <key => plural label of every registered taxonomy>)`, each default `[]`.
- **The default language stays `general.language`** (I-435; the API's D28 `[3.1]` already calls it the default language). Settings › Languages shows it and links to General to change it.
- **`App\Domain\Languages\SiteLanguages`** (`final readonly`, scoped, over `SettingsReader`; every answer is read through the settings memo, so a page that asks records the `settings` tag):

```php
public const string GROUP = 'languages';
public function defaultTag(): string;            // general.language, as SiteLanguage::tag()
/** @return list<string> */ public function others(): array;   // in the order added
/** @return list<string> */ public function all(): array;      // the default first, then others()
/** @return list<string> */ public function shown(): array;    // all() without the hidden
public function has(string $tag): bool;
public function isShown(?string $stored): bool;  // null (the default) is always shown
public function stored(string $tag): ?string;    // D1
public function of(?string $stored): string;     // D1
public function prefix(string $tag): string;     // '' for the default
public function byPrefix(string $segment): ?string;  // the other language whose prefix it is, shown or hidden
public function isMultilingual(): bool;          // others() is not empty
public function translates(string $type): bool;  // D25: the type is not in untranslated_types
public function translatesTaxonomy(string $taxonomy): bool;  // D25
public function label(string $tag): string;      // config's English name: "French"
public function native(string $tag): string;     // config's own name: "Français"
public function direction(string $tag): string;  // 'ltr' or 'rtl'
public function locale(string $tag): string;     // PHP's form: "pt_BR"
public function ogLocale(string $tag): string;   // Open Graph's form: "pt_BR", "fr_FR"
```

- **`config/languages.php`** gains three keys on each of its 41 languages: `native` (the language's own name, such as `Français`, `Português (Brasil)`, `العربية`), `direction` (`rtl` for `ar` and `yi`, `ltr` for the rest) and `og` (Open Graph's `ll_CC`, from Facebook's list as Yoast maps it, `[R] yoast-seo-28.5/wordpress-seo/src/generators/open-graph-locale-generator.php`; such as `en` → `en_US`, `en-GB` → `en_GB`, `es` → `es_ES`, `fr` → `fr_FR`, `de` → `de_DE`, `pt` → `pt_PT`, `pt-BR` → `pt_BR`, `ar` → `ar_AR`). *(review)* Take Facebook's list, not Yoast's fallback: Yoast doubles a two-letter code (`hi` → `hi_HI`) and answers `en_US` for anything not on the list (`open-graph-locale-generator.php:47-49`, `:216`), which would call a Hindi, Tamil, Swedish or Nigerian English page `en_US`. Where doubling misses, use the list's entry for the language (`hi_IN`, `ta_IN`, `sv_SE`, `nb_NO`, `en_NG` is not on it: `en_GB`); for a language with no entry (`pcm`), its main country (`pcm_NG`, which the test's pattern allows). `tests/Feature/Site/SiteLanguageTest.php` gains a test holding every entry to the three keys' forms.
- `SiteLanguage` keeps its API; `forContent(Content|string)` reads the row (`$content->language` for a model **whose attributes hold `language`**, *(review)* else one primary-key read, so a model loaded with a narrow `select()` never reads as the default by accident; one primary-key read for an ID) and returns `of()` of it; it gains `of(?string $stored): string`. The ~~four~~ *(re-check)* **five** callers that ask once per row switch to `of($row->language)` with `language` selected in the query they already run: `[4.1] ListingViews.php:329`, `[4.1] FieldViews.php:99`, `[3.1] Api/Items/FieldValues.php:171`, `[3.1] Api/Items/ItemShapes.php:104` (*(re-check)* was `:103`; `contents.language` goes into `ItemShapes::COLUMNS`), and *(re-check)* `[4.5] Api/Items/ItemQuery.php:157` (the full item's `ancestors`, one `forContent()` per ancestor: add `contents.language` to its `->get([...])`), so no listing page or API answer adds a statement. *(re-check)* Four callers pass an ID once per page or job and will cost one primary-key read each: `View/Components/Teme/Document.php:106` and `StructuredData/ItemNodes.php:47` (both on every article page), `Seo/Queries/EditorSearchAndSocial.php:108`, `Ai/Asking/Jobs/AnswerAiRequest.php:257`. C3 re-runs the article page's statement budget tests (grep `tests/` for them); if one fails, each ID's language is remembered for the request (`SiteLanguage` is `final readonly` and unbound today, so through a small `scoped()` class, `NoStaticStateTest`) rather than the budget being raised.

**D3. The page's language.** `App\Domain\Languages\PageLanguage`, bound `scoped()` (`NoStaticStateTest`): the language of the page being drawn, set once per public request, before anything is drawn, by whatever decided what the page is.

```php
public function use(string $tag): void;   // the site must have the tag; sets App::setLocale(locale($tag)) and remembers stored($tag)
public function tag(): string;            // the tag given to use(), or SiteLanguages::defaultTag()
public function stored(): ?string;        // the stored form given to use(), else null: reads nothing, so the settings overlay (D15) can ask it without asking settings
public function isSet(): bool;
```

- **Who calls `use()`:** `PublicController` with `PathResolver`'s answer's `language` (6.1a); `PreviewController`, `CommentController`, `UnlockController`, `PendingCommentsController` and the respond page from the item (6.1e, parked: until then they draw in the default language's locale, as today); `FeedController` from the feed's subject (6.1c, parked: until then `/fr/feed/` answers 404, A3); `SitemapController` never (a sitemap has no language); an error page from the request path's prefix, if any (6.1a, `PublicPage::error()`).
- **What reads it:** `Document`'s `lang` and `dir` (6.1e), `ThemeContext::locale()` and the view data (6.1c), the head's `og:locale` and alternates (6.1c), the settings overlay (D15), the search page (6.1c), the menus' and regions' choice (6.1f), every `__()` through the application's locale.
- Outside a public request (the admin, a queue job, the console) it is unset: `tag()` is the default, `stored()` null, and the admin's locale is the person's (D21).

**D4. The schema.** Every migration only adds; the previous image keeps running (`ExpandThenContractTest` sees no drop or rename of a column or table). Dated after every migration on `main`, the three branches and page parents (*(review)* page parents' four are `2027_03_20_1000xx` (its fifth, `contents_top`, is parked with its 4.5d), the newest `2027_03_20_100400_nest_page_addresses.php`, and merge first; block IDs adds none).

`database/migrations/2027_04_01_100000_add_language_columns.php` (in its transaction):

```php
public function up(): void
{
    // Inside the migration's own transaction: a busy table makes it give up, not queue every read behind it.
    DB::statement("SET LOCAL lock_timeout = '5s'");
    // Nullable, with no default: PostgreSQL writes no row to add any of them.
    DB::statement('ALTER TABLE contents ADD COLUMN language varchar(35) NULL');
    DB::statement('ALTER TABLE contents ADD COLUMN translation_group char(26) NULL');
    DB::statement('ALTER TABLE terms ADD COLUMN language varchar(35) NULL');
    DB::statement('ALTER TABLE terms ADD COLUMN translation_group char(26) NULL');
    DB::statement('ALTER TABLE content_revisions ADD COLUMN source_revision_id char(26) NULL');
    DB::statement('ALTER TABLE settings ADD COLUMN translations jsonb NULL');
    // NOT VALID: checked for new rows now, for existing rows by the next migration, without blocking writes.
    // A CHECK that comes out NULL passes: a NULL language is the default (I-3360).
    DB::statement("ALTER TABLE contents ADD CONSTRAINT contents_language_check CHECK (language ~ '^[a-z]{2,3}(-[A-Z]{2})?$') NOT VALID");
    DB::statement("ALTER TABLE contents ADD CONSTRAINT contents_translation_group_check CHECK (translation_group ~ '^[0-9a-hjkmnp-tv-z]{26}$') NOT VALID");
    DB::statement("ALTER TABLE terms ADD CONSTRAINT terms_language_check CHECK (language ~ '^[a-z]{2,3}(-[A-Z]{2})?$') NOT VALID");
    DB::statement("ALTER TABLE terms ADD CONSTRAINT terms_translation_group_check CHECK (translation_group ~ '^[0-9a-hjkmnp-tv-z]{26}$') NOT VALID");
    DB::statement('ALTER TABLE content_revisions ADD CONSTRAINT content_revisions_source_revision_fk FOREIGN KEY (source_revision_id) REFERENCES content_revisions (id) ON DELETE SET NULL NOT VALID');
    DB::statement("ALTER TABLE settings ADD CONSTRAINT settings_translations_object CHECK (translations IS NULL OR (jsonb_typeof(translations) = 'object' AND pg_column_size(translations) <= 65536))");
}
```

`down()` drops the six constraints, then the six columns (a `down()` may; the test reads `up()` only).

`2027_04_01_100100_validate_language_constraints.php` (in its transaction): `ALTER TABLE … VALIDATE CONSTRAINT …` for the five `NOT VALID` constraints. Each takes `SHARE UPDATE EXCLUSIVE`, so reads and writes go on while it scans; every existing value is NULL, so each passes. `down()` does nothing.

`2027_04_01_100200_add_language_indexes.php` (`public $withinTransaction = false;`, nothing but these statements, `.ai/rules/migrations.md`):

```php
public function up(): void
{
    // One live item per type, slug and language. Slug before language, so the previous image's slug search still walks it.
    DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS contents_type_slug_language_live_unique ON contents (type, slug, language) NULLS NOT DISTINCT WHERE deleted_at IS NULL');
    // One live item per language in a group (I-3360).
    DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS contents_translation_group_language_unique ON contents (translation_group, language) NULLS NOT DISTINCT WHERE translation_group IS NOT NULL AND deleted_at IS NULL');
    DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS terms_taxonomy_slug_language_unique ON terms (taxonomy, slug, language) NULLS NOT DISTINCT');
    DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS terms_translation_group_language_unique ON terms (translation_group, language) NULLS NOT DISTINCT WHERE translation_group IS NOT NULL');
    // A permanent delete sets these to NULL: without it, each deleted revision scans the table.
    DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS content_revisions_source_revision ON content_revisions (source_revision_id) WHERE source_revision_id IS NOT NULL');
    // Lists in another language: only the other languages' rows, newest first.
    DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS contents_other_language_published ON contents (language, type, published_at DESC, id DESC) WHERE status = 'published' AND deleted_at IS NULL AND language IS NOT NULL");
    // A group's members, trash included (GroupLock, memberIds()): the unique index above leaves the trash out.
    DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS contents_translation_group ON contents (translation_group) WHERE translation_group IS NOT NULL');
    // Counts of a language's or a type's items, trash included (RemoveSiteLanguage, ChangeTranslatedTypes): other languages' rows only.
    DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS contents_language_type ON contents (language, type) WHERE language IS NOT NULL');
}
```

*(review)* The last two were missing: `GroupLock::lock()` reads `translation_group IN (…)` over live **and trashed** rows on every Translate, link and restore, which the partial unique index can't serve, so it scanned `contents` under `FOR UPDATE`. Both hold only rows with a language or a group, so a one-language site pays nothing.

`2027_04_01_100300_drop_single_language_slug_index.php` (`$withinTransaction = false`): `DROP INDEX CONCURRENTLY IF EXISTS contents_type_slug_live_unique`. `2027_04_01_100400_drop_single_language_term_slug_key.php` (in its transaction, `lock_timeout` 5s): `ALTER TABLE terms DROP CONSTRAINT IF EXISTS terms_taxonomy_slug_unique` (the name Laravel gave `$table->unique(['taxonomy', 'slug'])`; C0 checks it with `\d terms`). **Why the previous image still runs:** it writes only NULL-language rows and checks slugs per type across every language, which is stricter than the new index; each new index allows everything the old one did. **Why the same release, not a later one:** a French `about` beside an English `about` needs the old ones gone. Each `down()` re-creates the old index or key, and fails, saying why, while two languages share a slug.

A failed `CREATE INDEX CONCURRENTLY` leaves an invalid index that `IF NOT EXISTS` would then skip; C1's test asserts every index named here is valid (`pg_index.indisvalid`).

Models: `Content`, `Term` and `ContentRevision` gain `@property string|null $language`, `@property string|null $translation_group`, `@property string|null $source_revision_id` (Larastan reads the docblock, lesson 1.8a); `SettingsGroupRecord` casts `translations` to `array` with an `Attribute` that writes `null` for an empty array (`.ai/rules/models.md`).

**D5. One item per language per group.** A group is the lower-case ULID in `translation_group`, minted by `TranslationGroups::newId()` (`strtolower((string) Str::ulid())`), never an item's ID, never printed on a public page or in an API answer. A version alone has no group (NULL), as Polylang makes none for a post alone (study §4.5). **Every Operation that changes a group's members or a member's language** follows one order:
0. *(review)* takes, before any other lock, `LanguageLock::shared()` (`pg_advisory_xact_lock_shared(hashtext('teme.languages'))`, as `CreateDraft::isSingle()` takes `teme.types`, `[5.1] CreateDraft.php:129`), then, for a hierarchical type, `[4.5]` `TreeLock::take($type)` (*(re-check)* through `app(SubtreeAddresses::class)->lock($type)`, `[4.5] SubtreeAddresses.php:96`, as page parents' lifecycle Operations take it: it answers false and takes nothing for a type that doesn't nest); `RemoveSiteLanguage` and `ChangeTranslatedTypes` take `LanguageLock::exclusive()` first, so a language can't be removed, or a type stop being translated, while a translation into it commits (without it both pass their checks and an item lands in a removed language). Every Operation that gives an item or term a language or a group takes the shared lock: `CreateDraft` with a language, `TranslateContent`, `LinkTranslations`, `ChangeContentLanguage`, `CreateTerm` with a language, the importer's linking. **The order, always: languages lock, tree lock, the group's content rows (by ID), terms (by ID), anything else.** *(re-check)* `CreateDraft` with a language takes `teme.types` (shared, single stored types only) after the languages lock and before any row; nothing takes them the other way round (`UpdateType` takes no languages lock; `RemoveSiteLanguage` and `ChangeTranslatedTypes` take no types lock). The importer's order, `[4.5]` Follow-up S1 item 2 (the run row FOR SHARE, the map object, the tree, the page), is unchanged: 6.1a adds no lock to `ImportObject`, `UpdateImportedContent` or `PlaceImportedPage`, and its own Operations take the tree before any row, as the import does, so the two can't wait on each other. *(For parked 6.1h: its linking runs inside an import's order, so it takes the languages lock after the run row and the object, before the tree; nothing that holds the languages lock exclusively takes a run row or an object.)*
1. reads, without a lock, the groups of the items it names;
2. locks every member of those groups and the named items, live and trashed, in one statement, `ORDER BY id`, `lockForUpdate()`, **as the Operation's first row lock, replacing its own** (`RestoreContent.php:55`'s *(re-check: `:61` on `[4.5]`, right after `$this->takeTree()` at `:60`)* `lockForUpdate()->findOrFail()` and its siblings' become `GroupLock::lock([$id])`; a row lock on the item taken before the group's would lock out of ID order and can deadlock), so two Operations on one group queue rather than deadlock;
3. checks under the lock: one live item per language, every member of one type;
4. writes. `contents_translation_group_language_unique` is the last guard; a `23505` on it is turned into the same words as step 3's refusal.

A trashed member keeps its group and language (it is outside the unique index, `WHERE deleted_at IS NULL`); restoring it where its language is taken leaves it out of the group (D11).

**D6. Addresses.**
- **An item, or a term, in another language stores its prefix in its path:** `fr/a-propos` (the pattern's address after the prefix), `fr/category/actualites`. `paths.path` stays unique and `paths_one_current` (one current path per target) holds. `Addresses::wouldHave()` and `Addresses::forTerm()` prepend `SiteLanguages::prefix($tag).'/'` when the row's language is not NULL. The default language's addresses are computed exactly as today, so **no existing address moves** (a test compares every path of a seeded site before and after).
- **The front page per language:** with a page as the home page (`reading`'s front page), its translation in French has the address `fr` (the prefix alone), and its own pattern address answers 301 there, as the default front page's does (`PathResolver::current()`). With the latest posts as the home page, or a page with no live French version, `/fr/` lists the latest French items. The posts page's translation lists the latest items in its language.
- **Every public answer says its language** in a `Content-Language` header (the page's tag), set by `PublicController`; the page cache stores it with the page (`StoreRules::VARY` is unchanged: the address alone decides the language).
- **Authors, type archives, date archives, search, feeds** have no row per language: `/fr/author/ada/`, `/fr/films/`, `/fr/2026/11/`, `/fr/?s=`, `/fr/feed/` are the default's address with the prefix before it, listing that language's items.
- **Routing**, `PathResolver::answer()` (written out in 6.1a, Task A2): after the comments-page and `page/N` rules, the first segment is looked up as another language's prefix. With one: a hidden language answers 404; the prefix alone is that language's home; otherwise the whole path is looked up in `paths` first (items and terms carry their prefix), then the rest as a type archive, an author (a current `user` row at the rest), a date; a miss is that language's 404. Without one: today's order, unchanged, the default language.
- **The 404 speaks the language of its prefix** (`notFound()` carries it).
- **A prefix is the site's own.** `Paths::taken($segment)` is also true for every other language's prefix, shown or hidden, so no item, term base, author base, type archive or pattern can take it once added. **Adding a language, or changing its prefix, is refused while anything holds the prefix:** `Paths::taken()` (the router's words, the site's routes, another language's prefix), a current `paths` row at the prefix or under `prefix/` (one index range on `paths_path_unique`, `path = ? OR path LIKE ?` with the C collation), a type archive (`TypeArchives::at()`), a taxonomy base, the author base, or a pattern whose first fixed segment is it. The refusal names the holder with `Paths::describe()`'s words, so a page at `/fr/` today blocks French until it moves (study flag 6).
- **The one thing a refusal can't cover**, a redirect whose source is the prefix itself (`/fr`), which the language's home now answers first, gets a warning banner, `LanguageHomeShadowsRedirect` (`app/Domain/Status/Checks/`, mirroring `[3.1] ApiAddressTaken`): words in *Every word*, action Tools › Redirects searching for it, ability `redirects.manage`.
- **A changed prefix** moves that language's addresses: `ChangeSiteLanguage` queues `RecomputeAddresses` for that language only (its new last parameter `?string $language = null`), each old address kept as history, so it answers 301, as an address setting's change does.
- **Redirects** are unchanged rows; they answer only where routing finds nothing (I-858), as today.
- **Old addresses** (history rows) are read after a live address and a type archive and before a date's listing, the order `PathResolver::answer()` keeps today (*(review)* an author's own address is a current `paths` row, found first today; only under a prefix is the author looked up after history), so an imported `/fr/about/` keeps answering 301 until the item takes it back (D22).

**D7. A hidden language** (Q2 A). Readers get nothing in it, the same answer as for a missing item, in these places, each with its test and again in 6.1z's leak matrix (parked: until then A3's and A5's tests run with the page cache on and off). *(I-3372)* While 6.1c is parked, places 3, 5, 6 and 7 are held by A5's default-language predicates, under which lists hold the default language's items only, so a hidden language's never; place 4 draws nothing at all:
1. `PathResolver`: a hidden prefix 404s; a current row whose target is in a hidden language 404s (6.1a);
2. `PathLinkResolver`: a link to an item or term in a hidden language has no address, so its text is drawn without a link (6.1a);
3. `ListedItems`: sitemaps list `language IS NULL OR language = ANY(shown)` (6.1c);
4. alternates, `hreflang`, `og:locale:alternate`, the view data's `alternates`, the API's `translations` and `languages`: shown languages only (6.1c);
5. search and feeds: reached through the prefix, so hidden by 1 (6.1c asserts it);
6. the API's `Live::apply()`: `(contents.language IS NULL OR contents.language = ANY(?))` when any language is hidden (6.1c), which covers `/items/{id}`, `by-path`, `entry` fields and links in bodies; `?language=` naming a hidden language is `400` with a typo's words;
7. `llms.txt`: the default language's items only; a `.md` page: an item of a shown language only (`ListedItems`, 6.1c's H3; asserted in its S4);
8. menus and regions: a hidden language's are never drawn, since its pages never are.
*(review)* Places the eight missed, each 6.1a's with a test in `HiddenLanguageTest` (A3):
9. `PathResolver::comments()` runs **before** the prefix rule, so `/es/hola/comments/page/2/` drew a hidden item's comments: `PathResolver::live()` (`:421`) gains the shown-language predicate, which covers `current()`, `comments()`, the front page and the posts page at once;
10. `PathResolver::history()`: an old address whose target is in a hidden language answers 301 to that target's prefixed address, naming it (an item moved by `ChangeContentLanguage`, an imported WordPress address): it answers 404 instead;
11. core's comment routes and the password form (`routes/public.php`: `_teme/comment/{comment}` redirects to its item's address, `…/reply`, the `_teme/comments` post, `_teme/comments/pending`, `_teme/unlock`): an item in a hidden language is treated as missing (`CommentViews::live()` and `item()`, the unlock lookup);
12. links by ID outside a body: a menu item (`[4.1] NavigationTree::paths()`, `NavigationTree.php:120`) and an `entry` field (`[4.1] FieldViews.php:258`) draw a hidden language's item as text alone, as `PathLinkResolver` does.

Previews and the admin are not readers: an editor previews a hidden language's item as any draft.

**D8. Translate.** `TranslateContent` makes a **draft** of the source's type in the target language, in the source's group (minting one, and setting it on the source, if it has none), by `CreateDraft::start()` (its signature gains `?string $language = null, ?string $group = null, ?string $sourceRevisionId = null`, last):
- **from the source's published revision** when it has one **and the source is public**; otherwise its working revision, which only someone who may edit the source may copy (Q4 A): a translator never reads an unpublished draft they could not open; *(review)* nor a private or password-protected item's text, which readers can't read either, so a contributor can't translate one to read it;
- title, document (with every block ID, I-3362), excerpt and every field are copied, except: **the canonical** (`BuiltInFields::CANONICAL`) is cleared, since a French version named canonical to the English would drop out of search; **the primary category** is mapped to its sibling (D13);
- **links by ID** (`teme:content/{id}` in link marks, a button card's `contentId`, an `entry` field's value) are pointed at the target's version in the target language where the group has one, live or not, and kept otherwise; a link to a term is pointed at its sibling the same way;
- **terms** are mapped to their siblings in the target language; a missing one is made (Q6 A, D13);
- the slug is the source's, made unique in `(type, slug, language)` (so `a-propos` waits for the translator; `about` in French is allowed beside `about` in English);
- author: the translator; comments: as a new item of the type (`DiscussionSettings::forNewItem()`); status: draft; nothing published;
- the new item's first revision records `source_revision_id` = the revision copied.
After commit: `ContentSaved` (created) for the new item through `start()`, carrying `ItemRef::$language` `[5.1]`; **no purge**: a draft changes no public page, and the source's new group changes nothing a reader sees.

**D9. Out of date** (Q3 A), worked out, never stored as a flag. A version's working revision carries `source_revision_id`, the revision of the version it was translated from; `SaveDraft` carries it forward from the working revision it replaces (or from the revision restored), so it changes only by `TranslateContent` and `MarkTranslationUpToDate`. *(review)* Every writer of a revision carries it, not only `SaveDraft` (and through it `CheckpointDraft` and `RestoreRevision`): `UpdateContentDetails` writes one too (`[5.1] UpdateContentDetails.php:187`; *(re-check)* `:258` on `[4.5]`), and `ArchiveAutosave` a checkpoint (`ArchiveAutosave.php:71`); the importer's (`Concerns/WritesImportedContent.php`) writes NULL. A version is **out of date** when the item that revision belongs to, still live, has published a newer revision since. One query for a group (6.1a, `TranslationStatus::forGroup()`), written out:

```sql
SELECT c.id, c.language, c.type, c.title, c.status, c.visibility, c.working_revision_id,
       src.id AS source_id, src.language AS source_language, pub.created_at AS source_changed_at,
       (src.published_revision_id IS NOT NULL
        AND src.published_revision_id <> sr.id
        AND pub.created_at > sr.created_at) AS out_of_date
FROM contents c
JOIN content_revisions r ON r.id = c.working_revision_id
LEFT JOIN content_revisions sr ON sr.id = r.source_revision_id
LEFT JOIN contents src ON src.id = sr.content_id AND src.deleted_at IS NULL
LEFT JOIN content_revisions pub ON pub.id = src.published_revision_id
WHERE c.translation_group = ? AND c.deleted_at IS NULL
ORDER BY c.language NULLS FIRST, c.id
```

(`status` includes `review`, 1.5e's `add_review_status.php`.) A version whose source is gone or in the trash is never out of date. **Mark as up to date** writes a new working revision, a copy of the current one with `source_revision_id` = the source's published revision (or its working one, for an unpublished source the person may edit), kind `checkpoint`: revisions are never edited in place (`.ai/rules/models.md`).

**D10. Link, unlink, change a language, and a site's languages.**
- **`LinkTranslations`** joins two or more items, and every member of their groups, into one group: the first named item's group, else a new one. Refused when two would share a language, when the types differ, or when one is trashed. Used by the panel and, in its own batched form, by the importer (D22).
- **`UnlinkTranslation`** sets one item's group to NULL. A group left with one member keeps it (harmless; nothing lists a group of one as translations).
- **`ChangeContentLanguage`** moves one item to another site language: refused when its group has a live item in that language, *(review)* or, for a hierarchical type (`[4.5]`), while it has a parent or any page under it, trash included ("Move it to the top, and move the pages under it, first.": the same-language rule of `[4.5]` `TreeRules` would break otherwise; **decided by the maintainers, I-3373 (2): refused**, no longer provisional), or when a term it is filed under, on its working or published revision, has no sibling in that language (named); otherwise its terms are mapped to their siblings, its slug kept if free in the new language or given the next free one (said in the message), and, if published, its address moves (`PathProjection::syncContent()`, the old kept as history, so 301) and its search words are rebuilt in the new language.
- **`AddSiteLanguage`**, **`ChangeSiteLanguage`** (prefix, shown), **`RemoveSiteLanguage`**: D2's group through `SettingsWriter`, under the group's version check; prefix rules in D6; `RemoveSiteLanguage` is refused while any item or term, live or trashed, is in the language, naming the counts. A removed language's translated settings are left in place (nothing reads them; adding the language again brings them back).
- **The default language** (`general.language`) can't change while `others` is not empty (Q1 A): a `SettingsCheck` on `general` (the tag `SettingsCheck::TAG`) refuses it, and refuses a new default that is one of `others`.

**D11. The lifecycle Operations and a group.** Each existing Operation keeps its authorization, validation and hooks; what changes:

| Operation | For a version in a group | After commit, beyond today's |
|---|---|---|
| `CreateDraft` | Input gains `?string $language = null` (last): a site language, stored by D1; a single type allows one item **per language** | — |
| `SaveDraft`, `CheckpointDraft` | carries `source_revision_id` forward; a term of another language is refused (`TermRules::usableBy()`, D13) | — |
| `UpdateContentDetails` | slug unique in `(type, slug, language)` | `translations:{group}` when published |
| `PublishRevision`, `SchedulePublication`'s run | address with the prefix (D6) | `translations:{group}` |
| `UnpublishContent`, `TrashContent`, `ChangeVisibility` | — | `translations:{group}` |
| `RestoreContent` | if a live version in its language is in its group now, it leaves the group (NULL), and its result says so; slug made unique per language | `translations:{group}` |
| `DeleteContentPermanently` | other members keep the group; revisions translated from its revisions lose `source_revision_id` (the foreign key) | `translations:{group}` |
| `ReindexSearch` | compares each row with its own language's configuration (D18) | as today |

Scheduling is per version, unchanged: `scheduled_publications` is one pending per `content_id`. Comments are per version, unchanged (I-3360).

**D12. Purges.** `PageTags::translations(string $group): string` gives `translations:{group}`. **A page records it** when it draws alternates (the head, the view data, the sitemap page) for a group; **an Operation purges it** (with `purgeAfterCommit()`, after commit, `.ai/rules/operations.md`) whenever a member's liveness, language or address changes (D11) or the group's membership does (`LinkTranslations`, `UnlinkTranslation`, `ChangeContentLanguage`). A group's other pages then lose or gain the `hreflang` of the version that changed, at once. Lists are unchanged: `list:{type}` already purges every language's lists of the type, a bounded over-purge.

**D13. Terms per language.** `terms.language` and `terms.translation_group`, D1 and D5 as for items. A term's address carries its prefix (D6). **An item may be filed only under terms of its own language:** `TermRules::usableBy(string $type, ?string $language = null)` (the stored form) adds `->where(fn ($q) => $language === null ? $q->whereNull('language') : $q->where('language', $language))`. `CreateTerm` takes `?string $language = null` (last); `TermSlugs::unique()` gains `?string $language = null`. `TermSiblings::inLanguage(list<string> $termIds, ?string $language, int $actorId): array{map: array<string, string>, made: list<string>}` maps each term to its sibling, making the missing ones (Q6 A) as copies in the target language, parent first, linked into the source term's group (minted if none), through `CreateTerm`'s own code inside the caller's transaction.

**D14. Menus and regions per language** (Q5 A). *Parked with 6.1f's G tasks (I-3372); until then `Regions::published()` reads the default language's version only (A5) and menus carry none.*
- **Menus carry no language** (*Where* 3). A theme slot's setting `nav_{slot}` (`ThemeSlotSettings::settingKey()`) becomes translatable (D15): `theme.{code}` holds the default language's menu, `translations.fr.nav_primary` the French one. `SlotMenus::all()` reads the slot through `SettingsReader`, so a French page gets the French menu, and the default's where none is set. `BindNavigationSlot` gains `?string $language = null` (last). A menu's **Home** item (`kind = 'home'`) leads to the page language's home (`/fr/`).
- **Regions are items** of the region type. A region's translation is its French version (`TranslateContent` on the region; its slug is the region's fixed one, unique per language). `Regions::published()` reads the page language's published version, falling back to the default's: `ORDER BY language IS NULL` over `WHERE slug = ? AND (language IS NULL OR language = ?)`, the first row.

**D15. Words in settings, per language.** *Parked with 6.1f's G tasks (I-3372).*
- `Setting` gains `public readonly bool $translatable = false` as its constructor's **last** parameter and `public function translatable(): self` (`@since 0.6.0`; `.ai/rules/contracts.md`). Marked translatable in this release: `general.site_title`, `general.tagline`; Search and social's `title_home`, `title_type_{type}`, `title_archive_{type}`, `title_taxonomy_{taxonomy}`, `title_author`, `title_date`, `title_search`, `title_not_found` (`app/Domain/Seo/TitlesAndListing.php`); every theme slot `nav_{slot}` (D14).
- **Stored** in `settings.translations`, one object per group row: `{"fr": {"site_title": "…", "tagline": "…"}}`, only translatable keys, only other languages (D4's check keeps it an object of at most 64 KB).
- **Read:** `SettingsRepository::group()` overlays, for the page's language, the translatable keys that language has (written out in 6.1f, Task G2); a key it lacks shows the default language's (Q5 A). The overlay asks `PageLanguage::stored()`, which reads nothing, so asking the `general` group for the default language never loops. Memoised under its own cache key, `settings:{group}:translations`, forgotten by `SettingsWriter` beside today's.
- **Written** by `TranslateSettings` (D23's ability: the group's own), through `SettingsWriter::writeTranslations()`, which bumps the group's version, logs to `settings_changes` (`before` and `after` keyed `"{language}.{key}"`), purges the group's cache tags after commit, and announces `SettingsChanged` with its new last member **`?string $language = null`** (`@since 0.6.0`, `[5.1] packages/teme-contracts/src/Settings/SettingsChanged.php`): the language whose words changed, null for the site's own values. Announcing a French title as a plain change would tell a plugin the site's name changed.
- `SettingsReader`'s docblock gains "Values are the current request's: on a public page in another language, translatable settings answer in that language." (signature unchanged.)

**D16. A page's view data** (the names `[4.1]` reserved, `ComposerRegistry::RESERVED`). *Parked with 6.1c (I-3372): the three names stay reserved and unfilled.* `App\Domain\Languages\LanguageComposer`, a core composer (added to `ComposerRegistry::CORE`), gives every theme view:
- `$language`: `PageLanguageView` (`App\Domain\Site\ViewModels\PageLanguageView`, `final readonly`): `tag` (`fr`), `locale` (`fr`, PHP's form), `name` (its own name, `Français`), `isDefault` (bool), `home` (that language's home address, absolute, `https://example.com/fr/`).
- `$direction`: `'ltr'` or `'rtl'`.
- `$alternates`: `list<Alternate>` (`App\Domain\Site\ViewModels\Alternate`, `final readonly`): `tag`, `name` (its own name), `url` (absolute), `current` (bool), `isDefault` (bool); **every shown language version of this page that a reader can open, this page included**, in the site's language order (the default first). For an item: its group's live, public versions; for a term archive: its group's terms with a live address; for the home page and a type archive's first page: every shown language; for an author, date, search, comments page, a paged listing past page 1 and a 404: `[]` (*For later* 12). **`[]` when there is only the page itself**, so a theme's switcher draws nothing for a page with no other version (study §5.1's fallback: no other language's text in place of a missing version).
- `ViewContext::locale()` returns `PageLanguage::tag()` (*Where* 6). `$site->language` and `$site->locale` stay the default's (`[4.1] SiteView`'s docblock already says so).

**D17. The head, sitemaps, feeds and structured data.** *Parked with 6.1c (I-3372), but for A5's default-language predicates on sitemaps and feeds.*
- **`hreflang`** (`LanguageLinks`, a `HeadContributor` at `Precedence::Core`): when `$alternates` has two or more entries, one `<link rel="alternate" hreflang="{tag}" href="{url}">` each, the page itself included, and `hreflang="x-default"` to the default language's entry when it is among them (every page in a group, not only the home page as Polylang does, study §4.5). Never on a page with `noindex` or no canonical. The page records `translations:{group}` (D12) and `content:{id}` of each version drawn.
- **`og:locale`** on every page that has Open Graph tags: `SiteLanguages::ogLocale(PageLanguage::tag())`; **`og:locale:alternate`** for each other entry of `$alternates` (#370). Written by `HeadTags::social()` (6.1c).
- **The canonical** stays each version's own address, as today.
- **Sitemaps:** an item's `<url>` gains `<xhtml:link rel="alternate" hreflang="…" href="…"/>` for each shown, live, public version, `x-default` included, when there are two or more; `xmlns:xhtml="http://www.w3.org/1999/xhtml"` on `urlset`. Items of every shown language are in their type's sitemap pages (`ListedItems::page()` and `starts()`); the archives sitemap lists each shown language's home and type archives that have a live item in that language. One more statement per sitemap page fetches the page's groups (`WHERE translation_group = ANY(?)`).
- **Feeds:** a feed has its page's language: `/fr/feed/` lists French items, `<language>fr</language>` (`[A]` 6.1c).
- **Structured data:** item nodes' `inLanguage` already come from `forContent()` (now the row); the page's `WebPage` node and listings' nodes take `PageLanguage::tag()`; the `WebSite` node keeps the default's.

**D18. Search per language.** *Ships in 6.1a: S1 in A5, S2 in C3 (I-3372).* `SearchRequest` gains `public ?string $language = null` (last, `@since 0.6.0`): the stored form, null for the default language. `PostgresSiteSearch` searches only that language's items (`c.language IS NULL` or `c.language = ?` in `MATCHES`) with that language's configuration. The search page passes `PageLanguage::stored()`. A plugin's `SiteSearch` that ignores the new member searches every language, as today. `ReindexSearch` waits on each row against its own language's configuration (6.1c, Task S2, written out).

**D19. The read API** `[3.1]`, under its D14 ("may be added within v1: routes; optional query parameters; members of any object").
- **Musts, before any translation exists on a released site:** `/items` and `/terms` list the default language's only (`contents.language IS NULL`, `terms.language IS NULL`), as D5 promised; `Live::apply()` hides a hidden language (D7); `llms.txt` lists the default language's items (`ListedItems::latestListed()` gains the default-only predicate); `.md` pages answer for shown languages only.
- **Additions** *(parked with 6.1c; the musts above ship in 6.1a's A5)*: `?language=` on `/items` and `/terms`: a shown language's tag (the default's tag selects the same as none); anything else is `400 invalid_parameter` with the words a typo gets, so a hidden language isn't told. **`translations`** on the full item, always present: `[{"id", "language", "path", "url"}]` of its other live, public versions in shown languages, in the site's order, `[]` when none. **`languages`** on the site: `[{"tag", "name", "native", "path", "url", "default"}]` of every shown language, the default first; the site's `language` keeps its meaning (the default). All three in the exact-shape tests of `[3.1]` 3.1a and 3.1b, updated.
- **Never** by `Accept-Language` or a cookie (`[3.1]` D5), unchanged.

**D20. Teme's own reader-facing words.** *Parked with 6.1e (I-3372), but for `PageLanguage::use()` setting the locale on public pages (6.1a, C3).*
- **The locale follows the page** (#368): `PageLanguage::use()` sets `App::setLocale(SiteLanguages::locale($tag))` on every reader entry point (D3). No email reaches a reader today (1.8d's two go to the post's author and the moderators, people with accounts, so they follow D21); the first that does is sent in its item's language with Laravel's `->locale()`.
- **One catalogue, keyed by the English text** (study §5.2 A): `lang/es.json`, `lang/fr.json`, `lang/pt_BR.json`, `lang/de.json` (Laravel's JSON form; English is the source and needs no file beyond today's `lang/en.json` overrides). Missing keys read as English (Laravel's fallback). A site language with no file (`pt`, `it`, `ar` …) reads English: content may be in any language; only these four ship words (I-3360).
- **A theme's words** go through `__()` too, from its own `lang/{locale}.json`, which core loads for the active theme with `Lang::addJsonPath()` after core's own, so a theme may also word core's keys its own way. The default theme ships `themes/default/lang/{es,fr,pt_BR,de}.json`. The rule for themes, in `[A] docs/architecture/themes.md` (the architect's, at the merge): words through `__()`, never `dir` or `lang` in markup, logical CSS.
- **`dir`** on the public `<html>` from `SiteLanguages::direction(PageLanguage::tag())` (#367): `rtl` for Arabic and Yiddish today. The default theme already uses logical properties only (study §2.5).
- **Numbers in words** (`SubmitComment`'s "It has :count.") use `Number::format(…, locale: app()->getLocale())`.
- **"No sentence built from pieces":** a sentence is one key with `:placeholders`; plurals use `trans_choice()` with Laravel's pipe forms, which hold for the five languages (two forms each).

**D21. The admin's translation foundation** (I-3360: the mechanism, new screens follow it, no admin language translated yet). *Moved to the admin-words plan (I-3373 (6)), with 6.1f's F1 to F4; kept here as that plan's source. It is built beside page parents, before 6.1a, so 6.1a's one new screen file follows it (C2).*
- **One source:** the same `lang/{locale}.json` files, keyed by the English text, read by PHP's `__()` and by React. Server-provided words (Inertia props built with `__()` in PHP) and React's own words are therefore the same catalogue.
- **A person's admin language:** a preference `admin.language` (`select`, default `en`, options from `config('teme.admin_languages')`, which ships `['en' => 'English']` and a comment that a language is added when a site owner needs it and a fluent reader has checked it). Not shown on Preferences while it has one option. A middleware, `UseAdminLanguage`, in the admin group after authentication, calls `App::setLocale()` with it; `resources/views/app.blade.php` writes `lang` and `dir` from it (`dir` from `config/languages.php`; `ltr` for English).
- **To React:** a shared Inertia prop `i18n` = `{locale, direction, messages}`, `messages` the locale's JSON (empty for English beyond `lang/en.json`'s overrides), sent with `Inertia::once()` keyed by the locale and the file's modification time, so it crosses the wire once a visit.
- **In TypeScript:** `resources/js/lib/i18n.ts`: `__(key: string, replace?: Record<string, string | number>): string` and `__n(key: string, count: number, replace?: Record<string, string | number>): string` (Laravel's pipe forms, `{0}`, `[2,*]` and `one|many`, chosen with `Intl.PluralRules(locale)`), reading the messages that `app.tsx` sets from the `i18n` prop on every visit. Placeholders `:name` as Laravel's, replaced longest name first.
- **The pseudo-locale** `en-XA`, only while `config('app.debug')` and `TEME_PSEUDO_LOCALE=true`: every admin word comes back accented and bracketed (`[Šàvé çĥàñĝéš]`), so a word that doesn't is hard-coded. PHP's side through `Lang::handleMissingKeysUsing()` (F0 checks it exists in Laravel 13.39), React's in `__()`.
- **The check:** `tests/Architecture/AdminWordsTest.php` reads every `.tsx` under `resources/js` **not** listed in `tests/Architecture/admin-words-baseline.txt` (the 349 files on `main` at the time, by path) and fails on a JSX text node holding a letter, or a string literal holding a letter in `aria-label`, `title`, `placeholder`, `alt`, `label` or `description` props, outside `__()`/`__n()`. New screens are new files, so they follow it from their first line; an existing file leaves the baseline when it is converted (later). It skips Wayfinder's three generated folders (lesson 1.5·0). *(re-check: as built on `slice/6.0` the baseline is `path<TAB>word` lines with a pinned count, and a second test refuses `__()` at a module's top level; C0 says what 6.1a follows.)*
- **Rule, in `[A] docs/architecture/admin-design.md`** (the architect's): words through `__()`; no sentence built from pieces; numbers and dates through `Intl` with the admin's locale; a new screen's words in its own files.

**D22. Linking an import's remembered translations** (I-351). *Parked with 6.1h (I-3372).* 1.11 kept, in `import_map.facts`, each post's and term's Polylang `language` (Polylang's slug, `fr`) and `translations` (`{"en": 103, "fr": 104}`, slug → WordPress ID) (`ContentPhase.php:445-446`, `TermsPhase.php:176-178`).
- **`LinkImportedTranslations`** (Input: actor, source, `map: array<string, string>` Polylang slug → site language tag, `takeBackAddresses: bool`), run by `LinkImportedTranslationsJob` in batches of 500 objects, with the actor (lesson: a queued job carries its actor; a test runs it under `DeniedElevation`).
- **Groups by union, never pairwise** (Strapi's lesson, study §3.4): every map row of the source with `facts.translations` is read once; each WordPress ID named in any map is a node; every map joins all its IDs. A broken map (Polylang's "corrupted group" fixes) therefore joins rather than splits. Written out in 6.1h, Task L2.
- For each resulting set: the Teme items (`teme_id` of the rows whose `object_id` is in the set; terms by `facts.term_id`, 1.11e D13) get `language` = `stored(map[slug])` and one new group, **after** checking each set holds one item per language and one type; a set that doesn't is skipped and reported, never half-linked.
- **Old addresses back:** with `takeBackAddresses`, an item whose WordPress address (`facts.path`, its prefix included) would be its address with its slug's `-N` removed gets that slug, if free in its language: its own history row becomes current again (`PathProjection::sync()`), so `/fr/about/` answers 200 once more (study flag 7).
- A set whose type, or whose terms' taxonomy, isn't translated on this site (D25) is skipped and reported, never linked.
- Refused while a slug in `map` names no site language ("Add French in Settings › Languages first"). Never reads MultilingualPress or TranslatePress (study §4.6: later).

**D23. Permissions** (Q4 A): no new permission.

| Operation | Who |
|---|---|
| `TranslateContent` | `create` on the source's type (regions: `regions.manage`, as `CreateRegion`); and, for an unpublished source, `update` on it |
| `LinkTranslations`, `UnlinkTranslation`, `MarkTranslationUpToDate` | `update` on every item involved |
| `ChangeContentLanguage` | `update` on the item; for a published item, what `UpdateContentDetails` asks to change a published item's slug |
| `AddSiteLanguage`, `ChangeSiteLanguage`, `RemoveSiteLanguage` | `settings.general.manage` |
| `ChangeTranslatedTypes` (D25) | `settings.general.manage` |
| `TranslateSettings` | the group's own ability (`settings.general.manage`, `settings.seo.manage`, the theme's `settings.design.manage`) |
| `BindNavigationSlot` with a language | as today (`navigation.manage`) |
| `LinkImportedTranslations` | as starting an import (`AuthorizesImports`, `imports.run`) |

**D24. Costs at a million items.** Every address and lookup is the same index as today, the language a third column (`paths.path` unchanged). Lists in the default language filter `language IS NULL` on today's indexes (a 95%-default site discards a few rows a page). Lists in another language walk `contents_other_language_published`, which holds only other languages' published rows. The group lookups use `contents_translation_group_language_unique`. Search filters `language` beside its GIN index. 6.1z's benchmark scenario: a million items, 5% French and 1% Spanish (hidden), every listing and search page in French and English, each within its 1.9 budget and statement counts over the same page uncached unchanged except the one alternates statement on items.


**D25. Which types are translated** (I-3370; Polylang's `post_types` and `taxonomies` options). The site owner chooses, per content type and per taxonomy, which can have versions in other languages, on Settings › Languages.
- **Stored** in D2's group as what is *not* translated: `untranslated_types` and `untranslated_taxonomies`, default `[]`. Polylang stores the chosen ones; Teme stores the exceptions, so a type added later (a site's own, a plugin's) is translated without a setting naming it, and no default names a type (`NoTypeLiteralsTest`; types live in the database). Read through `SiteLanguages::translates()` and `translatesTaxonomy()`.
- **A type that isn't translated:** its items are always in the default language (`language` NULL, no group), so their addresses carry no prefix and nothing about them moves. `CreateDraft` with a language, `TranslateContent`, `LinkTranslations` and `ChangeContentLanguage` refuse it (*Every word*). `EditorSession` gives it no `languages`, no `translations` and `can.translate` false, so the screens draw no Language row, no Translate action and no Translations panel; the lists offer it no Language column or filter.
- **A taxonomy that isn't translated:** its terms are always in the default language and shared by every language. `TermRules::usableBy()` applies D13's language predicate only to translated taxonomies, so a French post may be filed under its terms; `TermSiblings::inLanguage()` and Translate keep its terms unchanged; `CreateTerm` refuses a language for it. Its archive has one address, without a prefix, listing the default language's items (**accepted for this release by the maintainers, I-3373 (3)**; T1's test holds it; *For later* 17).
- **Changing it:** `ChangeTranslatedTypes` (`ChangeTranslatedTypesInput(?int $actorId, list<string> $untranslatedTypes, list<string> $untranslatedTaxonomies, int $expectedVersion)`; `settings.general.manage`; through `SettingsWriter` under the group's version check). Every key must be a registered type or taxonomy. Turning translation **off** for a type is refused while any item of it, trash included, has a language or a group (`language IS NOT NULL OR translation_group IS NOT NULL`, one statement a type), naming the count and what to do first; for a taxonomy, the same over `terms`. Turning it **on** moves nothing. After commit: the writer's purge of `settings`.
- **The importer** skips a Polylang group whose type or taxonomy isn't translated, and reports it (D22).
---

## The WordPress file audit (I-749)

WordPress has no multilingual content in core (study §3.4); what it has is the language of the site, the person, and words.

| WordPress file | What it does | Teme |
|---|---|---|
| `wp-includes/general-template.php:4816` `get_language_attributes()` | Writes `dir="rtl"` from `is_rtl()` and `lang` from the site's language | **Kept, improved** (D20): `lang` and `dir` from the page's language, not the site's |
| `wp-includes/l10n.php:124` `determine_locale()` | The admin in the person's locale (`get_user_locale()`, `:94`), the login page by a `wp_lang` cookie or query, the public site in the site's | **Kept** for the admin (D21); **dropped**: the cookie and query (rule 8: the page cache can't key on them; the address decides, D6) |
| `wp-includes/l10n.php:1908` `switch_to_locale()`, `class-wp-locale-switcher.php` | Switches the locale for one task (an email in a user's language) | **Kept** as Laravel's `->locale()`, for the first email that reaches a reader (D20; none does today) |
| `wp-includes/l10n.php:1083` `load_theme_textdomain()` | A theme's `.mo` files under its text domain | **Improved** (D20): JSON keyed by English in the theme's `lang/`, loaded for the active theme only |
| `wp-includes/l10n.php:1143` `load_script_textdomain()` | JSON per script handle for the block editor | **Improved** (D21): one catalogue for PHP and React, sent once a visit |
| `wp-includes/l10n.php:1890` `is_rtl()`, `class-wp-locale.php` (`text_direction`) | Direction from the locale's own translation file | **Improved**: from `config/languages.php`, so a language with no admin translation still draws right to left for readers |

(Paths are `[R] wordpress-develop-trunk/src/`, each line checked 2026-11-11.)

---

## Global Constraints

- **Rule 1:** every change is an Operation with an authorization failure test proving it writes nothing; controllers and middleware call Operations and query classes, never a model, a builder or `DB` (`LayeringTest`, `.ai/rules/http.md`). `SettingsWriter` is called only from Operations.
- **Rule 2:** no meta table. A language is a column; a group a column; translated settings one JSONB object per group row, governed by the registry's `translatable` flag; the languages list a settings group.
- **Rule 3:** every test on PostgreSQL; `NULLS NOT DISTINCT` and partial unique indexes are PostgreSQL's (15+; C0 reads the PostgreSQL 18 page in `docs/references.md`).
- **Rule 4:** nothing serialised; the import's maps are read as JSONB.
- **Rule 5:** `teme/contracts` gains three members, each last, with a default, `@since 0.6.0`; no framework type. 6.1a adds one (`SearchRequest::$language`, A5); the other two are parked with 6.1f.
- **Rule 6:** purges and hooks after commit (`purgeAfterCommit()`, `PendingNotifications`).
- **Rule 7:** a translation is a Tiptap document of its own; nothing translates rendered HTML (study §4.7).
- **Rule 9:** links stay by ID; Translate points them at the target language's versions (D8); prefixes and hosts are made at render.
- **`NoTypeLiteralsTest`:** no quoted `post`, `page`, `category`, `tag` (or plurals) beside `=>`, `===` or `!==` in `app/`; fixtures name types through `CoreTypes` and the registries.
- **`NoStaticStateTest`:** `SiteLanguages`, `PageLanguage`, `TranslationGroups` hold no static mutable state; `PageLanguage` is `scoped()`.
- **`DocumentationTest`:** every class opens with what it is for; every public query method says what it returns. **Comments** cite one I-number or one document path (I-797), never a D-number or a part's name.
- **Lessons:** shared test helpers are classes under `tests/Fixtures/Languages/`, never `tests/Pest.php`; JSONB key order never asserted with `toBe`; a "raises nothing" test names `Throwable`; `Artisan::output()` read once; no backslash-u escape; a test that pins a whole registry, route list, index list or column list reads its own entries only (grep `tests/` for the neighbours of every index, setting, preference and route this release adds, and name what breaks under *Modify*); `php artisan test --parallel` takes one path; Browser tests wait with `cmWaitUntil()` and run after `npm run build`; builders run only the Browser tests they add or change (the full set runs on GitHub, in four shares).
- **A test written here as a sentence is a specification:** the builder writes it as complete Pest code, runs it to fail for the right reason, then builds; none is committed empty.
- **Builders never write** `CHANGELOG.md` (6.1a's T2 does, with Z5 moved from the parked 6.1z), `docs/architecture/*`, `docs/ideas.md`, `docs/roadmap.md`, `.ai/`; `docs/reference/*` only where a task names its generator (`teme:docs:settings` in 6.1a and 6.1f, `teme:docs:theme` in 6.1c, `teme:docs:export` if 6.1a's export kind change has one).

---

## Building side by side: the order and the seams

**Why this cut (I-3371, I-3367):** the part that goes first carries every task the others build on or extend (I-3371): the schema and the language registry, addresses and routing, the core of translations (the Operations, groups, the source revision and the out-of-date mark), the content and term queries by language, and which types are translated. After it, four pieces of work are truly separate and run at the same time, each in its own files: what readers get (6.1c), reader words (6.1e), words per language for the admin and for settings, menus and regions (6.1f), and the import's linking (6.1h). The screens (6.1i) wait for their pictures (I-468) and for 6.1f's and 6.1h's words and Operations. The join proves the side-by-side parts together where they draw one page. **I-3372 (2026-11-11): only 6.1a ships now; every part after it is parked for stage 3**, and 6.1a carries the guards that keep default-language answers correct while they wait (A5).

### The order

| When | Runs side by side | Waits for, and why |
|---|---|---|
| **Once page parents' 4.5a merges** into `slice/4.5` | **6.1a**; page parents' **4.5b** if still running | 4.5a: 6.1a adds after its migrations, `TreeLock`, `TreeRules` and lifecycle Operations (*With page parents*) |
| **Once page parents merges into `main`** | — | 6.1a merges into `main` after page parents, never before (*Merge order*) |
| *Parked (stage 3):* once 6.1a merges | 6.1c, 6.1e, 6.1f's G tasks, 6.1h | 6.1a: every one reads its columns, registry, addresses, router, Operations and fixture |
| *Parked:* screens | 6.1i | the pictures adopted (P1 to P6); 6.1f's G tasks and 6.1h; the admin-words plan merged (its `AdminWordsTest` and `__()`) |
| *Parked:* join | 6.1z | every parked part |

### The seams *(for the parked parts; 6.1a's only seam is its fixture)*

| What a part would have read | It codes against instead | Lives in, made by | Replaced in |
|---|---|---|---|
| Rows in several languages, linked (every part's tests) | `tests/Fixtures/Languages/LanguageSite::build()`: English default, French shown (`fr`), Spanish hidden (`es`), a post and its French and Spanish versions, a page and its French version, a category and its French version, written with factories and the projections | 6.1a | none: real |
| The page language's home in the default theme's links (6.1e's views) | `$site->url`, as today | — | 6.1z: Z3 uses 6.1c's `$language->home` |
| A French page drawn whole: 6.1c's head, 6.1e's `lang`, `dir` and words, 6.1f's tagline, menu and region | each part's own tests on `/fr/…` | — | 6.1z: Z1 |
| Reader words in French (6.1e) | `lang/fr.json` read through `__()` with `PageLanguage::use('fr')` | 6.1e | 6.1z: `/fr/` shows them |

Gone with this re-cut: the group's purge tag as a seam (6.1a's Operations purge it and 6.1c records it, and 6.1c's tests run against the real Operations), and the Operations a screen calls as a seam for the editor (6.1a holds them).

**One owner for every shared file.** **6.1a:** the migrations, the three models' docblocks and casts, `config/languages.php`, `app/Domain/Languages/*` (as created, its `Operations/*` included), `app/Providers/LanguageServiceProvider.php`, `bootstrap/providers.php`, `app/Domain/Site/SiteLanguage.php`, `app/Domain/Contents/Slugs.php`, `app/Domain/Taxonomy/*`, `app/Domain/Contents/GroupLock.php`, `app/Domain/Cache/PageTags.php`, `routes/admin.php` (four `require` lines) and `routes/admin/*.php` (created empty; `languages.php` and `translations.php` filled here), `tests/Fixtures/Languages/`; the ~~four~~ five *(re-check)* per-row callers of D2; `app/Domain/Addresses/*`, `app/Domain/Site/{PathResolver,ListingViews,LatestViews,RelatedPosts,Breadcrumbs}.php`, `app/Http/Site/{PublicController,PublicPage}.php`, `app/Http/Admin/Settings/LanguagesController.php` (but `show()`'s props, 6.1i's), `app/Domain/Status/Checks/LanguageHomeShadowsRedirect.php`, `app/Providers/AddressServiceProvider.php`; `app/Domain/Contents/Operations/*` but `ReindexSearch.php`, `app/Domain/Contents/{DraftChecks,TranslationCopy}.php`, `app/Domain/Contents/Queries/{EditorSession,ContentList,TranslationStatus}.php`, `app/Domain/Export/Kinds/{ContentsKind,TermsKind}.php`, `app/Http/Admin/Contents/TranslationsController.php`; *(I-3372, A5 and C3, taken from the parked parts' lists below)* `packages/teme-contracts/src/Search/SearchRequest.php`, `app/Domain/Search/PostgresSiteSearch.php`, `app/Domain/Site/SearchViews.php`, `app/Domain/Contents/Operations/ReindexSearch.php`, `app/Domain/Sitemaps/Queries/ListedItems.php`, `app/Domain/Sitemaps/TermsSitemap.php`, `app/Domain/Feeds/FeedItems.php`, `[3.1]` `app/Domain/Api/Live.php`, `Items/ItemsQuery.php`, `Catalogue/TermsQuery.php`, `app/Domain/Regions/Regions.php`, `[4.1]` `app/Domain/Menus/NavigationTree.php`, `app/Domain/Site/CommentViews.php`, `app/Http/Site/UnlockController.php`, `CHANGELOG.md` (T2). **When stage 3 builds the parked parts**, each takes these files from 6.1a's merged code and replaces A5's `IS NULL` with its language-aware form. **The admin-words plan** (I-3373 (6), built beside page parents, before 6.1a) owns 6.1f's F files; it may create `app/Domain/Languages/` first (`AdminLanguage`, `PseudoLocale`), and 6.1a adds its own classes beside them and edits nothing of theirs. **6.1c (parked):** `app/Providers/HeadServiceProvider.php`, `app/Domain/Site/Head/{HeadTags,LanguageLinks}.php`, `app/Domain/Themes/ComposerRegistry.php`, `app/Domain/Languages/{LanguageComposer,Alternates}.php`, `app/Domain/Site/ViewModels/{PageLanguageView,Alternate}.php`, `app/Domain/Themes/Reference/ViewDataCatalogue.php`, `docs/reference/theme.md` and `docs/reference/theme-view-data.json` (regenerated), `app/Domain/Sitemaps/*`, `app/Domain/StructuredData/{CoreGraph,ListingNodes}.php`, `packages/teme-contracts/src/Search/SearchRequest.php`, `app/Domain/Search/*`, `app/Domain/Site/SearchViews.php`, `app/Domain/Contents/Operations/ReindexSearch.php`, `app/Domain/Feeds/*`, `app/Http/Site/Feeds/FeedController.php`, `app/Domain/Api/*`, `app/Http/Api/V1/*`. **6.1e (parked):** `app/View/Components/Teme/Document.php`, `resources/views/teme/**`, `themes/default/views/**`, `themes/default/lang/*`, `lang/{es,fr,pt_BR,de}.json`, `app/Http/Site/{PreviewController,CommentController,UnlockController,PendingCommentsController,CommentAnswers}.php`, `app/Domain/Site/RespondViews.php`, `app/Domain/Comments/Operations/SubmitComment.php`, `app/Providers/ThemeServiceProvider.php`. **6.1f (F files moved to the admin-words plan; G files parked):** `resources/js/lib/i18n.ts`, `resources/js/app.tsx`, `resources/js/types/index.ts`, `app/Http/Middleware/HandleInertiaRequests.php`, `app/Http/Admin/Middleware/UseAdminLanguage.php`, `bootstrap/app.php` (the middleware's one line), `resources/views/app.blade.php`, `app/Domain/Preferences/BuiltInPreferences.php`, `config/teme.php`, `app/Providers/LanguageServiceProvider.php` (after 6.1a), `tests/Architecture/AdminWordsTest.php` and its baseline; `packages/teme-contracts/src/Settings/{Setting,SettingsReader,SettingsChanged}.php`, `app/Domain/Settings/*`, `app/Providers/SettingsServiceProvider.php`, `app/Domain/Seo/TitlesAndListing.php`, `app/Domain/Menus/*`, `app/Domain/Regions/Regions.php`, `app/Http/Admin/NavigationController.php`, `app/Domain/Export/Kinds/SettingsKind.php`, `routes/admin/settings-words.php`. **6.1h (parked):** `app/Domain/Import/Translations/*`, `app/Domain/Import/Operations/LinkImportedTranslations.php` and its Input, `app/Domain/Import/Jobs/LinkImportedTranslationsJob.php`, `app/Http/Admin/Tools/Import/ImportTranslationsController.php`, `routes/admin/import-translations.php`. **6.1i (parked):** its pages and components under `resources/js`, `BuiltInPreferences::columnsOf()`'s `language` (after 6.1f), `LinkChoicesController`, and `LanguagesController::show()`. **6.1z (parked):** `CHANGELOG.md`'s later lines (6.1a writes the slice's first, T2). Nobody edits `tests/Pest.php`. The architect checks `git diff --stat` of each part against the others before merging.

### What stays in order, and why

- **Page parents before 6.1a** (I-3373's order): 6.1a adds after 4.5a's migrations, tree lock, `TreeRules` and lifecycle Operations, and merges into `main` after page parents.
- **6.1a before every other part:** every part reads its columns, registry, addresses and Operations; one owner for the NULL rule, the prefix and the group's lock order keeps four builders storing, addressing and linking languages alike.
- *The rest is for the parked parts:*
- **The pictures before 6.1i** (I-468, feedback "show designs first"): none of the screens is drawn in the v9 demo (*Every word*, "What v9 draws"), so each is drawn, shown to the maintainers as options, and adopted on his word.
- **6.1f and 6.1h before 6.1i:** the screens are the first users of the admin's words foundation (I-3360) and call 6.1f's and 6.1h's Operations.
- **6.1z last:** its end-to-end and leak tests need every half.

**What the heavier first part costs:** nothing else starts until 6.1a merges, so a late failure in it holds every other part (all parked now; in I-3373's order it holds block IDs step 2 and bylines, which come after it). **How the plan contains it:** *When a session fails*: every task ends green and pushed, and 6.1a's tasks are ordered so the schema, the registry and the fixture (C1 to C5) land first, then addresses (A1 to A4), then the guards (A5), then translations (B1 to B6), then the per-type switch (T1), then the changelog and gate (T2); a restart continues from the last checkpoint. **Where side by side still costs:** 6.1e's theme views and 6.1c's language home, and the one French page 6.1c, 6.1e and 6.1f draw together, meet only at the join; Z1 and Z3 wire them, and the fixture is the one place every part's rows come from.

---

## When a session fails

Parts are large, so a session that fails late must lose nothing. Every part's tasks follow these rules.
- **Checkpoints.** Every task ends committed and pushed, with one line added under its part's records: "Done to Task N at `<hash>`, the quick check green". *(re-check)* A commit can't name its own hash, so Task N's line rides in Task N+1's commit (T2's in the records commit), as page parents' records did. The branch on GitHub always shows the last safe point. No task is left half-committed when a session ends; a task too large to finish in one go is split into steps that each end green and pushed.
- **The container can't install PHP:** try the install rule's three signature-checked ways; after about an hour, stop, push, and record "waiting for PHP".
- **PostgreSQL or Valkey stops mid-run:** start them again (`docker compose up -d`) and run the check again before believing any failure.
- **A test fails at the base and isn't this part's:** record it; take the architect's fix by cherry-pick when given; never change it otherwise. *(re-check)* Before believing a base failure in `tests/Feature/Backups` or `tests/Feature/Deployment`, check the checkout's `.env` has `TEME_POSTGRES_BINDIR=/usr/lib/postgresql/18/bin`: page parents' 87 base failures were a second checkout using PostgreSQL 16's `pg_dump` (its Follow-up S1 records).
- **The session runs out of room or time:** stop at the last green checkpoint, push, and record exactly where it is and what comes next.
- **Resuming.** A restarted session continues the same branch from its last checkpoint and never branches again; it reads the records first; it treats anything after the last green checkpoint as untested and proves it (break the code, see the test fail, restore); it never rewrites history.
- **Drafted code that couldn't be run** is pushed to `wip/<part>-drafts` (`wip/6.1a-drafts`), never to the part's branch, so a restart can use it.

---

# Part 6.1a: The foundation: languages, addresses, translations and which types are translated

From `slice/6.1` (the architect makes it once 4.1, 3.1 and 5.1 are on `main`, from page parents' code: `main` once page parents has merged, or `slice/4.5` once 4.5a has merged into it; *Merge order* below), on `slice/6.1a-foundation`; **Opus** (it decides what every stored language, every address and every group means). The first part (I-3371), and the only one that ships now (I-3372): everything the other parts build on or extend, with the guards that keep default-language answers correct while they are parked. Tasks C1 to C5 lay the schema, the registry and the fixture; A1 to A4 give other languages their addresses, their routes and the site's language Operations; A5 *(review)* the default-language guards while the other parts are parked; B1 to B6 the core of translations: drafts and terms in a language, Translate, links, out of date, the lifecycle's group rules, and the data the editor, the lists and the export read; T1 which types are translated; T2 writes the changelog (moved from 6.1z) and closes it. It is a long part: every task follows *When a session fails*. The architect merges it into `slice/6.1`, then `slice/6.1` into `main` after page parents, and tags.

## With page parents *(review)*

Page parents (`[4.5]`) merges into `main` first and owns every file both slices touch (its *The overlap with the multilingual plan*); 6.1a starts from its merged code and adds after it. What 6.1a adds, from its D22 and I-3363/I-3368:
- **The same language:** `TreeRules::refusal()` gains "the parent is in the item's language" (B6); `ParentChoices` would offer the item's language only, but it is page parents' 4.5d, parked (I-3372), so that filter waits with it; `TranslateContent` puts a page's version under its parent's version in that language, or at the top with a note (B2); `ChangeContentLanguage` refuses a page with a parent or pages under it (D10, B3; the maintainers' decision, I-3373 (2)). *(re-check, against `TreeRules` as built: an instance method, `refusal(Content $item, ?string $parentId): ?string`, `TreeRules.php:24`, resolved from the container.)* The same-language rule is one more check in it, after the type check (`:46`); its parent read, `Content::withTrashed()->find($parentId, ['id', 'type', 'deleted_at'])` (`:36`), gains `language`, and the item's is read from the model given (`UpdateContentDetails` passes a clone of the locked row, `withSlug()`, `:335`; `PlaceImportedPage` its locked page, `:190`, so Bring back leaves a page whose parent is in another language, with its `page.refused` words). The refusal in `ChangeContentLanguage` is not a `TreeRules` rule: under the tree lock, `$item->parent_id !== null || app(ContentTree::class)->hasChildren($id)` (`ContentTree.php:102`, `EXISTS … WHERE parent_id = ?`, which counts trashed children, as D10 wants).
- **Addresses:** the prefix goes before the whole address, outside the pattern, after `[4.5]`'s `{parent}` chain is filled (`wouldHave(…, parentPath:)` keeps its last parameter; the prefix is applied to what it returns). The chain is made of the French ancestors' own slugs (`fr/a-propos/equipe`), which the same-language rule guarantees; I-3368's rule (only parents a reader can see) is unchanged, and a hidden language's pages are hidden whole.
- **Locks:** D5's order, languages lock, `TreeLock`, the group's rows by ID, terms by ID. ~~`[4.5]` takes `TreeLock::take($type)` first in `perform()` of `UpdateContentDetails`, `ReorderContent` and the six lifecycle Operations~~ *(re-check, as built)* `[4.5]` takes the tree lock first, before any row lock: `TreeLock::take()` directly in `UpdateContentDetails.php:204` (**only when a slug or a place is given**, the type read unlocked at `:200`), `ReorderContent.php:66` and `RebuildPaths.php:104` (subtree mode only); through `FollowsTree::takeTree()` (`Concerns/FollowsTree.php:29`, which calls `SubtreeAddresses::lock()`) in `PublishRevision.php:119`, `UnpublishContent.php:66`, `TrashContent.php:64`, `RestoreContent.php:60`, `ChangeVisibility.php:123`, `DeleteContentPermanently.php:67` and `UpdateImportedContent.php:68`; through `SubtreeAddresses::lock()` in the two outer Operations, `RunScheduledPublication.php:69` and `ApproveReview.php:57`; and in `PlaceImportedPage` after its map object (`:78`, `:80`). 6.1a's group lock always comes after it, never before; 6.1a's new Operations that need the tree (`TranslateContent`, `ChangeContentLanguage`) take it with `SubtreeAddresses::lock($type)`.
- *(re-check)* **Page parents' fast address path** (`SubtreeAddresses::addresses()`, `:251-252`): for a pattern of `{slug}`, `{id}` and `{parent}` only, it builds a stub, `(new Content)->forceFill(['id' => $id, 'type' => $type])`, and calls `Addresses::wouldHave()` with the chain. The stub has no `language`, so a prefix read from the row would leave every French page under a renamed, published or hidden French page at an address without `fr/`, and its clash check would test the wrong addresses. A1 adds `'language' => $item->language` to the stub (the same-language rule makes every page under the item share its language), and `wouldHave()` reads `language` only from attributes the model holds (`array_key_exists('language', $content->getAttributes())`), else one primary-key read, as D2 says of `forContent()`. The slow path (`:238`) loads whole rows and needs nothing; the move itself (`:146`) locks whole rows too.
- **Shared files** (page parents first, 6.1a after): the `contents` migrations (its four `2027_03_20`, then `2027_04_01`), `Content.php`'s docblock, `Addresses.php`, `UpdateContentDetails.php` and its Input, the six lifecycle Operations, `RebuildPaths.php`, `PathProjection.php`, `TreeRules.php`, `EditorSession.php` and `ContentList*.php` (page parents' 4.5d, which edits them, is parked), `ContentsKind.php` and `export.md`; and, from page parents' 4.5b, `Breadcrumbs.php`, `[3.1]` `ItemShapes.php` and `ItemsQuery.php`, and `CHANGELOG.md`; *(re-check)* and `app/Domain/Contents/Tree/SubtreeAddresses.php` (the stub's language, above) and `app/Domain/Api/Items/ItemQuery.php` (the ancestors' language, D2). The rest of its overlap table (the view data, the API's members, the admin's screens, the importer) belongs to parked parts.
- **Not done here:** slugs unique among siblings (`[4.5]` *For later* 5) stays later.

### Merge order *(I-3373, 2026-11-11)*

Page parents merges into `main` first: 4.5a, then 4.5b, then `slice/4.5` into `main`. 6.1a builds after 4.5a merges, in one of two ways, the architect's choice when 6.1a starts:
- **After page parents:** `slice/6.1` from `main` once `slice/4.5` has merged into it. Nothing else to do.
- **Beside 4.5b:** `slice/6.1` from `slice/4.5` once 4.5a has merged into it. 6.1a then never rebases (*When a session fails*: no history is rewritten); once `slice/4.5` is on `main`, the architect merges `main` into `slice/6.1` (4.5b's files above conflict where both add; 4.5b's lines stay and 6.1a's follow them), and 6.1a's builder merges `slice/6.1` into its branch and runs the full check again before 6.1a merges.

Either way 6.1a's migrations are dated after page parents' (`2027_04_01` after `2027_03_20`), and nothing of 6.1a reaches `main` before page parents.

*(re-check, 2026-11-12)* **Only the first way is left.** `slice/4.5` holds both parts and `main` (`3e4a3ad6` 4.5a, `35a28775` 4.5b, `09f3b6e9` main merged in), so `slice/6.1` is made from `main` once `slice/4.5` has merged into it; "beside 4.5b" no longer applies anywhere in this part.

### Shared files with the parts built now *(re-check, 2026-11-12)*

Four parts are built while 6.1a is, and merge after it or beside it: privacy tools (5.5), notices (5.6), public CSP (6.4a) and block IDs step 2 (`[A] docs/superpowers/plans/2026-11-12-{privacy-tools,notices,public-csp,block-ids-step2}.md`). Their migrations are dated `2027_05_01`, `2027_05_02` and `2027_05_20`, after 6.1a's `2027_04_01`; block IDs adds none.

| File | 6.1a does | The other part does | Owner and order |
|---|---|---|---|
| `bootstrap/providers.php` | adds `LanguageServiceProvider::class` (none exists: the admin-words slice added only `app/Domain/Languages/AdminWords.php`) | notices adds `NoticeServiceProvider::class` after `PreferenceServiceProvider::class` | **6.1a**, merges first. Put its line right after `SettingsServiceProvider::class` (it registers a settings group), well away from `PreferenceServiceProvider::class`, so the two hunks don't touch |
| `app/Http/Site/PublicPage.php` | `error()` reads the language off the request path's prefix (A2) | 6.4a calls `app(PagePolicy::class)->start()` as the first statement of `render()` and `error()`, and `secured()` at their ends | Either order works if 6.1a **keeps both signatures unchanged** (`render(ViewIntent $intent, object $model, int $status = 200, int $pageNumber = 1): Response`, `:70`; `error(HttpExceptionInterface $exception): Response`, `:87`): 6.4a's A0 STOPs if either changed. The CSP plan names 6.1a the maintainers, merging first; if 6.4a reaches `main` first, 6.1a's builder keeps `start()` first and `secured()` last and puts the prefix read between. `Content-Language`, set in `PublicController`, is stored with the page (`PageCache::PER_REQUEST_HEADERS`, `PageCache.php:97`, doesn't list it), as CSP's headers are |
| `app/Domain/Cache/PageTags.php` | adds `translations()` only | privacy's `CommentPurges` calls it, changing nothing | 6.1a; no existing method's signature changes |
| `app/Domain/Contents/Operations/Concerns/WritesImportedContent.php` | **nothing**: the importer's revisions leave `source_revision_id` out, so it is NULL (D9) | block IDs step 2 changes `writeImported()` | **block IDs**; 6.1a's "`app/Domain/Contents/Operations/*`" never includes `Concerns/WritesImportedContent.php`. Block IDs merges after 6.1a |
| `tests/Architecture/admin-words-baseline.txt`, `AdminWordsTest.php` | nothing (C2's placeholder adds no line) | notices removes six lines and lowers the count | notices; 6.1a never edits them |
| `CHANGELOG.md` | T2 | none of the four writes it (the architect's, at each merge) | 6.1a, after page parents' lines (T2) |

Nothing else is shared: privacy's `HookCatalogue.php`, `RoleSeeder.php`, `routes/console.php`; notices' `BuiltInPreferences.php` and mail files; 6.4a's `Document.php`, `HeadServiceProvider.php` and theme files; block IDs' `TextBlocks.php`, `BlockConverter.php` and `tests/Pest.php` are none of 6.1a's (its parked 6.1c, 6.1e and 6.1i touch some; they come later).

## While the other parts are parked *(review)*

The maintainers parked 6.1c, 6.1e, 6.1f, 6.1h, 6.1i and 6.1z on 2026-11-11, so 6.1a may ship alone. Once 6.1a is on a site, a translation can exist (through its routes, even with no screen yet), so every reader-facing answer that no shipped part teaches about languages must give the **default language only**, and a hidden language nothing. What 6.1a already holds, and what it must take:

| Surface | Without a guard | 6.1a holds | 6.1a must take (from) |
|---|---|---|---|
| Archives, the home, author and date lists, related posts, the trail | French items in English lists | A2, A3 (`ListingViews`, `LatestViews`, `RelatedPosts`, `Breadcrumbs`) | — |
| A page's `lang`, dates, `ItemRef::$language`, the API's `language` | — | C3 (`forContent()` reads the row) | — |
| Search (`/?s=`, and `/fr/?s=` which A2 routes) | every language found, hidden ones included, with English rules | — | 6.1c **S1** whole: `SearchRequest::$language` (last, a default), `PostgresSiteSearch`'s predicate, `SearchViews` passes `PageLanguage::stored()` |
| The search rebuild | French rows rebuilt for ever; a release's rebuild never ends | — | 6.1c **S2** whole, in C3 |
| Sitemaps and `llms.txt` | every language's items and terms listed, hidden ones included | — | from 6.1c **H3**, only the predicate: `ListedItems`' list methods read `language IS NULL`. *(Checked 2026-11-11 on `[3.1]`: it has six public methods, not five: `starts()`, `page()`, `latestListed()` (`llms.txt`'s), `any()` and `newest()` list, and take the predicate; `listed($id)` answers one item, below.)* `TermsSitemap` (`app/Domain/Sitemaps/TermsSitemap.php`, its `listed()` SQL) reads `term.language IS NULL`. No alternates |
| `.md` pages | a hidden item's text served | — | `ListedItems::listed($id)`, which `[3.1]` `MarkdownPages::find()` calls (`:89`): `language IS NULL OR language = ANY(shown)`, so a `.md` page answers for a shown language only (D19), as `/items/{id}` does, and a hidden one's never |
| Feeds (`/feed/`, a term's, an author's) | French and hidden items in the default feeds | `/fr/feed/` 404s (A3) | from 6.1c **S3**, only `FeedItems::ids()`' `language IS NULL` |
| The read API | `/items` and `/terms` list every language, hidden ones included; `/items/{id}` and `by-path` answer a hidden item | — | from 6.1c **S4**, the musts only: `ItemsQuery` and `TermsQuery` `language IS NULL`; `Live::apply()` and `TermsQuery`'s single term refuse a hidden language; no `?language=`, `translations` or `languages` |
| Regions | `Regions::published()` reads by slug alone (`[4.1] Regions.php:51`), so a French version could draw on English pages | — | `whereNull('contents.language')` until 6.1f's G4 (parked) reads the page's language |
| *(re-check)* Page parents' reading side (`[4.5]`, built after this table was written) | a French child in an English list; a hidden parent named | `?parent=` is a list: `ItemsQuery`'s `language IS NULL` (below) makes a French parent's answer the empty list an unknown parent gets. `ancestors` (`ItemQuery::ancestors()`, `:125-157`) and `parent_id` (`ItemShapes::parents()`, `:231-242`) read through `Live::items()`, so `Live::apply()`'s hidden-language predicate covers them; they answer a single item in any shown language and take no default-only predicate. `PageFamily` (the view data's `parent`, `ancestors`, `children`), the trail and its BreadcrumbList are drawn only on a page in its own language, and the same-language rule (B6) keeps a page's family in it, so they need no predicate; the trail's Home is A3's | the A5 API test's two extra cases (below) |
| Menus and `entry` fields | a link to a hidden item | — | D7, 12 (A3) |
| Comment pages, old addresses, comment and password routes | a hidden item reached | — | D7, 9 to 11 (A3) |
| Settings words, menus per language, `hreflang`, `og:locale`, `dir`, reader words | a French page shows the default's title, tagline and menu, core's English words, no alternates | nothing to guard: Q5 A's fallback is what readers would see anyway | — |

These are **Task A5** below. Each predicate is the one the parked part would have written, so that part later replaces `IS NULL` with its language-aware form rather than undoing anything.

**Maintainers' decisions** this raised, **decided 2026-11-11 (I-3373 (1) to (3))**: **(a)** 6.1a ships as the foundation only: Settings › Languages is a placeholder with **no form to add a language** (C2's test proves it; the screen is stage 3); a language can be added only by a request to A4's routes or an Operation called by hand. **(b)** `ChangeContentLanguage` on a page with a parent or pages under it: **refused** (D10, B3; no longer provisional). **(c)** D25's untranslated taxonomy: its one archive lists **default-language items only** (accepted for this release; T1's test; *For later* 17).

**The guards, confirmed one by one** (each in A5 unless named; each test a pair, the default item found and the other languages' not, with the page cache on and off):

| Guard the review listed | Where | Test |
|---|---|---|
| Search by language | `SearchRequest::$language`, `PostgresSiteSearch`, `SearchViews` (6.1c's S1, whole) | S1's five tests, in A5 |
| The search rebuild | `ReindexSearch::waiting()` per row (6.1c's S2, whole) | C3, `ReindexPerLanguageTest` |
| Sitemaps (items) | `ListedItems::starts()`, `page()`, `any()`, `newest()` | A5, sitemaps |
| `TermsSitemap` | `TermsSitemap`'s listed terms, `term.language IS NULL` | A5, sitemaps |
| `llms.txt` | `ListedItems::latestListed()` | A5, `llms.txt` |
| The `.md` pages | `ListedItems::listed()`: shown languages only | A5, `.md` |
| `FeedItems` | `FeedItems::ids()` | A5, feeds |
| The read API | `ItemsQuery`, `TermsQuery` (list and single), `Live::apply()` | A5, API |
| Regions in the default language | `Regions::published()` | A5, regions |
| *(re-check)* Page parents' `?parent=`, `ancestors`, `parent_id` | `ItemsQuery` (the list), `Live::apply()` (the rest) | A5, API (its two extra cases) |
| *(re-check)* Page parents' family and trail | the same-language rule; the trail's Home | B6 (`refuses a parent in another language`), A3 (the breadcrumb) |
| Menus and `entry` fields; comment pages, old addresses, comment and password routes | D7, 9 to 12 | A3 |

## Files (6.1a)

- **Create:** the five migrations of D4; `app/Domain/Languages/{SiteLanguages,PageLanguage,TranslationGroups,LanguagesGroup,PrefixConflicts,DefaultLanguageCheck}.php`; `app/Domain/Languages/Operations/{AddSiteLanguage,ChangeSiteLanguage,RemoveSiteLanguage}.php` and their Inputs under `Operations/Input/`; `app/Domain/Contents/GroupLock.php`; `app/Providers/LanguageServiceProvider.php`; `app/Http/Admin/Settings/LanguagesController.php` (`show()` in C2; `store`, `update`, `destroy` in A4); `resources/js/pages/settings/languages.tsx` (a placeholder: the shell, the heading, nothing else; **no form**, I-3373 (1); its heading through `__()` once the admin-words plan has merged, C0); `routes/admin/{languages,translations}.php` (*(review)* the `settings-words` and `import-translations` stubs are dropped: their parts are parked; `languages.php` holds the `settings.languages.show` route, then A4's); `app/Domain/Languages/LanguageLock.php` (D5 step 0); `app/Domain/Status/Checks/LanguageHomeShadowsRedirect.php`; `tests/Fixtures/Languages/LanguageSite.php`; `tests/Feature/Languages/{LanguageSchemaTest,LanguageColumnsTest,SiteLanguagesTest,PageLanguageTest,SlugsPerLanguageTest,LanguageSiteFixtureTest}.php`; `tests/Feature/Languages/{LanguageAddressesTest,LanguageRoutingTest,FrontPerLanguageTest,HiddenLanguageTest,ListingsPerLanguageTest,SiteLanguageOperationsTest,PrefixConflictsTest,DefaultLanguageRuleTest,LanguageBannerTest}.php`; `app/Domain/Contents/Operations/{TranslateContent,LinkTranslations,UnlinkTranslation,ChangeContentLanguage,MarkTranslationUpToDate}.php` and their Inputs; `app/Domain/Contents/TranslationCopy.php`; `app/Domain/Contents/Queries/TranslationStatus.php`; `app/Domain/Taxonomy/TermSiblings.php`; `app/Http/Admin/Contents/TranslationsController.php`; `tests/Feature/Translations/{TranslateContentTest,LinkTranslationsTest,ChangeContentLanguageTest,OutOfDateTest,GroupLifecycleTest,TermsPerLanguageTest,TranslationScreensDataTest,TranslationRoutesTest}.php`; `app/Domain/Languages/Operations/ChangeTranslatedTypes.php` and its Input; `tests/Feature/Languages/TranslatedTypesTest.php`.
- **Modify:** `config/languages.php` (three keys a language); `bootstrap/providers.php` (the provider); `routes/admin.php` (two `require __DIR__.'/admin/….php';` inside the verified group, before `settings/{group}`); `app/Domain/Site/SiteLanguage.php`; `app/Domain/Contents/Slugs.php`; `app/Domain/Taxonomy/TermSlugs.php`; `app/Domain/Cache/PageTags.php`; `app/Domain/Contents/Models/Content.php`, `ContentRevision.php`, `app/Domain/Taxonomy/Models/Term.php`, `app/Domain/Settings/Models/SettingsGroupRecord.php`; the ~~four~~ five *(re-check)* callers of D2 (`[4.1] app/Domain/Site/ListingViews.php`, `[4.1] app/Domain/Site/FieldViews.php`, `[3.1] app/Domain/Api/Items/FieldValues.php`, `[3.1] app/Domain/Api/Items/ItemShapes.php`, `[4.5] app/Domain/Api/Items/ItemQuery.php`); `tests/Feature/Site/SiteLanguageTest.php` (two tests added); `docs/reference/settings.md` (regenerated by `php artisan teme:docs:settings`); `app/Domain/Addresses/{Addresses,Paths,PathLinkResolver}.php`, `app/Domain/Addresses/Jobs/RecomputeAddresses.php` (last parameter `?string $language = null`), `app/Domain/Site/{PathResolver,ListingViews,LatestViews,RelatedPosts,Breadcrumbs}.php`, `app/Http/Site/{PublicController,PublicPage}.php`, `app/Providers/AddressServiceProvider.php` (the banner, the check); `CreateDraft.php` and `CreateDraftInput.php` (`?string $language = null`, last), `SaveDraft.php`, `CheckpointDraft.php`, `PublishRevision.php`, `UnpublishContent.php`, `TrashContent.php`, `RestoreContent.php` (and its result), `ChangeVisibility.php`, `DeleteContentPermanently.php`, `UpdateContentDetails.php`, `DraftChecks.php`; `app/Domain/Taxonomy/{TermRules,Operations/CreateTerm,Operations/Input/CreateTermInput,Operations/UpdateTerm}.php`; `app/Domain/Contents/Queries/{EditorSession,ContentList}.php` and `ContentListRequest`; `app/Domain/Export/Kinds/{ContentsKind,TermsKind}.php`; `routes/admin/translations.php`; `tests/Feature/Languages/LanguageColumnsTest.php` (its walk of writers); `docs/reference/export.md` (regenerated by `php artisan teme:docs:export`). *(review)* After page parents (`[4.5]`, *With page parents* below): `app/Domain/Contents/Tree/TreeRules.php` (the same-language rule), `app/Domain/Addresses/Operations/RebuildPaths.php` (`MAY_ASK`, A4); not `ParentChoices.php`, which is page parents' parked 4.5d (*(re-check)* confirmed: no `ParentChoices` on `[4.5]`). *(re-check)* Also `app/Domain/Contents/Tree/SubtreeAddresses.php` (A1: the stub's language), `app/Domain/Api/Items/ItemQuery.php` (C3: the ancestors' language) and `app/Domain/Site/Breadcrumbs.php`'s `toItem()` (A3: the language's home). For the guards while the other parts are parked (*While the other parts are parked*): `app/Domain/Search/PostgresSiteSearch.php`, `packages/teme-contracts/src/Search/SearchRequest.php`, `app/Domain/Site/SearchViews.php`, `app/Domain/Contents/Operations/ReindexSearch.php`, `[3.1] app/Domain/Sitemaps/Queries/ListedItems.php`, `app/Domain/Sitemaps/TermsSitemap.php`, `app/Domain/Feeds/FeedItems.php`, `[3.1] app/Domain/Api/Live.php`, `[3.1] app/Domain/Api/Items/ItemsQuery.php`, `[3.1] app/Domain/Api/Catalogue/TermsQuery.php`, `app/Domain/Regions/Regions.php`, `[4.1] app/Domain/Menus/NavigationTree.php`, `app/Domain/Site/CommentViews.php`, `app/Http/Site/UnlockController.php`. And `CHANGELOG.md` (T2, moved from 6.1z's Z5). *Modify, for whole-list pins:* grep `tests/` for `ContentsKind`'s and `TermsKind`'s field lists and for `EditorSession`'s keys (`toEqualCanonicalizing` lists), and name each under the records.

## Task C0: Preconditions, then branch

Each line was read at `525c51b5` and the three branches on 2026-11-11. If any differs by its text, STOP and write why under the records heading at the end of this file.

- [ ] **Step 1:** `git fetch origin && git log --oneline --merges -16 origin/slice/6.1` shows the merges of `slice/4.1`, `slice/3.1`, `slice/5.1` into `main`, and *(review, I-3373's order)* page parents: either `slice/4.5` into `main`, or `slice/4.5a-tree` into `slice/4.5` (6.1a beside 4.5b, *Merge order*); record which, and the head. If neither, STOP. *(re-check)* Now: `slice/4.5` into `main` (its head was `09f3b6e9`, holding `3e4a3ad6` 4.5a, `35a28775` 4.5b and `3527d686`, the admin-words slice, `slice/6.0`); the "beside 4.5b" way is gone. `php artisan migrate:status` lists the pending migrations; read them before running any.
- [ ] **Step 2:** each must match by its text:
  - `ls database/migrations | tail -3` ends with `2027_03_20_100400_nest_page_addresses.php` (*(review)* page parents' last; was 5.1's `2027_03_10_100100`), nothing dated 2027-04 or later;
  - *(review)* page parents as merged: `grep -n "class TreeLock" app/Domain/Contents/Tree/TreeLock.php`; `grep -n "public function refusal(Content \$item, ?string \$parentId): ?string" app/Domain/Contents/Tree/TreeRules.php` (an instance method, never static: page parents' D6 as reviewed); `grep -n "?string \$parentPath = null" app/Domain/Addresses/Addresses.php` (on `wouldHave()` and `forContent()`); ~~`grep -n "TreeLock::take" app/Domain/Contents/Operations/*.php`~~ *(re-check: that finds only `UpdateContentDetails.php:204` and `ReorderContent.php:66`)* `grep -n "takeTree()\|SubtreeAddresses::class)->lock(\|TreeLock::take(" app/Domain/Contents/Operations/*.php app/Domain/Addresses/Operations/RebuildPaths.php app/Domain/Import/Operations/PlaceImportedPage.php` → the twelve places *With page parents* lists under *Locks* (which Operations take the tree lock, and where: GroupLock comes after it); `grep -n "public function lock(?string \$type): bool" app/Domain/Contents/Tree/SubtreeAddresses.php` → `:96`; `grep -n "forceFill(\['id' => \$id, 'type' => \$type\])" app/Domain/Contents/Tree/SubtreeAddresses.php` → `:251` (A1); `grep -n "public function hasChildren(string \$id): bool" app/Domain/Contents/Tree/ContentTree.php` → `:102` (B3); `grep -n "find(\$parentId, \['id', 'type', 'deleted_at'\])" app/Domain/Contents/Tree/TreeRules.php` → `:36` (B6); `grep -n "return \[\$this->home(), ...array_values(array_filter(\$ancestors, fn (Crumb \$crumb): bool => \$crumb->url !== '/'))" app/Domain/Site/Breadcrumbs.php` → `:49` (A3); `grep -n "this->language->forContent((string) \$ancestor->id)" app/Domain/Api/Items/ItemQuery.php` → `:157` (C3); `grep -n "public ?TreePlace \$place = null" app/Domain/Contents/Operations/Input/UpdateContentDetailsInput.php`; `grep -rn "class ParentChoices" app` → nothing (page parents' 4.5d is parked); record each;
  - *(I-3373 (6))* whether the admin-words plan has merged: `ls tests/Architecture/AdminWordsTest.php resources/js/lib/i18n.ts`. If both exist, C2's placeholder writes its words through `__()` from `@/lib/i18n` and leaves `admin-words-baseline.txt` alone (it is a new file); read what that plan created under `app/Domain/Languages/` and `app/Providers/` (a `LanguageServiceProvider` already there is 6.1a's to extend, not create). If neither exists, the placeholder writes its heading as today's screens do. Record which; *(re-check)* **merged** (`slice/6.0`, `3527d686`): both exist, with `lang/en.json`; `app/Domain/Languages/AdminWords.php` is there (6.1a adds beside it, editing nothing of it) and **no** `LanguageServiceProvider` (6.1a creates it). The built check is not D21's description: `admin-words-baseline.txt` holds one `path<TAB>word` line for each word written outside the catalogue, `it('never lets the baseline grow')` pins its count (`grep -n "toHaveCount(" tests/Architecture/AdminWordsTest.php` → 2585 at `09f3b6e9`; notices lowers it by six, so record it, never change it), and `it('looks no word up where a module loads')` refuses `__()` at a module's top level. So the placeholder calls `__()` while drawing, inside the component, its breadcrumb through `setLayoutProps()` there (as `resources/js/pages/plugins/index.tsx:238`), never a `Page.layout = {…}` with words; it adds no baseline line and leaves the count alone. The "if neither exists" branch no longer applies;
  - for A5's guards (checked 2026-11-11 on `[3.1]` `82e7355f`): `grep -n "    public function " app/Domain/Sitemaps/Queries/ListedItems.php` → six lines (`starts`, `page`, `latestListed`, `any`, `listed`, `newest`); `grep -n "FROM terms term" app/Domain/Sitemaps/TermsSitemap.php` → one line, in `listed()`; `grep -n "app(ListedItems::class)->listed(\$id)" app/Domain/Api/Llms/MarkdownPages.php` → one line; `grep -n "public function ids(FeedSubject \$subject, int \$limit): array" app/Domain/Feeds/FeedItems.php` → one line; `grep -n "->where('contents.slug', \$slug)" app/Domain/Regions/Regions.php` → one line;
  - `grep -n "contents_type_slug_live_unique" database/migrations/2026_09_20_100000_add_content_lifecycle_schema.php` → `:31`; `grep -n "\$table->unique(\['taxonomy', 'slug'\]);" database/migrations/2026_09_20_200000_create_taxonomy_tables.php` → one line; in `psql`, `\d terms` names that key `terms_taxonomy_slug_unique` and `\d paths` the path key `paths_path_unique`;
  - `grep -n "public function forContent(Content|string \$content): string" app/Domain/Site/SiteLanguage.php` → one line, its body `return $this->tag();`;
  - `grep -n "public static function unique(string \$type, string \$base): string" app/Domain/Contents/Slugs.php` and `grep -n "public static function isAvailable(string \$type, string \$slug, ?string \$exceptId = null): bool"` → one line each; `grep -n "public static function unique(string \$taxonomy, string \$base, ?string \$exceptId = null): string" app/Domain/Taxonomy/TermSlugs.php` → one line;
  - `grep -n "forContent((string) \$id)" app/Domain/Site/ListingViews.php app/Domain/Site/FieldViews.php` → two lines; `grep -n "forContent((string) \$row->id)\|forContent(\$id)" app/Domain/Api/Items/FieldValues.php app/Domain/Api/Items/ItemShapes.php` → two lines (*(re-check)* `FieldValues.php:171`, `ItemShapes.php:104`; and the fifth, `ItemQuery.php:157`, above);
  - `grep -n "public const array RESERVED = \['language', 'direction', 'alternates'\];" app/Domain/Themes/ComposerRegistry.php` → one line;
  - `grep -n "public string \$language," packages/teme-contracts/src/Content/ItemRef.php` → one line;
  - `grep -n "A value class or a hook grows only at the end" .ai/rules/contracts.md` → one line;
  - `grep -n "screen: 'settings.addresses'" app/Domain/Addresses/AddressSettingsGroup.php` → one line (a group's `screen` is a route-name prefix; read how `SettingsNavigation` builds its link and mirror it);
  - `grep -n "public static function multiselect\|public static function pairs" packages/teme-contracts/src/Settings/Setting.php` → two lines;
  - the PostgreSQL 18 page for `CREATE INDEX` (`docs/references.md`) shows `NULLS [ NOT ] DISTINCT` after the column list and before `WHERE`.
  - for addresses and routing (6.1a's Task A0 before the re-cut): `grep -n "private static function answer(string \$rawPath, string \$query, ReaderKeys \$keys): array" app/Domain/Site/PathResolver.php`; `grep -n "if (\$path === '') {" app/Domain/Site/PathResolver.php` → one line, after the `page/N` rule; `grep -n "return \$pattern->render(\$values);" app/Domain/Addresses/Addresses.php`; `grep -n "return mb_strtolower(implode('/', array_filter(\[\$settings->base(\$term->taxonomy)" app/Domain/Addresses/Addresses.php`; `grep -n "public static function taken(string \$segment): bool" app/Domain/Addresses/Paths.php`; `grep -n "public readonly string \$kind = 'content'," app/Domain/Addresses/Jobs/RecomputeAddresses.php`; `grep -n "->register(ApiAddressTaken::class)" app/Providers/AddressServiceProvider.php` (the banner to mirror); `grep -rn "SettingsCheck::TAG" app/Providers` (how a check is tagged).
  - for translations (6.1b's Task B0 before the re-cut): `grep -n "public static function start(string \$type, string \$slug, string \$title, array \$document, array \$fields, array \$terms, string \$actorId): Content" app/Domain/Contents/Operations/CreateDraft.php`; `grep -n "private function refuseSecondItem(string \$type, string \$singular): void"` in the same file; `grep -n "'restored_from_id' => \$input->restoredFromId," app/Domain/Contents/Operations/SaveDraft.php`; `grep -n "public static function usableBy(string \$type): Exists" app/Domain/Taxonomy/TermRules.php`; *(review)* `grep -n "public function actorId(): ?string;" packages/teme-contracts/src/Operations/OperationInput.php` (every new Input's `actorId` is `?string`); `grep -n "pg_advisory_xact_lock_shared(hashtext('teme.types'))" app/Domain/Contents/Operations/CreateDraft.php` (the pattern `LanguageLock` follows); `grep -n "private static function waiting(string \$config): Builder" app/Domain/Contents/Operations/ReindexSearch.php` and how `app/Domain/Deployment/ReleaseRebuilds.php` runs it (to `remaining` 0); `grep -n "public const string CANONICAL" app/Domain/Fields/BuiltInFields.php`; `grep -n "public const string PRIMARY_CATEGORY" app/Domain/Fields/BuiltInFields.php`; how `RestoreContent` reports a changed slug (`RestoredContent`, `ContentActionController::restoreMessage()`); how a test records the purges an Operation asks for (the 1.7b helper `tests/Pest.php` names; read it, never edit `tests/Pest.php`).
- [ ] **Step 3:** restate the tech stack from the locks in the records. `git checkout -b slice/6.1a-foundation origin/slice/6.1`.

## Task C1: The schema

**Files:** the five migrations; the four models; `LanguageSchemaTest.php`, `LanguageColumnsTest.php`.

- [ ] **Step 1: Write the failing tests** (`tests/Feature/Languages/LanguageSchemaTest.php`; rows written with `DB::table()` so the test pins the database, not the code):
  - `it('adds a nullable language and translation group to contents and terms, a source revision to revisions and translations to settings')`: `information_schema.columns`, each `is_nullable = 'YES'`, with no default. *Proves D4's columns and that installing the release rewrites no row.*
  - `it('lets two items of a type share a slug in different languages, never in the same one')`: `about` with NULL and `about` with `fr` both insert; a second `about` with NULL and a second with `fr` each raise SQLSTATE 23505. *Proves `(type, slug, language)` NULLS NOT DISTINCT.*
  - `it('frees a slug in the trash, in every language')`: a trashed `fr` `about` beside a live `fr` `about`.
  - `it('keeps one live item per language in a translation group')`: two NULL-language live items in one group: 23505; NULL and `fr`: fine; a trashed `fr` beside a live `fr`: fine.
  - `it('lets two terms of a taxonomy share a slug in different languages, never in the same one')`.
  - `it('keeps one term per language in a translation group')`.
  - `it('refuses a language that is not a canonical tag, and a group that is not a lower-case ULID')`: `FR`, `fr-fr`, `en_GB`, `english` each 23514; `pt-BR`, `pcm` fine; a group in upper case 23514.
  - `it('forgets the revision a translation came from when that revision is deleted')`.
  - `it('keeps a settings row's translations an object of at most 64 KB')`: a JSON list 23514; 70 KB 23514; `{}` and NULL fine.
  - `it('leaves every index this release builds valid')`: the eight names of D4 *(review: was six)* in `pg_index` joined to `pg_class`, each `indisvalid`.
  - `it('no longer has the one-language slug index or the term slug key')`.
  - `it('can answer the previous release's slug search from the new index')`: inside a transaction, `SET LOCAL enable_seqscan = off`, a few hundred rows of two types between the rows under test, `EXPLAIN` of `Slugs::unique()`'s query as `main` writes it today (type, `deleted_at IS NULL`, slug equal or `LIKE` a prefix) names `contents_type_slug_language_live_unique` and no Seq Scan (lesson: "can serve", never "will choose").
  - (`LanguageColumnsTest.php`) `it('finds a row that stores the default language's tag')`: plant `en` on an item of an English site; `LanguageColumns::defaultTagged()` (a test-only helper class in `tests/Fixtures/Languages/`) returns its ID. `it('finds none after the fixture is built')`. *The red case first: the check is shown to fail before it is trusted.*
- [ ] **Step 2:** they fail (no columns).
- [ ] **Step 3: Build it.** D4's five migrations exactly; the models' docblocks and the `SettingsGroupRecord` cast. `php artisan migrate --pretend` and read the order PostgreSQL gets (lesson 1.8a).
- [ ] **Step 4:** quick check (`.ai/rules/builders.md`); commit `feat(languages): a language and a translation group on items and terms (I-3360)`.

## Task C2: The site's languages

**Files:** `config/languages.php`, `SiteLanguages.php`, `LanguagesGroup.php`, `LanguageServiceProvider.php`, `bootstrap/providers.php`, `LanguagesController.php`, `languages.tsx`, `routes/admin.php`, `routes/admin/*.php`, `SiteLanguagesTest.php`, `SiteLanguageTest.php`.

- [ ] **Step 1: Write the failing tests** (`tests/Feature/Languages/SiteLanguagesTest.php`; languages written with `SettingsWriter` in the tests, since the group has a screen of its own):
  - `it('has one language, the default, on a site that added none')`: `all()` is `['en-GB']` for a site set to `en-GB`; `isMultilingual()` false; `prefix('en-GB')` is `''`.
  - `it('lists the default first, then the others in the order they were added')`.
  - `it('leaves a hidden language out of what readers are shown, and always shows the default')`: `shown()`, `isShown(null)`, `isShown('es')` false.
  - `it('stores the default as null and any other language as its tag, and reads null back as the default')`: `stored('en-GB')` null, `stored('fr')` `fr`, `of(null)` `en-GB`; `stored('it')` (not a site language) throws `InvalidArgumentException`.
  - `it('finds a language by its prefix, shown or hidden, and never the default by an empty one')`: `byPrefix('fr')` `fr`; `byPrefix('es')` `es`; `byPrefix('')`, `byPrefix('admin')` null.
  - `it('gives each language its own name, its direction and its Open Graph locale')`: `native('fr')` `Français`; `direction('ar')` `rtl`, `direction('yi')` `rtl`, `direction('de')` `ltr`; `ogLocale('pt-BR')` `pt_BR`, `ogLocale('es')` `es_ES`; `locale('pt-BR')` `pt_BR`.
  - `it('registers the languages group with its own screen, under General's ability')`: the group's `screen`, `ability` `settings.general.manage`, its three settings' keys and defaults (read by key, never the whole registry, lesson 1.9a).
  - `it('refuses the languages group through the generic settings form')`: `UpdateSettings` on `languages` raises "Change these settings on their own screen." and writes nothing.
  - `it('shows the Languages screen to whoever manages General settings, and to nobody else')`: `GET /admin/settings/languages` 200 renders `settings/languages`; 403 for an editor.
  - *(I-3373 (1))* `it('offers no form to add, change or remove a language')`: as an administrator, `GET /admin/settings/languages` renders `settings/languages` with none of the props a form would need (`assertInertia`: `missing('others')`, `missing('available')`, `missing('version')`, `missing('types')`); and the page's source, read with `file_get_contents(resource_path('js/pages/settings/languages.tsx'))`, holds none of `<form`, `<Form`, `useForm`, `<input`, `<select`, `<button`, `<Button`, nor any import from `@/routes/settings/languages` or `@/actions/App/Http/Admin/Settings/LanguagesController` (Wayfinder's names for the store, update and destroy routes). *Proves the maintainers' decision: the screen is stage 3.* The routes themselves stay (A4), tested by request.
  - (`SiteLanguageTest.php`, added) `it('gives every language its own name, a direction and an Open Graph locale')`: every key of `config/languages.php` has a non-empty `native`, `direction` in `ltr`/`rtl`, and `og` matching `^[a-z]{2,3}_[A-Z]{2}$`; `it('draws Arabic and Yiddish right to left, and nothing else')`.
- [ ] **Step 2:** they fail.
- [ ] **Step 3: Build it.** The three config keys for all 41 languages (D2); `LanguagesGroup::group(): SettingsGroup` (D2's words) registered by `LanguageServiceProvider::boot()` with `SettingsRegistrar::group()`; `SiteLanguages` (D2's signatures, `scoped()`): it reads `general.language` and the `languages` group through `SettingsReader`, reads `prefixes` as `name => value`, and treats a tag in `others` that is not in `config/languages.php` as absent (a config change never breaks a page). The route and the placeholder page (the settings shell and the heading "Languages", through `__()` if C0 found the admin-words plan merged; no form, no button, no list); the route files and their `require` lines. `php artisan teme:docs:settings`. *(re-check)* Admin words has merged: the page's `<Head title>`, heading and breadcrumb are `__('Languages')`, looked up inside the component (C0); `AdminWordsTest` runs green with the baseline and its count untouched. `routes/admin/` doesn't exist yet: C2 creates it, and `routes/admin.php`'s `require` lines sit inside the `verified` group (`:108`) before `settings/{group}` (`:279`), beside the comments that already say "Before settings/{group}". The group's and settings' labels and descriptions are written as plain strings, as every registered group's are (`AddressSettingsGroup.php:44-45`): they are the registry's data, which `teme:docs:settings` prints; every refusal, toast and banner 6.1a adds in PHP goes through `__()`, as `Paths::conflict()` (`Paths.php:76-92`) and `ApiAddressTaken` (`:65-66`) do. `teme:docs:export` reads the type registry from the database (4.5b's records; `teme:docs:settings` may too, through the addresses group's types): run each against the test database's connection as 4.5b's records did (`DB_PORT=5433 DB_DATABASE=teme_test …` on the command line; no `.env` changed).
- [ ] **Step 4:** quick check; commit `feat(languages): the site's languages, as a settings group with its own screen (I-3360)`.

## Task C3: The page's language, and what an item is in

**Files:** `PageLanguage.php`, `LanguageServiceProvider.php`, `SiteLanguage.php`, the ~~four~~ five *(re-check)* callers, `PageLanguageTest.php`; *(review)* `ReindexSearch.php` and `ReindexPerLanguageTest.php`: 6.1c's Task S2, its written-out `waiting()` and its three tests, taken here whole (see *Where*, 10).

- [ ] **Step 1: Write the failing tests** (`tests/Feature/Languages/PageLanguageTest.php`):
  - `it('is the default language until something says otherwise, and stores nothing')`: `tag()` the default; `stored()` null; `isSet()` false.
  - `it('sets the application's locale to the page's language')`: `use('pt-BR')`; `app()->getLocale()` is `pt_BR`; `stored()` is `pt-BR`.
  - `it('stores nothing for the default language even when set to it')`: `use('en-GB')` on an `en-GB` site; `stored()` null.
  - `it('refuses a language the site does not have')`: `use('it')` throws; the locale unchanged.
  - `it('starts every request unset')`: two requests in one test application (`scoped()`), the second sees `isSet()` false (B-227's lesson).
  - `it('answers an item's own language, and the default for one that names none')`: `SiteLanguage::forContent()` with a model and with an ID, for a French and a default item; `of(null)`.
  - `it('draws a listing's dates with no statement per item')`: an archive of 20 items, statements counted before and after this task's change to `ListingViews`, equal (the budget the 4.1 test already pins, re-run).
- [ ] **Step 2:** they fail.
- [ ] **Step 3: Build it.** `PageLanguage` (D3), `scoped()`; `forContent()` and `of()`; the five *(re-check)* callers select `language` in their query and call `of($row->language)`.
- [ ] **Step 4:** quick check; commit `feat(languages): the page's language, and each item's own (I-3360)`.

## Task C4: Slugs per language, the group helper and the purge tag

**Files:** `Slugs.php`, `TermSlugs.php`, `TranslationGroups.php`, `GroupLock.php`, `LanguageLock.php` *(review)*, `PageTags.php`, `SlugsPerLanguageTest.php`.

**Its code, written out** (`Slugs::unique()`'s query keeps its shape; the language is a third predicate the new index serves):

```php
public static function unique(string $type, string $base, ?string $language = null): string
{
    $row = DB::table('contents')
        ->where('type', $type)
        ->whereNull('deleted_at')
        // One language's slugs: null is the default language (I-3360).
        ->where(fn ($query) => $language === null ? $query->whereNull('language') : $query->where('language', $language))
        ->where(fn ($query) => $query->where('slug', $base)->orWhere('slug', 'like', $base.'-%'))
        // …the rest exactly as today.
```

`isAvailable()` gains `?string $language = null` after `$exceptId`, with the same predicate; `TermSlugs::unique()` gains `?string $language = null` last, likewise. *(review)* **Every caller passes the row's language** (a missed one numbers a French slug against the default's rows): `CreateDraft.php:104,107` (the input's), `SaveDraft.php:123,130` (the title's slug of a draft), `RestoreContent.php:64-68`, `UpdateContentDetails.php:95`, `CreateTerm.php:50`, `UpdateTerm.php:61` (`[5.1]` lines; *(re-check)* on `[4.5]`: `CreateDraft.php:107`, `SaveDraft.php:130`, `RestoreContent.php:73,75`, `UpdateContentDetails.php:106`, `CreateTerm.php:50`, `UpdateTerm.php:61`); the importer's (`ImportedContents.php:89`, `ContentPhase.php:480` *(re-check: `:483`)*, `TermsPhase.php:107`) keep the default, since an imported row is in it until linked. `TranslationGroups`: `newId(): string` (`strtolower((string) Str::ulid())`) and `memberIds(string $group, bool $withTrashed = false): list<string>` (ordered by ID). `PageTags::translations(string $group): string` → `'translations:'.$group`, and `isList()` unchanged.

**`GroupLock::lock()`, written out** (`app/Domain/Contents/GroupLock.php`; shared by 6.1a and 6.1h; delicate: the order two Operations on one group take their locks, D5):

```php
/**
 * Locks the named items and every member of their translation groups, live and trashed,
 * in ID order, so two Operations on one group queue rather than deadlock
 * (I-3360). Call it in perform() after the languages lock and a hierarchical
 * type's tree lock, as the first row lock.
 *
 * @param  list<string>  $contentIds
 * @return Collection<string, Content> the locked rows, keyed by ID
 */
public static function lock(array $contentIds): Collection
{
    $groups = DB::table('contents')->whereIn('id', $contentIds)->whereNotNull('translation_group')->distinct()->pluck('translation_group')->all();

    $rows = Content::withTrashed()
        ->where(fn ($query) => $query->whereIn('id', $contentIds)->when($groups !== [], fn ($query) => $query->orWhereIn('translation_group', $groups)))
        ->orderBy('id')
        ->lockForUpdate()
        ->get()
        ->keyBy('id');

    // A named item joined another group between the read and the lock. Locking that group now would take
    // rows out of ID order and could deadlock, and an Operation's transaction is tried once, so refuse instead.
    $now = $rows->only($contentIds)->pluck('translation_group')->filter()->unique()->values()->all();

    if (array_diff($now, $groups) !== []) {
        throw ValidationException::withMessages(['translations' => __('Someone changed these translations while you were working. Try again.')]);
    }

    return $rows;
}
```

*(review)* The draft retried and, on its second try, locked the new group's rows while already holding the first try's, out of ID order: a deadlock PostgreSQL ends with `40P01`, and `BaseOperation` runs `DB::transaction()` with one attempt (`[5.1] BaseOperation.php:59`), so the person got an error. The race is rare; a refusal in words is the honest answer. The unique index stays the last guard (D5, step 4). *Every word* gains the sentence above.

- [ ] **Step 1: Write the failing tests** (`tests/Feature/Languages/SlugsPerLanguageTest.php`):
  - `it('gives a French item the slug an English one has')`: `Slugs::unique($type, 'about', 'fr')` is `about` while an English `about` lives.
  - `it('numbers a slug only within its own language')`: two French `about`s make `about-2`; the English `about` is never counted.
  - `it('treats the default language as one language')`: `unique($type, 'about')` with an English `about` gives `about-2`.
  - `it('checks availability within one language')`.
  - `it('numbers term slugs within one language')`.
  - `it('mints group IDs as lower-case ULIDs, never an item's')`.
  - `it('lists a group's members, live only unless asked')`.
  - `it('names the purge tag of a group')`.
  - `it('locks a group's members, live and trashed, with the named items')`: inside a transaction, `lock([$site->post])` returns the post and its French and Spanish versions; a second connection's `SELECT … FOR UPDATE NOWAIT` on the French row fails with 55P03.
  - *(review)* `it('refuses, in words, when a named item joined another group between the read and the lock')`.
  - *(review)* `it('lets one change of the site's languages wait for every translation in flight')`: a second connection holding `LanguageLock::shared()`; `pg_try_advisory_xact_lock(hashtext('teme.languages'))` answers false.
- [ ] **Step 2:** they fail.
- [ ] **Step 3: Build it.**
- [ ] **Step 4:** quick check; commit `feat(languages): slugs per language, groups and their purge tag (I-3360)`.

## Task C5: The fixture

**Files:** `tests/Fixtures/Languages/LanguageSite.php`, `LanguageColumns.php`, `LanguageSiteFixtureTest.php`.

`LanguageSite::build(): self` (a class, never `tests/Pest.php`) gives a site whose default is `en-GB`, with French (`fr`, shown) and Spanish (`es`, hidden) added through `SettingsWriter`; a published post `hello` (English), its published French `bonjour` and published Spanish `hola`, one group; a published page `about` and its French draft `a-propos`, one group; a category `news` and its French `actualites`, one group, the English post under `news`, the French under `actualites`; a published English post `alone` with no group. Rows by factories, then `ContentCounts`, `TermProjection`, `PathProjection`, `SearchText` as the Operations would; IDs exposed as readonly properties (`$site->post`, `$site->postFr`, `$site->postEs`, `$site->page`, `$site->pageFr`, `$site->news`, `$site->newsFr`, `$site->alone`, `$site->postGroup`). Fixture types are named through `CoreTypes` (`NoTypeLiteralsTest`).

- [ ] **Step 1:** `it('builds a site in three languages whose rows follow the rules')`: each row's `language` (NULL for English), the groups, `LanguageColumns::defaultTagged()` empty, `SiteLanguages::shown()` `['en-GB', 'fr']`. *Proves the one fixture every part's tests stand on.*
- [ ] **Step 2 to 4:** fail, build, quick check; commit `test(languages): a site in three languages for every part's tests`.

## Task A1: Addresses with a prefix

**Files:** `Addresses.php`, `Paths.php`, `LanguageAddressesTest.php`.

- [ ] **Step 1: Write the failing tests** (`LanguageSite::build()` unless said):
  - `it('gives every existing item, term and author the address it had before this release')`: a single-language site of every kind of address (items of three patterns, nested terms, an author, the home page as a page); `RebuildPaths` over all; every `paths` row unchanged. *Proves "no existing address moves" (D6).*
  - `it('puts the prefix before a French item's address and leaves the default's alone')`: the French post's current path is `fr/bonjour` (its pattern's address after `fr/`), the English `hello`.
  - `it('puts the prefix before a French term's address')`: `fr/category/actualites`, with the site's category base.
  - `it('gives the home page's French version the prefix alone')`: the front page is `about`; its French version, published, has the path `fr`.
  - `it('keeps every other language's prefix, shown or hidden, for the site itself')`: `Paths::taken('fr')`, `Paths::taken('es')` true; `Paths::taken('it')` false; `Paths::conflict('fr', 'content', $id)` gives "The address /fr/ is used by the site itself."
  - *(re-check)* `it('keeps the prefix on the pages under a French page when it moves')`: pages' pattern `/{parent}/{slug}/`; a published French child under the French `a-propos` (both through the Operations); renaming `a-propos` to `qui-sommes-nous` with `UpdateContentDetails` gives the child `fr/qui-sommes-nous/{slug}`, its old address history; the English `about`'s children unchanged. *Proves the stub in `SubtreeAddresses::addresses()` carries the language (*With page parents*).*
- [ ] **Step 2:** they fail.
- [ ] **Step 3: Build it** (D6). `wouldHave()` reads the item's `language` (*(re-check)* from the attributes the model holds, else one primary-key read; `SubtreeAddresses.php:251`'s stub gains `'language' => $item->language`); the front-page check becomes "this item, or its version in the front page's group"; the prefix is joined with `/` before the pattern's address, and alone for the front page's version. `forTerm()` the same. `taken()` adds `SiteLanguages::byPrefix($segment) !== null`.
- [ ] **Step 4:** quick check; commit `feat(addresses): another language's items and terms carry its prefix (I-3360)`.

## Task A2: Routing a prefix

**Files:** `PathResolver.php`, `ListingViews.php`, `LatestViews.php`, `PublicController.php`, `PublicPage.php`, `LanguageRoutingTest.php`, `FrontPerLanguageTest.php`.

**Its code, written out** (delicate: the order decides what every address answers). `answer()` keeps its comments-page and `page/N` rules first; then:

```php
$languages = app(SiteLanguages::class);
$first = explode('/', $path, 2)[0];
$tag = $first === '' ? null : $languages->byPrefix($first);

// No prefix: the default language, in today's order, unchanged.
if ($tag === null) {
    return self::inDefault($path, $rawPath, $page, $query, $keys);
}

$language = $languages->stored($tag);

// Readers get nothing in a hidden language (I-3360).
if (! $languages->isShown($language)) {
    return self::notFound(null);
}

$rest = $path === $first ? '' : substr($path, strlen($first) + 1);

if ($rest === '') {
    return self::front($rawPath, $page, $query, $keys, $language);
}

// Items and terms in this language store their prefix, so the whole path is looked up first.
$row = Paths::find($path);

if ($row !== null && $row->is_current) {
    return self::current($row, $rawPath, $page, $query, $keys);
}

$archive = TypeArchives::at($rest);

if ($archive !== null) {
    return self::typeArchive($archive, $path, $rawPath, $page, $query, $language);
}

if ($row !== null) {
    return self::history($row, $page, $query);
}

// An author has one row, the default language's; their list in this language is that address after the prefix.
$author = Paths::find($rest);

if ($author !== null && $author->is_current && $author->target_type === 'user') {
    return self::authorIn($author, $path, $rawPath, $page, $query, $language);
}

if (preg_match('#^(\d{4})(?:/(\d{2})(?:/(\d{2}))?)?$#', $rest, $date) === 1) {
    return self::dateArchive($path, $date, $rawPath, $page, $query, $language);
}

return self::notFound($language);
```

`inDefault()` is today's body from `if ($path === '')` on, every listing asked with `$language = null`. *(review)* `comments()` runs before this rule, so its answer takes its item's language, and its home-page case (`:153`) uses the language's home, `/fr/`, for the front page's French version; `live()` gains the shown-language predicate (D7, 9) and `history()` its check (D7, 10). Every answer gains `language` (the stored form): an item's or term's row's (read with the row, no extra statement: `current()` already reads the item's `type`, and reads `language` beside it), a listing's from its prefix, a 404's from its prefix. A hidden item reached by a default-language address can't exist (its path carries its prefix), and `current()` checks `isShown()` of the target's language anyway. `front()`, `typeArchive()`, `dateArchive()` and the author listing take `?string $language` and build their canonical with `'/'.$languages->prefix($tag).'/'` before the rest (`Pagination::urlFor()`). `ListingViews::forTerm()` reads the term's own language; `forType()`, `forDate()`, `forAuthor()`, `LatestViews::latest()` take `?string $language` and add D1's predicate. `PublicController::handle()` calls `app(PageLanguage::class)->use($languages->of($answer['language'] ?? null))` **before** `PublicPage::render()` and sets `Content-Language`; `PublicPage::error()` reads a prefix off the request path the same way.

- [ ] **Step 1: Write the failing tests** (`LanguageRoutingTest.php`, `FrontPerLanguageTest.php`; through `$this->get()`, page cache off):
  - `it('answers a French item at its prefixed address, in French')`: `/fr/bonjour/` 200, `Content-Language: fr`; the view model's item is the French one.
  - `it('answers the default language's addresses exactly as before')`: the existing `tests/Feature/Site` routing files run unchanged (named in the records), and `/hello/` says `Content-Language: en-GB`.
  - `it('redirects a French address to its canonical spelling')`: `/fr/bonjour` 301 to `/fr/bonjour/`; `/fr` 301 to `/fr/`.
  - `it('lists a French term's French items at its prefixed address')`.
  - `it('lists an author's French items under the prefix and their default-language items without it')`: `/fr/author/{slug}/` holds `bonjour` only; `/author/{slug}/` holds `hello` and `alone` only.
  - `it('lists a type's French items at its archive's address after the prefix')`.
  - `it('lists a month's French items after the prefix')`.
  - `it('answers a search at /fr/ in French')`: `/fr/?s=bonjour` is a search page whose language is French (what it finds is 6.1c's).
  - `it('answers 404 in French for an address under the prefix with nothing at it')`: `Content-Language: fr`.
  - `it('redirects an old French address to the item's current one')`.
  - `it('reads no prefix for the default language')`: `/en-gb/hello/` 404.
  - (`FrontPerLanguageTest.php`) `it('lists the latest French items at /fr/ when the home page is the latest posts')`; `it('shows the home page's French version at /fr/ and redirects its own address there')`; `it('lists the latest French items at /fr/ while the home page has no live French version')`; `it('lists the latest French items at the posts page's French version')`; `it('serves /fr/page/2/ and redirects /fr/page/1/ to /fr/')`.
- [ ] **Step 2:** they fail.
- [ ] **Step 3: Build it** as written out.
- [ ] **Step 4:** quick check; commit `feat(site): /xx/ addresses and listings in each language (I-3360)`.

## Task A3: Hidden languages, links and listings

**Files:** `PathLinkResolver.php`, `PathResolver.php`, `RelatedPosts.php`, `Breadcrumbs.php`, `HiddenLanguageTest.php`, `ListingsPerLanguageTest.php`.

- [ ] **Step 1: Write the failing tests:**
  - `it('answers 404 for every address under a hidden language's prefix')`: `/es/`, `/es/hola/`, `/es/author/{slug}/`, `/es/feed/` (the feed's route hands a non-feed back; 6.1c answers the feed itself), each the same 404 a missing address gets, `Content-Language` the default's.
  - `it('draws a link to an item or term in a hidden language as its text alone')`: an English body linking `teme:content/{hola}`; the page shows the words with no `<a>`.
  - `it('shows a language's published items the moment it is shown')`: `ChangeSiteLanguage` shows Spanish; `/es/hola/` 200 with no address rebuilt. *(review)* Run with the page cache **on** as well (6.1z's leak matrix is parked): `/es/hola/` stored as a 404 while hidden is purged by the show, and a stored 200 by the hide.
  - *(review)* `it('answers a hidden item's later comment pages, its old addresses and its comment and password routes as missing ones')` (D7, 9 to 11): `/es/hola/comments/page/2/`, an old address of `hola` (history row), `_teme/comment/{id}` of a comment on it, its reply page, a comment posted to it and `_teme/unlock` for it, each answering as for a missing item, with nothing written.
  - *(review)* `it('draws a menu item and an entry field that name a hidden item as text alone')` (D7, 12).
  - *(review)* `it('refuses a prefix that is all digits, page or comments')` (A4's rule; here because the router reads them first).
  - (`ListingsPerLanguageTest.php`) `it('lists only the default language's items on the default home page, archives and author pages')`.
  - `it('relates a post only to posts in its own language')`.
  - `it('leads a breadcrumb's Home to the page language's home')`. *(re-check)* `[4.5]` built the trail from a page's live parents: `Breadcrumbs::home()` (`:31-34`) is always `/`, and `toItem()` drops an ancestor at `'/'` (`:49`) because Home stands for it. Both read the page language's home (`/fr/` on a French page), so a French page under the French version of the home page shows Home once, not Home and "À propos" at `/fr/`; the test covers that case too.
  - `it('can answer a French list from the other-language index')`: `SET LOCAL enable_seqscan = off`, other types' and languages' rows between; `EXPLAIN` of `ListingViews`' French archive query names `contents_other_language_published`.
  - `it('adds no statement to a default-language listing')`: the existing archive budget test, re-run, unchanged.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(site): a hidden language answers nothing, and lists keep to their language (I-3360)`.

## Task A4: Adding, changing and removing a language

**Files:** the three Operations and Inputs, `PrefixConflicts.php`, `DefaultLanguageCheck.php`, `RecomputeAddresses.php`, `LanguagesController.php`, `routes/admin/languages.php`, `AddressServiceProvider.php`, `SiteLanguageOperationsTest.php`, `PrefixConflictsTest.php`, `DefaultLanguageRuleTest.php`.

**Their contracts:**
- `AddSiteLanguageInput(string $tag, string $prefix, bool $shown, int $expectedVersion, ?string $actorId)`; `ChangeSiteLanguageInput(string $tag, string $prefix, bool $shown, int $expectedVersion, ?string $actorId)`; `RemoveSiteLanguageInput(string $tag, int $expectedVersion, ?string $actorId)`. *(review)* `actorId` is `?string`, as `OperationInput::actorId(): ?string` (`[5.1] packages/teme-contracts/src/Operations/OperationInput.php:15`) and every sibling has it, and comes after the payload as in `UpdateSettingsInput` and `CreateDraftInput`; the draft's `?int $actorId` first fits neither. **The same holds for every new Input in this plan** (B2, B3, B4, T1): read `?int $actorId` there as `?string $actorId`, after the payload.
- **Authorize:** `settings.general.manage` (D23). **Validate** (words in *Every word*): the tag in `config/languages.php`; not the default; for Add, not already one of the site's; the prefix `^[a-z0-9]+(?:-[a-z0-9]+)*$`, at most 20 characters, *(review)* not all digits (a year's archive, `/2026/`, would be shadowed) and not `page` or `comments` (`PathResolver::answer()` reads `page/N` and `comments/page/N` before any prefix); `PrefixConflicts::for(string $prefix, ?string $exceptTag): ?string` (D6's list, the holder named in `Paths::describe()`'s words; one statement for `paths`: `path = ? OR path LIKE ?` on the C-collated unique index); for Remove, no item (trash included) and no term in it (`DB::table('contents')->where('language', $tag)->count()` and the terms' likewise, named in words).
- **Perform:** `SettingsWriter` on `languages` with the expected version (a stale form is refused as today's `StaleSettingsException` is); Add appends to `others`, `prefixes` and, if not shown, `hidden`; Change edits them; Remove deletes the tag from all three. **After commit:** the writer's own purge of `settings`; Change with a new prefix queues `RecomputeAddresses` for that language (`new RecomputeAddresses(JobActor::of($actorId), language: $tag)`), which rebuilds only `contents` and `terms` rows of it (`WHERE language = ?`, by ID, 500 a run, as today). *(review)* Each next run it queues (`RecomputeAddresses::handle()`'s `new self(…)`) carries the language, and with one it stops after terms (users have no language). `RebuildPaths` authorizes only `MAY_ASK = ['settings.addresses.manage', 'terms.manage', 'users.manage']` (`[5.1] RebuildPaths.php`), so it gains `settings.general.manage`, or a role given General but not Addresses fails the job it started (page parents also edits this file; add after it).
- `DefaultLanguageCheck implements SettingsCheck`, group `general`: a change of `language` while `others` is not empty is refused ("The default language can't change while the site publishes in other languages."), and a new default that is in `others` too.
- `LanguagesController`: `store`, `update` (`{tag}`), `destroy` (`{tag}`), each an Operation and a toast, as `AddressesController` does; routes `settings.languages.store|update|destroy` in `routes/admin/languages.php` under `can:settings.general.manage`.

- [ ] **Step 1: Write the failing tests:**
  - `it('adds French with its prefix, shown, and lists it after the default')`.
  - `it('adds a language hidden from readers')`.
  - `it('refuses a language the site already has, the default, and one it does not know')`.
  - `it('refuses a prefix that isn't lower-case letters, digits and hyphens, or is longer than 20')`.
  - (`PrefixConflictsTest.php`) a dataset, each refused with its holder named: a reserved word (`admin`), a site route (`feed`), the API (`api`), another language's prefix, a page at `/fr/`, a page under `/fr/` (a pattern with a fixed first segment `fr`), a term base `fr`, the author base `fr`, a type archive at `fr`. `it('allows a prefix that only an old address or a redirect holds')`.
  - `it('moves a language's addresses when its prefix changes, and the old ones redirect')`: the job run inline with its actor under `DeniedElevation` (lesson: a queued job carries its actor); `/fr/bonjour/` 301 to `/francais/bonjour/`.
  - `it('rebuilds only that language's addresses when its prefix changes')`: the job's batches name French rows only.
  - `it('shows and hides a language without moving an address')`.
  - `it('refuses to remove a language while items or terms are in it, counting them')`: the trash counted too.
  - `it('removes a language nothing is in')`.
  - `it('refuses a form opened on an older version of the languages')`.
  - `it('writes nothing when someone without the General settings ability adds, changes or removes a language')`: a dataset of the three; the `settings` row, `settings_changes` and the queue unchanged.
  - `it('writes nothing when a language is refused')`.
  - (`DefaultLanguageRuleTest.php`) `it('refuses a new default language while other languages are added')`; `it('refuses a default that is already one of the other languages')`; `it('changes the default as before on a site with one language')` (the existing reindex test passes unchanged).
  - (`LanguageBannerTest.php`) `it('warns whoever manages redirects when a redirect's source is a language's prefix')`, with its words and action; `it('shows no banner while no redirect starts there')`; `it('shows the banner to nobody without redirects.manage')`.
  - `it('answers each language route for an administrator and refuses an editor')` (HTTP, one test a route).
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(languages): add, change and remove a site's languages (I-3360)`.

## Task A5: Default language only, where no shipped part reads another *(review)*

**Files:** those named under *While the other parts are parked* (the guards' table there, each confirmed): `SearchRequest.php`, `PostgresSiteSearch.php`, `SearchViews.php` (S1, taken whole from 6.1c); `ListedItems.php`, `TermsSitemap.php`, `FeedItems.php`; `[3.1]` `Live.php`, `ItemsQuery.php`, `TermsQuery.php`; `Regions.php`; `tests/Feature/Languages/DefaultOnlyGuardsTest.php`; 6.1c's `SearchPerLanguageTest.php` (S1's tests, taken whole). (`ReindexSearch.php` is C3's.)

- [ ] **Step 1: Write the failing tests** (`LanguageSite::build()`; each a pair, lesson B-358: the default item is found, the French and Spanish are not):
  - **Search by language:** S1's five tests, as 6.1c writes them.
  - **Sitemaps and `TermsSitemap`:** `it('lists only the default language's items and terms in the sitemaps')`: `hello` and `news` listed; `bonjour`, `hola` and `actualites` not; the sitemap index's newest date is the default language's (`newest()`).
  - **`llms.txt`:** `it('lists only the default language's items in llms.txt')`.
  - **The `.md` pages:** `it('serves a .md page for a shown language's item and never a hidden one's')`: the French item's `.md` address answers (as `MarkdownPages::answers()` reads it); the Spanish item's answers as a missing page.
  - **`FeedItems`:** `it('lists only the default language's items in every feed')`: the site's, `news`' and the author's feeds.
  - **The read API:** `it('lists only the default language's items and terms in the read API, and answers a hidden item as one that never existed')`: `/items`, `/terms`, `/items/{hola}`, `by-path?path=/es/hola/`, `/terms/{id}` of a hidden term; the same status, `code` and body as an unknown ID; `/items/{bonjour}` 200 with `language` `fr`. 3.1a's and 3.1b's tests pass unchanged. *(re-check)* `ItemsQuery`'s `contents.language IS NULL` goes on the list's own query (`ItemsQuery.php:38`'s chain), **never in `Live::items()` or `Live::apply()`**, which `/items/{id}`, `by-path`, `ancestors`, the `?parent=` check and `parent_id` share and which keep answering every shown language. Two more cases, with a French page `$pageFrChild` under the fixture's French `a-propos` (published for the test): `?parent={a-propos's French ID}` answers exactly as an unknown parent's ID (`links.self` aside, as 4.5b's `ItemParentsTest` compares them); `/items/{pageFrChild}` gives `parent_id` and `ancestors` naming the French parent, with `language` `fr`, in the statement count it had before C3 (D2's fifth caller).
  - **Regions in the default language:** `it('draws the default language's region on a default-language page while a French version exists')`.
  - each with the page cache on as well as off (6.1z's leak matrix is parked).
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(languages): default-language answers wherever no part reads another yet (I-3360)`.

## Task B1: Drafts in a language, and terms in it

**Files:** `CreateDraft.php`, `CreateDraftInput.php`, `SaveDraft.php`, `CheckpointDraft.php`, `DraftChecks.php`, `TermRules.php`, `CreateTerm.php`, `CreateTermInput.php`, `UpdateTerm.php`, `UpdateContentDetails.php`, `TermsPerLanguageTest.php`.

- [ ] **Step 1: Write the failing tests** (`LanguageSite::build()`):
  - `it('creates a draft in another site language, storing its tag')`; `it('creates a default-language draft storing no language')`; `it('refuses a language the site does not have')`.
  - `it('allows one item per language of a type with one item only')`: an English and a French item of a single type; a second French refused with today's words.
  - `it('carries the revision a version was translated from through every save, checkpoint and restore')`.
  - `it('refuses a term of another language on an item's draft, naming it')`: the French post saved with `news` refused; with `actualites` fine.
  - `it('creates a term in a language, its slug unique within it')`: `actualites` in French beside an English `actualites` is refused only within French.
  - `it('changes a published item's slug within its language only')` (`UpdateContentDetails`).
  - `it('writes nothing when someone may not create the term')` (the existing authorization test of `CreateTerm`, extended with a language).
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(content): drafts and terms in a language (I-3360)`.

## Task B2: Translate

**Files:** `TranslateContent.php` and Input, `TranslationCopy.php`, `TermSiblings.php`, `CreateDraft.php` (`start()`'s three new last parameters), `TranslateContentTest.php`.

**Its contract.** `TranslateContentInput(?int $actorId, string $sourceId, string $language)`, `$language` a tag. **Authorize** (D23): the actor exists; `Gate::forUser($actor)->allows('create', [Content::class, $source->type])` (*(review)* as `CreateDraft::authorize()` asks, `[5.1] CreateDraft.php:56`: an Operation never reads the signed-in user), or `regions.manage` for a region; for a source with no published revision, or one that isn't public (D8), `Gate::forUser($actor)->allows('update', $source)`. **Validate:** the tag is one of the site's (shown or hidden) and not the source's; the source is not trashed. **Perform:** `LanguageLock::shared()`, `[4.5]` `TreeLock::take($type)` for a hierarchical type, then `GroupLock::lock([$sourceId])` (D5's order); under it, refuse when a live member has the language (words name it); the group is the source's, or `TranslationGroups::newId()` set on the source's row; the revision copied is the published one, or the working one when the source is unpublished; `TranslationCopy::of($revision, $language, $actorId)` gives the title, the document with its links pointed (D8), the fields without the canonical and with the primary category mapped, and the terms mapped by `TermSiblings::inLanguage()`; then `CreateDraft::start($type, Slugs::unique($type, $source->slug, $stored), $title, $document, $fields, $terms, $actorId, language: $stored, group: $group, sourceRevisionId: $revision->id)`. **Returns** `TranslatedContent(Content $content, list<string> $madeTerms, bool $parentLeft = false)`. *(review, `[4.5]` D22)* **A page's parent:** the new version's parent is the source's parent's version in the target language, live or not; with none, the top level, and `parentLeft` true (*Every word*: "Its parent “{About}” has no {French} version yet, so it is at the top. Choose its parent once there is one."), as Polylang does (`[R] polylang-master/src/crud-posts.php:239-247`); set through `[4.5]`'s placement with `TreeRules::refusal()` under the tree lock. *(re-check, as built: `[4.5]`'s only placement is inside `UpdateContentDetails`, which wants the actor's `update` right and an expected revision; `CreateDraft::start()` takes no parent.)* So `TranslateContent`, under the tree lock (`SubtreeAddresses::lock($type)`) and after `TreeRules::refusal()` answers null, writes the new draft's `parent_id` and `position` itself (last among its siblings: the largest sibling `position` plus one, or 0, as `UpdateContentDetails::nextPosition()`, private, `:350`, works it out), as `PlaceImportedPage` writes them with the query builder; a draft has no address, so nothing moves and nothing is purged. **After commit:** `start()`'s `ContentSaved`; no purge.

`GroupLock::lock()` is 6.1a's (Task C4), shared with 6.1h. `TermSiblings::inLanguage()` (D13) maps through `terms.translation_group`; a missing sibling is made by `CreateTerm`'s own code path (a `TermWriter::create()` both share, so the term counts, its address and its slug follow the same rules), parent first, its group the source term's (minted and set on the source term if none); a term's lock: `lockForUpdate()` on the source terms, in ID order, before reading their groups.

- [ ] **Step 1: Write the failing tests** (`TranslateContentTest.php`):
  - `it('makes a French draft from the published English version, in one group with it')`: the source had no group; both now share a new lower-case ULID that is neither's ID.
  - `it('copies the title, the body with its block IDs, the excerpt and the fields, and clears the canonical')`.
  - `it('points links at the French versions the group has, and keeps the rest')`: a link mark to `hello` (whose group has `bonjour`) becomes `teme:content/{bonjour}`; one to `alone` stays; a button card's `contentId` and an `entry` field likewise.
  - `it('files the draft under the French versions of its terms, making the missing ones in their group')` (Q6 A): `news` maps to `actualites`; a second English category with no French version gets one, named and slugged as the English, French, in a new group with it, and `madeTerms` names it.
  - `it('makes a missing term's parent first')`.
  - `it('maps the primary category to its French version')`.
  - `it('records the copied revision on the draft's first revision')`.
  - `it('gives the draft the source's slug while it is free in that language')`.
  - `it('copies an unpublished source only for someone who may edit it, and tells anyone else to wait')`: a contributor translating an editor's unpublished draft is refused with *Every word*'s sentence and nothing is written.
  - `it('refuses a language the group already has, naming that version')`; `it('refuses the source's own language and a language the site does not have')`; `it('refuses a source in the trash')`.
  - `it('translates into a hidden language')`: Spanish is hidden; the draft is made.
  - `it('translates a region into its French version under the same fixed slug')` *(review: and the English page still draws the English region: `Regions::published()` reads `language IS NULL` while 6.1f is parked, *While the other parts are parked*)*.
  - *(review)* `it('puts a page's translation under its parent's version in that language, or at the top and says so')`; `it('copies a private or protected source only for someone who may edit it')`.
  - `it('announces the new draft as saved, telling its language')`: one `ContentSaved` whose `item->language` is `fr`.
  - `it('purges no public page')`: nothing recorded for purging.
  - `it('writes nothing when the person may not create items of the type')`: `contents`, `content_revisions`, `terms` and the source's group unchanged.
  - `it('refuses a second French version found under the lock')`: a French row planted in the group between validation and perform (through a `beforeExecuting` hook as 1.7e's writes test does) is refused with the same words, and the unique index's `23505` reads the same.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(content): translate an item into a new draft in another language (I-3360)`.

## Task B3: Link, unlink and change a language

**Files:** `LinkTranslations.php`, `UnlinkTranslation.php`, `ChangeContentLanguage.php` and Inputs, `LinkTranslationsTest.php`, `ChangeContentLanguageTest.php`.

**Their contracts** (D10, D23): `LinkTranslationsInput(?int $actorId, list<string> $contentIds)` (2 to `count(SiteLanguages::all())`, distinct); `UnlinkTranslationInput(?int $actorId, string $contentId)`; `ChangeContentLanguageInput(?int $actorId, string $contentId, string $language, string $expectedRevisionId)`. Each takes `GroupLock::lock()` as its first row lock, after `LanguageLock::shared()` (Link, Change) and a hierarchical type's `[4.5]` `TreeLock::take()` (Change) (*(review)* D5's order). **After commit:** `translations:{group}` for every group involved, before and after, and `content:{id}` of each published member (their pages' alternates change); `ChangeContentLanguage` also what `UpdateContentDetails` purges for an address change, and its `PathProjection` and `SearchText` sync run inside `perform()`.

- [ ] **Step 1: Write the failing tests:**
  - `it('joins two items, and every member of their groups, into one group')`.
  - `it('refuses two versions in one language, naming both')`; `it('refuses items of different types')`; `it('refuses an item in the trash')`.
  - `it('takes one version out of its group and leaves the others linked')`.
  - `it('purges the pages of every version whose group changed')`.
  - `it('writes nothing when the person may not edit one of the items')`: link and unlink, each.
  - (`ChangeContentLanguageTest.php`) `it('moves a published item to French: its address takes the prefix, the old one redirects, its search speaks French')` (the address asserted as "a new current row, the old one history", D6's text left to 6.1z).
  - `it('refuses a language its group already has')`.
  - `it('maps its terms to their French versions, and refuses naming a term with none')`.
  - `it('keeps its slug when free in the new language, and otherwise takes the next and says so')`.
  - `it('refuses a change made on an older revision')`.
  - *(review)* `it('refuses to move a page that has a parent or pages under it to another language')` (`[4.5]`).
  - `it('writes nothing when the person may not edit the item')`.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(content): link, unlink and move items between languages (I-3360)`.

## Task B4: Out of date

**Files:** `Queries/TranslationStatus.php`, `MarkTranslationUpToDate.php` and Input, `OutOfDateTest.php`.

`TranslationStatus::forGroup(string $group): list<array{id: string, language: string, title: string, status: string, visibility: string, outOfDate: bool, sourceId: string|null, sourceLanguage: string|null, sourceChangedAt: string|null}>` runs D9's query once (each `language` read through `SiteLanguages::of()`); `TranslationStatus::forItem(Content $content)` returns `[]` for an item with no group. `MarkTranslationUpToDateInput(?int $actorId, string $contentId, string $expectedRevisionId)`: authorize `update` (D23); validate: the working revision has a `source_revision_id` whose item is live; perform: lock the item, refuse a stale revision (`StaleRevisionException`, as `SaveDraft`), write a copy of the working revision with `source_revision_id` set to the source's published revision (or working, for an unpublished source the person may edit), kind `checkpoint`, and move `working_revision_id`. No purge.

- [ ] **Step 1: Write the failing tests:**
  - `it('marks a version out of date once its source publishes a newer revision')`.
  - `it('keeps it up to date while the source only saves drafts')`.
  - `it('keeps the mark through the version's own saves and restores')`.
  - `it('never marks a version whose source is gone or in the trash')`.
  - `it('marks it up to date with a new revision, leaving the others as they were')`.
  - `it('reads a whole group's state in one statement')`: statements counted around `forGroup()`.
  - `it('refuses to mark a version made on an older revision')`.
  - `it('writes nothing when the person may not edit the version')`.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(content): a translation is out of date when its source changes (I-3360)`.

## Task B5: The lifecycle and a group

**Files:** the six Operations of D11, `GroupLifecycleTest.php`. *(review)* `[4.5]` already edits these six (the tree lock first, `SubtreeAddresses::follow()`); this task adds after it. `RestoreContent` replaces its row lock (`:55`; *(re-check)* `:61`, right after `$this->takeTree()` at `:60` and before `noteChain()`, which reads the locked row) with `GroupLock::lock([$id])`, taken after the tree lock, because its group check needs the group's rows; the others keep their own row lock (they don't read the group's other rows) and only add the purge tag. *(re-check)* `RunScheduledPublication` and `ApproveReview` reach `PublishRevision`, so its purge covers them. `UpdateImportedContent` (`[4.5]` gave it `FollowsTree`) writes `deleted_at` back from WordPress (`Concerns/WritesImportedContent.php:96`), so a catch-up can restore a version whose language its group has since filled; 6.1a changes neither file (an imported row is in the default language until linked, and block IDs owns `WritesImportedContent.php`); the unique index refuses that rare restore, and that object fails rather than two versions sharing a language. Recorded as a `B-?? (draft)` in the records, for 6.1h.

- [ ] **Step 1: Write the failing tests:**
  - `it('purges the group's pages when a version is published, scheduled to go live, unpublished, trashed, made private or deleted for good')`: a dataset of six; each records `translations:{group}` to purge after commit, and nothing before it.
  - `it('restores a trashed version into its group while its language is free')`.
  - `it('restores a trashed version outside its group when its language is taken, and says so')`: the result's flag, *Every word*'s sentence in the toast.
  - `it('keeps the other versions linked when one is deleted for good, and forgets the revision they came from')`.
  - `it('changes nothing for an item with no group')`: today's purges only.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(content): every version's lifecycle keeps its group whole (I-3360)`.

## Task B6: What the screens and the export read, and the routes

**Files:** `EditorSession.php`, `ContentList.php`, `ContentListRequest`, `ContentsKind.php`, `TermsKind.php`, `TranslationsController.php`, `routes/admin/translations.php`, `TranslationScreensDataTest.php`, `TranslationRoutesTest.php`, `LanguageColumnsTest.php`.

- `EditorSession` gains `language` (the tag), `languages` (`list<array{tag, name, shown}>`, empty for a single-language site), `translations` (`TranslationStatus::forItem()`: a version the person may open with its title and editor URL; one they may not, by its language and state only) and `can.translate` (D23's rule for each missing language is the same, so one flag); never a group ID.
- *(review, `[4.5]` D22)* `TreeRules::refusal()` gains the same-language rule (the parent's `language` is the item's, NULL with NULL: "A page's parent is in its own language: choose the {French} “{À propos}”."), and its test in `TranslationScreensDataTest`: `it('refuses a parent in another language')`. *(re-check)* Its parent read (`TreeRules.php:36`) gains `language`; the test runs it through `UpdateContentDetails` with a `TreePlace` in both directions (a French page under an English one, an English under a French), each writing nothing, and through `PlaceImportedPage`, which leaves the page where it is with its `page.refused` message. *(I-3372)* `ParentChoices`' language filter (and its test, `it('offers parents in the item's language only')`) waits with page parents' parked 4.5d, which makes `ParentChoices`.
- `ContentList` takes `language` (a site tag, or absent): D1's predicate; each row gains `language` (the tag); a filtered list shows no total, as today's filters (`ContentList.php:28`).
- `ContentsKind` and `TermsKind` write `language` and `translation_group`, each described (*Every word*), and raise their kind's version.
- Routes, under the verified admin group, each an Operation and a toast or a redirect as `ContentActionController` does: `POST content/{type}/{content}/translations` (`content.translations.store`, `language`) → the new draft's editor with *Every word*'s toast; `POST …/translations/link` (`content.translations.link`, `with`); `DELETE …/translations/{other}` (`content.translations.unlink`); `PUT …/language` (`content.language.update`); `POST …/translations/up-to-date` (`content.translations.up-to-date`).

- [ ] **Step 1: Write the failing tests:**
  - `it('gives the editor the item's language, the site's languages and its group's versions with their state')`; `it('gives a single-language site's editor no languages and no versions')`; `it('never gives the editor a group's ID')`; `it('names a version only to someone who may open it')`.
  - `it('lists one language's items when the list is filtered by it, with no total')`; `it('shows each row's language')`.
  - `it('exports each item's and each term's language and group')`.
  - (`TranslationRoutesTest.php`) one test a route: the person who may gets the toast or the new editor; `it('answers 403 and writes nothing for someone who may not')`, a dataset of the five.
  - (`LanguageColumnsTest.php`) `it('never writes the default language's tag through any of this release's Operations')`: every Operation of 6.1a run on the fixture, then `LanguageColumns::defaultTagged()` empty.
- [ ] **Step 2 to 4:** fail, build, quick check; `php artisan teme:docs:export`; commit `feat(content): what the editor, the lists and the export know of languages (I-3360)`.

## Task T1: Which types are translated (D25)

**Files:** `app/Domain/Languages/Operations/ChangeTranslatedTypes.php` and its Input, `SiteLanguages.php`, `LanguagesGroup.php`, `LanguagesController.php` (`translated`), `routes/admin/languages.php`, `CreateDraft.php`, `TranslateContent.php`, `LinkTranslations.php`, `ChangeContentLanguage.php`, `CreateTerm.php`, `TermRules.php`, `TermSiblings.php`, `TranslationCopy.php`, `EditorSession.php`, `ContentList.php`, `tests/Feature/Languages/TranslatedTypesTest.php`.

The two settings and `translates()`, `translatesTaxonomy()` (D2, D25); `ChangeTranslatedTypes` (D25) and its route `PUT settings/languages/translated` (`settings.languages.translated`, `LanguagesController::translated`) under `can:settings.general.manage`; the checks D25 names in each Operation, in `validate()` and again under `GroupLock` where the Operation takes it.

- [ ] **Step 1: Write the failing tests** (`TranslatedTypesTest.php`, `LanguageSite::build()`):
  - `it('translates every content type and taxonomy until the maintainers says otherwise')`: on a site that never saved the setting, `translates()` true for every registered type and `translatesTaxonomy()` for every taxonomy.
  - `it('stops translating a type with no items in other languages, moving no address')`.
  - `it('refuses to stop translating a type while items of it are in another language or linked as translations, counting them')`: the trash counted too; the `settings` row and `settings_changes` unchanged.
  - `it('refuses to stop translating a taxonomy while terms of it are in another language or linked, counting them')`.
  - `it('refuses a type or a taxonomy the site doesn't have')`.
  - `it('starts translating a type again, moving nothing')`.
  - `it('refuses a draft in a language, a translation, a link and a language change for a type that isn't translated, in these words')`: a dataset of `CreateDraft` with a language, `TranslateContent`, `LinkTranslations`, `ChangeContentLanguage`; each writes nothing.
  - `it('refuses a term in a language for a taxonomy that isn't translated')`.
  - `it('lets an item in any language be filed under a taxonomy that isn't translated, and keeps those terms on Translate')`: the French post filed under an untranslated tag; its translation into Spanish keeps the same tag.
  - `it('gives the editor of a type that isn't translated no languages, no versions and no Translate')`.
  - `it('keeps the addresses of a type that isn't translated without a prefix')`: every item's `language` NULL and its path unchanged.
  - *(I-3373 (3))* `it('lists only the default language's items in the archive of a taxonomy that isn't translated')`: the English and the French post both filed under an untranslated tag; its archive (`/tag/{slug}/`) lists the English one and not the French; `/fr/tag/{slug}/` answers 404.
  - `it('refuses a form opened on an older version of the languages')`.
  - `it('writes nothing when someone without the General settings ability changes what is translated')`.
  - `it('answers its route for an administrator and refuses an editor')`.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(languages): choose which types and taxonomies are translated (I-3370)`.

## Task T2: The changelog (Z5, moved from 6.1z), 6.1a's gate and records

- [ ] **The changelog** *(moved from the parked 6.1z's Z5, I-3372)*: `CHANGELOG.md` gains the slice's section, its heading in the form `grep -n "^## " CHANGELOG.md | head -3` shows, with *Every word*'s 6.1a paragraph, `### Added (6.1a)` from this part's records, `### Contracts` (`SearchRequest::$language`, `@since 0.6.0`), and a *Deploy* note (five migrations, all adding but the last two: the columns and constraints, their validation, eight indexes built concurrently, then the one-language slug index and term key dropped; no rebuild asked, since on a one-language site no search row waits). If `slice/6.1` was cut beside 4.5b, the section goes after page parents' once `main` is merged in (*Merge order*). *(re-check: where the lines go.)* The base's newest heading is `## [Unreleased]` (`CHANGELOG.md:5`), holding page parents' paragraph ("Slice 4.5: Page parents, the foundation. …") and its `### Added (4.5)`, `### Console`, `### Theme view data`, `### API` and `### Deploy (4.5)`. **While that heading is still `## [Unreleased]`**, 6.1a adds no second `## [Unreleased]`: its paragraph goes after page parents' paragraph, opening "Slice 6.1a: " as page parents' opens "Slice 4.5: ", and its own headings, `### Added (6.1a)`, `### Contracts (6.1a)` and `### Deploy (6.1a)`, after `### Deploy (4.5)`, the last of page parents' (4.5b's records: one section per slice, its headings suffixed with the part). **If the architect has tagged page parents first** (the heading reads `## [0.x.y-alpha.N] — date`), 6.1a opens a new `## [Unreleased]` above it with the same contents. Either way the architect renames `[Unreleased]` at the tag; whether `v0.6.0-alpha.1` also carries page parents' lines is the architect's call at page parents' merge. Commit `docs(changelog): one site in several languages, the foundation (I-3360)`.
- [ ] The full `composer check`. Records: the commits, the totals, every `B-?? (draft)`, the signatures as built (later parts read them from here), the migration timings on the development database, the routes as built (`php artisan route:list --path=admin/settings/languages` and `--path=admin/content`) and the editor session's props as built, for 6.1i when it is built. Commit and push. The architect then merges `slice/6.1` into `main` after page parents (*Merge order*) and tags `v0.6.0-alpha.1` (Q8 A), and updates `docs/architecture/` for languages (the NULL rule, the prefix, the lock order).

---

# Part 6.1c: What readers get in each language: the head, view data, sitemaps, search, feeds and the read API

**Parked** (I-3372, 2026-11-11): stage 3. Trigger: a site turns on a second language and needs its pages' `hreflang`, `og:locale` and alternates, and its sitemaps, search, feeds and API in that language. Already in 6.1a: S1 and S2 whole (A5, C3), and the default-language predicates of H3, S3 and S4 (A5), which this part replaces with their language-aware forms when it is built.

From `slice/6.1` after 6.1a, on `slice/6.1c-reading`; **Opus** (the hidden-language predicates of sitemaps, search, feeds and the API are the leak surface; the shapes are the theme contract's and the API's promise; the reindex's SQL). Tasks H1 to H4 give the head, the view data, sitemaps and structured data; S1 to S4 search, feeds and the API, which read H1's `Alternates` and H3's `ListedItems`. Every task follows *When a session fails*.

## Files (6.1c)

- **Create:** `app/Domain/Languages/{LanguageComposer,Alternates}.php`; `app/Domain/Site/ViewModels/{PageLanguageView,Alternate}.php`; `app/Domain/Site/Head/LanguageLinks.php`; `tests/Feature/Languages/{ViewDataLanguageTest,HreflangTest,OgLocaleTest,SitemapAlternatesTest,StructuredDataLanguageTest}.php`; `tests/Feature/Languages/{SearchPerLanguageTest,ReindexPerLanguageTest,FeedsPerLanguageTest}.php`; `tests/Feature/Api/{ApiLanguageMustsTest,ApiLanguageAdditionsTest}.php`.
- **Modify:** `[4.1] app/Domain/Themes/ComposerRegistry.php` (`CORE` gains `LanguageComposer`; `ThemeContext` gets `PageLanguage::tag()`); `app/Providers/HeadServiceProvider.php`; `app/Domain/Site/Head/HeadTags.php` (`og:locale`, `og:locale:alternate`); `app/Domain/Sitemaps/{SitemapAddress,SitemapXml,ItemsSitemap,TermsSitemap,ArchivesSitemap,HomeSitemap}.php`; `[3.1] app/Domain/Sitemaps/Queries/ListedItems.php`; `app/Domain/StructuredData/{CoreGraph,ListingNodes}.php`; `[4.1] app/Domain/Themes/Reference/ViewDataCatalogue.php`; `docs/reference/theme.md` and `docs/reference/theme-view-data.json` (regenerated by `php artisan teme:docs:theme`). *Modify, for whole-list pins:* grep `tests/` for `ComposerRegistry::CORE`, the head's entry lists and `SitemapXml`'s exact output, and name each. `packages/teme-contracts/src/Search/SearchRequest.php`; `app/Domain/Search/PostgresSiteSearch.php`; `app/Domain/Site/SearchViews.php`; `app/Domain/Contents/Operations/ReindexSearch.php`; `app/Domain/Feeds/{FeedResolver,FeedSubject,FeedBuilder,FeedItems,FeedLinks}.php`; `app/Http/Site/Feeds/FeedController.php`; `[3.1] app/Domain/Api/{Live,ApiWords}.php`, `[3.1] app/Domain/Api/Items/{ItemsQuery,ItemFilters,ItemShapes}.php`, `[3.1] app/Domain/Api/Catalogue/{TermsQuery,TermFilters,SiteShape}.php`, `[3.1] app/Domain/Api/Llms/MarkdownPages.php`, `[3.1] app/Http/Api/V1/{ItemsController,TermsController}.php` (`PARAMETERS`). *Modify, for whole-list pins:* `[3.1]` 3.1a's two exact-keys tests and 3.1b's site and terms shape tests gain `translations` and `languages`; any test that lists `ItemsController::PARAMETERS`.

## Task H0: Preconditions, then branch

- [ ] 6.1a's merge is on `slice/6.1`. By its text: `grep -n "private const array CORE = \[SiteComposer::class, NavigationComposer::class, ThemeSettingsComposer::class\];" app/Domain/Themes/ComposerRegistry.php`; `grep -n "new ThemeContext(\$name, \$this->intent, \$this->language->tag(), \$view)"` in the same file; `grep -n "\$tags\[\] = MetaTag::property('og:site_name'" app/Domain/Site/Head/HeadTags.php`; `grep -n "<urlset xmlns=" app/Domain/Sitemaps/SitemapXml.php`; `grep -n "public function page(string \$type, ?string \$from): array\|public function latestListed(string \$type, int \$count): array\|public function listed(string \$id): ?CarbonImmutable\|public function any(string \$type): bool" app/Domain/Sitemaps/Queries/ListedItems.php` → four lines; `grep -n "inLanguage" app/Domain/StructuredData/*.php`. For search, feeds and the API (6.1d's Task S0 before the re-cut): `grep -n "private const string MATCHES = " app/Domain/Search/PostgresSiteSearch.php`; `grep -n "private static function waiting(string \$config): Builder" app/Domain/Contents/Operations/ReindexSearch.php`; `grep -n "public function __construct(" -A5 packages/teme-contracts/src/Search/SearchRequest.php` shows four parameters, `$types` last; `grep -n "public static function apply(Builder \$query, ?array \$types = null): Builder" app/Domain/Api/Live.php`; `grep -n "public const array PARAMETERS" app/Http/Api/V1/ItemsController.php app/Http/Api/V1/TermsController.php`; `grep -n "'language' => \$this->language->tag()," app/Domain/Api/Catalogue/SiteShape.php`; how `FeedResolver` reads a base (`FeedPath::parse()`, then the base's lookup). STOP on a difference. `git checkout -b slice/6.1c-reading origin/slice/6.1`.

## Task H1: The view data's three names

**Files:** `LanguageComposer.php`, `Alternates.php`, `PageLanguageView.php`, `Alternate.php`, `ComposerRegistry.php`, `ViewDataLanguageTest.php`.

`Alternates::for(DocumentPage $page): list<Alternate>` (D16), remembered for the page drawn (as `PageHead` keeps its values), so the view data, the head and `og:locale:alternate` read it once. For an item, **one statement**: its group's members that are published, live by now, `visibility = 'public'`, of a shown language, joined to their current `paths` row (`JOIN paths p ON p.target_type = 'content' AND p.target_id = c.id AND p.is_current`), ordered by the site's language order in PHP; for a term the same over `terms`; for the home page every shown language's home; for a type archive's first page, the default and each other shown language with a live item of the type (one indexed probe a language, `contents_other_language_published`). It records `translations:{group}` and `content:{id}` (or `term:{id}`) of each entry drawn. A list of one becomes `[]`.

- [ ] **Step 1: Write the failing tests** (a template of a test theme that prints the three values; `PageLanguage::use()` as the seam):
  - `it('gives every theme view the page's language, its direction and its alternates')`: the French post: `$language->tag` `fr`, `->name` `Français`, `->isDefault` false, `->home` ending `/fr/`; `$direction` `ltr`; `$alternates` the English and French entries in that order, French `current`, the hidden Spanish left out.
  - `it('gives no alternates to a page with no other version')`: `alone` gives `[]`.
  - `it('leaves out a version that is private, unpublished, dated ahead or in a hidden language')`: a dataset.
  - `it('gives the home page every shown language, and a type's archive the languages it has items in')`.
  - `it('gives an author page, a search page, a later page of a listing and a 404 no alternates')`.
  - `it('records the group's purge tag and each version's own')`.
  - `it('lets core give the three reserved names and still refuses them from a theme's composer')`.
  - `it('tells a theme's composer the locale of the page being drawn')`: `ViewContext::locale()` is `fr`.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(themes): a page's language, direction and alternates in its view data (I-3360)`.

## Task H2: `hreflang` and `og:locale`

**Files:** `LanguageLinks.php`, `HeadServiceProvider.php`, `HeadTags.php`, `HreflangTest.php`, `OgLocaleTest.php`.

- [ ] **Step 1: Write the failing tests:**
  - `it('writes an hreflang link for each version, the page itself included, and x-default to the default language's')`: three `<link rel="alternate">` on the English and on the French post, identical sets.
  - `it('writes no hreflang on a page with no other version')`.
  - `it('writes no hreflang on a page search engines are told not to list')`: the item's noindex, and a type hidden from search engines.
  - `it('writes x-default only when the default language's version is among them')`.
  - `it('writes hreflang on page 1 of the home page and never on page 2')`.
  - (`OgLocaleTest.php`) `it('writes og:locale in Open Graph's form of the page's language')`: `fr_FR` on the French post, `en_GB` on the English.
  - `it('writes og:locale on a single-language site too')` (#370): a Spanish site gives `es_ES`.
  - `it('writes og:locale:alternate for each other version')`.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(seo): hreflang with x-default, and og:locale (I-306, I-3360)`.

## Task H3: Sitemaps

**Files:** the six sitemap files, `ListedItems.php`, `SitemapAlternatesTest.php`.

`ListedItems`: `page()` and `starts()` list every shown language's items (`language IS NULL OR language = ANY(?)`, the shown other languages bound as one array literal, as `PostgresSiteSearch` binds its types); `latestListed()`, `any()` and `newest()` take `?string $language = null` last, the default language when not given, so `[3.1]`'s `llms.txt` keeps the default language's without a change of its own; `listed($id)` answers for any shown language. A `SitemapAddress` gains `alternates: list<array{hreflang: string, href: string}>` (last, default `[]`), filled for a page's items by **one** statement over their groups (`WHERE translation_group = ANY(?)`, live, public, shown, joined to current paths).

- [ ] **Step 1: Write the failing tests:**
  - `it('adds the alternates of an item with other versions, x-default included')`.
  - `it('declares the xhtml namespace once on every page of items')`.
  - `it('lists every shown language's items and never a hidden one's')`.
  - `it('lists each shown language's home, and each type archive in the languages that have its items')`.
  - `it('adds one statement to a sitemap page')`: the 1.9f budget plus one.
  - `it('lists only the default language's items where nothing names a language')`: `latestListed()` and `any()` called as `[3.1]` calls them.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(sitemaps): every version listed, each with its alternates (I-3360)`.

## Task H4: Structured data, and the reference

**Files:** `CoreGraph.php`, `ListingNodes.php`, `ViewDataCatalogue.php`, the two reference files, `StructuredDataLanguageTest.php`.

- [ ] **Step 1: Write the failing tests:**
  - `it('says an item's own language in its nodes')`: the French post's `Article` and `WebPage` `inLanguage` `fr`.
  - `it('says the page's language on a French listing, and the default's on the site's node')`.
  - `it('documents the three names and their shapes in the theme reference')`: `teme:docs:theme` regenerated; the pin test passes (three additions, nothing changed or removed).
- [ ] **Step 2 to 4:** fail, build, regenerate, quick check; commit `feat(structured-data): each page's own language (I-3360)`.

## Task S1: Search per language

*(Moved whole to 6.1a's A5, I-3372.)*

**Files:** `SearchRequest.php`, `PostgresSiteSearch.php`, `SearchViews.php`, `SearchPerLanguageTest.php`.

`SearchRequest` gains `public ?string $language = null` last (`@since 0.6.0`: "the language searched, as stored: null for the site's default"). `PostgresSiteSearch`'s `MATCHES` becomes a method `matches(?string $language): array{0: string, 1: list<mixed>}` adding `AND c.language IS NULL` or `AND c.language = ?`, used by both statements, with `SiteLanguage::searchConfig()` of that language for `websearch_to_tsquery` and `ts_headline`. `SearchViews::for()` passes `PageLanguage::stored()`.

- [ ] **Step 1: Write the failing tests:**
  - `it('finds a French item with French stemming at the French search')`: a French item whose text is "ils mangeaient", found by "manger" at `/fr/?s=` (French Snowball stems both to `mang`; English does not).
  - `it('never finds a French item from the default language's search, nor an English one from the French')`.
  - `it('counts and ranks only the language searched')`.
  - `it('tells a search binding the language searched')`: a test `SiteSearch` bound in the container receives `language` `fr`.
  - `it('searches as before on a site with one language')`: the existing search tests, unchanged, named in the records.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(search): each language searched in its own words (I-435, I-3360)`.

## Task S2: Rebuilding search per row

*(Moved whole to 6.1a's C3, at the review.)*

**Files:** `ReindexSearch.php`, `ReindexPerLanguageTest.php`.

**Its code, written out** (delicate: which rows a change of rules rebuilds):

```php
/**
 * The published, live items whose search was built with other rules than their own language's
 * (I-3360).
 */
private static function waiting(): Builder
{
    $languages = app(SiteLanguages::class);
    $rules = app(SiteLanguage::class);

    return DB::table('contents')
        ->where('status', 'published')
        ->whereNull('deleted_at')
        ->whereNotNull('published_revision_id')
        ->where(function (Builder $query) use ($languages, $rules): void {
            $default = $rules->searchConfig($languages->defaultTag());
            $query->where(fn (Builder $query) => $query->whereNull('language')
                ->where(fn (Builder $query) => $query->whereNull('search_config')->orWhere('search_config', '<>', $default)));

            foreach ($languages->others() as $tag) {
                $config = $rules->searchConfig($tag);
                $query->orWhere(fn (Builder $query) => $query->where('language', $tag)
                    ->where(fn (Builder $query) => $query->whereNull('search_config')->orWhere('search_config', '<>', $config)));
            }
        });
}
```

`perform()` calls `self::waiting()` for both the batch and the count; `SearchText::sync()` already builds each row with its own language's rules (6.1a's `forContent()`).

- [ ] **Step 1: Write the failing tests:**
  - `it('rebuilds each row with its own language's rules')`: French rows built `english` become `french`; English rows built `french` become `english`.
  - `it('leaves rows already built with their own language's rules alone')`: zero reindexed on a second run.
  - `it('still writes nothing when someone without the ability asks')` (the existing test, run).
- [ ] **Step 2 to 4:** fail, build, quick check; commit `fix(search): rebuild each item with its own language's rules (I-435, I-3360)`.

## Task S3: Feeds per language

**Files:** the feed files, `FeedController.php`, `FeedsPerLanguageTest.php`.

`FeedResolver` reads a base whose first segment is another language's prefix as that language's: the prefix alone is its site feed; the rest, a term's (by the whole path, which carries the prefix) or an author's (by the rest); a hidden language's base is not a feed (handed back, so it 404s). `FeedSubject` gains `?string $language` (last); `FeedItems` adds D1's predicate; `FeedBuilder` writes `<language>` from it; `FeedController` calls `PageLanguage::use()` with it; `FeedLinks` points a page at its own language's feed.

- [ ] **Step 1: Write the failing tests:**
  - `it('serves the French feed at /fr/feed/, listing French items and saying fr')`.
  - `it('serves a French term's feed and an author's French feed under the prefix')`.
  - `it('keeps the default language's feeds to its own items')`.
  - `it('answers 404 for a hidden language's feed')`.
  - `it('links a French page to the French feed')`.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(feeds): a feed in each language (I-3360)`.

## Task S4: The read API's musts and additions

**Files:** the `[3.1]` API files above, `ApiLanguageMustsTest.php`, `ApiLanguageAdditionsTest.php`.

D19, under `[3.1]` D14. `Live::apply()` gains the hidden-language predicate when `SiteLanguages::shown()` is shorter than `all()`; `ItemsQuery` and `TermsQuery` add D1's predicate for `language` (absent: the default); `ItemFilters` and `TermFilters` read `language` (a shown tag; anything else `400 invalid_parameter` with the typo's words: `ApiWords`' sentence for an unknown value, *Every word*); `ItemShapes::full()` adds `translations` (one statement for the item's group, through `Alternates`' query without the current version, so the API and the head agree); `SiteShape` adds `languages`; `MarkdownPages` answers for shown languages only (`ListedItems::listed()`, as H3 leaves it).

- [ ] **Step 1: Write the failing tests:**
  - (`ApiLanguageMustsTest.php`) `it('lists only the default language's items and terms, as before a translation existed')`.
  - `it('answers a French item by its ID and its address')`: `/api/v1/items/{bonjour}` and `by-path?path=/fr/bonjour/`, each `language` `fr`.
  - `it('answers a hidden language's item as one that never existed')`: the same status, `code` and body as an unknown ID, by ID and by path; an `entry` field naming it is `null`; a link to it in a body is its text alone.
  - `it('lists only the default language's items in llms.txt, and serves .md pages for shown languages only')`.
  - `it('gives the same answer whatever Accept-Language or cookie a client sends')`.
  - (`ApiLanguageAdditionsTest.php`) `it('lists French items and terms at ?language=fr')`.
  - `it('lists the default language's items for its own tag, as for none')`.
  - `it('refuses a language the site does not show, in a typo's words')`: `language=es` (hidden) and `language=xx` give the same problem.
  - `it('gives every full item its translations, [] when it has none')`: the exact keys and values.
  - `it('lists the site's shown languages, the default first')`.
  - `it('keeps every member 3.1 gave, with its meaning')`: 3.1a's and 3.1b's exact-shape tests, updated by addition only.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(api): language on lists, translations and the site's languages (I-3360)`.

## Task S5: 6.1c's gate and records

The full `composer check`; the reference files committed by this part (its named generator). Records with the API's new members exactly as built, for `CHANGELOG.md` (6.1z). Commit and push.

---

# Part 6.1e: Teme's own words for readers

**Parked** (I-3372, 2026-11-11): stage 3. Trigger: a site's readers need Teme's own words in a language other than English (a second language, or a one-language site in Spanish, French, Portuguese or German), or a right-to-left site launches (#367, #368, #369 stay open until then).

From `slice/6.1` after 6.1a, on `slice/6.1e-reader-words`; **Sonnet**. Fixes #367, #368 and #369. Every task follows *When a session fails*.

## Files (6.1e)

- **Create:** `lang/{es,fr,pt_BR,de}.json`; `themes/default/lang/{es,fr,pt_BR,de}.json`; `app/Domain/Languages/ReaderWords.php`; `tests/Feature/Languages/{DocumentLanguageTest,ReaderLocaleTest,ReaderWordsTest,ThemeWordsTest}.php`.
- **Modify:** `app/View/Components/Teme/Document.php` and `resources/views/teme/components/document.blade.php` (`lang`, `dir`); `resources/views/teme/fallback/templates/{archive,search}.blade.php` and every other core view with a bare word; every view of `themes/default/views` with a word; `app/Http/Site/{PreviewController,CommentController,UnlockController,PendingCommentsController}.php` and `app/Domain/Site/RespondViews.php` (the locale from the item); `app/Domain/Comments/Operations/SubmitComment.php` (`Number::format`); `app/Providers/ThemeServiceProvider.php` (the active theme's `lang/`). *Modify:* `tests/Feature/DefaultTheme/*` tests that pin a label's English text keep passing (English is unchanged); grep them for `not->toContain` on words (lesson B-359).

## Task W0: Preconditions, then branch

- [ ] 6.1a's merge is on `slice/6.1`. By its text: `grep -n '<html lang="{{ \$lang }}" data-scheme="{{ \$scheme }}">' resources/views/teme/components/document.blade.php`; `grep -n "\$this->lang = \$model instanceof ArticleView" app/View/Components/Teme/Document.php`; `grep -n ">Previous<\|>Next<" themes/default/views/partials/pagination.blade.php` → two lines; `grep -rn "App::setLocale\|app()->setLocale" app routes bootstrap` → only 6.1a's `PageLanguage`; how the active theme's views are registered in `ThemeServiceProvider` (its `loadViewsFrom` or `View::addNamespace`). STOP on a difference. `git checkout -b slice/6.1e-reader-words origin/slice/6.1`.

## Task W1: `lang` and `dir`

**Files:** `Document.php`, `document.blade.php`, `DocumentLanguageTest.php`.

`Document::$lang` = `PageLanguage::tag()`; `$dir` = `SiteLanguages::direction()` of it; the shell writes `<html lang="{{ $lang }}" dir="{{ $dir }}" …>`.

- [ ] **Step 1:** `it('writes the page's language and direction on html')`: the French post `lang="fr" dir="ltr"`; `it('draws an Arabic site right to left')` (#367): `lang="ar" dir="rtl"`, and Yiddish likewise; `it('writes dir on every public page, ltr on an English site')` (the existing 1.6c shell test extended).
- [ ] **Step 2 to 4:** fail, build, quick check; commit `fix(site): dir on the page, from its language (I-3360)`.

## Task W2: The locale on every reader entry point

**Files:** the four controllers, `RespondViews.php`, `SubmitComment.php`, `ReaderLocaleTest.php`.

- [ ] **Step 1: Write the failing tests** (with a stand-in `lang/fr.json` of two keys written by the test into a temporary path added with `Lang::addJsonPath()`, so this task passes before W4's files exist):
  - `it('answers a comment posted on a French item in French')`: a missing name gives the French refusal.
  - `it('answers the password form of a French item in French')`.
  - `it('draws the reply page of a French item's comment in French')`.
  - `it('draws a French item's preview in French')`.
  - `it('draws core's words in the site's language on a single-language Spanish site')` (#368).
  - `it('writes a number in a reader's message as the page's language writes it')`: "5 000" in French, through `Number::format()`.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `fix(site): readers get core's words in the page's language (I-3360)`.

## Task W3: Every word through the translator

**Files:** core's views, the default theme's views, `ThemeServiceProvider.php`, `ThemeWordsTest.php`.

Every word a reader sees in `resources/views/teme/**` and `themes/default/views/**` goes through `__()` (`trans_choice()` for a count), one key a sentence (D20); the comment form's privacy line, built today from two pieces, becomes whole sentences with the same words (*Every word*), and the 1.8b test that pins it is named under the records. `ThemeServiceProvider` adds the active theme's `lang/` folder with `Lang::addJsonPath()` after core's own, for public requests.

- [ ] **Step 1:** `it('draws the default theme's labels through the translator')` (#369): "Previous", "Next", "Related", "Reply" and the 404's sentences come from a stand-in French file; `it('loads the active theme's words after core's, so a theme may word core's keys its own way')`; `it('loads no theme's words in the admin')`; `it('reads English where a language ships no words')`: an Italian site's comment form in English, `lang="it"`.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `fix(themes): the default theme's words through the translator (I-3360)`.

## Task W4: The four languages' files

**Files:** `ReaderWords.php`, the eight word files, `ReaderWordsTest.php`.

`ReaderWords::SOURCES` lists the files a reader's words come from (core's public views, the default theme's views, and the PHP classes of *Every word*'s reader list); `ReaderWords::keys(string $source): list<string>` reads their `__('…')` and `trans_choice('…')` keys with the same pattern the test uses. The builder writes the four core files (`lang/`) and the four theme files (`themes/default/lang/`) from the English keys of *Every word*, each value a natural sentence in that language, never a word-for-word one. **Then a second pass checks each file with AI** (Q7 as decided, I-3370): a check separate from the pass that wrote it reads each value against its English key and the context the key is used in (the file and line `ReaderWords::SOURCES` finds it on: a button, a heading, a refusal, a count), corrects what reads wrong or unnatural, and records, file by file, what it changed and why. Each file is marked in the records **AI-prepared, AI-checked**.

- [ ] **Step 1:** `it('has every reader-facing key in each of the four shipped languages')`; `it('has no key in a shipped file that no reader-facing source uses')`; `it('keeps each translation's placeholders those of its key')`; `it('keeps a plural key's forms in each translation')`; `it('leaves admin words out of the reader files')`: no key from `app/Http/Admin` or `resources/js`.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(site): reader words in Spanish, French, Brazilian Portuguese and German (I-3360)`.

## Task W5: 6.1e's gate and records

The full `composer check`. Records: the key counts per file, and, for each of the eight files, the AI check's changes and the line "AI-prepared, AI-checked". Commit and push.

---

# Part 6.1f: Words per language: the admin's foundation, settings, menus and regions

**Split** (I-3373 (6), 2026-11-11): **F0's first half and F1 to F4, the admin's words foundation, moved to the admin-words plan (I-3373 (6))**, written from this text by another planner, with D21; it is built beside page parents, before 6.1a. **The rest of 6.1f is parked** (I-3372): F0's second half and G1 to G5, stage 3. Trigger: a site turns on a second language and needs its title, tagline, title templates, menus or regions in it. Already in 6.1a: G4's default-language region predicate (A5). The text below is kept as written.

From `slice/6.1` after 6.1a, on `slice/6.1f-words`; **Opus** (every later screen is written against its foundation; the settings overlay and the contract growth). Tasks F1 to F4 lay the admin's words foundation; G1 to G4 give settings, menus and regions words per language. Every task follows *When a session fails*.

## Files (6.1f)

- **Create:** `resources/js/lib/i18n.ts`; `app/Domain/Languages/{AdminLanguage,PseudoLocale}.php`; `app/Http/Admin/Middleware/UseAdminLanguage.php`; `tests/Architecture/AdminWordsTest.php`; `tests/Architecture/admin-words-baseline.txt`; `tests/Feature/Languages/AdminLanguageTest.php`; `tests/Fixtures/i18n.mjs`; `app/Domain/Settings/Operations/TranslateSettings.php` and its Input; `app/Domain/Settings/Queries/SettingsTranslations.php`; `app/Http/Admin/Settings/SettingsWordsController.php`; `tests/Feature/Languages/{TranslatedSettingsTest,TranslateSettingsTest,MenusPerLanguageTest,RegionsPerLanguageTest}.php`.
- **Modify:** `app/Http/Middleware/HandleInertiaRequests.php` (`i18n`); `resources/js/app.tsx`; `resources/js/types/index.ts`; `resources/views/app.blade.php`; `bootstrap/app.php` (the middleware in the `admin` group); `app/Domain/Preferences/BuiltInPreferences.php` (`admin.language`); `config/teme.php` (`admin_languages`). *Modify, for whole-list pins:* grep `tests/` for the shared props' key list (`toEqualCanonicalizing` over `share()`) and for the preferences' list. `packages/teme-contracts/src/Settings/{Setting,SettingsReader}.php`, `[5.1] packages/teme-contracts/src/Settings/SettingsChanged.php`; `app/Domain/Settings/{SettingsRepository,SettingsWriter}.php`; `app/Providers/SettingsServiceProvider.php`; `app/Domain/Seo/TitlesAndListing.php`; `app/Domain/Menus/{ThemeSlotSettings,NavigationTree}.php`, `app/Domain/Menus/Operations/BindNavigationSlot.php` and its Input; `app/Http/Admin/NavigationController.php`; `app/Domain/Regions/Regions.php`; `app/Domain/Export/Kinds/SettingsKind.php`; `routes/admin/settings-words.php`; `docs/reference/settings.md` (`teme:docs:settings`, which says which settings have words per language). *Modify, for whole-list pins:* `tests/Architecture/RegisteredSettingsTest.php` if it pins `Setting`'s constructor; the `[5.1]` hook catalogue's member list for `SettingsChanged`.

## Task F0: Preconditions, then branch

*Its lines up to "For settings, menus and regions": moved to the admin-words plan (I-3373 (6)). The rest: parked with G1 to G5.*

- [ ] 6.1a's merge is on `slice/6.1`. By its text: `grep -n "'preferences' => \$request->user() === null ? \[\] : PreferencesFor::user(\$request->user())," app/Http/Middleware/HandleInertiaRequests.php`; `grep -n "<html lang=\"{{ str_replace('_', '-', app()->getLocale()) }}\"" resources/views/app.blade.php`; `grep -n "function handleMissingKeysUsing" vendor/laravel/framework/src/Illuminate/Translation/Translator.php` (and read what its callback's return does); how Inertia 3 sends a once prop and how a test sees it (`vendor/inertiajs/inertia-laravel`, `Inertia::once()`); how `tests/Fixtures/ulid.mjs` is run from a PHP test (the pattern `i18n.mjs` follows); `ls resources/js/**/*.tsx | wc -l` (the baseline's size, about 349 plus 6.1a's placeholder). For settings, menus and regions (6.1g's Task G0 before the re-cut): `grep -n "public readonly ?string \$unit = null," packages/teme-contracts/src/Settings/Setting.php` (the constructor's last parameter today); `grep -n "private function with(array \$changes): self" packages/teme-contracts/src/Settings/Setting.php`; `grep -n "public ?string \$actorId," packages/teme-contracts/src/Settings/SettingsChanged.php` (last); `grep -n "fn (): array => SettingsGroupRecord::query()->find(\$group)->overrides ?? \[\]," app/Domain/Settings/SettingsRepository.php`; where `SettingsWriter` forgets `SettingsRepository::cacheKey()` and announces `SettingsChanged`; `grep -n "Setting::text(self::settingKey(\$slot), \$label, 'The menu shown in this place.', '0.1.0')" app/Domain/Menus/ThemeSlotSettings.php`; `grep -n "->where('contents.slug', \$slug)" app/Domain/Regions/Regions.php`. STOP on a difference. `git checkout -b slice/6.1f-words origin/slice/6.1`.

## Task F1: A person's admin language

*Moved to the admin-words plan (I-3373 (6)).*

**Files:** `AdminLanguage.php`, `UseAdminLanguage.php`, `bootstrap/app.php`, `BuiltInPreferences.php`, `config/teme.php`, `app.blade.php`, `AdminLanguageTest.php`.

`AdminLanguage::for(?User $user): string` (the preference, else `en`; a value no longer in `config('teme.admin_languages')` reads as `en`). The preference `admin.language` (`select`, default `en`, options the config's), hidden from Preferences while it has one option. `UseAdminLanguage` sets the locale on admin requests; `app.blade.php` writes `lang` and `dir` from it.

- [ ] **Step 1:** `it('sets the admin's locale from the person's admin language, English by default')`; `it('offers English only, and refuses another admin language')`; `it('shows no admin language on Preferences while English is the only one')`; `it('writes the admin's lang and dir on html')`; `it('leaves the public site's locale to the page')`: a signed-in person's `/fr/bonjour/` still French.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(admin): a person's admin language, English for now (I-162, I-3360)`.

## Task F2: One catalogue to React

*Moved to the admin-words plan (I-3373 (6)).*

**Files:** `HandleInertiaRequests.php`, `i18n.ts`, `app.tsx`, `types/index.ts`, `i18n.mjs`, `AdminLanguageTest.php`.

`i18n` = `{locale, direction, messages}` through `Inertia::once()` keyed by the locale and the catalogue file's modification time; `app.tsx` passes it to `setTranslations()` on the first page and on every `navigate`. `__()` and `__n()` as D21.

- [ ] **Step 1:** `it('sends the admin's words once a visit, keyed by its locale')`; `it('sends English an empty catalogue beyond its overrides')`; (`i18n.mjs`, run from `AdminLanguageTest`) `it('translates, fills placeholders longest name first and falls back to the key')`; `it('chooses a plural form as Laravel does')`: `{0}`, `[2,*]` and `one|many` forms at 0, 1, 2 and 21.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(admin): one catalogue of words for PHP and React (I-3360)`.

## Task F3: The pseudo-locale

*Moved to the admin-words plan (I-3373 (6)).*

**Files:** `PseudoLocale.php`, `app/Providers/LanguageServiceProvider.php` (its one registration; after 6.1a, 6.1f is the only part that edits this file), `i18n.ts`, `AdminLanguageTest.php`.

- [ ] **Step 1:** `it('brackets and accents every admin word under the pseudo-locale, from PHP')`; `it('does the same in React')` (`i18n.mjs`); `it('never turns the pseudo-locale on outside debug')`; `it('never applies it to the public site')`.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(admin): a pseudo-locale that shows hard-coded words (I-3360)`.

## Task F4: New screens' words through the catalogue

*Moved to the admin-words plan (I-3373 (6)).*

**Files:** `AdminWordsTest.php`, `admin-words-baseline.txt`.

The baseline is every `.tsx` path under `resources/js` on `slice/6.1` at this part's branch point, sorted, one a line. The test reads files with `__DIR__`-relative paths (`tests/Architecture` runs without a booted app, lesson B-092).

- [ ] **Step 1:** `it('finds no hard-coded word in a screen added since the baseline')`; `it('finds a word planted in a new file')` (a temporary folder: a JSX text node, then an `aria-label` literal, each red); `it('accepts words given through __() and __n()')`; `it('skips the files Wayfinder generates')`; `it('lists only files that still exist')`.
- [ ] **Step 2 to 4:** fail (plant first), build, quick check; commit `test(admin): new screens' words go through the catalogue (I-3360)`.

## Task G1: Which settings have words per language

*Parked, G1 to G5 (I-3372): see this part's head.*

**Files:** `Setting.php`, `SettingsReader.php`, `SettingsServiceProvider.php`, `TitlesAndListing.php`, `ThemeSlotSettings.php`, `TranslatedSettingsTest.php`.

- [ ] **Step 1:** `it('marks the site title, the tagline, the title templates and the menu slots as having words per language, and nothing else')` (each read by key); `it('keeps every setting built before this release as it was')` (a setting made with each factory has `translatable` false, its other properties unchanged).
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(settings): settings may have words per language (I-3360)`.

## Task G2: Reading them

**Files:** `SettingsRepository.php`, `SettingsWriter.php` (forgetting the new key), `TranslatedSettingsTest.php`.

**Its code, written out** (delicate: it runs on every settings read of every page):

```php
public function group(string $group): array
{
    // …as today, down to the filled $values…

    // On a page in another language, its words over the default's (I-3360).
    // stored() reads nothing, so this never asks a settings group again.
    $language = app(PageLanguage::class)->stored();

    if ($language !== null) {
        $translated = $this->translations($group)[$language] ?? [];

        foreach ($definition->settings as $setting) {
            if ($setting->translatable && array_key_exists($setting->key, $translated)) {
                $values[$setting->key] = $translated[$setting->key];
            }
        }
    }

    return $values;
}

/**
 * @return array<string, array<string, mixed>> each other language's words in this group
 */
private function translations(string $group): array
{
    /** @var array<string, array<string, mixed>> */
    return Cache::memo()->rememberForever(
        self::cacheKey($group).':translations',
        fn (): array => SettingsGroupRecord::query()->find($group)->translations ?? [],
    );
}
```

- [ ] **Step 1:** `it('shows a French page the French site title and tagline, and the default language's page its own')`; `it('shows the default language's words where French has none')` (Q5 A); `it('fills the French title template on a French page')`: the `<title>` of the French post; `it('reads translations only on a page in another language')`: statements on an English page unchanged; `it('never reads a settings group twice to learn the page's language')`; `it('forgets the words when they are written')`.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(settings): a page in another language reads its own words (I-3360)`.

## Task G3: Writing them

**Files:** `TranslateSettings.php` and Input, `SettingsWriter.php` (`writeTranslations()`), `SettingsChanged.php`, `SettingsTranslations.php`, `SettingsWordsController.php`, `routes/admin/settings-words.php`, `SettingsKind.php`, `TranslateSettingsTest.php`.

`TranslateSettingsInput(?int $actorId, string $group, string $language, array $values, list<string> $reset, int $expectedVersion)`. **Authorize:** the group's ability (as `UpdateSettings`; elevation where the group is sensitive). **Validate:** `$language` is one of `others`; every key in `values` and `reset` is translatable in the group ("This setting has no words per language."); the values pass `SettingRules::validateValues()` for those settings. **Perform:** `SettingsWriter::writeTranslations()` under the version check. **After commit:** the group's purge, and `SettingsChanged($group, $keys, $actorId, $language)`. `SettingsTranslations::of(string $group, string $language): array{values: array<string, mixed>, defaults: array<string, mixed>, version: int}` for the screens. The route `PUT settings/{group}/words/{language}` (`settings.words.update`) under the group's ability.

- [ ] **Step 1:** `it('saves French words for a group and leaves the default's as they were')`; `it('resets a French word to the default's')`; `it('refuses a setting with no words per language')`; `it('refuses a language that is not one of the other languages')`; `it('refuses a form opened on an older version')`; `it('logs the change with its language in each key')`; `it('purges the group's pages after commit')`; `it('announces the change with its language, and the site's own changes with none')`; `it('writes nothing when the person lacks the group's ability')`; `it('exports each group's words per language')`; `it('saves through its route for whoever manages the group, and refuses anyone else')`.
- [ ] **Step 2 to 4:** fail, build, quick check, `teme:docs:settings`; commit `feat(settings): write a group's words in another language (I-3360)`.

## Task G4: Menus and regions per language

**Files:** `ThemeSlotSettings.php`, `NavigationTree.php`, `BindNavigationSlot.php` and Input, `NavigationController.php`, `Regions.php`, `MenusPerLanguageTest.php`, `RegionsPerLanguageTest.php`.

- [ ] **Step 1:** `it('draws a slot's French menu on a French page')`; `it('draws the default language's menu where French has none')`; `it('binds a menu to a slot for French only')`; `it('lists, for each menu, the slots and languages it is shown in')` (for the *Show in* list); `it('leads a menu's Home item to the page language's home')`; `it('writes nothing when someone without navigation.manage binds a slot for a language')`; (`RegionsPerLanguageTest.php`) `it('draws a region's French version on a French page')`; `it('draws the default language's version where French has none')`; `it('never draws an unpublished French version')`.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(menus): menus and regions per language (I-3360)`.

## Task G5: 6.1f's gate and records

*Parked (I-3372); when built, the browser check on the admin shell's `lang` and `dir` belongs to the admin-words plan.* The full `composer check`, and `composer check:browser` on the admin shell's own files only (lang and dir). Records: the props as built, and the route and the props of `SettingsTranslations` as built, for 6.1i. Commit and push.

---

# Part 6.1h: Import: linking remembered translations

**Parked** (I-3372, 2026-11-11): stage 3. Trigger: a Polylang site is imported, or one imported before turns on a second language, and its remembered translations need linking. 1.11 keeps the maps in `import_map.facts` meanwhile, so nothing is lost by waiting.

From `slice/6.1` after 6.1a, on `slice/6.1h-import-links`; **Opus** (the union and the address take-back). I-351. Every task follows *When a session fails*.

## Files (6.1h)

- **Create:** `app/Domain/Import/Translations/{PolylangGroups,ImportedLanguages}.php`; `app/Domain/Import/Operations/LinkImportedTranslations.php` and its Input; `app/Domain/Import/Jobs/LinkImportedTranslationsJob.php`; `app/Http/Admin/Tools/Import/ImportTranslationsController.php`; `tests/Fixtures/Import/PolylangSite.php`; `tests/Feature/Import/Translations/{PolylangGroupsTest,LinkImportedTranslationsTest,TakeBackAddressesTest,ImportTranslationsRouteTest}.php`.
- **Modify:** `routes/admin/import-translations.php`.

## Task L0: Preconditions, then branch

- [ ] 6.1a's merge is on `slice/6.1`. By its text: `grep -n "'language' => is_string(\$item\['language'\] ?? null) ? \$item\['language'\] : null," app/Domain/Import/Phases/Content/ContentPhase.php`; `grep -n "\$facts\['translations'\] = is_array(\$item\['translations'\] ?? null) ? \$item\['translations'\] : \[\];" app/Domain/Import/Phases/Terms/TermsPhase.php`; `ImportedTerms::byWordPressTerm()`'s signature; `AuthorizesImports`' method; how `tests/Feature/Import/TermsPhaseTest.php:141` writes `facts` (the fixture's shape). STOP on a difference. `git checkout -b slice/6.1h-import-links origin/slice/6.1`.

## Task L1: The old site's languages

**Files:** `ImportedLanguages.php`, `PolylangSite.php`, `ImportTranslationsRouteTest.php`.

`ImportedLanguages::of(string $sourceId): list<array{slug: string, items: int, terms: int}>`: the Polylang slugs the source's map rows name, with counts, one statement (`facts ->> 'language'`, grouped). `PolylangSite::build()` writes a source and its map rows through the import's own Operations' fixture helpers (L0 reads them), with an English and French post pair, a page in three languages, a map that lost a member, and a French post whose old address is `/fr/about/` imported as `about-2`.

- [ ] **Step 1:** `it('lists the old site's languages and how many items and terms each holds')`; `it('lists none for a site imported without Polylang')`.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(import): the languages an imported site held (I-351)`.

## Task L2: Groups by union

**Files:** `PolylangGroups.php`, `PolylangGroupsTest.php`.

**Its code, written out** (delicate: a broken map must join, never split):

```php
/**
 * Polylang's maps joined into groups: every WordPress ID a map names is one node, and each map joins
 * all of its IDs, so a map that lost a member joins rather than splits (docs/studies/2026-11-11-multilingual.md).
 *
 * @param  iterable<array{id: string, translations: array<string, int|string>}>  $maps  one row's own ID and its map
 * @return list<list<string>> each group's WordPress IDs, sorted, groups of two or more only, sorted by their first ID
 */
public static function groups(iterable $maps): array
{
    $parent = [];

    foreach ($maps as $map) {
        $ids = [$map['id'], ...array_map(strval(...), array_values($map['translations']))];
        $first = self::root($parent, $ids[0]);

        foreach ($ids as $id) {
            $root = self::root($parent, $id);

            if ($root !== $first) {
                $parent[$root] = $first;
            }
        }
    }

    $groups = [];

    foreach (array_keys($parent) as $id) {
        $groups[self::root($parent, (string) $id)][] = (string) $id;
    }

    $groups = array_values(array_filter($groups, fn (array $group): bool => count($group) > 1));
    array_walk($groups, fn (array &$group) => sort($group, SORT_STRING));
    usort($groups, fn (array $a, array $b): int => strcmp($a[0], $b[0]));

    return $groups;
}

/**
 * @param  array<string, string>  $parent
 */
private static function root(array &$parent, string $id): string
{
    $parent[$id] ??= $id;

    while ($parent[$id] !== $id) {
        $parent[$id] = $parent[$parent[$id]];
        $id = $parent[$id];
    }

    return $id;
}
```

(`array_keys()` turns numeric IDs into integers, hence the casts; lesson 1.7b.) Posts and terms are grouped apart: a post's node is its `object_id`, a term's its `facts.term_id`.

- [ ] **Step 1:** `it('joins each map's posts into one group')`; `it('joins two maps that share a post, even when one lost a member')`; `it('leaves a post with no translation out of every group')`; `it('groups posts and terms apart')`; `it('gives the same groups whatever order the maps come in')`.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(import): Polylang's groups, joined by union (I-351)`.

## Task L3: Linking, and old addresses back

**Files:** `LinkImportedTranslations.php` and Input, `LinkImportedTranslationsJob.php`, `ImportTranslationsController.php`, `routes/admin/import-translations.php`, `LinkImportedTranslationsTest.php`, `TakeBackAddressesTest.php`.

`LinkImportedTranslationsInput(?int $actorId, string $sourceId, array $map, bool $takeBackAddresses, list<list<string>> $groups, string $kind)` (one batch of groups, `$kind` `content` or `term`). The job's first run reads the source's maps once, computes `PolylangGroups::groups()` for posts and for terms, and queues one run of the Operation for each 500 groups, each carrying its groups and the actor (I-534: queue payloads are Laravel's own serialisation, allowed). **Authorize:** `imports.run` (D23). **Validate:** every slug `ImportedLanguages::of()` lists is mapped to a site language (*Every word*). **Perform**, for each group: its Teme rows (`import_map.teme_id` of the group's IDs, imported, not trashed); one type (or taxonomy), translated on this site (D25); their mapped languages distinct; else skip it and record an import message (`RecordImportMessages`' shape). `GroupLock::lock()` (6.1a's, D5); `language` = `stored()`, `translation_group` = a new ID; with `takeBackAddresses`, a row whose slug is `{base}-N`, whose `facts.path` is the address it would have with `base`, and whose `base` is free in its type and language, takes `base`. Then `PathProjection` and `SearchText` for each item, and for each term its subtree's paths. **After commit:** each item's and term's purge tags, `list:{type}`, and `RecomputeAddresses` for each term whose address changed, for the items its `{category}` reaches.

- [ ] **Step 1:** `it('sets each imported item's and term's language from the map, storing none for the default')`; `it('gives each group of translations one new group')`; `it('skips and reports a group with two items in one language')`; `it('skips a group of items of different types')`; `it('skips and reports a group whose type or taxonomy isn't translated here')`; `it('refuses while a language of the old site maps to none here')`; `it('runs in batches as the person who started it')` (under `DeniedElevation`); `it('changes nothing when run a second time')`; `it('writes nothing when the person may not run imports')`; `it('starts through its route for whoever may run imports, and refuses anyone else')`; (`TakeBackAddressesTest.php`) `it('gives a French post its old address back where its slug is free')` (its own history row current again); `it('leaves the numbered slug where the old one is taken in its language')`; `it('takes nothing back when not asked')`.
- [ ] **Step 2 to 4:** fail, build, quick check; commit `feat(import): link an imported site's translations (I-351)`.

## Task L4: 6.1h's gate and records

The full `composer check`. Records: the route and `ImportedLanguages`' shape, for 6.1i. Commit and push.

---

# Part 6.1i: The screens *(waits for the pictures P1 to P6)*

**Parked** (I-3372, 2026-11-11): stage 3, built with its pictures against a foundation that no longer moves. Trigger: a site owner needs to add a language or translate from the admin. Until then 6.1a's Settings › Languages is a placeholder with no form (C2's test, I-3373 (1)). When built, it also waits for the admin-words plan (its `__()` and `AdminWordsTest`) in place of 6.1f's F tasks.

From `slice/6.1` after 6.1f and 6.1h merge (6.1a is already in) and the six pictures are adopted, on `slice/6.1i-screens`; **Opus** (the editor is the screen people live in). Every task follows *When a session fails*. Planned at the lighter depth: what each screen holds, its words (*Every word*), its tests by name; the markup follows the adopted picture. Every new file passes `AdminWordsTest` (its words through `__()`); `resources/js/pages/settings/languages.tsx` leaves the baseline (it becomes a new screen). New files only for new words: `resources/js/components/editor/rail/{translations-panel,language-row,link-translation-dialog}.tsx`, `resources/js/components/lists/language-filter.tsx`.

- **P0, preconditions:** the merges of 6.1a, 6.1f and 6.1h; the routes and props in 6.1a's, 6.1f's and 6.1h's records; the adopted pictures' files in `[A] docs/demos/`; `npm run build` before any Browser run.
- **E0, preconditions:** 6.1a's routes and `EditorSession`'s new props in its records; `editor-rail.tsx`'s order (Action bar, Publish, Taxonomy, Checks, Trash, `[main]` lines 590 to 680); the content list page and the term screens' files; `npm run build`.
- **P1, Settings › Languages** (P1): `LanguagesController::show()` gives `{default: {tag, name, generalUrl}, others: list<{tag, name, native, prefix, shown, items: int, terms: int}>, available: list<{tag, name}>, version}` (one statement for the counts, grouped by `language`); the page lists the default and the others, **Add a language**, **Change** and **Remove** dialogs, each calling 6.1a's routes; and a **Translated content** section (D25, I-3370): `show()` also gives `types` and `taxonomies`, each `list<{key, label, translated: bool}>`, listed with a check box each and saved through `settings.languages.translated`. Browser: `it('adds French with its prefix and shows it in the list')`; `it('stops translating a type, and refuses one that has translations, naming the count')`; `it('refuses a prefix a page already has, naming the page')`; `it('hides and shows a language')`; `it('refuses to remove a language that holds items, counting them')`; axe on the page and on each dialog (lesson 1.8c: after a closing dialog leaves the DOM). Feature: `it('gives the screen the languages and their counts in one statement')`.
- **P2, words per language on General and Search and social** (P4): on a multilingual site, a **Language** select above each group's form; another language shows only the translatable fields, each with the default's value as its placeholder, and saves through 6.1f's route. Browser: `it('saves the French tagline and shows the English as its placeholder')`; `it('shows the language switch only on a site with other languages')`.
- **P3, menus per language** (P5): the menu editor's **Show in** lists each slot once per shown language on a multilingual site ("Main menu, French"), binding through 6.1f's `BindNavigationSlot` with its language. Browser: `it('shows a menu in the main menu for French only')`.
- **P4, Tools › Import's Link translations** (P6): on an imported source whose maps name languages, **Link translations** opens a step listing each old language (`ImportedLanguages`) with a select of the site's languages, a link to Settings › Languages to add one, and *Give translations their old addresses back*; it starts 6.1h's job, whose progress shows in the header's jobs as every import job's does. Browser: `it('links an imported site's translations after choosing a language for each')`; `it('asks for a language for each old one before it starts')`.
- **E1, the Language row** (P2): in the Publish panel on a multilingual site, for a type that is translated (D25), after the address: the item's language; a select for whoever may edit; changing a published item's language asks first (*Every word*) and calls `content.language.update`. Browser: `it('moves a draft to French from the Publish panel')`; `it('asks before moving a published item and says where its address goes')`.
- **E2, the Translations panel** (P2): a rail section after Publish on a multilingual site, for a type that is translated (D25): each version (language, title linking to its editor, its state, **Out of date** with the date its source changed); **Translate into …** for each language the group lacks, which opens the new draft; on an out-of-date version being edited, the line saying so with **Mark as up to date**; a version's menu with **Unlink**, confirmed; **Link an existing item…**, a dialog searching the same type's items in languages the group lacks. Browser: `it('translates a post into French and opens the draft')`; `it('marks the French version out of date after the English is published again, and up to date when asked')`; `it('links an existing French post as a translation')`; `it('unlinks a version after asking')`; `it('shows no Translations panel on a site with one language')`; axe on the rail with the panel open.
- **E3, the lists** (P3): a **Language** column (offered in the list's columns preference, on by default on a multilingual site; `BuiltInPreferences::columnsOf()` gains `language`, so the pinned column lists in `tests/` are named under *Modify*) and a **Language** filter (6.1a's `ContentList` filter); the term screens likewise, and a language select when adding a term. Browser: `it('filters the posts list to French')`; `it('adds a category in French')`.
- **E4, the link picker** offers the item's language's items first (`LinkChoicesController` takes `language`; a test of its order).
- **P5, gate and records:** the full `composer check`; `composer check:browser` on this part's Browser files only (I-3317, the browser rule); the screens compared with the adopted pictures, by looking (screenshots in the records). Both groups of screens: the settings and import screens, and the editor and lists.

---

# Part 6.1z: The join

**Parked** (I-3372, 2026-11-11): stage 3. Trigger: the parked parts are built. Moved out while it waits: Z5's changelog and the tag, to 6.1a's T2 (6.1z, when built, adds the parked parts' lines); the page cache on in 6.1a's A3 and A5 tests stands in for Z2's matrix until then.

From `slice/6.1` after every part merges, on `slice/6.1z-join`; **Opus**. Every task follows *When a session fails*. Lighter depth: its tests are named; their bodies are the builder's.

- **Z0, preconditions:** the six merges in `git log --oneline --merges -16 origin/slice/6.1`; the whole suite green on `slice/6.1`.
- **Z1, end to end with the page cache on** (`tests/Feature/Languages/EndToEnd/`; every change through its Operation): `it('publishes a translation at /fr/{slug}/ and lists it in both versions' hreflang at once')`; `it('drops a version from its siblings' heads and the sitemap at once when it is unpublished, trashed or made private')`; `it('moves a language's addresses when its prefix changes, and the old ones redirect')`; `it('shows /fr/ in French: lang, dir, og:locale, the French tagline, menu and region, and core's and the theme's words')`; `it('keeps / exactly as before in the default language')`; `it('answers the French search, feed, sitemap and API in French')`; `it('links an imported Polylang site and answers its old /fr/ addresses with 200 again')`; `it('shows Spanish everywhere the moment it is shown, and nowhere a moment after it is hidden')`.
- **Z2, the leak matrix** (`LeakMatrixTest.php`), with the page cache on and off: Spanish hidden, a French draft and a French private item; every public surface (each page kind under every prefix, sitemaps, feeds, search, `llms.txt`, `.md`, every API route, the head, the view data, structured data, links in bodies) searched for their titles, IDs, slugs and addresses: none found; and **no group ID** on any public answer. Paired with an "it exists" assertion for each (the French published item's address is found), lesson B-358.
- **Z3, the theme's home and search links:** the default theme's site name, Home link and search form use `$language->home` (6.1c's view data; 6.1e left `$site->url`), with a test on `/fr/`.
- **Z4, the benchmark scenario** (`BenchmarkSeeder`, a million items, 5% French, 1% Spanish hidden; D24): every listing, search and item page in French and English within its 1.9 budget; the statement counts over the same page uncached; results in `docs/benchmarks/v0.6.0-alpha.1.md`.
- **Z5, the changelog, gate and records:** `CHANGELOG.md` gains `## [0.6.0-alpha.1] — {date}` with *Every word*'s paragraph, `### Added (6.1)` from the parts' records, `### Theme view data` (the three names), `### Hooks` (`SettingsChanged::$language`), `### API` (`language`, `translations`, `languages`), `### Fixed` (#367, #368, #369, #370), `### Reader words` (es, fr, pt-BR, de: prepared and checked by AI against the English and each key's context, I-3370). The full `composer check`; GitHub's full Browser run. The architect merges `slice/6.1` into `main` and tags `v0.6.0-alpha.1` once 6.1e's records show each of the eight files AI-checked (I-3370).

---

## Every word

Every sentence a person reads, in English; the server's through `__()`, React's through `__()` (D21). Words in quotes are exact; `{x}` is filled.

### What v9 draws, and the pictures

The v9 demo (`[A] docs/prototypes/2026-09-22-admin-v9.source.html`, grep of `language`, `translat`, `locale`, `hreflang`, `multiling` on 2026-11-11) draws **one** thing: Settings › General's **Language** select (line 7072: `English (UK)`, `English (US)`, keyword `locale`) and the General entry's line "The site's name, tagline, contact address, time zone, language and brand colour." (line 2024). It draws no list of languages, no Translate action, no translations panel, no language column or filter, no words per language, no import linking. Its admin formats every number and date in `en-GB` (`toLocaleString('en-GB')`, lines 1384 to 1407): D21's later conversion replaces those with the admin's locale.

**The pictures** (I-468; each shown to the maintainers as options, one at a time at full size, adopted on his word, then copied into `[A] docs/demos/2026-11-1x-multilingual-*-options.html`):
- **P1** Settings › Languages: v9's settings page shell, extended; options for where the default language is changed (here, or only in General).
- **P2** The editor's Language row and Translations panel: v9's rail (Publish's summary rows, a collapsible section), extended; options for where the panel sits (after Publish, or after Taxonomy).
- **P3** The lists' Language column and filter, and the term screens' language: v9's list header and filters, extended.
- **P4** Words per language on General and Search and social: v9's settings forms, extended; options for the switch (a select above the form, or tabs).
- **P5** Menus per language in *Show in*: v9's menu editor, extended.
- **P6** Tools › Import › Link translations: v9's import screens (lines 6542 to 6706), extended.

### Settings › Languages (P1; 6.1a's Operations, 6.1i's screen)

*(I-3372: the screen's words wait with 6.1i; 6.1a uses the settings list entry, the page title "Languages" on its placeholder, and every refusal and toast its Operations and routes answer.)*

- Settings list entry: **Languages**, "The language your site is written in, and the other languages it publishes in."
- Page title "Languages". Section "Default language": "{English (United Kingdom)}" and "Every item that names no other language is in it. Its addresses have no prefix." Link: "Change it in General".
- Section "Other languages". Columns: "Language", "Addresses", "Readers", "Content". A row: "French", "/fr/…", "Shown" or "Hidden", "{12} posts and pages, {3} terms" ("1 item", "no content yet" for none). Row actions: "Change", "Remove".
- Empty: "Your site publishes in one language. Add another to translate posts and pages into it."
- Button and dialog title: "Add a language". Fields: "Language"; "Address prefix", help "Addresses in this language start with it, such as /fr/about/. Lower-case letters, digits and hyphens."; check box "Show it to readers now", help "Leave it off while you translate: readers get nothing in this language until you show it." Buttons "Add language", "Cancel".
- Change dialog: title "Change {French}"; the same fields, the box worded "Shown to readers"; when the prefix changes: "Every {French} address moves to the new prefix. The old ones redirect to the new." Buttons "Save changes", "Cancel".
- Remove dialog: title "Remove {French}?"; "Nobody will be offered {French} any more. Nothing is deleted: there is no {French} content." Buttons "Remove {French}", "Cancel".
- Toasts: "Added {French}." · "Saved {French}." · "Removed {French}." · "{French} is now shown to readers." · "{French} is now hidden from readers."
- Refusals: "Choose a language." · "{French} is already one of your site's languages." · "{English (United Kingdom)} is your site's default language." · "Use lower-case letters, digits and hyphens, at most 20." · "Another language already uses /{fr}/." · "The address /{fr}/ is used by the site itself." and "The address /{fr}/ is used by {the page "Français"}." (today's `Paths::conflict()` words) · "The {category} base is {fr}: change it in Settings › Addresses first." · "The author base is {fr}: change it in Settings › Addresses first." · "{Films} addresses start with /{fr}/: change them in Settings › Addresses first." · "{12} posts and pages and {3} terms are in {French}. Move them to another language, or delete them, first." (each count "1 post or page", "1 term" at one; a part left out at zero) · "Someone changed the languages while you were editing. Reload and try again."
- Section "Translated content" (D25): "Choose what can have versions in other languages. Anything not ticked is always in {English (United Kingdom)}, and its addresses have no prefix." Two lists, "Content types" and "Taxonomies", a check box each with its plural ("Posts", "Pages", "Categories"). Button "Save". Toast: "Saved what is translated." Refusals: "{12} {pages} are in other languages or linked as translations. Move them to {English (United Kingdom)} and unlink them, or delete them, before {pages} stop being translated." ("1 {page} is" at one) · "{3} {categories} are in other languages or linked as translations. Move them to {English (United Kingdom)} and unlink them, or delete them, before {categories} stop being translated." · "Choose content types and taxonomies this site has."
- Where a type isn't translated (a draft in a language, Translate, Link, a language change): "{Pages} aren't translated on this site. Turn translation on for them in Settings › Languages first." A term in a language: "{Categories} aren't translated on this site, so their terms are shared by every language."
- Settings › General, the Site language's refusal: "The default language can't change while the site publishes in other languages. Remove them in Settings › Languages first." · "{French} is one of the site's other languages. Remove it in Settings › Languages before making it the default."
- The status banner (6.1a): "The redirect from /{fr}/ no longer answers: {French} readers' home page is there now." Action "See the redirect".

### The editor and the lists (P2, P3; 6.1a's Operations, 6.1i's screens)

- Publish panel row: "Language", value "{French}"; a hidden language's option "{Spanish} (hidden)". Confirmation for a published item: title "Move this {post} to {French}?"; "Its address becomes {/fr/hello/}, and the old one redirects there." Buttons "Move to {French}", "Cancel". Toast: "Moved to {French}." or "Moved to {French}. Its address is now {/fr/hello-2/}, because {French} already has {hello}."
- Rail section "Translations". A row: "{French}", the title, a state ("Draft", "In review", "Scheduled", "Published", "Private"), "This version" on the one being edited, "Out of date" with "{English} changed on {12 November 2026}." Buttons "Translate into {French}" for each missing language. Empty: "No translations yet."
- On an out-of-date version: "{English} has changed since this was translated." Button "Mark as up to date". Toast: "Marked as up to date with the {English} version."
- A version's menu: "Open", "Unlink". Confirmation: title "Unlink “{Bonjour}”?"; "It stays as it is, a {French} {post} of its own." Buttons "Unlink", "Cancel". Toast: "Unlinked “{Bonjour}”."
- "Link an existing {post}…" dialog: title "Link a translation"; "Choose the same {post} in another language. Each language has one." Search label "Find a {post}". Buttons "Link", "Cancel". Toast: "Linked “{Bonjour}” as the {French} version."
- Translate toasts: "Made a {French} draft from the {English} version." and, with terms made (Q6 A), "Made {French} versions of {2 categories}: {Sport, Local}. Rename them in {Categories}." ("1 category", the taxonomy's own plural).
- Refusals: "This {post} already has a {French} version: “{Bonjour}”." · "{French} isn't one of your site's languages." · "It's already in {French}." · "The {English} version isn't published yet. Ask someone who can edit it, or wait until it is." · "Restore it first." · "Both would be in {French}. Each language can have one version." · "Choose {posts}: a translation is of the same type." · "Its {category} “{Sport}” has no {French} version. File it under a {French} one first." · "Someone changed this {post} while you were editing. Reload and try again." (today's words) · "The version it was translated from is gone."
- Restore toast when its language is taken: "Restored. Another {French} version exists, so this one is no longer linked to its translations."
- Lists: column and filter "Language"; filter's first option "All languages". Term screens: column "Language", filter as the lists', the add form's "Language".

### Words per language, menus and the import (P4, P5, P6) *(parked with 6.1f's G tasks, 6.1h and 6.1i)*

- Above a group's form: "Language", options "{English (United Kingdom)} (default)", "{French}"…; with another chosen: "Showing the words {French} readers see. Empty fields use the {English} words." Toast "Saved the {French} words." Refusals: "This setting has no words per language." · "{Italian} isn't one of the site's other languages."
- Settings reference descriptions (`teme:docs:settings`): "Has words per language." beside each translatable setting.
- *Show in*: a slot "{Main menu}, {French}"; hint "A place with no {French} menu shows the {English} one to {French} readers."
- Tools › Import, on a source: button "Link translations". Step title "Link translations"; "{example.com} kept its posts and terms in several languages, as Polylang does. Link each version to its translations here." Table columns "On the old site", "Here", "Content"; a row "{fr}", a select of the site's languages, "{120} posts and pages, {8} terms". Link: "Add a language in Settings › Languages". Check box "Give translations their old addresses back where they're free", help "A {French} post imported at /{about-2}/ answers at /{fr}/{about}/ again, as on the old site." Button "Link translations". Job label "Linking translations". Done: "Linked {450} groups of translations. {380} posts and pages and {70} terms took their old addresses back." Skipped message (in the import's messages): "Not linked: “{About}” and “{À propos}” are both in {French} here." · "Not linked: “{About}” is a {page}, and {pages} aren't translated here." · "Not linked: “{X}” and “{Y}” are different types." Refusal: "Choose a language here for {fr}." · "Add {French} in Settings › Languages first."

### Added in review (6.1a)

- A group changed under the lock (D5): "Someone changed these translations while you were working. Try again."
- A prefix (A4): "Use a prefix with a letter in it, not page or comments."
- A page's parent (`[4.5]`, B2, B3, B6): "Its parent “{About}” has no {French} version yet, so it is at the top. Choose its parent once there is one." · "A page's parent is in its own language: choose the {French} “{À propos}”." · "Move it to the top, and move the pages under it, first."

### The settings group's own words (6.1a) and the export's (6.1a)

- `languages` group: label "Languages"; description as the settings list entry; settings "Other languages" ("The languages beside the default one, in the order they were added."), "Address prefixes" ("Each other language's first part of every address."), "Hidden from readers" ("Other languages readers get nothing in, while they are translated."), "Content types not translated" ("Content types whose items are always in the default language."), "Taxonomies not translated" ("Taxonomies whose terms are always in the default language, shared by every language.").
- Export, `ContentsKind` and `TermsKind`: `language` "The language it is written in, as a BCP 47 tag; null for the site's default language."; `translation_group` "The same for every language version of one item (or term); null for one with no other version."

### Reader words: the four files' keys (6.1e) *(parked)*

English is the source; `lang/{es,fr,pt_BR,de}.json` hold every key below, `themes/default/lang/{es,fr,pt_BR,de}.json` the theme's. These are the keys `ReaderWords` finds at `525c51b5`; the builder's list replaces them where the code has moved, and **the comment form's privacy line, built today from two pieces (`'Your name is shown with your comment'` then `'; your email address never is. …'`), becomes whole sentences, one key each** (D20's rule), its words unchanged.

- **Core, the comment form and thread:** "Leave a comment" · "Comment" · "Comments" · "Your comment" · "Your reply" · "Name" · "Email address" · "Website" · "(optional)" · "Never shown." · "Shown with your comment." · "Your name will link to it." · "Leave this empty" · "Plain text. A blank line starts a new paragraph, and web addresses become links." · "Save my name and email address in this browser for next time" · "Save my name, email address and website in this browser for next time" · "Post comment" · "Post reply" · "Posting your comment…" · "Posting your reply…" · "Cancel reply" · "Reply to :name" · "Replying to" · "You’re signed in, so you’ll comment as" · "Only people signed in to this site can comment here." · the privacy line's whole sentences · "Either way, this browser keeps a random key, so it can show you your own comment while it waits." · "Replies stop nesting here, so yours will appear at the end of this conversation." · "This comment was removed." · ":name’s comment" · "Newer comments" · "Older comments" · "Pages of comments" · "Page :number" · "Page :number of :total" · "Page :current of :total" · "1 comment" · ":count comments" · "Comments on “:title”" · "Comments on :title, page :number" · "Open" · "Closed" · "Hidden".
- **Core, after posting:** "Thanks. Your comment is posted." · "Thanks. Your reply is posted." · "Thanks. Your comment is awaiting moderation: only you can see it until a moderator approves it." · "Thanks. Your reply is awaiting moderation: only you can see it until a moderator approves it." · "Thanks, :name." · "Thanks." · "Your comment is posted" · "Your comment is awaiting moderation" · "Your comment wasn’t posted" · "Your comment wasn’t sent. Check your connection, then try again." · "See your comment" · "It’s under :story now." · "It will appear under :story once a moderator approves it. Until then it won’t show there, even to you." · "Here’s what you wrote, so you can keep a copy." · "What you wrote" · "On :story" · "Read the story" · "Back to the story" · "There’s a problem" · "Error:" · "Error: :heading".
- **Core, refusals:** "Comments are switched off." · "Comments here are closed." · "This page takes no comments." · "You’ve already said that here." · "Write your comment." · "Shorten your comment to 5,000 characters or fewer. It has :count." · "Enter your name." · "Enter a name of 100 characters or fewer." · "Enter your email address." · "Enter an email address like name@example.com." · "Enter a web address that starts with https://, or leave this empty." · "The comment you’re replying to isn’t there to reply to." · "This email address belongs to someone with an account here. Sign in to use it." · "This name belongs to someone with an account here. Sign in to use it, or choose another." · "This form came from another site, so your comment wasn’t posted. To comment, go back to the story and use the form there." · "Your browser didn’t say this form came from this site. Turn on JavaScript, or use a newer browser, and try again." · "{1} You’ve sent several comments in a short time. Try again in a minute.|[2,*] You’ve sent several comments in a short time. Try again in :minutes minutes."
- **Core, the password form:** "Password" · "Unlock" · "This :singular is protected by a password. Enter it to read on." · "That isn’t the password. Check it and try again." · "Too many tries. Wait a minute, then try again." · "page".
- **Core, the page and the head:** "Skip to content" · "Home" · "Page not found" · "Something went wrong" · "Also in :name" · "Also tagged :name" · "Edit" · "Edit :type" · "expires :date" · and the fallback templates' "Newer" · "Older" · "Search".
- **Core, shown to editors on public pages:** "Preview of an unpublished version" · "Preview of the latest saved version" · "Previewing unpublished design changes" · "Back to Themes" · "Visible only to people who can edit it".
- **The default theme:** "Previous" · "Next" · "Page" · "Pages" · "Related" · "Reply" · "Author" · "Awaiting moderation." · "Only you can see it until a moderator approves it." · "Linked from" · "This comment was removed." · "Comments are closed." · "No comments yet. Start the conversation." · "Newer" · "Older" · "Read the story" · "Comments" · "Pages of comments" · "What you wrote" · "Your comment" · "Go to the home page" · "Please wait a minute and try again." · "Too many searches" · "Something went wrong" · "Something went wrong with this page." · "This page has no template of its own." · "Nothing published yet." · "Nothing here yet." · "It may have moved, or the address may have a typo. Try a search, or start from the home page." · "There is nothing at this address." · "We couldn’t find that page" · "Search" · "Type a word or two to search the site." · "Updated" · "Menu" · "Tags" · and every other word W3 finds in the theme's views (reading time, result counts), each a whole sentence.
- **Language names** a reader sees (`$alternates`' `name`, the API's `native`) are data: `config/languages.php`'s `native`, never a translation key.

### `CHANGELOG.md` (6.1a, Task T2; moved from 6.1z's Z5)

"The foundation for publishing one site in several languages. Every post, page and term now carries a language, and translations of one item are linked as versions of it, each its own item with its own address under a prefix such as /fr/, its own drafts, schedule and comments. Teme can add, change, hide and remove a site's languages, translate an item into a new draft, tell when a translation is out of date, and choose which content types and taxonomies are translated, but there is no screen for any of it yet: Settings › Languages is a placeholder, and the screens come in a later release. On a site with one language nothing readers see changes; the site's search, sitemaps, feeds, llms.txt and the read API's lists keep to its default language, and a hidden language answers nothing. A page with a parent, or with pages under it, can't be moved to another language."

*(Parked with 6.1z, its paragraph for the whole slice:)*

"A site can now publish in several languages. Each translation is its own post or page, at its own address under a prefix such as /fr/, with its own drafts, schedule, comments and search, linked to its other versions. Settings › Languages adds a language, shown or hidden while it's translated; the editor translates a post into a new draft and marks a translation out of date when its source changes; pages carry hreflang, og:locale and their language and direction; the sitemap, feeds, search and the read API each follow the language. Teme's own words for readers, and the default theme's, now come in Spanish, French, Brazilian Portuguese and German; those words were prepared and checked by AI, and corrections are welcome. The site owner chooses which content types and taxonomies are translated."

---

## Security

- **No translation leaks another's draft.** Translate copies the source's published revision, or its unpublished one only for someone who may edit it (D8, D23); the editor's panel names a version (its title and link) only to someone who may open it; any other version is shown by its language and state alone.
- **A hidden language answers as a missing one** everywhere a reader can reach (D7's eight places and the review's four), with the page cache on and off (6.1a's A3 and A5 now; 6.1z's Z2, parked, later), the same status, words and headers as an address with nothing at it.
- **While the reading side is parked** (I-3372), every list and search at an address without a prefix, and every list the sitemaps, feeds, `llms.txt` and the read API give, holds the default language's items only (A2, A3, A5), so nothing in another language reaches a default-language answer.
- **The group link reveals nothing hidden:** `alternates`, `hreflang`, `og:locale:alternate`, the sitemap's alternates and the API's `translations` list only live, public versions in shown languages; a group's ID is never printed to a reader (Z2 searches for it).
- **Language is chosen by the address only**, never `Accept-Language` or a cookie (study §5.1, `[3.1]` D5): nothing a reader sends can make a cached page serve another language, and `StoreRules::VARY` is unchanged.
- **Every new Operation** refuses before writing, with its writes-nothing test; the settings words go through the group's own ability; linking an import needs `imports.run`; the queued link carries its actor, never an elevation.
- **The pseudo-locale** runs only in debug with its own switch, and never on the public site (now the admin-words plan's, I-3373 (6)).
- **A prefix can't shadow anything live** (D6's refusals), and a redirect it shadows is named in a banner.

---

## For later releases

Each waits for a release that needs it (I-3323), roughly in order:
1. **Shared fields across translations** (I-3360 later; study §4.3): a per-field `translate` flag, default true, beside `search` and `filterable`; a shared value copied to the group's other versions when the source is published, each copy a new revision by one Operation.
2. **A domain per language** (I-3360 later): a host per language in `SiteLanguages`, `Addresses::urlFor()` choosing it, the page cache already keyed by host (`PageKey`).
3. **Machine translation as a plugin** (I-324): a `Translator` socket `TranslateContent` calls, its draft marked as machine-translated, never published unseen.
4. **The admin in a language**, when a site owner needs one and a fluent reader checks it (I-3360): its `lang/{locale}.json`, the existing screens converted (an estimated 1,500 to 3,000 words, 4 to 6 builder days, each file leaving `admin-words-baseline.txt`), numbers and dates through `Intl` with the admin's locale.
5. **The admin right to left** (I-3360, parked with the admin's languages): about 114 physical-direction classes to logical, the editor's direction by the item's language.
6. **Per-language roles and permissions** (Q4 C; Strapi, Craft, Statamic).
7. **Changing the default language while others hold items** (Q1 B): a background swap of NULL and the tags.
8. **A language switcher component** beyond what a theme draws from `$alternates` (core may offer `<x-teme::languages>`, as `<x-teme::navigation>`).
9. **Translation memory**, and a site maintainers' per-language overrides of Teme's and a theme's reader words, with "the original changed" shown (study §4.7).
10. **Media's words per language** (study §4.3: the library's alt, caption and title per language in one typed JSONB).
11. **Importing the rest of Polylang** (media translations, menus by location and language, translated strings), **a MultilingualPress network**, **a TranslatePress site** (study §4.6).
12. **Alternates on author, date and later listing pages**; `hreflang` in an HTTP `Link:` header (study §4.5); per-language `llms.txt`.
13. **Out of date in the lists**, a "needs translation" view, side-by-side editing, "copy this field from another language".
14. **Hooks for a translation made or a group linked**, if a plugin asks (study §6.2).
15. **Per-language sort order** (I-151) and its indexes; a "not yet in French" notice where a version is missing.
16. **A Translate action on the term screens**, and linking terms by hand.
17. **A term of an untranslated taxonomy listed under each language's prefix** (`/fr/tag/x/` listing the French items filed under it), as Polylang shares such terms across languages (D25; the first release lists default-language items only, the maintainers' call, I-3373 (3)).
18. **The parked parts of this slice** (I-3372, stage 3): 6.1c, 6.1e, 6.1f's G tasks, 6.1h, 6.1i and 6.1z, each with its trigger at its head; `ParentChoices`' language filter with page parents' 4.5d.

---

## Self-review

**Against the study's §7.1 and I-3360** *(written for the whole slice; since I-3372 only 6.1a's rows ship now, the rest are parked with their parts, and the admin's foundation (D21) is the admin-words plan's)*:

| Piece | Where |
|---|---|
| Each translation its own item, its own ID, address, revisions and comments, with a language and a group (a1) | D1, D4, D5, D11; 6.1a |
| `/xx/` prefix, the default unprefixed; no existing address moves | D6; 6.1a A1 and A2 |
| Translate makes a new draft from a source | D8; 6.1a B2; 6.1i E2 |
| The translations panel; out of date from the revision it came from | D9; 6.1a B4; 6.1i E2 |
| `hreflang` with `x-default`; sitemap alternates | D17; 6.1c H2, H3 |
| Search in each item's language | D18; 6.1c S1, S2 |
| Settings that are words (title, tagline, SEO defaults) and menus per language | D14, D15; 6.1f |
| The importer's remembered groups linked, as an action (I-351) | D22; 6.1h; 6.1i P4 |
| Teme's reader words in en, es, fr, pt-BR, de, `App::setLocale` from the page | D20; 6.1e |
| `dir` on the page; `og:locale` | D20, D17; 6.1e W1, 6.1c H2 |
| The admin: only its foundation, new screens follow it, no admin language translated, the code and docs say languages come later | D21; 6.1f; 6.1i use it |
| Right to left for readers fixed; the admin's parked | D20; *For later* 5 |
| Shared fields later; comments per version | *For later* 1; D11 |
| Content in any language a site adds | D1 (any tag `config/languages.php` lists) |
| The three promises: `language`, `direction`, `alternates` filled; the API's additions under its D14; nothing for hooks beyond `ItemRef::$language` but `SettingsChanged::$language` (why: D15) | D16, D19, D15 |
| The study's §4.4: only adds; no backfill | D4; D1 |
| The first part carries what the others build on (I-3371); parts only where the work is truly separate (I-3367) | the part map: 6.1a (languages, addresses, translations, the per-type switch); then 6.1c, 6.1e, 6.1f and 6.1h side by side; 6.1i once its pictures are adopted; the join |
| Which types are translated, per type (I-3370) | D25; 6.1a T1; 6.1i P1; 6.1h L3 |
| The reader words checked by AI (Q7 as decided, I-3370) | 6.1e W4, W5; 6.1z Z5 |
| A large part that fails late loses nothing (the maintainers, 2026-11-11) | *When a session fails*; every part's header |
| Foundations ship, finished features wait (I-3372); 6.1a only, no form to add a language, the guards listed (I-3373 (1) to (3)) | the cut at the top; the part map's *Parked* table; *While the other parts are parked* (the guards, confirmed); C2's no-form test; B3; T1's archive test |
| Page parents merges first (I-3373's order) | *With page parents*, *Merge order*; C0 |

**Issues 367 to 370** *(all four stay open while 6.1c and 6.1e are parked, I-3372; only #368's half, `App::setLocale` on public pages, ships in 6.1a)*: #367 (no `dir`) fixed by 6.1e W1; #368 (`App::setLocale` never called) by 6.1a's `PageLanguage::use()`, called by 6.1a on public pages and 6.1e on every other reader entry point, with the four languages' files in 6.1e W4; #369 (the default theme's English labels) by 6.1e W3 and W4; #370 (`og:locale`) by 6.1c H2. Each closes with the tag; none needs to wait for the rest (each would also be correct on a single-language site), so the architect may cut 6.1e W1 and 6.1c H2 into a fix branch earlier if the maintainers wants them sooner.

**Sizes, honestly:** the study said 10 to 13 days for content plus 1 for reader words, 2 for files and 2 for the admin. This plan's 12 to 13 is a little under that sum because the admin does not convert its screens, menus need no column, and the API's musts are small; it is over the study's half day for the API, which missed the default-language predicate and the hidden-language one.

**Checked against the plan-writing lessons:** helpers in `tests/Fixtures/Languages/` and `tests/Fixtures/Import/`, never `tests/Pest.php`; `--merges` with a large enough N; no JSONB key order with `toBe` (`translations` read by key); "raises nothing" names `Throwable`; migrations dated after every migration of `main` and the three branches; a concurrent index migration holds only concurrent statements; the index-plan tests are "can serve" with `enable_seqscan = off` and other rows between; whole-list pins named under each part's *Modify*; a queued job carries its actor and a test runs it under `DeniedElevation`; no type literal in written-out code (`'user'` is not one of the four); comments cite one I-number or this plan's path; no backslash-u escape; Larastan reads the models' new `@property` lines; `array_keys()`'s integer keys cast (L2); Browser tests after `npm run build`, waiting with `cmWaitUntil()`, axe after a dialog leaves the DOM; builders run only the Browser tests they touch.

**Not verified in the code:** `Lang::handleMissingKeysUsing()`'s return in Laravel 13.39 (F0 reads it); Inertia 3's `once()` and how a test sees it (F0); `NULLS NOT DISTINCT`'s place in `CREATE UNIQUE INDEX` against the PostgreSQL 18 page (C0); the name Laravel gave the terms key (C0, `\d terms`); whether 1.11's imported `/fr/…` addresses are history rows of the item (the study's reading; L0 checks one in the `PolylangSite` fixture before relying on it); the French stemmer's stem of "mangeaient" (S1's test proves it); Facebook's locale for each of the 41 languages (C2 reads Yoast's generator); the Inertia prop and route names of 6.1i's and 6.1i's existing screens (their E0 and P0). No Reference system was re-read for this plan beyond the WordPress lines in the audit; the study's evidence stands for the rest.

---

## Records (the builder's)

### Part 6.1a

### Part 6.1c

### Part 6.1e

### Part 6.1f

### Part 6.1h

### Part 6.1i

### Part 6.1z

