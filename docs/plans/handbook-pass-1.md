# Plan: handbook pass 1, and a public testing kit

**One part, one branch: `docs/handbook-pass-1`, from `origin/main`.** Built side by side with "plugin sockets, round 1" (form POST, feed links, settings write, root files, the indexable flag, an admin action, a data panel, URL-setting hosts, `SiteInfo`, `FeedsChanged`, job delay and unique, issue #334). Proposal: `docs/proposals/handbook-pass-1.md` (its Settled section is binding). **Bound by:** I-569 (lighter plan), I-3439 (American English), I-432 and I-3190 (the `teme/testing` kit), I-3465 (an outside author tests locally), I-3466 (first-party plugins in `plugins/`), I-3442 (a plugin's own tables), I-3445 and I-3449 (jobs, schedules and who they run as), I-3353 (the loader and its doors), I-3440 (plugin word files, parked).

**What it answers:** entries H2 to H16 of the findings an outside developer wrote while building the Pings plugin from the handbook alone. H1 is void: `start/development-site.md`, `start/code-standards.md` and `rules/plugins-and-themes.md` are on `main`.

**Read first:** `AGENTS.md`; `.ai/rules/index.md`, `.ai/rules/builders.md`, `.ai/rules/tests.md`, `.ai/rules/contracts.md`; `docs/architecture/documentation.md`; `docs/architecture/plugins.md`; every page under `docs/guides/developers/`; `docs/plans/developer-handbook.md` (how the handbook and its tests were built); `tests/Feature/Guides/Developers/**`, `tests/Fixtures/Guides/DeveloperGuide.php`, `tests/Fixtures/Guides/GuideFile.php`; `app/Console/Commands/GenerateHooksReference.php` and its test (the pattern for the new generator); `app/Console/Commands/GenerateManifestReference.php`, `GenerateHandbookIndex.php`; `tests/TestCase.php`; `tests/Fixtures/Plugins/PluginFixtures.php`; the fixture plugins `tests/Fixtures/plugins/{hello,jobs,inbound,tables}`.

## Before the builder starts (the maintainers)

**M-0.** Builders never change `composer.json`. If the proposal's Q1 is answered as recommended, the maintainers add one line to `composer.json`'s `autoload-dev.psr-4`, `"Teme\\Testing\\": "packages/teme-testing/src/"`, run `composer dump-autoload`, and commit it on `docs/handbook-pass-1` as `build(testing): autoload the testing kit for development`. No lock change follows (autoload isn't part of the lock's hash), and no `composer update` runs, so `boost:update` doesn't rewrite the agent files. If Q1 is answered otherwise, the maintainers replace this step and Task 3's first line before the builder starts.

## Owns

- New: `packages/teme-testing/**` (`composer.json` named `teme/testing`, `src/**`), `app/Console/Commands/GenerateContractsReference.php` (`teme:docs:contracts`), `docs/reference/contracts.md` (written by the command), `docs/guides/developers/plugins/tables.md`, `docs/guides/developers/examples/plugins/first-plugin/tests/FirstPluginTest.php`, `tests/Feature/Testing/**`, `tests/Feature/Console/GenerateContractsReferenceTest.php`.
- Rewritten: `docs/guides/developers/plugins/{jobs,public-routes}.md`, `docs/guides/developers/testing.md`; and, if Q5 is answered yes, `plugins/{content-write,import}.md`.
- Changed: `docs/guides/developers/{README.md,for-agents.md,concepts/manifest.md,concepts/hooks.md,concepts/operations.md,start/first-plugin.md,start/development-site.md,start/code-standards.md,rules/plugins-and-themes.md,llms.txt}`; `tests/Feature/Guides/Developers/{ExamplesTest.php,DeveloperPagesTest.php}`; `tests/Feature/Console/GenerateManifestReferenceTest.php`; `tests/Fixtures/Guides/DeveloperGuide.php`; `phpunit.xml` (one `<directory>` line in the Feature suite); `docs/reference/plugin-manifest.md` (regenerated).
- **Contracts, docblocks and schema only:** `packages/teme-contracts/schema/teme-plugin.schema.json` (descriptions; the route keys core already reads, Task 2); a missing `@since` added where none exists (Task 1). No PHP signature in `packages/teme-contracts` changes.
- **Shared with "plugin sockets, round 1", additions only:** `GenerateHandbookIndex::PAGES`, the handbook README's tables, `teme-plugin.schema.json`. Never edit or remove that part's lines. Whichever part is joined second regenerates `docs/reference/contracts.md`, `docs/reference/plugin-manifest.md`, `docs/reference/hooks.md` and `llms.txt` at the join. That part adds its own sections to `jobs.md`, `public-routes.md` and `settings.md` for its sockets; this part writes what is there today.
- **Never changed:** anything under `app/Domain/**` (read only: a wrong behavior found while documenting is a `B-?? (draft)`, never fixed here), `composer.json`, `composer.lock`, `CHANGELOG.md`, `docs/roadmap.md`, `docs/architecture/*.md`, routes, migrations.

## Names

- **Command:** `teme:docs:contracts {--output=} {--source=} {--namespace=}`, class `GenerateContractsReference`, output `docs/reference/contracts.md`. Options as `teme:docs:hooks` takes them.
- **Kit:** package `teme/testing`, folder `packages/teme-testing`, namespace `Teme\Testing`. Classes:
  - `TemeTestCase` (abstract, extends `Illuminate\Foundation\Testing\TestCase`, uses `RefreshDatabase` and `InteractsWithTeme`): boots the Teme checkout the kit sits in (`bootstrap/app.php`), then does what `Tests\TestCase::setUp()` does for core (no Vite, the setup hold off, stray HTTP refused, the database-trait guard), seeds the roles, and sets `teme.version` (see "The Teme version" below). A plugin's test file starts with `uses(Teme\Testing\TemeTestCase::class);`.
  - `InteractsWithTeme` (trait, for an author with their own base case): every method below.
  - `Person` (readonly: `id`, `name`, `email`, `role`).
  - `PluginUnderTest`, `ThemeUnderTest`, `FakeOutbound`, `SentRequest` (readonly: `method`, `url`, `host`, `headers`, `body`, plus `json()` and `form()` that decode the body), `JobRun` (readonly: `id`, `state` one of `finished`, `failed`, `cancelled`, `queued`, `message`).
- **Kit methods** (all on `InteractsWithTeme` unless named on a class):

  | Method | What it does, through which part of Teme |
  |---|---|
  | `person(string $role = 'editor'): Person` | A person with that role (the roles `RoleSeeder` makes) |
  | `plugin(string $nameOrFolder): PluginUnderTest` | A folder holding `teme-plugin.json` is added to `teme.plugin_paths`; otherwise the name is found as Composer installed it. Fails the test with the registry's problems when it isn't found or can't be used |
  | `PluginUnderTest::enable(?Person $as = null): PluginUnderTest` | `EnablePlugin` as `$as` (a new administrator when null), agreeing to exactly what the manifest asks, with that person's password freshly confirmed; runs its migrations as enable does; then starts the enabled plugins as the next request would (`PluginLoader::load()`). Fails the test with the refusal |
  | `PluginUnderTest::disable(?Person $as = null)` | `DisablePlugin` |
  | `PluginUnderTest::changeSettings(array $values, ?Person $as = null)` and `changeSettings(string $group, array $values, ?Person $as = null)` | `UpdateSettings` on `plugin.<code>`, or on any group, with the group's current version; secrets go in the input's secret slot, never as plain values |
  | `PluginUnderTest::runJob(string $key, array $payload = []): JobRun` | Queues through the plugin's own `Jobs` door as the person who enabled it; on the test's `sync` queue it runs at once; answers the tracked job's state and message |
  | `PluginUnderTest::runSchedules(?DateTimeInterface $at = null): int` | One pass of the due-schedules runner at that time; answers how many jobs were queued |
  | `draft(string $title, string $type = 'post', ?Person $by = null): ItemRef`, `publish(string\|ItemRef $titleOrItem, string $type = 'post', ?Person $by = null): ItemRef`, `unpublish(ItemRef $item, ?Person $by = null): ItemRef`, `trash(ItemRef $item, ?Person $by = null): ItemRef` | `CreateDraft`, `PublishRevision`, `UnpublishContent`, `TrashContent`, as an editor when `$by` is null; answer `Teme\Contracts\Content\ItemRef` built as hooks build it (`Payloads::item()`) |
  | `outbound(): FakeOutbound` | The fake outbound door (see below) |
  | `FakeOutbound::answer(string $host, int $status = 200, string\|array $body = '', array $headers = [])`, `answerInTurn(string $host, list<int> $statuses)`, `sent(?string $host = null): list<SentRequest>`, `assertSent(Closure $match, int $times = 1)`, `assertNothingSent()` | |
  | `visit(string $path, string $method = 'GET', array $headers = [], string $body = ''): TestResponse` | One request for that exact path through the HTTP kernel, as `addrGet()` does in core's tests (the test client would trim a trailing slash) |
  | `theme(string $codeOrFolder): ThemeUnderTest`; `ThemeUnderTest::check(): list<string>` (the checker's problems, empty when it passes), `activate(?Person $as = null)` | `teme.theme_paths` for a folder; `teme:theme:check`; `ActivateTheme` |

## Decisions written out

- **The kit changes nothing except through Operations.** No method writes a table, binds `OperatorElevation`, or reaches past a check the admin would meet. An editor who tries `enable()` is refused exactly as in the admin. The kit reads what it must (a group's version, a tracked job's state) through core's own query classes, never raw SQL.
- **The fake outbound door fakes the HTTP client, not the door.** `outbound()` binds a `HostResolver` that answers a public address (from the documentation range, as `FakeResolver` in core's tests) for every host, and calls `Http::fake()` with a callback that answers what `answer()`/`answerInTurn()` set (200 with an empty body otherwise) and records a `SentRequest`. The door's own checks still run: a host the manifest didn't declare is refused with `HostRefused`, as in production. Because the fake sits below the door, a door method another part adds later (a form POST) is recorded with no change to the kit. Without `outbound()`, any outbound call fails the test, as core's `TestCase` already arranges, and no DNS lookup leaves the machine.
- **A person's confirmed password:** `enable()`, `disable()` and `changeSettings()` on a sensitive group act as `$as` with core's `SessionElevation`, over a request whose session holds `auth.password_confirmed_at` set to now and whose user is `$as`. Never `OperatorElevation`: that is the operator, and would record no person, which is exactly why schedules don't run (issue #334).
- **The Teme version:** see the proposal's Q2. As recommended, `TemeTestCase` sets `teme.version` to `TEME_VERSION` when it is a release, and otherwise to the highest `@since` in this checkout's `packages/teme-contracts/src` (a checkout that offers a contract `@since 0.9.0` is at least 0.9.0). `contracts.md` prints that version in its first lines, so `start/development-site.md` can tell people what to put in `TEME_VERSION` for the development site.
- **The constructor table is computed, not written.** `teme:docs:contracts` asks `PluginServices` (the bound `PluginDoors`) for a plugin with no capability, then with each `Capability` case alone (hosts `['example.com']`), and records which contracts each adds: "always" for the first set, the capability for the rest. Every class in `PluginServices::OFFERED` must appear exactly once; one that never appears, or a contract offered that `OFFERED` doesn't list, fails the command naming it.
- **The contracts page shape:** a heading and the do-not-edit line as in `hooks.md`; "This checkout offers contracts up to **X**" (the highest `@since`); the constructor table (contract, given when, since); then one section per namespace under `Teme\Contracts`, in name order, with each interface, class and enum: its kind, the first paragraph of its docblock, its `@since`; then each public method as `name(parameters): return`, the first sentence of its docblock and its `@since`; each public constant and enum case with its `@since`. A hook class gets one line linking its section in `hooks.md` instead of its methods. A public type, method or constant without `@since` fails the command, naming each.
- **Schema wording is public.** Every description in `teme-plugin.schema.json` that a builder touches drops internal labels (`(I-3445)`, `(slice 5.2)`): the generated reference is read by outside developers.
- **The example plugin's test lives in the example package**, `examples/plugins/first-plugin/tests/FirstPluginTest.php`, written as an outside author would (`uses(Teme\Testing\TemeTestCase::class);`, the kit only, nothing from `App\` or `Tests\`), and runs in core's suite through one `<directory>` line in `phpunit.xml`'s Feature suite (Q6). `testing.md` quotes it.

## Where each finding is answered

Each page states what `main` does today, from the code named. No page says a thing "lands later" or "until then". Where the sibling part will change a rule, the page states today's rule and links its issue; that part rewrites the paragraph when it lands.

| Finding | Where | Read it from |
|---|---|---|
| H2 jobs, public routes out of date | `plugins/jobs.md`, `plugins/public-routes.md` rewritten as how-to pages; the stale line in `concepts/manifest.md` ("land with v1; joined on…") removed, and `concepts/operations.md`'s "When plugins gain content-write abilities" made present tense | below |
| H3 what a constructor may ask for | generated table in `contracts.md`; one paragraph and a link in `start/first-plugin.md` §2 and `concepts/operations.md` | `PluginServices::OFFERED`, `PluginDoors::for()`, `PluginLoader::build()` (a constructor asking for anything else is refused) |
| H4 the settings group | already in `plugins/settings.md`; one sentence and a link added to `concepts/manifest.md` | `DeclaredSettings` |
| H5 a plugin's own tables | new `plugins/tables.md`; `concepts/operations.md`'s "does not open the database" paragraph made exact | `PluginMigrations`, `MigratePlugin`, `RemovePluginData`, `teme:plugins:migrate`, `teme:plugins:remove-data`, `PluginLoader::skipReason()` (waiting migrations stop the start), `docs/architecture/plugins.md` › Plugin data; Q4 for reading and writing them |
| H6 route rules and keys | `plugins/public-routes.md`; schema (Task 2) | `DeclaredRoute`, `RouteCollisions` (reserved prefixes, core `.well-known` names, core addresses, unconditional paths only under `/{code}/` or `/.well-known/`, a POST needs `verify`, `secret` names a declared `secret` setting, `hmac` needs `header`, a cache key must carry the condition, collisions), `RouteVerifier` and its three verifiers, `RouteDispatcher` (handler built with no constructor arguments, 30 seconds, body cap 1 MB by default and 10 MB at most, a throw answers the site's error page and is reported), `routes/public.php` (the order addresses are tried) |
| H7 job keys and retries | `plugins/jobs.md`; schema | `DeclaredJobs` (defaults: tries 3, backoff 30/120/600), `RunPluginJob` (timeout 75 s, 1,800 s when long; a long job's outbound ceiling 300 s; a throw is reported and retried; the last failure marks the tracked job failed with the first line of the message, at most 200 characters; `JobStopped` ends it quietly; cancelled under safe mode or once disabled), `PluginJobs` (payload JSON-ready with string keys, at most 64 KB; who queues: the signed-in person, else the operator, else refused, so a public route handler with no one signed in can't queue), the `sync` queue in tests and on a small site |
| H8 schedules | `plugins/jobs.md` § Schedules | `DueSchedules` (`every` counts from the last queued run recorded on the plugin's row; the first is due at once; the runner is `teme:plugins:run-due-schedules`, every minute from `routes/console.php`, so Laravel's scheduler must run), runs as the administrator who enabled it with that person's permissions; enabled from the console nobody is recorded and schedules are skipped without a word: say so and link issue #334 |
| H9, H10 testing | the kit; `testing.md` rewritten | Tasks 3 and 4 |
| H11 installing a plugin you develop | `start/development-site.md` § Your plugin through Composer; one line in `start/first-plugin.md` §4 | `TEME_PLUGIN_PATHS`; a Composer path repository (`"type": "path"`, `"options": {"symlink": true}`, required as `"@dev"`); a package Composer installed is found with no path; the manifest's `version` is the one Teme reads (Q7 for composer.json's); if `plugins/` exists on `main` when you build, one paragraph on Teme's own plugins (I-3466), otherwise record a B-?? and leave it out |
| H12 translation | `rules/plugins-and-themes.md` § Words and `start/code-standards.md` | Today nothing loads a plugin's `lang` folder (only the active theme's, `ThemeWords`). Say so plainly; correct the rules page's sentence that tells plugins to keep translations in their `lang` folder "as themes do"; keep `__()` with English sentences as keys. Draft the gap issue in the records |
| H13 feed addresses | `plugins/public-routes.md` § Addresses Teme answers itself | `routes/public.php`, `Paths::RESERVED`, `Paths::SITE_ROUTES`, `FeedPath`, `FeedResolver`: the main feed, a type's archive feed, category, tag and author feeds, the WordPress spellings |
| H14 which `requires.teme` | already in `concepts/hooks.md`; one line in `concepts/manifest.md` pointing to the highest Since in `contracts.md` | |
| H15 a listener that throws | `concepts/hooks.md` § When a listener fails; one paragraph each in `jobs.md` and `public-routes.md` | `HookDispatcher` (Notification: recorded, the rest go on; Transform and Collect: skipped, unless core marked the hook `Failure::FailOperation`, then the change fails; Gate: refuses, "couldn't check this, so it was stopped to be safe"; Resolve: answers nothing), `PluginLoader::start()` (a plugin that fails to start is skipped for that process and its subscriptions removed), `SpooledFaults` (each is reported to the log with plugin, subscription and hook; **nothing counts faults or quarantines a plugin yet**: say so; the `faults` and `quarantine` columns are not described to readers), a job's exception on a `sync` queue reaching the code that queued it (prove it in Task 3 before writing it) |
| H16 contracts reference | `teme:docs:contracts`, linked from the README's Generated reference table and `for-agents.md` | Task 1 |

## Tasks (tests first; each seen red for the reason given)

### Task 1 — the contracts reference, generated, with its drift check
- Tests, `tests/Feature/Console/GenerateContractsReferenceTest.php`: every public interface, class and enum under `packages/teme-contracts/src` has a section (count equals the files); the constructor table lists each `PluginServices::OFFERED` class once, with the capability `PluginDoors` really gives it for (assert two rows by value: `SettingsReader` always, `OutboundHttp` with `http`); a fixture type under `--source` with no `@since` fails naming it, and so does a fixture method without one; the committed `docs/reference/contracts.md` equals what the command writes now. Red: the command doesn't exist.
- Build the command. Run it on `main`'s contracts. A scan while planning found these with no `@since`: `Operations\Operation` and its `execute()`, `Operations\OperationInput` and its `actorId()`, and possibly the constants of `Import\ImportOutcome` and `Import\WordPressRecord`. Add `@since` only where none exists, docblock only, with the first release tag whose tree holds it (`git log --diff-filter=A --format=%H -- <file>`, then the oldest of `git tag --contains <hash>`); never edit an existing one. List each in the records.
- Add the row to the handbook README's Generated reference table, and one line to `for-agents.md`.

### Task 2 — the manifest schema says what each key does
- Tests, added to `tests/Feature/Console/GenerateManifestReferenceTest.php`: every key under `jobs[]`, `schedules[]` and `routes[]` in the plugin reference has a description; no description in either schema carries an internal label (`I-` followed by digits, or `slice`). Red: `jobs[].tries` has none.
- In `teme-plugin.schema.json`: descriptions for `jobs[].key`, `label`, `long`, `tries`, `backoff`, `schedules[].job`, `every`, the four existing route keys, and `owns.tables` (the prefix rule); declare the route keys core already reads, `when` (`query`, `accept`), `verify` (`kind`, `secret`, `header`, and what `custom` takes), `cache` (`lifetime`, `query`, `varyAccept`), `maxBody`, `throttle`, `where`, each with the type and limits `DeclaredRoute` and `RouteCollisions` accept, so no manifest that loads today is refused. Leave `additionalProperties` as it is. Run `teme:docs:manifest`.
- Also run `php artisan test --parallel --compact tests/Feature/Plugins` and the public-routes tests: nothing they enable may now be refused.

### Task 3 — the testing kit
- Tests first, `tests/Feature/Testing/` (one file per area: plugins, content, settings, jobs, outbound, visits, themes), against the fixture plugins: `plugin()` finds a folder and a Composer-installed name, and fails with the registry's problem for a broken one; `enable()` as an editor is refused and writes nothing; `enable()` with no person records an administrator as the one who agreed, so `runSchedules()` queues the `jobs` fixture's hourly schedule (the case issue #334 misses); `publish()` reaches the `hello` fixture's `ContentPublished` listener and answers an `ItemRef` with its address; `unpublish()` and `trash()` answer it with no address; `changeSettings()` on `plugin.<code>` is read back through `SettingsReader`, and an editor's is refused; `runJob()` answers `finished`, and `failed` with the handler's message for one that throws on its last try; `outbound()` records a request to a declared host, refuses an undeclared one with `HostRefused`, and answers in turn (429, then 200); `visit('/x/')` keeps the slash; `theme()->check()` is empty for `first-theme` and `activate()` changes what `/` draws. Red: `Teme\Testing\TemeTestCase` doesn't exist.
- Build `packages/teme-testing` until green. Its `composer.json` names `teme/testing`, MIT, PHP ^8.4, PSR-4 `Teme\Testing\` → `src/`, no `version`. Every class opens with its docblock (coding standards); no `@since` (that tag is only for `teme/contracts`).
- PHPStan already reads `packages`; the kit passes it, including the project's own rules. If an Architecture test or a PHPStan rule refuses something the kit does, change the kit, never the allow-list; STOP if no change keeps the decisions above.

### Task 4 — the examples use the kit
- Write `examples/plugins/first-plugin/tests/FirstPluginTest.php`: the plugin enables through `plugin(__DIR__.'/..')->enable()`, and a published item is heard (the listener's line holds the item's id). Add the example's `tests` folder to the Feature suite in `phpunit.xml`. Red: before the `phpunit.xml` line, `php artisan test --testsuite=Feature --filter=FirstPlugin` runs no test.
- Rewrite `tests/Feature/Guides/Developers/ExamplesTest.php` with the kit: discovery and manifest checks of the example plugin, and the first theme checked, activated and drawn (`/` and the post's address), with no `App\`, `Database\` or `Tests\` import where the kit can replace it (record any left, and why). Keep its assertions.
- Bump the example's `requires.teme` only if Task 1 shows a contract it uses has a later Since than `^0.8` accepts.

### Task 5 — the pages
- Tests first, in `DeveloperPagesTest`: no page tells the reader something lands later (`/\blands\b|until then|join\/v1/i` outside code), and no page is left with `type: stub` (the README's "Built in" column stays); `DeveloperGuide::contractBasenames()` also knows the kit's classes, so a page may name `TemeTestCase` and an invented `TemeTestKase` fails; the README's Generated reference table lists `contracts.md`. Red: `plugins/jobs.md` says "lands".
- Write, in this order, from the table above: `plugins/jobs.md`, `plugins/public-routes.md`, `plugins/tables.md` (add it to `GenerateHandbookIndex::PAGES` after `plugins/settings.md`, and to the README's reading order, item 4), `concepts/hooks.md` § When a listener fails, `concepts/operations.md`, `concepts/manifest.md`, `start/first-plugin.md`, `start/development-site.md` (Composer path repository; `TEME_VERSION` for the development site, as the proposal's Q2 settles), `rules/plugins-and-themes.md` and `start/code-standards.md` (words), `testing.md`, then `plugins/content-write.md` and `plugins/import.md` if Q5 is yes (from `ContentWriter`, `PluginContentWriter`, `ActingPerson` and I-3449; `WordPressImporter`, `ImportScope`, `RunScope` and the manifest's `imports`).
- **`testing.md`** is a how-to: where tests live (the plugin's own `tests/` folder) and how to run them from the development site (`php artisan test ../my-plugin/tests`); the first line every test file needs; the kit's methods as a table with one line each; one worked test quoted from `FirstPluginTest.php`; faking the outbound door; jobs on the `sync` queue (they run at once, so a retry is a separate `runJob()` or `runSchedules()` call); themes; the database (PostgreSQL from `docker compose up -d`, each test with a fresh database); what the kit promises (Q3); and that the handbook's own examples are tested with it.
- Page rules as `DeveloperPagesTest` enforces them: front matter, `rests_on` paths that exist, every code block over three lines quoted from a real file, no internal labels, plain words, "the maintainers" if anyone is named, American English. Every command and option named exists.

### Task 6 — index, records and the gate
- Run `teme:docs:handbook-index`, `teme:docs:contracts`, `teme:docs:manifest`; commit their output.
- `composer check` once. Fill the Records.

## Quick check

`php artisan test --parallel --compact tests/Feature/Guides/Developers`, then the same for `tests/Feature/Testing`, `tests/Feature/Console/GenerateContractsReferenceTest.php`, `tests/Feature/Console/GenerateManifestReferenceTest.php` and `tests/Feature/Plugins`; plus the gate in `.ai/rules/builders.md`. `composer check` once at the end. No Browser test changes.

## Records (the builder's)

### Commits

### Each test seen red (first failure line)

### Gate

### Contract changes (docblocks and schema only)

### `@since` added where none existed

### Issues drafted for the maintainers to file

### B-?? (draft)

- B-01:
- B-02:
- B-03:
- B-04:
- B-05:
- B-06:
- B-07:
- B-08:
- B-09:
- B-10:
- B-11:
- B-12:
- B-13:
- B-14:
- B-15:
- B-16:
- B-17:
- B-18:
- B-19:
- B-20:

### STOP

### Changelog lines for the maintainers

### How to try it
