# Plan: the multilingual admin screens (v1, part ML-3)

**One part, one branch: `i18n/screens`, built ON `origin/i18n/readers` (ML-1) merged with `origin/main`.** Proposal: `docs/proposals/multilingual-screens.md` (its Settled section is binding). Decisions: I-3446, I-3449. Pictures: approved by the maintainers; the proposal describes each screen fully.

**How this plan is written (I-569):** decisions, names, permissions, tests and the delicate parts are written out; the rest is described. Read first: the proposal; `docs/architecture/design-system.md` (the list, settings and editor-rail patterns; status pills; the row "⋯" menu; empty and failed states); `docs/plans/multilingual-first-release.md` sections D2, D9, D10, D11, D23 (languages group, out of date, link and unlink, the Operations, who may) and its Global Constraints; `docs/plans/multilingual-readers.md` (what ML-1 adds: menus' `language` column, the `languages` item kind, the word-settings writer).

## Owns

- `resources/js/pages/settings/languages.tsx` (replace the placeholder), `app/Http/Admin/Settings/LanguagesController.php`, and its requests
- `resources/js/components/editor/rail/translations-section.tsx` (new), `resources/js/components/editor/language-chip.tsx` (new); in `resources/js/pages/content/edit.tsx` and `components/editor/rail/publish-panel.tsx`'s parent only the lines that place the section under Publish and the chip by the status
- `resources/js/components/list/language-marks.tsx` (new); in `pages/content/index.tsx` and `pages/terms/index.tsx` only the lines that draw the marks, the filter and the chooser's column
- `resources/js/pages/navigation/edit.tsx`: the "For readers of" control inside **Show in**; the item picker entry for the `languages` kind (the kind itself is ML-1's)
- `resources/js/pages/settings/group.tsx` (and the generic settings field renderer it uses): the **In other languages** table for word settings
- `app/Domain/Languages/Operations/ReorderSiteLanguages.php` and its input (new)
- the admin controllers' props for these screens: `app/Http/Admin/ContentEditorController.php` (or the editor's props query: translations of this item via `TranslationStatus`), the content list query's language marks, `NavigationController`, `SettingsController`: only the lines these screens need
- tests under `tests/Feature/Admin/Languages/**`, `tests/Browser/MultilingualScreensTest.php` (new), and additions to `tests/Browser/ContentListsTest.php` and `tests/Browser/NavigationScreensTest.php`

**Never touched:** `app/Domain/Import/**` (ML-2), ML-1's files except reading them, `resources/js/components/ui/**`, the design tokens in `resources/css/app.css`, `CHANGELOG.md`, `.ai/`.

## Operations and who may (D23)

| Operation | From | Who |
|---|---|---|
| `AddSiteLanguage`, `ChangeSiteLanguage`, `RemoveSiteLanguage`, `ChangeTranslatedTypes` | Settings › Languages | `settings.manage`, with elevation, as on `main` |
| `ReorderSiteLanguages` (new) | Settings › Languages "⋯" | the same |
| `TranslateContent` | editor Translate, chip menu | `create` on the type, as `main` |
| `LinkTranslations`, `UnlinkTranslation`, `MarkTranslationUpToDate` | editor "⋯" | `update` on every item involved |
| ML-1's word-settings writer | settings group | the group's own permission |

**`ReorderSiteLanguages`** (`ReorderSiteLanguagesInput(list<string> $tags, int $expectedVersion, ?string $actorId)`): writes the languages group's order in one settings change (versioned, logged, purged like any settings write, D2). Refused when `$tags` isn't exactly the site's non-default languages (no more, no fewer, no repeats), when the version is stale, or without permission; the default language stays first and isn't in `$tags`. Purges what the language switcher draws (the pages carrying `translations:*` tags, D12) after commit.

## Tasks, in order

Each task: tests first, each seen red for the reason given; the quick check; commit; push.

1. **`ReorderSiteLanguages`.** Feature tests: reorders and logs a settings change; refused for a wrong set, a stale version, an editor, writing nothing each time (settings row and log unchanged).
2. **Settings › Languages** (proposal 2). Props: languages with tag, own name, prefix, shown, item and term counts, and the group's version; the translated-types lists. The table, the row "⋯" (Move up and Move down call `ReorderSiteLanguages`; Remove confirms and calls `RemoveSiteLanguage`, showing its refusal sentences), **Edit** opens a sheet calling `ChangeSiteLanguage`, **Add a language** a sheet calling `AddSiteLanguage`. Browser tests: add, edit, move, remove; the default row has no actions; keyboard-only path through Add; axe at 1280 and 375, light and dark.
3. **The editor's Translations section and the chip** (proposal 3). Props from `TranslationStatus`: every site language for this item's type, each with the version's id, status and out-of-date state, or none. The section under Publish with the summary line; **Open** links to the version's editor; **Translate** confirms (P2) and calls `TranslateContent`; "⋯": Mark up to date (`MarkTranslationUpToDate` with the version's current revision id as `expectedRevisionId`), Link a different post… (a search of items of this type in that language, then `LinkTranslations`), Unlink… (confirm, `UnlinkTranslation`). The chip's menu lists the versions and switches. Not shown for a type that isn't translated. Browser tests: each action, the out-of-date pill and its clearing, unlink, a refused action shows its sentence; keyboard path (Tab into the section, the "⋯" menu with arrows, Escape returns focus); axe at both widths and schemes.
4. **Language marks in the lists** (proposal 4). The list query adds, per row, the languages of its group in site order (one query for the page, not one per row: test the query count). Marks under the title; the **Languages** column in the chooser; the **Language** filter (default language, each language, "missing a version in…"). Browser tests: marks in order, the filter, the column on and off, no sideways scrolling at 1440 with the default columns.
5. **Menus** (proposal 5). "For readers of" inside **Show in**, writing ML-1's `navigations.language` through the existing menu Operation; the item picker offers **Language switcher** (ML-1's `languages` kind). Browser test: set a menu's language, add the switcher item, save, reload.
6. **Words in other languages** (proposal 6). For each translatable word setting in a group, the **In other languages** table (a row per non-default language; the default's words as the placeholder; an empty field means fallback). Saves through ML-1's word-settings writer. Browser test: fill Igbo's tagline, save, reload; clear it, and the placeholder shows again.

## Shared files (add only your own lines)

`pages/content/edit.tsx`, `pages/content/index.tsx`, `pages/terms/index.tsx`, `pages/navigation/edit.tsx`, `pages/settings/group.tsx`, the content list query, `tests/Browser/ContentListsTest.php`, `tests/Browser/NavigationScreensTest.php`; the admin-words and design-system baselines only lose lines.

## Quick check

`php artisan test --compact tests/Feature/Admin/Languages` and `vendor/bin/pint --test` on changed files; `npm run build` then `php artisan test --compact tests/Browser/MultilingualScreensTest.php`. `composer check` once at the end.

## Records (the builder's)

`cat /tmp/teme-cloud-setup.log`: "2026-11-15T19:12:06Z ran in /home/user/teme as root / 2026-11-15T19:23:39Z finished"; `pwd`: /home/user/teme.

**Branch.** `i18n/screens`, made from `origin/i18n/readers` (its records commit and the fix after it) merged with `origin/main` (ef4e6fa); the merge had no conflicts.

**Commits.**

- a355d6a feat(languages): reorder the site's languages
- c6f889d feat(languages): the Settings › Languages screen
- b8abf69 feat(translations): the editor's Translations section and language chip
- a8a2637 feat(lists): language marks, column and filter in the lists
- da0f46a feat(menus): show a menu to one language's readers, and add the language switcher
- 61e9cdd feat(settings): a group's words in other languages
- 8179756 fix(languages): keep the languages' order when one is added, changed or removed
- d1bd4dd test(browser): the multilingual screens
- d601491 fix(lists): offer the Languages column in the column chooser
- 487232c test(browser): language marks, filter and column in the posts list
- 60891e8 test(browser): a menu for French readers and the language switcher item

**Tests seen red** (each run against the code before its change):

- `Admin/Languages/ReorderSiteLanguagesTest`: 9 failed, the Operation's class missing. Its later test "keeps the order when a language is added or changed" failed with Spanish before French: the order lost on a change (see B-11).
- `Admin/Languages/LanguagesScreenTest`: 5 failed, the props missing and the order route answering 405.
- `Admin/Languages/EditorTranslationsTest`: 5 failed, `workingRevisionId` undefined and the candidates route 404.
- `Admin/Languages/ListLanguageMarksTest`: 6 of 7 failed, `versions` and the `missing:` filter and the lists' `languages` props missing. "Gives no versions on a site with one language" passed before too: it guards that nothing is read there. "Offers the Languages column" failed with the preference refused (B-12).
- `Admin/Languages/MenuReadersTest`: 3 failed, the `readers` prop missing and the slot bound for every language.
- `Admin/Languages/SettingsWordsTest`: 5 failed, the `words` prop missing and the words route 405.
- Browser: the first run of `MultilingualScreensTest` failed 5 of 22. Two were real: the row "⋯" cut off by the table's edge at 1280 (the columns narrowed), and an added language reordering the others (B-11). The rest were the test opening the rail sheet on a phone. `ContentListsTest` first failed on the Languages column refused by the preference (B-12).

**Baselines.** `admin-words-baseline.txt` 876 lines before and after; `design-system-baseline.txt` 222 before and after. Nothing added.

**Full check.** Base (`i18n/readers` merged with `main`, before this part): 10772 passed, 2 skipped; Architecture 139. After: 10808 passed, 2 skipped; Architecture 139; Pint, PHPStan, types and lint clean. Browser files run: MultilingualScreensTest 22 passed, ContentListsTest 13 passed, NavigationScreensTest 14 passed.

**Decisions (draft).**

- B-01 (draft). Files outside "Owns" that the screens can't work without. Options: stop; or add only the lines each screen needs. Chosen: the lines, each commented: `routes/admin/languages.php` (the order route), `routes/admin/translations.php` (the candidates route), `routes/admin.php` (the words route), `TranslationsController::candidates`, `ContentListController` and `TermController` (the `languages` and `versions` props), `resources/js/types/{content,editor,menus,settings,terms}.ts`, and `components/navigation/add-item-panel.tsx` (the item picker's entry, which the plan names but whose file it doesn't). Commits b8abf69, a8a2637, da0f46a, 61e9cdd.
- B-02 (draft). Where the screens' reads live. Options: in the controllers (which may not touch models or the DB facade); or query classes. Chosen: `app/Domain/Languages/Queries/{LanguagesScreen,TermVersions,MenuLanguages,SettingsWords}` and `app/Domain/Contents/Queries/TranslationCandidates`, read-only. Commits c6f889d, b8abf69, a8a2637, da0f46a, 61e9cdd.
- B-03 (draft). What `ReorderSiteLanguages` purges. Options: name the `translations:*` tags; or rely on the settings writer, which purges the `settings` tag after commit for every languages write, as Add, Change and Remove do. Chosen: the writer; a test proves a cached `/fr/` page is purged. Commit a355d6a.
- B-04 (draft). `tests/Feature/Languages/SiteLanguagesTest` "offers no form to add, change or remove a language" proved the placeholder bare. The plan replaces the placeholder, so it can't hold. Options: stop; or rewrite it to prove the replacement. Chosen: rewritten as "offers the forms … each through the screen's own routes" (the props are there and the page writes only through `LanguagesController`). This changes what one test proves: the maintainer should confirm. Commit c6f889d.
- B-05 (draft). "Link a different post…" when a version exists. Options: refuse until unlinked; or unlink the old version, then link the chosen one. Chosen: unlink then link, each its own Operation; the dialog says so. Commit b8abf69.
- B-06 (draft). Mark up to date's `expectedRevisionId`. The editor's versions carried no revision. Chosen: `EditorSession` adds `workingRevisionId`, only for a version the person may open. Commit b8abf69.
- B-07 (draft). The "Missing a version in…" filter. Options: a new query parameter; or `language=missing:{tag}` on the existing Language filter. Chosen: the latter, so the filter row keeps one Language control. Commit a8a2637.
- B-08 (draft). The terms list. It has no filter row or column chooser. Options: build both; or draw the marks only. Chosen: the marks only. Commit a8a2637.
- B-09 (draft). "For readers of". No Operation on `main` or ML-1 writes `navigations.language` for a menu that exists, and the Menus Operations aren't this part's. Options: stop; add a language to `RenameNavigation` (another part's file); or make "For readers of" choose which language's places Show in binds, through `BindNavigationSlot`'s `language` (ML-1), defaulting to the menu's own language. Chosen: the last; a menu's own language stays as it was made. Commit da0f46a.
- B-10 (draft). The words table's Save. Options: one Save with the group's form (two Operations behind one bar); or its own Save under the table, one `TranslateSettings` call per language, each on the version the last left. Chosen: its own Save. Commit 61e9cdd.
- B-11 (draft). Adding, changing or removing a language reordered the others: `WritesSiteLanguages::current()` read `others` in the config's order and wrote the prefixes' rows, the switcher's order, from it. Options: leave it (the order the screen sets would not survive an Edit); or read `others` in the prefixes' order in `current()`. Chosen: the one line in `current()`, with its test. Outside "Owns". Commit 8179756.
- B-12 (draft). The Languages column. The columns preference refused `languages`. Options: no column; or allow it as Featured image is allowed, off until turned on. Chosen: one more allowed id in `BuiltInPreferences` (outside "Owns"), with its test. Commit d601491.
- B-13 (draft). The language switcher item's options are not drawn in the item's row; the item saves as a bare `languages` item, as ML-1 accepts it. Commit da0f46a.
- B-14 (draft). `docs/guides/editors/settings.md` said Settings › Languages "only shows them". The maintainer added that guide to Owns for this one change: only the Languages page's description is rewritten to say what the page now does; nothing else in the guide changed. Commit: the `docs(guides)` commit after the records.

**STOP.** None.

**Changelog lines.**

- Added: Settings › Languages lists the site's languages in one table, adds, edits, reorders and removes them, and says which content is translated.
- Added: the editor's Translations section under Publish, with Open, Translate, Mark up to date, Link a different post and Unlink, and a language chip by the status that switches between versions.
- Added: language marks under each title in the content and term lists, a Languages column in the column chooser, and a Language filter that finds what is missing a version.
- Added: a menu's Show in card chooses the menus for each language's readers, and the item picker adds the language switcher.
- Added: an "In other languages" table for a settings group's words, falling back to the default language's.
- Fixed: adding, changing or removing a language no longer changes the order of the others.
