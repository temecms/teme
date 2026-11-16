# Proposal: handbook pass 1, and a public testing kit

**Status:** draft for the maintainers. **Plan:** `docs/plans/handbook-pass-1.md` (draft beside this file). **Branch:** `docs/handbook-pass-1`. **Built beside:** "plugin sockets, round 1". **Evidence:** the findings an outside developer wrote while building the first first-party plugin (Pings) from the handbook alone, entries H2 to H16; `docs/studies/2026-11-15-developer-docs-references.md`; the reference systems listed at the end.

## What

1. **The handbook catches up with `main`.** `plugins/jobs.md` and `plugins/public-routes.md` stop being stubs and become real how-to pages. A new page, `plugins/tables.md`, explains a plugin's own tables. Existing pages gain what a builder had to dig out of the code: the settings group `plugin.<code>`, which `requires.teme` to state, what a listener's failure does, the route path rules and every route key, job retries and schedules, installing a plugin you're developing, and where the feeds are. Two wrong sentences are corrected: the manifest page's "land with v1" line, and the rules page's advice to keep a plugin's translations in its `lang` folder, which nothing loads today.
2. **A generated contracts reference.** A new `teme:docs:contracts` writes `docs/reference/contracts.md`: every interface, class and enum in `teme/contracts`, with methods and `@since`, and a table of what a plugin's constructor may ask for and which capability gives it. The table is computed from the doors Teme really hands out, so it can't drift. A drift check keeps the committed page equal to the command's output.
3. **A testing kit, `teme/testing`.** A small package in `packages/teme-testing`, namespace `Teme\Testing`, that lets a plugin or theme author write Pest tests with no core internals: boot Teme with their plugin, enable it as a given administrator, publish, unpublish and trash items, change settings, run the plugin's jobs and schedules, fake the outbound door and assert what was sent, and request a public address. `testing.md` is rewritten around it, and the handbook's own example tests use it.

## Why

The handbook's promise is that a person or an agent can build a plugin from it alone. The first real attempt worked, but only after reading `tests/`, `app/Domain/Plugins` and core's Pest helpers, and it hit one silent failure (schedules never run for a plugin enabled from the console, issue #334). Every gap it found is either a page that says "lands later" for something that has landed, or a fact that lives only in code. The tests an author writes today import `App\` classes and core's global helpers, so they break on any internal change, and they can't run outside a Teme checkout's own `tests/` conventions without knowing them.

## Settled

Built as written once the maintainers accept this proposal.

1. **This pass documents what `main` does today.** No page says a thing "lands later". Where "plugin sockets, round 1" will change a rule (#334 above all), the page states today's rule and links the issue; that part rewrites the paragraph when it lands, and adds its own sections for its new sockets.
2. **Reference stays generated.** The contracts page and the constructor table come from a command, with a drift check, like the hooks and manifest references. A public type, method or constant without `@since` fails the command. The few found without one (`Operation`, `OperationInput`, possibly two import value classes' constants) get the `@since` of the first release that held them, docblock only; no existing `@since` is edited.
3. **The manifest schema describes every key it accepts.** Descriptions for job, schedule and route keys; the route keys core already reads (`when`, `verify`, `cache`, `maxBody`, `throttle`, `where`) declared with the types and limits core accepts, so the generated reference lists them and no manifest that loads today is refused. Internal labels come out of the schema's descriptions.
4. **The kit's home:** `packages/teme-testing`, Composer name `teme/testing`, namespace `Teme\Testing`, for development only: never in the production autoloader, and outside `teme/contracts`, since it drives Teme's own Operations. It follows I-432 and I-3190, and brings forward the testing package I-3465 had placed after v1.
5. **The kit changes nothing except through Operations**, as the person the test names, with that person's password freshly confirmed. An editor who tries to enable a plugin is refused in a test exactly as in the admin. Enabling with no person named records an administrator, so schedules run in tests.
6. **The outbound fake sits below the door,** at the HTTP client, with a fake address lookup. The door's own checks still run (an undeclared host is refused), no lookup leaves the machine, and door methods added later are recorded with no change to the kit.
7. **The kit's names:** `TemeTestCase` (a test file's first line is `uses(Teme\Testing\TemeTestCase::class);`), the trait `InteractsWithTeme` for authors with their own base case, and `person()`, `plugin()` → `enable()`, `disable()`, `changeSettings()`, `runJob()`, `runSchedules()`; `draft()`, `publish()`, `unpublish()`, `trash()` answering `ItemRef`; `outbound()` → `answer()`, `answerInTurn()`, `sent()`, `assertSent()`, `assertNothingSent()`; `visit()`; `theme()` → `check()`, `activate()`. The plan lists their signatures.
8. **The example plugin's test lives in the example package,** written exactly as an outside author would, and core's suite runs it. The handbook's `ExamplesTest` uses the kit for the rest.
9. **Translation is stated as it is:** Teme loads the active theme's word files, not a plugin's. Plugins keep wrapping their words in `__()` with English sentences as keys, which show in English. The gap is filed (it sits beside I-3440).
10. **Failures are stated as they are:** a failing listener, start, route handler or job is reported to the log with the plugin's name; Notification listeners go on, a Gate refuses, a Transform or Collect skips the listener unless core marked the hook to fail the change, a Resolve answers nothing; nothing counts faults or quarantines a plugin yet.
11. Public wording, "the maintainers", American English, no internal labels in what a reader sees.

## Open questions (the maintainers decide)

- **Q1. How the kit is loaded.** (a) *Recommended:* one `autoload-dev` line in `composer.json` mapping `Teme\Testing\` to `packages/teme-testing/src/`, added by the maintainers before the builder starts (builders never edit `composer.json`); no lock change, no `composer update`. The folder still carries its own `composer.json` so it can be published like `teme/contracts` later (I-3460). (b) A path repository plus `require-dev: teme/testing`: closer to a real package, but changes the lock and runs `boost:update` on `composer update` (finding C1). (c) Under `tests/` as `Tests\Testing`: no Composer change, but it reads as core internals, which is what H9 asked to avoid.
- **Q2. Which Teme version a checkout reports.** An untagged checkout reports 0.1.0, so a plugin requiring `^0.8` or `^0.9` can't be enabled on a development site or in a test without `TEME_VERSION`. (a) *Recommended:* the kit uses `TEME_VERSION` when it is a release, else the highest `@since` in the checkout's contracts (a checkout offering a `@since 0.9.0` contract is at least 0.9.0); `contracts.md` prints that version, and the development-site page tells people to set `TEME_VERSION` to it. (b) Require `TEME_VERSION` in every case and say so. Either way, file the wider question: should an untagged build report the version it is building toward?
- **Q3. What the kit promises.** *Recommended:* documented and tested, but not covered by semver before 1.0; a changed kit method gets a changelog line. From 1.0 it follows the contracts' deprecation policy. No `@since` in the kit (the project keeps that tag for `teme/contracts`).
- **Q4. A plugin reading and writing its own tables.** Today nothing in `teme/contracts` covers it; the fixtures and the enable scan accept Laravel's query builder on the plugin's own tables, and the Pings plugin does that. (a) *Recommended:* document it as allowed on the plugin's own tables only, never Teme's, and say plainly that this ties the plugin to Laravel's query builder. (b) Leave it undocumented until a tables door exists in the contracts; authors keep guessing.
- **Q5. The two other stale stubs.** `plugins/content-write.md` and `plugins/import.md` also say "lands with…" although `ContentWriter` and the import socket are on `main`. *Recommended:* fill them in this pass (the plan includes them under Q5), so no stub is left; otherwise the "nothing lands later" test exempts them.
- **Q6. Where core's suite finds the example's test.** *Recommended:* one `<directory>` line in `phpunit.xml`'s Feature suite. The alternative, a `Plugins` suite covering `plugins/*/tests` and the examples, also answers finding C3, but changes `composer check`'s script; better with the first-party plugins' own part.
- **Q7. `version` in a plugin's composer.json.** *Recommended:* leave it out for a package in its own repository (Composer takes versions from tags; the manifest's `version` is the one Teme reads); a package inside Teme's repository sets it equal to the manifest's, as `themes/default` does, which also answers finding C2 for those packages.

## Gaps found while planning (to file as issues, each on its own)

- A plugin's `lang` folder is never loaded (H12; relates to I-3440).
- A public route's handler is built with no constructor arguments, so it gets none of the plugin's doors (no settings reader, no jobs door); it can only read its own tables.
- An untagged checkout reports Teme 0.1.0 (Q2).
- Faults are reported but never counted, and nothing writes `quarantine`; check `docs/architecture/plugins.md` › Not built yet before filing.
- From the findings, outside this part: C1 (`composer update` runs `boost:update`), C2 (`teme/contracts` has no `version`, so the lock records a branch name), C3 (`plugins/*/tests` aren't in the suites, `composer check` or PHPStan's paths).

## Not in this part

Every socket in "plugin sockets, round 1" (W1 to W12), and their pages; a `teme:make:plugin` scaffold (I-432); agent skills; screenshots; a docs site.

## References checked

All in `Reference/` unless labeled outside.

| System | What it gives plugin authors for tests | What Teme takes |
|---|---|---|
| Statamic (`src/Testing/AddonTestCase.php`) | `AddonTestCase` ships inside `statamic/cms`; registers the addon from its own `composer.json`, fakes the installed version, points content stores at the addon's fixtures | Ship the kit with core; the kit settles the version (Q2) |
| Winter and October (`modules/system/tests/.../PluginTestCase.php`) | `PluginTestCase` boots the install's own `bootstrap/app.php`, runs migrations, guesses the plugin from the test's path and instantiates it | The same model: tests run inside a Teme checkout. Not taken: their default to an in-memory SQLite database (PostgreSQL only), and guessing the plugin from the path (an explicit folder is clearer) |
| Craft (`src/test/Craft.php`, `fixtures/`) | A Codeception module in core with `installPlugin()`, `saveElement()`, `deleteElement()`, `expectEvent()`, `assertElementsExist()`, and element fixtures | Actions named in product nouns; a test acts as a person through the real write path |
| Drupal (`KernelTestBase`, `BrowserTestBase`) | Base classes in core; a test lists the modules to enable, with `installSchema()` and `installConfig()`; kernel tests without HTTP, browser tests with it | Enabling is explicit in the test; requests go through the real kernel (`visit()`) |
| Filament (`packages/*/src/Testing`) | Small testing traits per component (`TestsActions`, `TestsRecords`, `TestsFilters`) mixed into Livewire tests | A trait (`InteractsWithTeme`) for authors who keep their own base case |
| EmDash (`docs/.../creating-plugins/testing.mdx`) | `@emdash-cms/plugin-test`, a development dependency; a direct host and a runtime host; fixtures write state without hooks, actions go through the real boundary; declared capabilities and hosts are still enforced in tests | Actions go through Operations and the doors' checks stay on; a direct-invoke level is left for later |
| WordPress (`tests/phpunit/includes`) | `WP_UnitTestCase` with factories (`self::factory()->post->create()`), `go_to()`, and external HTTP blocked unless faked with the `pre_http_request` filter | Stray requests refused by default; one fake at the HTTP layer. Not taken: a library each author fetches separately (outside, from WordPress's public docs and not in `Reference/`: `wp scaffold plugin-tests` and `wp-env` exist to work around exactly that) |
| Wagtail (`WagtailTestUtils`) | A mixin with login and admin helpers (keyword scan only) | Confirms the trait shape |
| Payload | Its plugin template ships a test suite and CI (from the developer-docs study) | The example package carries its own test |
| Twill, Sharp, TypiCMS, Capell, Acorn and Sage, LaraDashboard, the ArtisanPack packages, Pursae, Ghost, Strapi, Joomla, Pubvana, phprs, WebBlocks, basehim | No plugin test harness found (keyword scan for test-case classes and plugin test helpers) | — |
| Polylang, TranslatePress, MultilingualPress, Yoast SEO, Rank Math, MilliCache, EmDash exporter, React starter kit | Plugins or tools, not systems that host plugins; not relevant | — |
| Outside: Orchestra Testbench | Laravel's package-testing base: boots a skeleton app around a package | Not used: Teme is an application, and an author's tests must run against Teme itself |

## Decided (provisionally by the architect while the maintainers were away, 2026-11-16; to be confirmed)

All open questions as recommended:

- Q1 (a): one `autoload-dev` line maps `Teme\Testing\` to `packages/teme-testing/src/`, added by the maintainers before the builder starts (step M-0).
- Q2 (a): the kit uses TEME_VERSION when it is a release, else the highest @since in the checkout's contracts; the development-site page says to set TEME_VERSION to it. The wider question is filed.
- Q3: documented and tested, not covered by semver before 1.0; a changed kit method gets a changelog line.
- Q4 (a): a plugin may use Laravel's query builder on its own tables only, never Teme's; the page says this ties it to Laravel's query builder.
- Q5: fill content-write.md and import.md in this pass.
- Q6: one directory line in phpunit.xml's Feature suite for the example's test.
- Q7: as recommended.
- The plugin `lang` gap and route handlers' doors are built in "plugin sockets, round 1" (A-W14, A-W13); this pass states today's rule and that part rewrites it.
