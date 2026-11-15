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

**Setup:** a cloud container, in a worktree of its own; `export PATH=/opt/node24/bin:$PATH COMPOSER_ALLOW_SUPERUSER=1`, `bash scripts/cloud-setup.sh`, `npm ci`, `cp .env.example .env`, `php artisan key:generate`, `php artisan wayfinder:generate --with-form`; PHP 8.4, PostgreSQL on 5433, Valkey on 6380; the worktree's tests ran with `DB_DATABASE` set to a test database of their own and `TEME_POSTGRES_BINDIR=/usr/lib/postgresql/18/bin` (which the setup script writes into the main checkout's `.env` only). Base `origin/main` at `ee3e83c`: `composer check` green, Architecture 139 passed, the suite 10600 passed and 2 skipped (54728 assertions).

### Commits

On `i18n/readers`, in order:

- `6205059` feat(languages): menus and word settings carry a language (the seam)
- `a1053b1` feat(themes): a page's language, direction and alternates in its view data
- `fc5b496` feat(seo): hreflang and og:locale for each language version
- `f5994b7` feat(sitemaps): each language's items, with their versions as alternates
- `ad76321` feat(structured-data): a listing's nodes in the page's language
- `de7d012` feat(feeds): a feed in each language
- `1cb327e` feat(api): language on lists, translations and the site's languages
- `ae65702` feat(menus): a language switcher item and `<x-teme::languages>`
- `408cba1` fix(site): dir on the page, from its language (W1; the theme reference regenerated)
- `7c6dded` fix(site): readers get core's words in the page's language (W2)
- `4e5a06e` fix(themes): the default theme's words through the translator (W3)
- `00339e9` feat(site): reader words in seven languages (W4, with `laravel-lang/lang`)
- `893e953` feat(languages): Japanese (Task 5)
- `e9e13de` feat(settings): word settings, menus and regions read in the page's language (Task 6)
- `06ba837` test(languages): the leak matrix, with the page cache off and on (Task 7)
- `04bfbcd` test(site): a French item's preview, and a Spanish site's comment refusal, in their language (W2's last two tests)
- `4082700` fix(site): a reader's own waiting comments in the page's language (W2, found while writing these records)
- `8c7783c` test(languages): name the leak matrix's helpers apart from the page tree's (the full check found the clash)
- `3006b05` refactor(settings): one cache key helper for a group's values and its words (the full check found the second public static)

### Tests seen red

Each was run against the seam commit (`6205059`) with only the new test files copied in, before the code:

- ViewDataLanguage, Hreflang, OgLocale, SitemapAlternates, StructuredDataLanguage and LanguageSwitcher: 30 of 33 failed (no `$language`, `$direction`, `$alternates`, `hreflang`, `og:locale:alternate`, sitemap alternates or switcher).
- FeedsPerLanguage: 3 of 5 failed (`/fr/feed/` answered 404).
- ApiLanguage: 6 of 8 failed (no `?language=`, `translations` or `languages`).
- WordsPerLanguage: 7 of 8 failed (the French page read the default's words).
- DocumentLanguage: 4 of 4 failed (no `dir`).
- Japanese: 2 of 2 failed, on the drafts before the config line (`ja` unknown).
- ReaderLocale: 5 of 6 failed (English words on a French item's comment refusal, password form, reply page and preview, and on a Spanish site's comment refusal); its seventh test, a reader's waiting comment on a French post, failed on the branch before `4082700`.
- ThemeWords: 2 of 4 failed (the theme's labels were English, and a theme's words were never read).
- ReaderWords: every file test failed, "lang/{locale}.json is missing" (with `ReaderWords` copied in).
- LeakMatrix: 3 of 4 failed (`/fr/feed/` answered 404; no version was linked).

### The checks

- Quick check after the tasks: Pint, PHPStan, `npm run types:check`, `npm run check` and the Architecture suite (139 passed) green; the touched folders, each with `--parallel`: Languages 364, Site 220, Sitemaps 47, Feeds 93, Api 334, Comments 411, DefaultTheme 58, Themes 116, Navigation 89, StructuredData 125, Seo 195, all passed.
- The end-of-part `composer check`: green on the branch at 3006b05: Pint and PHPStan clean, Architecture 139 passed (1309 assertions), Unit and Feature 10,772 passed and 2 skipped (56,557 assertions), against the base's 10,600 passed and 2 skipped.
- Baselines: `tests/Architecture/admin-words-baseline.txt` 876 lines before and after, `design-system-baseline.txt` 222 before and after; no PHPStan baseline. No count raised, no line added.
- No Browser test was added or changed, and no screen changed, so `composer check:browser` was not run.

### Decisions (B-?? drafts)

- **B-?? (draft) `Navigation/**` in Owns.** The menus live in `app/Domain/Menus`; there is no `app/Domain/Navigation`. Read Owns as `app/Domain/Menus` for `NavigationTree` (the switcher, and Home pointing at the page language's home). Commit `ae65702`.
- **B-?? (draft) Files edited outside Owns, each the smallest change a task needs:** `Themes/CoreComposers/SiteComposer.php` and `StructuredData/ListingNodes.php` (the page's language, Task 1); `Http/Site/Feeds/FeedController.php` (Task 2); `Http/Api/V1/ItemsController.php` and `TermsController.php` (the `language` parameter, Task 2); `View/Components/Teme/Document.php` and `resources/views/teme/components/document.blade.php` (W1); `Http/Site/{CommentController,UnlockController,PreviewController,PendingCommentsController,CommentFragments}.php` and `Comments/Operations/SubmitComment.php` (W2); `Http/Site/PublicPage.php`, `Themes/ThemeWords.php` (new), core's public views and the default theme's views (W3); `Cache/StaticPages.php` (`ja` added to the languages a static file may say, which `StaticFilesPerLanguageTest` holds to `config/languages.php`, Task 5); `Regions/Regions.php` (Task 6). No other part owns these files today.
- **B-?? (draft) Where the new classes live.** `Alternates`, `LanguageComposer`, `LanguageLinks`, `ItemLanguage` and `ReaderWords` are in `app/Domain/Site` (owned), not `Languages`. `ReaderWords` lists its files with an SPL iterator, because `NoStorageListingTest` refuses `->files(` anywhere in `app/`.
- **B-?? (draft) `<x-teme::languages>`'s path.** Owns names `resources/views/components/teme/languages.blade.php`; core's other components are classes under `App\View\Components\Teme` with views in `resources/views/teme/components/`, so it follows them: `app/View/Components/Teme/Languages.php` and `resources/views/teme/components/languages.blade.php`.
- **B-?? (draft) hreflang** is written by `HeadTags`, beside the canonical, not by a new head contributor: one place decides both, and it writes nothing without a canonical.
- **B-?? (draft) The menu switcher's links carry no `lang` or `hreflang`.** The plan's component has them. Adding them to `$navigation`'s items retypes a view-data value, which `ViewDataPin` holds to a `### Theme view data` changelog line that builders may not write. Options: (a) add the keys and stop for the changelog; (b) keep `$navigation`'s shape and give the attributes in the component only. Chose (b); the matching I-?? draft below asks for (a) later.
- **B-?? (draft) Term sitemaps stay in the default language.** The items and archives sitemaps carry every shown language; terms keep 6.1a's guard, as the older plan's H3 lists items only.
- **B-?? (draft) `HiddenLanguageTest` and `DefaultOnlyGuardsTest` changed**, as the older plan says H3 and S3 replace 6.1a's A5 guards: the French term feed is now served and its old address redirects to it (the hidden language's feeds stay 404); the items sitemap now lists `/fr/bonjour/` and never `/es/hola/`. Each test was renamed to say so.
- **B-?? (draft) The leak matrix's helpers** are named `langLeak*`: Pest helpers are global, and `tests/Feature/Tree/EndToEnd/LeakMatrixTest.php` already declares `lmSurfaces()`. Commit `8c7783c`.
- **B-?? (draft) The words' cache key** is `SettingsRepository::cacheKey($group, translations: true)`, not a second public static: `PluginSettingsReadTest` holds the reader's public surface to the contract plus `cacheKey()`. Commit `3006b05`.
- **B-?? (draft) The sitemap statement test** proves the items sitemap reads no statement per item (no N+1) rather than the plan's "budget + 1", which the warm settings read made unstable.
- **B-?? (draft) The API's cold `/api/v1` budget**, `CatalogueBudgetTest`, goes from 3 statements to 4: the site's `languages` list (S4) reads the languages settings group. Options: (a) fold the group into another read, a change to `SettingsRepository` beyond this part; (b) update the pin with its comment. Chose (b); the warm figures are unchanged.
- **B-?? (draft) The API's `languages` keys** follow the older plan's S4 exactly (`tag`, `name`, `native`, `path`, `url`, `default`). The `tag` member is written with `compact()`, because `NoTypeLiteralsTest` reads a quoted `'tag' =>` as the taxonomy.
- **B-?? (draft) The settings words route, controller and export (G3's reading screen)** are left to ML-3, which owns the screens; `SettingsTranslations::of()` gives them what they read.
- **B-?? (draft) `BindNavigationSlot`** does not check that a menu's language matches the slot's language; an empty translated word removes the translation.
- **B-?? (draft) The pending comments endpoint** (`/_teme/comments/pending`) is a reader entry point the older plan's W2 doesn't name: it returns the theme's HTML for a reader's own waiting comments. It now takes the language from the address it is asked about, and its fragments and the swapped-in form read the theme's words. Commit `4082700`, with its test seen red first (English "Awaiting moderation." on a French post).
- **B-?? (draft) Fragments in the theme's views.** One key a sentence wherever the view allows; where a visible word is followed by screen-reader text (`Older` + `comments`, `Reply` + `to :name`, `Page` + a number, the byline's `By` and `and`), the pieces stay separate keys, so the visible text and every existing test stay as they were. The comment form's privacy line is whole sentences.
- **B-?? (draft) Theme words are a path, not a JSON path.** Laravel reads JSON paths before `lang/` and paths after, so the theme's folder is added with `addPath()` so its words win, as W3 asks; it is added only when a public page is drawn. A worker that draws pages for two themes keeps the first one's folder too.
- **B-?? (draft) The stand-in word files in tests.** W2's and W3's tests were seen red with a French stand-in added by `Lang::addJsonPath()`. Once the shipped French file existed it won over a JSON path, so `ReaderLocaleTest` adds its stand-in with `addPath()` (read after `lang/`), and `ThemeWordsTest`'s labels test uses Dutch, which ships no words. What each proves is unchanged.
- **B-?? (draft) `laravel-lang/lang` 15.38.0** is still the newest release (checked with `composer show -a` on 2026-11-15), so no newer one was taken. `composer require --dev` also ran the project's `boost:update` script, which rewrote `CLAUDE.md`, `AGENTS.md`, `boost.json` and the skills; those were put back unchanged. The lock's own line for the path package and its plugin API version were put back as on `main`. The published PHP files are formatted by Pint.
- **B-?? (draft) A language's words for a regional locale.** The files are named by Laravel locale (`pt_BR`), so a site in `pt` or `fr-CA` reads English for Teme's words until a file of that name exists.

### I-?? drafts (for the maintainers)

- **I-?? (draft)** `lang` and `hreflang` on the menu switcher's links: a `### Theme view data` changelog line retyping `$navigation`'s items.
- **I-?? (draft)** Announce settings words to plugins: `SettingsChanged` carries no language, so a translation write raises no plugin hook; the contract addition is the maintainers' to make.

### STOP

None.

### Changelog lines for the maintainers

- **Added:** each language a site shows now has its own feeds, its items in the sitemaps with their other versions as alternates, `hreflang` and `og:locale` in every page's head, and a language switcher, as a menu item (`languages`) or as `<x-teme::languages>`.
- **Added, theme view data:** `$language`, `$direction` and `$alternates`, and the `<x-teme::languages>` component.
- **Added, API:** `?language=` on `/items` and `/terms`; `translations` on a full item; `languages` on the site.
- **Added:** Japanese among the languages a site can add.
- **Added:** a page in another language reads its own title, tagline, title templates, menus and regions where they are translated.
- **Fixed:** #367 (`dir` on every page), #368 (core's words in the page's language at every reader entry point), #369 (the default theme's words through the translator), #370 (`og:locale`).
- **Reader words:** Spanish, French, Brazilian Portuguese, German, Italian, Hindi and Japanese, for core and the default theme, prepared by AI and checked by a second, separate AI pass against the English and each key's context (I-3370); the Italian, Hindi and Japanese files are new in this release and AI-checked like the others. Laravel's own words for the same languages come from `laravel-lang/lang` 15.38.0, a development dependency.

### Reader words: key counts and the AI check, file by file

Every file below is **AI-prepared, AI-checked**. Core's files hold Teme's 123 keys plus the 70 laravel-lang publishes (193); the theme's hold 53.

- `lang/es.json`: 8 changes. "lo apruebe" for a comment (masculine); a gender-neutral form for `This :singular is protected…` ("Este contenido (:singular)…"); one past tense (the preterite) across the posting and refusal sentences.
- `themes/default/lang/es.json`: 1 change. "Relacionados" as the plural heading of a list.
- `lang/pt_BR.json`: 3 changes. The gender-neutral `:singular` sentence; "Você já entrou" for the signed-in line, so the reader's gender isn't assumed; "vai levar até ele" for a linked name.
- `themes/default/lang/pt_BR.json`: no changes.
- `lang/fr.json`: 9 changes. One word for "setup" (configuration); a narrow no-break space before `?`, `;` and `:` throughout; "personne d'autre que vous" instead of the masculine "vous seul"; a neutral wording in place of "connecté·e"; "rechercher sur le site".
- `themes/default/lang/fr.json`: 3 changes. "Personne d'autre que vous"; "Patientez une minute, puis réessayez." to match core; "rechercher sur le site".
- `lang/de.json` and `themes/default/lang/de.json`: no changes.
- `lang/it.json`: 1 change. The example address kept as `name@example.com`, as in the key.
- `themes/default/lang/it.json`: no changes.
- `lang/hi.json`: 6 changes. "अनलॉक करें" for Unlock; one word for page (पेज) in every page key.
- `themes/default/lang/hi.json`: 4 changes. पेज for page throughout.
- `lang/ja.json`: 3 changes. A full-width question mark; "控えとしてお手元に残せます" in place of a sentence that implied the page saves a copy; the same colon as the other labels.
- `themes/default/lang/ja.json`: 1 change. "さん" in `to :name`, as in the other reply keys.

### Left open

- The fragments that join in English order (`By` … `and`, `Reply` + `to :name`, `Older` + `comments`) read stiffly in Japanese and Hindi; whole-sentence keys there would change the theme's markup and its tests.
- The comments script fills a new count with `toLocaleString()` in the page's `lang`; its other words come from the page.

### How to try it

Run `php artisan test --compact tests/Feature/Languages`. Or, on a seeded site with French added: open `/fr/` and a French post. You get `lang="fr" dir="ltr"`, `hreflang` links, French core and theme words, `/fr/feed/`, the item in `/sitemap-items-post.xml` with its alternates, and `/api/v1/items?language=fr`.
