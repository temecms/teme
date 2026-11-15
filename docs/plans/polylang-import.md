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

*(Filled in while building: commits; each test seen red; every changed line in shared import files; B-?? decisions with the options weighed; any STOP; changelog lines.)*
