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

(The builder fills this: commits; each test seen red; baselines before and after; B-?? decisions with options; any STOP; changelog lines.)
