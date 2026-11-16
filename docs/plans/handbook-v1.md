# Handbook v1 pages

There is no plan file for this part: the task message was the plan. This file holds only the builder's records.

## Records (the builder's)

### Setup

- `/tmp/teme-cloud-setup.log`: not present at the start (`none`); working directory `/home/user/teme`. PHP was 8.3 and PostgreSQL and Valkey were down, so `bash scripts/cloud-setup.sh` ran once (exit 0); afterwards PHP 8.4.27, PostgreSQL on 5433 accepting connections.
- Branch `docs/handbook-v1` from `origin/main` at `2488d21`. No other branch merged. Branch code read with `git show origin/<branch>:<path>` after a fresh `git fetch`: `render/insertion-points` `432d822`, `fediverse/actors` `8cc45bf`, `fediverse/publishing` `beb5319`, `i18n/readers` `4a393b6`, `i18n/screens` `fbe8882`, `api/manage-foundation` `8b13e77`, `join/v1-wave1` `b7c0247`, `plugins/public-routes` `6e7a043`.

### Commits

- `37d0726` docs(handbook): plugin settings and secrets, and regions, site code and featured posts
- `de47345` docs(handbook): content inside articles, from the insertion points branch
- `01ee42f` docs(handbook): the fediverse sockets a plugin can use
- `10f43b0` docs(handbook): what a multilingual site gives a theme
- `3f7b643` docs(handbook): the management API for outside services
- `5f1fe3d` docs(handbook): v1 capabilities, jobs and schedules in the manifest and for agents
- `b6bc5f4` docs(handbook): Since and requires.teme, and code standards for plugins and themes
- `ff1aab5` docs(handbook): list the v1 pages in the README, PAGES and llms.txt
- `dcccfcd` docs(handbook): rules for plugins and themes
- this file: `docs(plans): handbook v1 pages' records`

### Pages

New: `plugins/settings.md`, `themes/regions.md`, `start/code-standards.md`, `rules/plugins-and-themes.md` (all describe `main`, in the README's reading order); `plugins/insertions.md`, `plugins/fediverse.md`, `themes/languages.md`, `outside/management-api.md` (in the README's "Pages that land with later work" table, each with its branch, and a `lands_with` key in front matter). Changed: `concepts/manifest.md` (capabilities table, the `jobs` and `schedules` keys), `concepts/hooks.md` (a section on `@since` and `requires.teme`), `for-agents.md` (choosing capabilities), `README.md`, `llms.txt` (regenerated with `php artisan teme:docs:handbook-index`), and `GenerateHandbookIndex::PAGES` (my lines only: four inserted before `for-agents.md`, four appended). No test file changed: the tests read PAGES, so they needed no list of their own.

No code fence over three lines was added; every block over three lines in the handbook is still one that names an example file. Branch code is described in prose and inline code, with the branch named on each page.

### Checks

The handbook's tests (`tests/Feature/Guides/Developers`, `tests/Feature/Console/GenerateHandbookIndexTest.php`) on this branch: **12 failed, 163 passed**. Every failure names something that is only on tonight's branches, as the task foresaw; no check was weakened. Each was checked by a script against the branch it cites: every PascalCase name each page uses is a contracts file on that branch, and every `rests_on` path exists there.

| Check | Page | Why it fails on `main` | Passes once joined |
|---|---|---|---|
| rests only on folders and files that exist | `plugins/insertions.md` | `packages/teme-contracts/src/Insertions`, `app/Domain/Site/Insertions` | `render/insertion-points` |
| rests only on … | `plugins/fediverse.md` | `packages/teme-contracts/src/Fediverse`, `app/Domain/Fediverse` | `fediverse/publishing` |
| rests only on … | `themes/languages.md` | `LanguageComposer.php`, `Languages.php`, `ThemeWords.php` | `i18n/readers` / `i18n/screens` |
| rests only on … | `outside/management-api.md` | `routes/api/manage.php`, `app/Domain/ManageApi`, `app/Http/Api/Manage`, the operators' page | `api/manage-foundation` |
| links only to files and headings that exist | `themes/languages.md` | `theme.md#pagelanguageview`, `#alternate`, `#x-temelanguages` | `i18n/readers` (regenerated reference) |
| links only to … | `outside/management-api.md` | `docs/guides/operators/management-api.md` | `api/manage-foundation` |
| names only classes and hooks that exist | `concepts/manifest.md` | `ContentWriter`, `MediaWriter`, `InsertionsChanged`, `Jobs`, `PluginHasJobs`, `JobHandler` | `join/v1-wave1` |
| names only … | `start/code-standards.md` | `ContentWriter` | `join/v1-wave1` |
| names only … | `for-agents.md` | `ContentWriter`, `MediaWriter`, `Jobs`, `InsertionContributor` | `join/v1-wave1` |
| names only … | `plugins/insertions.md` | `InsertionContributor`, `ArticleFacts`, `Insertion`, `Position`, `InsertionsChanged` | `render/insertion-points` |
| names only … | `plugins/fediverse.md` | the eleven Fediverse contracts | `fediverse/publishing` |
| names only schema keys on the manifest page | `concepts/manifest.md` | `jobs`, `schedules`, `long`, `tries`, `backoff`, `job`, `every` | `join/v1-wave1` |

One failure was mine and is fixed: `themes/languages.md` had `Next` in backticks, read as a class name (`10f43b0`).

`composer check` before: not run on `main` (this part touches no code that runs; the totals before are the architect's). After, once at the end on `dcccfcd`: Pint passed, PHPStan no errors, Architecture **139 passed**; Unit and Feature **12 failed, 2 skipped, 10769 passed** (1719 s). The 12 failures are exactly the twelve in the table above, and nothing else failed. Baselines (admin words, design system) untouched. No Browser tests: this part changes no screen.

### Decisions (B-?? drafts)

1. **B-?? (draft) Custom HTML and featured-post lists on main.** The task asks `themes/regions.md` to cover "custom HTML and featured-post lists, as on main". Neither exists on `main` or any branch as a region card (searched every remote branch). Options: (a) describe what `main` has for those needs, Settings › Site code (an administrator's own HTML in three places, written by `<x-teme::document>`) and the latest posts page's pinned `$lead` and `$featured`; (b) describe cards that don't exist; (c) leave them out. Chose (a), and the page says plainly that there is no custom HTML card for a region. `37d0726`.
2. **B-?? (draft) Audience of the management API page.** The tests allow `plugin`, `theme` or `both`; the page is for outside services. Options: a new audience (changes the test's list, which names no pages, so not mine), or `both`. Chose `both`; the page's first lines say it is not for plugins. `3f7b643`.
3. **B-?? (draft) Code samples.** The proposal allows only tested examples or one-line commands. Branch code and the settings examples are shown inline, not as blocks; `outside/management-api.md` keeps one one-line `curl` command. `37d0726`, `3f7b643`.
4. **B-?? (draft) Code standards: page, not section.** The task offered `start/code-standards.md` or a section of `for-agents.md`; a page, since people read it too, and `for-agents.md` links it. `b6bc5f4`.
5. **B-?? (draft) Rules page, where the note and the code differ.** (a) "the theme checker reports one H1": `teme:theme:check` doesn't check headings, so the page lists one `h1` per page under what authors must follow and leaves it out of what the checker does. (b) "plugin public routes limited in size and rate": on `plugins/public-routes` a route's `throttle` is read from the manifest but nothing enforces it, so the page states the size, time and verifier limits only. (c) "the plugin's own language files": Teme doesn't load a plugin's word files yet; the page says to keep them in the package's `lang` folder and doesn't claim Teme reads them. `dcccfcd`.

### Left open

- `fediverse/publishing` adds `docs/guides/developers/fediverse.md`, a stub with no front matter. Once both are joined, `DeveloperPagesTest` "has every page the guide names and no other" fails on it. `plugins/fediverse.md` replaces it: drop that stub at the join.
- `docs/reference/theme.md` on `main` doesn't yet have the language anchors `themes/languages.md` links to; they arrive with `i18n/readers`.
- `Position::everyParagraphs()`'s cap is per insertion; the insertions page says so, as that branch's records leave it open.
- A public route's declared `throttle` isn't enforced on `plugins/public-routes` (decision 5b).

### Changelog lines for the maintainer

- Developer handbook: pages for content inside articles, plugin settings and secrets, the fediverse sockets, theme regions and site code, languages in a theme, the management API for outside services, code standards, and the rules for plugins and themes; the manifest page covers the v1 capabilities and the `jobs` and `schedules` keys, and the hooks page `@since` and `requires.teme`.

### Try it

`php artisan test --compact tests/Feature/Guides/Developers`, then read `docs/guides/developers/README.md` in order. On the joined branch, the twelve failures above should all pass.
