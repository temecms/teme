# Plan: importing a Polylang site (v1, part ML-2)

**One part, one branch: `import/polylang`.** Proposal: `docs/proposals/polylang-import.md` (its Settled section is binding). Decisions: I-3446, I-3360, I-351.

**How this plan is written (I-569):** the parked part 6.1h in `docs/plans/multilingual-first-release.md` has the groups-by-union code written out (Task L2) and the linking Operation (L3). **This part changes 6.1h's approach:** language is set **during** the import, not linked afterward, so L3's "take the old address back" step is not needed. Keep L2's `PolylangGroups::groups()` exactly as written there (with its tests). Read that plan's *Global Constraints*, *The decisions, written out* and *Security* first; they bind this part too.

## When it starts, and from what

- **After wave 1's import fixes (`import/v1-fixes`) merge into `main`:** that part owns `app/Domain/Import/**` until then.
- **Branch from `main` once both that merge and ML-1's first commit ("menus and word settings carry a language") are on `main`;** if ML-1's first commit is not on `main` yet, merge `origin/i18n/readers` at that commit into this branch and say so in the Records.
- The wave-2 **plugin import socket** (`import/plugin-socket`) may build at the same time. Its files are `app/Domain/Import/PluginData/**` and one phase-registration line: do not touch them. If both parts add a phase-registration line in the same file, each adds only its own.

## Owns

- `app/Domain/Import/Phases/Languages/**` (new: the languages step before content)
- `app/Domain/Import/Translations/**` (new: `PolylangGroups`, `ImportedLanguages`, the group join at the end)
- `app/Domain/Import/Phases/Addresses/AddressSettingsStep.php`: the default language from Polylang when Polylang is active (lines changed only for that)
- the content, terms and navigation phases: **only** the lines that set `language` at creation, bind menus to a language and map switcher items (record each changed line in the Records)
- the analyze step: the "not supported in v1" messages
- `tests/Feature/Import/Translations/**`, `tests/Fixtures/Import/PolylangSite.php` (new), and `tests/Fixtures/Import/FakeCompanion.php` (one new `languages` answer; the plugin import socket adds its own route there too, lines added only)
- `docs/specs/companion-languages.md` (new: the companion change, below)

**Never touched:** `app/Domain/Import/PluginData/**`, anything ML-1 owns (it owns the reading side, menus' schema and word settings' writing Operation, which this part calls), `resources/js/**`, `components/ui/**`.

## Tasks, in order

1. **The fixture** (`PolylangSite`): a site whose WordPress locale differs from its Polylang default; seven languages with prefixes that differ from tags (`pt` for `pt-BR`); a post group of three; **an unlinked post in a non-default language**; a broken map (a member missing); a term with a `-it` slug suffix; two menus, one per language, each with a switcher item; translated site title and tagline. Built through the fake companion's answers.
2. **The companion's `languages` answer, in the fake** (`FakeCompanion`): the shape in the spec below. Tests read it through `CompanionClient`.
3. **The languages step before content:** `AddSiteLanguage` for each language in Polylang's order, tag from the locale (`es_ES`→`es`, `pt_BR`→`pt-BR`, `pt_PT`→`pt`, `it_IT`→`it`, `hi_IN`→`hi`, `ja`→`ja`, `en_US`→`en`), prefix = Polylang slug, all shown; `ChangeTranslatedTypes` from Polylang's translated types and taxonomies. Tests first: the languages exist with their prefixes before any item is created; a refused actor writes nothing.
4. **The default from Polylang:** `AddressSettingsStep` takes `general.language` from Polylang's `default_lang` when Polylang is active. Test first: the fixture's default is Polylang's, not WordPress's locale, and its default-language posts have unprefixed addresses.
5. **Language at creation:** content and terms are created with their language (null for the default); every one, linked or not. Tests first: the unlinked post answers at its old `/xx/slug/` address with status 200, not a redirect; the `-it` term keeps its slug.
6. **Groups by union at the end:** `PolylangGroups::groups()` exactly as 6.1h's Task L2 writes it, then one `translation_group` per group through `LinkTranslations`, posts and terms apart, in batches of 500 groups. Skips and reports a group with two items in one language or of mixed types. Tests: 6.1h L2's five cases, plus the broken map joining.
7. **Front pages per language:** each language's front page is the group's version in it. Test on the fixture.
8. **Menus per language and switcher items:** menus read from Polylang's menu assignments, imported with their language and bound to their slot per language; each switcher item becomes a `languages` item, unknown options dropped and reported. Tests first.
9. **String translations:** site title, tagline and title templates per language through ML-1's word-settings Operation; others reported. Test first.
10. **The analyze step's messages:** subdomain or domain mode, language-from-content mode (stops), MultilingualPress and TranslatePress (unsupported). Tests first.
11. **Gate:** `composer check`; the import's browser tests you touched, after `npm run build`.

## The companion change (spec for `github.com/temecms/teme-companion`)

Write `docs/specs/companion-languages.md` with this content, refined as needed, for the companion's maintainers:

- **Route:** `GET /wp-json/teme/v1/languages`, administrators only, HTTPS only, the same authentication as the other export routes. Bumps the companion's API version.
- **Answer (JSON, never serialized PHP):** `active` (bool, Polylang active), `plugin_version`, `default` (Polylang slug), `languages` (in order: `slug`, `locale`, `name`, `rtl`, `order`), `options` (`force_lang`, `hide_default`, `rewrite`, `redirect_lang`, `browser`, `media_support`), `post_types`, `taxonomies`, `menus` (theme → location → language slug → menu ID), `strings` (per language slug: `{ "name": ..., "translation": ... }` pairs from Polylang's string translations).
- **When Polylang is absent:** `{ "active": false }`.
- Teme reads per-item `language` and `translations` from the existing content and terms answers, which already carry them.

## Quick check

`php artisan test --compact tests/Feature/Import/Translations tests/Feature/Import/Addresses` (and any import test file you changed), and `vendor/bin/pint --test` on changed files. `composer check` once at the end.

## Records (the builder's)

### Setup

- `cat /tmp/teme-cloud-setup.log`: `2026-11-15T15:23:40Z ran in /home/user/teme as root`, `2026-11-15T15:35:26Z finished`. `pwd`: `/home/user/teme`.
- PHP was 8.3 and PostgreSQL and Valkey were down when the session started, so `bash scripts/cloud-setup.sh` ran once more; after it PHP 8.4.27, PostgreSQL accepting connections, Valkey answering.
- **Base:** ML-1's first commit was not on `main`, so this branch is `origin/main` (0de97bd) merged with `origin/import/v1-fixes` (76f8eb9, "docs(plans): Import fixes's records", commit 0785da7) and `origin/i18n/readers` at 6205059 ("feat(languages): menus and word settings carry a language", commit 8675eef). Both merges were clean.

### Commits

- a3acd97 feat(import): read the old site's Polylang languages through the companion
- a0a06d1 feat(import): create items and terms in their Polylang language
- 426c102 feat(import): link Polylang's translations by union, posts and terms apart
- 6cc9dd9 feat(import): import menus per language with their language switchers
- a22493e feat(import): copy Polylang's string translations of the site's words
- 2bb9bc6 feat(import): add Polylang's languages before anything is written
- 3a801af feat(import): say what the analysis can't bring of a site's languages

### Tests seen red

The code was written ahead of its tests while the base check held the test database (see B-?? (draft) 9), so each test was seen red by taking its implementation away, on a second test database:

- With the phases unwired (every tracked `app/` change stashed: the provider, the content, terms and menus lines, the analysis), 14 tests failed, each for the plan's reason: the languages weren't added (`'es'` expected, `others()` was `[]`); the Italian post had no language (`null` for `'it'`); no translation group (`null`); `/es/` answered 301, not 200; the Spanish menu had no language; no Spanish site title or tagline (size 0); a from-content site wasn't stopped (`Finished`, not `Stopped`); no `languages.not-offered` message; the category stayed translated; and the four analysis notes were missing.
- `PolylangGroupsTest`'s five cases failed against a `groups()` that returns `[]`.
- The three "writes nothing for someone who may not import" tests failed with the Operations' import check removed (no `AuthorizationException`).
- `PolylangLanguagesTest` was written with the reader and was green from its first run.

### Changed lines in shared files

- `app/Domain/Import/Phases/Addresses/AddressSettingsStep.php`: reads the Polylang answer afresh, and gives `WordPressSettings::early()` Polylang's default as the locale when Polylang is active and Teme offers it.
- `app/Domain/Import/Phases/Content/ContentPhase.php`: `create()` works out the item's language (`ImportedLanguages::stored()`), skipping an item whose language this site lacks (`post.language-missing`); `decide()` takes the language and puts it in `ImportedItem`; `again()` passes the item's own; `freeSlug()` asks for a free slug in that language and checks the path after the prefix.
- `app/Domain/Import/Phases/Terms/TermsPhase.php`: the term's language (a new term's from Polylang, an existing one's own), skipping one whose language this site lacks (`term.language-missing`); the slug holder and `TermSlugs::unique` per language; `language:` passed to `ImportTermInput`.
- `app/Domain/Import/Phases/Menus/WordPressMenus.php`, `MenuNode.php`, `MenuTargets.php`, `MenuShape.php`: a `#pll_switcher` item becomes a `languages` item with the options Teme has (`dropdown`, `show_names`, `hide_current`), the others reported when on (`menu.switcher-options`).
- `app/Domain/Import/Phases/Menus/MenusPhase.php`: passes the menu's language (from Polylang's menu places) to `ImportNavigationInput`.
- `app/Domain/Import/Phases/Menus/MenuPlacesStep.php`: after the default language's places, each other language's menu is bound to its place in that language (`placeLanguages()`), only menus this run made, never over one chosen here.
- `app/Domain/Import/Planning/ImportAnalysis.php` and `SiteAnalysis.php` (the analyze step): the analysis reads the `languages` answer too and adds its notes (`withStays()`).
- `app/Domain/Contents/ImportedItem.php`, `ImportedContents.php`, `Operations/ImportContent.php`, `app/Domain/Taxonomy/Operations/ImportTerm.php` and `Input/ImportTermInput.php`, `app/Domain/Addresses/Paths.php`: a last optional `language` (see B-?? (draft) 2).
- `app/Providers/ImportServiceProvider.php`: three tag lines (`LanguagesStep` after `AddressSettingsStep`; `TranslationsStep`, `LanguageWordsStep` after `SiteSettingsStep`).
- `tests/Feature/Import/ImportAcceptanceTest.php`: three rows in `accSteps()` for the new steps.
- `tests/Fixtures/Import/OldSite.php`: one line, the `languages` answer of a site without Polylang.
- `tests/Fixtures/Import/FakeCompanion.php` is unchanged: the `languages` answer is a fixture file, `tests/Fixtures/Import/Companion/languages.json`, which the fake already serves by route name.

### Decisions

- **B-?? (draft) 1, Operations the import may run.** Tasks 3, 6, 8 and 9 name `AddSiteLanguage`, `ChangeTranslatedTypes`, `LinkTranslations`, `BindNavigationSlot` and ML-1's `TranslateSettings`; each needs a person who may manage languages or settings, so an import run by the operator, or by someone who may only run imports, is refused. Options: widen those Operations' checks (another part's files, and a permission change), or add import Operations that check as starting an import does, as `ImportSettings` and 6.1h's D23 (`LinkImportedTranslations`, "as starting an import") do. Chose the import Operations: `ImportSiteLanguages`, `LinkImportedTranslations`, `ImportTranslatedSettings`. Each locks, validates and writes as its person-facing twin does, and each has a "writes nothing when refused" test. Commits 2bb9bc6, 426c102, 6cc9dd9.
- **B-?? (draft) 2, language at creation outside the import's own files.** Creating an item or a term in its language needs `ImportedItem`, `ImportedContents`, `ImportContent`, `ImportTerm`/`ImportTermInput` and `Paths::conflict()` to know it. Options: create in the default language and move afterwards (6.1h's approach, which the plan drops), or one last optional `?string $language = null` on each. Chose the optional parameter: no caller changes, and no other part owns those files now. Commit a0a06d1.
- **B-?? (draft) 3, Japanese.** `config/languages.php` doesn't offer `ja` yet (ML-1's task 5). The fixture adds it to the config when missing; a language Teme doesn't offer is reported (`languages.not-offered`) and its items and terms are left (tested with `tlh`). Commit a3acd97.
- **B-?? (draft) 4, en_US.** The plan maps `en_US` to `en`; `WordPressSettings::language()` gives `en-US`. Followed the plan: a locale that is its language's main country (`og` in config) becomes the language alone. Commit a3acd97.
- **B-?? (draft) 5, title templates.** Yoast's translated templates are turned into Teme's tokens one part at a time; a template with a part Teme has no token for is not copied and is counted with the strings left. Commit a22493e.
- **B-?? (draft) 6, words never replaced.** `ImportTranslatedSettings` keeps a word this site already has in a language when it differs, and says so (`language-words.kept-here`, `menu-places.language-kept`), so a person's translation or chosen menu is never overwritten by a later run. Commit 6cc9dd9.
- **B-?? (draft) 7, menus' theme.** Polylang keeps menu places for every theme the site has had. A menu's language is read from any theme's places; the places bound are those of the theme whose default-language menus match WordPress's current locations. Commit 6cc9dd9.
- **B-?? (draft) 8, front pages.** Task 7 falls out of linking: `LinkImportedTranslations` moves a front page's version to its language's home (`MovesVersionAddresses`). Commit 426c102.
- **B-?? (draft) 9, tests after code.** The base check held the one test database for half an hour while the code was being written, so the tests were written after the code and seen red by taking the code away (above), on a second database (`teme_test_ml2`).
- **B-?? (draft) 10, older companions and other plugins.** A companion without the `languages` route (404 `rest_no_route`) reads as a site without Polylang. The spec gains `others`, the other translation plugins active (TranslatePress, MultilingualPress), which the analysis lists as not imported. Commits a3acd97, 3a801af.
- **B-?? (draft) 11, the default language's addresses.** Polylang's default language has no prefix here (Polylang's `hide_default`), as Teme's default never has one. A site that showed `/en/` for its default (Polylang's `hide_default` off) isn't covered by a test: its default-language items take the addresses WordPress reports, and that case is left for a later part.

### I-?? drafts and open items

- **I-?? (draft):** a language's home page gets two false notes during the import: `page.slug-suffixed` ("another page had the name es") from `PlaceImportedPage`, which reads the slug from the old path `/es/`, and `post.old-addresses-taken`, because `/es/` is the language's own home. The page ends at `/es/` all the same. The fix belongs in `PlaceImportedPage` and the content phase's old addresses, which this part doesn't own.
- **I-?? (draft):** `TranslationsStep` reads every map row of its kind again for each batch of 500 groups (one cursor statement each time): linear per batch, so quadratic over a very large site. Keeping the groups between jobs would need a place to keep them.
- **Open:** `PluginSettingsReadTest`'s "hands a plugin nothing that writes" fails on the base: i18n/readers added `SettingsReader::translationsKey()`, which the test's list of allowed statics doesn't name. Not this part's.
- **Contract changes:** none in `packages/teme-contracts`. The companion spec, `docs/specs/companion-languages.md`, is new.

### Totals

- Base (8675eef, clean): Pint and PHPStan pass; Architecture 139 passed (1310 assertions); Unit and Feature 1 failed, 2 skipped, 10645 passed (54919 assertions). The one failure is PluginSettingsReadTest (open item above).
- This branch: Pint and PHPStan pass; Architecture 139 passed (1310 assertions); Unit and Feature 1 failed, 2 skipped, 10677 passed (55030 assertions): the same one failure, and 32 new tests passing.
- Baselines: no baseline count changed.

### How to try it

On a WordPress site with Polylang and the companion's `languages` route (the spec above), connect it in Tools › Import. The analysis says if Polylang sets languages from the content or by host, and names TranslatePress or MultilingualPress. Run the import: Settings › Languages lists Polylang's languages with its slugs as prefixes; `/es/` shows the Spanish home page; an old `/it/…` address answers 200; posts' Translations link their versions; Content › Navigation shows the Spanish menu in the main place for Spanish, with its language switcher; Settings › General in Spanish shows the old site's Spanish title and tagline. The tests: `php artisan test --compact tests/Feature/Import/Translations`.

### Changelog lines for the maintainer

- Added: importing a WordPress site that uses Polylang brings every language, with Polylang's slugs as address prefixes, every post, page and term in its language at the address it had, translations linked, each language's home page, menus per language with their language switcher, and the site's title, tagline and title templates in each language.
- Added: the import's analysis says when Polylang set languages from the content (the import stops), gave each language a host of its own, or when TranslatePress or MultilingualPress translates the site.

### Follow-up R7

The review's seven findings and its B11 note, each fixed with its test written first and seen red. The services had stopped since the first run, so `bash scripts/cloud-setup.sh` ran once more before the tests; it changed no tracked file.

- **1, an unreadable answer read as no Polylang** (6f7e7b1). `ImportedLanguages::for()` no longer catches a refusal: a step waits for the site or stops as any read does. The phases that import one object at a time (content, terms, menus) call `forObjects()`, which stops the run with the refusal's reason, so no object is written in the wrong language. Red: `for()` with nothing kept and a 500 answered `none()` instead of throwing; an import whose kept answer was gone by the content phase, and whose site then answered 500, finished with every item in the default language (`Finished`, not `Stopped`).
- **2, the from-content stop came after settings were written** (c858be0). The stop moved into `AddressSettingsStep`, before `ImportSettings`, and left `LanguagesStep`. Red: the test now also compares every settings group before and after, and `general` had gained `timezone` (version 1).
- **3, Yoast's item parts all became `{title}`** (92be581). Every template keeps the shared parts; an item's template turns `%%date%%`, `%%name%%`, `%%category%%`/`%%primary_category%%`, `%%pt_single%%`/`%%pt_plural%%` and `%%currentyear%%` into `{date}`, `{author}`, `{term}`, `{type}` and `{year}`; an archive's, whose page fills only `{title}` with what it lists, turns `%%term_title%%`, `%%name%%`, `%%date%%` and `%%pt_plural%%` into `{title}`. `%%currentyear%%` in an archive's template has no token there and is counted with the strings left. Here `{year}` is the year the item was published, not the current year. Red: `title-post` gave `{title} {sep} {title} {sep} {title}`.
- **4, the switcher constants split EMAIL from its docblock** (4d4353f). Moved below `EMAIL`. Only the layout of the code changed, so there is no test.
- **5, two old languages that become one here** (cce2c43). `LanguagesStep` says so (`languages.same-tag`, with both prefixes); the first keeps its prefix. Red: an extra `es_MX` language (Teme has no `es-MX`) left no message.
- **6, validation words outside `__()`** (a928c83). The three now go through `__()`. Red: with each sentence translated in the test, each refusal still said the English.
- **7, groups read again for each batch** (1d58383). Fixed rather than left as a draft: `TranslationsStep` keeps a run's groups of each kind in the cache for its later jobs, and reads the maps again whenever they aren't kept (I-261). Red: three jobs of one run read the maps three times; now once. The I-?? draft about this is withdrawn.
- **B11, `hide_default` off** (c402ee3). The analysis says when Polylang showed the default language under its own prefix (`languages.default-prefixed`). Red: no note for `hide_default: false`.

**Totals** (`composer check` on c402ee3): Pint and PHPStan pass; Architecture 139 passed (1310 assertions); Unit and Feature 1 failed, 2 skipped, 10685 passed (55049 assertions). That is 8 more passing than before R7, and the same one failure as on the base (`PluginSettingsReadTest`, i18n/readers' `translationsKey()`). No baseline count changed.

**Changelog line for the maintainer, added:** the import's analysis also says when Polylang showed the default language under a prefix of its own.
