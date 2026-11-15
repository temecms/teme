# Plan: multilingual for readers (v1, part ML-1)

**One part, one branch: `i18n/readers`, from `origin/main`.** Proposal: `docs/proposals/multilingual-readers.md` (its Settled section is binding). Decisions: I-3446, I-3360, I-3370.

**How this plan is written (I-569):** the detailed tasks already exist in `docs/plans/multilingual-first-release.md`, written for the parts that were parked then (6.1c, 6.1e, 6.1f's G tasks). **Build those tasks as written there, with the v1 changes below**, which win where they differ. Read that plan's *Global Constraints*, *The decisions, written out* and *Security* sections first; they bind this part too. Where that plan says "builders never write `CHANGELOG.md`", that holds here.

## Owns

- `app/Domain/Site/**` (the language view data, alternates, the head), `app/Domain/Head/**`, `app/Domain/Sitemaps/**`, `app/Domain/Feeds/**`, `app/Domain/Api/**` (the read API's language additions), `app/Domain/Navigation/**`, `app/Domain/Settings/**` (word settings per language), `app/Domain/Themes/ComposerRegistry.php` and the view-data catalogue
- `config/languages.php` (Japanese)
- `composer.json` and `composer.lock`: the one development dependency Task 4 names, nothing else
- `lang/**` and `themes/default/lang/**` (reader words)
- one migration: `navigations.language` (and the slot binding with a language)
- `resources/views/components/teme/languages.blade.php` (new: `<x-teme::languages>`)
- tests under `tests/Feature/Languages/**`, `tests/Feature/Site/**`, `tests/Feature/Sitemaps/**`, `tests/Feature/Feeds/**`, `tests/Feature/Api/**` that this part adds or extends

**Shared with parts building at the same time (lines added only; the maintainers join them):**
- `routes/public.php`: plugin public routes and insertion points add lines there too. This part adds only what its tasks name (per-language feed routes, if they aren't already in the language route files from 6.1a).
- `app/Domain/Cache/PageTags.php`: insertion points adds one constant. This part only reads `PageTags::translations()` (on `main`).
- `app/Domain/Site/ArticleViews.php`: insertion points changes it. If a task here needs it, add only, and record the line as a B-?? note.

**Never touched:** `app/Domain/Import/**` (the import parts own it), `app/Domain/Cache/PageKey.php` (plugin public routes changes it; this part needs no key change), `resources/js/**` (the screens are ML-3), `components/ui/**`, the design tokens.

## The seam (do this first, commit alone, push)

**Task 0, the seam for ML-2:** the migration adding `navigations.language` (and whatever 6.1f's G4 says the slot binding needs to carry a language), the model's cast and docblock, and **`TranslateSettings`'s writing side** (G3) with its "writes nothing when refused" test. Commit as `feat(languages): menus and word settings carry a language`, push. **ML-2 starts from this commit.** Then build the rest.

## Tasks, in order

1. **6.1c H1 to H4** (view data's three names, `hreflang` and `og:locale`, sitemaps, structured data and the reference), with these v1 changes:
   - **`x-default` on every page of a group**, pointing at the default-language version (not only the front page).
   - **Empty languages left out:** on the home page and a type archive's first page, `$alternates` lists only languages that have something published.
2. **6.1c S3 to S5** (feeds per language, the read API's musts and additions, the gate). S1 and S2 are on `main`.
3. **The `languages` menu item kind and `<x-teme::languages>`** (new task):
   - A navigation item kind `languages`, stored like other kinds, with the options a theme may read (`dropdown`, `show_names`, `hide_current`, all optional booleans), expanding when drawn into one link per entry of `$alternates`.
   - `<x-teme::languages>` renders the same list for themes that place it themselves: a plain `<ul>` with `lang` and `hreflang` attributes on each link, no styling.
   - Tests first: a menu with a `languages` item on an item with three versions draws three links, the current one marked; on a page with no siblings it draws nothing; the component and the menu item agree.
4. **6.1e W1 to W5** (`lang` and `dir`, the locale on every reader entry point, every word through the translator, the files), with these v1 changes:
   - **Laravel's own framework messages come from a maintained package, not by hand** (I-3448): add **`laravel-lang/lang` 15.38.0** (MIT, released 2026-11-13; it brings `laravel-lang/publisher` 16.9.0, which supports Laravel 13) as a **development** dependency only. Publish the framework files for `es`, `fr`, `pt_BR`, `de`, `it`, `hi` and `ja` with its `lang:add` command and commit them under `lang/`, so production needs no package. This is the one dependency this plan names. Before adding it, re-read its latest release and changelog; if a newer release exists, take it and record the version as a B-?? note.
   - The publisher merges into `lang/{locale}.json`: a test first proves that **Teme's own keys survive** the publish (every Teme key still present with Teme's text after `lang:add` and `lang:update`).
   - **Teme's own reader words: seven files, not four.** Add `it`, `hi` and `ja` to English's keys, translated by AI and checked by a second AI pass for sense and register; the test that every file has every key covers all of them.
   - **Dates, month and day names, numbers:** from PHP's intl (already required) and Carbon in the page's locale. No files, no package.
5. **Japanese in `config/languages.php`** (new task): `ja` with its own name (日本語), `ltr`, `og` `ja_JP`, and search configuration `simple`; the existing test that holds every configured language to `pg_ts_config` stays green.
6. **6.1f G1 to G5** (which settings have words per language, reading them, writing them, menus and regions per language, the gate). G3's writing side is already in Task 0.
7. **The leak matrix** (6.1z's Z2): with two languages, the page cache on, check every list, feed, sitemap, search and API answer shows a language's items only where it should.

## Quick check

`php artisan test --compact tests/Feature/Languages tests/Feature/Site tests/Feature/Sitemaps tests/Feature/Feeds tests/Feature/Api` and `vendor/bin/pint --test` on changed files. `composer check` once at the end.

## Records (the builder's)

*(Filled in while building: commits; each test seen red; B-?? decisions with the options weighed; any STOP; changelog lines, including that the it, hi and ja files are AI-checked.)*
