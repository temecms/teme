# Plan: the plugin import socket

**One part, one branch: `import/plugin-socket`, from `origin/main` after `import/v1-fixes` has merged** (that part owns `app/Domain/Import/**` today). Proposal: `docs/proposals/plugin-import-socket.md`, its Settled section binding.

**Bound by:** I-332 (one object, one transaction, one map row), I-349 (import phases are a registry; plugins' work runs inside the import), I-1718 (the reader answers the export API's shapes), I-058 (core ActivityPub will use this route), rule 1 (Operations), rule 4 (no serialized PHP), rule 5 (contracts free of `App\` and `Illuminate`), I-3396 (a wall is a missing socket), I-569 (lighter plan).

## Owns

- `app/Domain/Import/PluginData/**` (new)
- one line registering the new phase where core's phases are tagged (Task 1 names the file)
- `app/Domain/Plugins/`: the manifest validator's `imports` key, the `import.wordpress` capability and its consent wording (line additions only; parts A and B added theirs the same way)
- `packages/teme-contracts/src/Import/**` (new)
- `tests/Feature/Import/PluginData*Test.php`, `tests/Fixtures/plugins/importer/**` (a fixture plugin), and the test companion `tests/Fixtures/Import/FakeCompanion.php` (one new route)
- `docs/architecture/plugins.md` (the `imports` key row and a short section) and `docs/specs/companion-extension-route.md` (new: the companion change)

Nothing else.

## Decisions written out

### The manifest key

```json
"imports": {
    "options": ["activitypub_actor_mode", "activitypub_keypair_for_0"],
    "postTypes": { "ap_actor": ["_activitypub_inbox", "_activitypub_following"] },
    "userMeta": ["activitypub_also_known_as"],
    "termMeta": []
}
```

The validator refuses: an unknown key; a name that isn't a WordPress option or meta key's shape (`/^[A-Za-z0-9_\-:.]{1,191}$/`); the never-sent keys (`user_pass`, `session_tokens`, `_application_passwords`, `wp_user_roles`), each with a sentence naming the key; an `imports` block without the `import.wordpress` capability. More than 50 names in total is refused too ("declare what you need").

### The contract (`packages/teme-contracts/src/Import/`)

```php
interface WordPressImporter
{
    /** Called once per run, with the declared options' values (decoded JSON; an object is null). */
    public function options(array $options, ImportScope $scope): void;

    /** One record: a post of a declared type, a person's declared meta, or a term's. */
    public function record(WordPressRecord $record, ImportScope $scope): ImportOutcome;
}

final readonly class WordPressRecord
{
    public function __construct(
        public string $kind,          // 'post' | 'user' | 'term'
        public int $sourceId,         // WordPress's ID
        public ?string $type,         // post type or taxonomy; null for a person
        public array $fields,         // a post's guid, title, content, status, date, author, parent
        public array $meta,           // only the declared keys, decoded
    ) {}
}

interface ImportScope
{
    /** What an imported WordPress post became in Teme, or null. */
    public function itemFor(int $sourcePostId): ?\Teme\Contracts\Content\ItemRef;
    /** The Teme person ID a WordPress user became, or null. */
    public function personFor(int $sourceUserId): ?string;
    public function isCatchUp(): bool;
}

final readonly class ImportOutcome
{
    public static function imported(): self;
    public static function skipped(string $why): self;
    public static function failed(string $sentence): self;
}
```

The plugin names its importer class in the manifest (`"imports": { "importer": "Vendor\\Plugin\\Importer", ... }`), built through the container with the services its capabilities allow.

### The phase

`PluginDataPhase` (key `plugin-data`, order 850 (P2), runs in full and catch-up runs) is a `ListPhase`. For each enabled plugin holding `import.wordpress`, in code order: one whole read of its options, handed to `options()`; then one paged list per declared post type, then user meta, then term meta, each record handed to `record()` inside `ImportObject`'s transaction, with the map row's source reference `plugin:<code>:<kind>:<type>:<id>` and its hash over the record's fields and meta. Catch-up skips an unchanged hash. `ImportOutcome::failed()` becomes `ImportFailed` (the run carries on); an exception from the plugin is the same, with its message logged and a plain sentence reported.

### The companion route (spec in `docs/specs/companion-extension-route.md`)

`GET teme/v1/extension`, the companion's API version 2 (Teme asks only when the probe answers `api >= 2`; otherwise the phase reports "the companion on this site is too old for plugin data; update it" and skips, without stopping the run):

- `kind=options&names=a,b`: `{ options: { a: <json>, b: <json> } }`
- `kind=posts&type=ap_actor&meta=k1,k2&after_id=0&limit=200`: a keyset page of `{ id, type, status, guid, title, content, date_gmt, author, parent, meta: { k1: [...values] } }`
- `kind=usermeta&keys=k1&after_id=0&limit=500`: `{ id, meta: {...} }` per user
- `kind=termmeta&taxonomy=t&keys=k1&after_id=0&limit=500`: `{ id, taxonomy, meta: {...} }`

It uses the same guard as every route; refuses the never-sent keys; decodes serialized values with `maybe_unserialize` and JSON-encodes them, sending `null` plus `"objects": ["k1"]` for a value holding an object; never reads a table directly.

## Tasks (tests first, each seen failing for the reason given)

1. **Read main.** Confirm with file and line: where phases are tagged; `ImportObject`'s transaction and map row; how `ListPhase` pages and hashes; how a plugin's class is built with its capabilities; the manifest validator's shape. Record differences as `B-?? (draft)`.
2. **Manifest and capability.** Tests: each refusal by its sentence; a valid `imports` block passes; `imports` without the capability is refused; the consent screen lists the declared names. Build.
3. **Contracts.** The four types above, no `App\` or `Illuminate` (the contracts' architecture test). Build.
4. **The test companion route** in `FakeCompanion`, with the never-sent refusal and the object flag.
5. **The phase.** Tests, with the fixture plugin `tests/Fixtures/plugins/importer` (declares one option, one post type with two meta keys, one user meta key; stores what it receives in its own table, 5.2):
   - a full run hands over exactly the declared data, and the fixture's table holds it;
   - undeclared meta is never asked for (assert the fake companion's queries);
   - a catch-up run with nothing changed hands nothing over; with one record changed, hands over that one;
   - one record whose importer fails is reported with its sentence, and the run finishes;
   - a plugin not granted `import.wordpress` gets nothing, and the run writes nothing for it;
   - a disabled plugin's data is not read;
   - a serialized value arrives decoded; an object arrives as null and is reported;
   - a companion on API 1: the phase reports and skips, the run finishes;
   - `itemFor()` returns what an imported post became.
6. **The run report:** a section per plugin with brought, skipped and failed counts. Test.
7. **Docs:** the `imports` row and section in `docs/architecture/plugins.md`; the companion spec file.

**Quick check:** `php artisan test --compact tests/Feature/Import/PluginData* tests/Feature/Plugins` and `vendor/bin/phpstan analyse app/Domain/Import/PluginData packages/teme-contracts/src/Import`.

## Records

### Records (the builder's)

**Setup:** `2026-11-15T15:23:32Z ran in /home/user/teme as root` / `2026-11-15T15:35:43Z finished`; `pwd` `/home/user/teme`. PHP 8.4.27, PostgreSQL on 5433 and Valkey on 6380 answered. Branch `import/plugin-socket` = `origin/import/v1-fixes` (`76f8eb9`, its records commit) merged with `origin/main` (`0de97bd`), no conflict (`4ee51e5`).

**Commits**

1. `44cd033` feat(plugins): declare WordPress imports behind import.wordpress (Task 2)
2. `fa6015b` feat(plugins): WordPressImporter contracts for plugin import data (Task 3)
3. `8a18efe` test(import): extension route in the test companion (Task 4)
4. `8ab9c6e` feat(import): plugin-data phase hands declared WordPress data to plugins (Task 5)
5. `3209d9e` feat(import): a report row for each plugin's data (Task 6)
6. `2bf1bd1` docs(plugins): imports key and the companion's extension route (Task 7)
7. `658c7ef` style(plugins): format the manifest schema's capability list (gate fix)
8. (this) `docs(plans): plugin import socket's records`

**Each test seen red (implementation moved aside), then green**

- Task 2, `PluginDataManifestTest`: 16 failed, first `import.wordpress isn’t something a plugin can ask for.` and each new sentence missing.
- Task 3, `PluginDataContractsTest`: `Class "Teme\Contracts\Import\WordPressImporter" does not exist`.
- Task 4, `PluginDataCompanionTest`: `Call to undefined method Tests\Fixtures\Import\FakeCompanion::wordpress()`.
- Task 5, `PluginDataPhaseTest`: `Target class [App\Domain\Import\PluginData\PluginOptionsStep] does not exist.`; then one test-data fault (a serialized string with a wrong length) fixed in the test. `ImportAcceptanceTest` went red as it should ("a mark for every step"): marks added, B-07.
- Task 6, the report row: `Failed asserting that an array contains Array [...'plugin:importer'...]` (the plugins' records were one `plugin` row).

**`composer check`**

- Before (the base, clean): Architecture 139 passed (1,310 assertions); Unit+Feature 10,621 passed, 2 skipped (54,842 assertions). Pint, PHPStan, types, lint passed. (A first base run was spoiled by new test files written while it ran; it was run again with the work stashed.)
- After: Pint, PHPStan, `types:check`, `npm run check` passed; Architecture 139 passed (1,310); Unit+Feature **10,664 passed, 2 skipped** (54,971 assertions), 0 failed.
- Browser: none run. The plan has no Browser test and no screen changes (the report's new rows come through the existing kinds table's data).
- Baselines: `admin-words-baseline.txt` 876 lines and `design-system-baseline.txt` 222 lines, untouched before and after.

**B-?? (draft)**

- **B-01 (draft): the phases' order.** Settled 5 says after core's content, terms and people and before the second pass; P2 says 850, but on main the second pass is 600 (menus 800, redirects 900), so 850 would run after it. Options: 850 as P2 writes, or a place between page-parents (510) and second-pass (600). Chose the Settled section: `plugin-options` 540, `plugin-data` 550, `plugin-data-gone` 560. `8ab9c6e`.
- **B-02 (draft): options are a step, and gone records a third phase.** `ImportListPage` reads a whole route's items as objects, but the options answer is `{options: {a: <json>}}`, so options can't go through a list phase. Options: change `ImportListPage` (not owned), or a step. Chose a step (`PluginOptionsStep`) before the list phase. Settled 6 ("one gone from WordPress is reported") needed a catch-up step like core's `DeletedStep`: `PluginDataGoneStep`. All three registered on the one provider line. `8ab9c6e`.
- **B-03 (draft): how the importer is built.** The plan says "through the container"; `docs/architecture/plugins.md` and `PluginLoader` say a plugin is never given the container. Chose the loader's way: constructor parameters filled from `PluginServices::for()` with the plugin's granted capabilities and agreed hosts. `8ab9c6e`.
- **B-04 (draft): `termMeta` has no taxonomy.** The manifest lists term meta keys only, the route takes `taxonomy`. Chose `taxonomy` optional on the route (every taxonomy unless named); the record's type is each term's taxonomy. `8a18efe`, `2bf1bd1`.
- **B-05 (draft): every extension request names `plugin=<code>`.** Two plugins declaring the same post type and keys would otherwise be identical lists, and `identify()` couldn't tell whose record it is. The companion ignores the parameter (spec). `8ab9c6e`.
- **B-06 (draft): the map reference.** `SourceRef`'s type can't hold a colon, so the type is `plugin` and the ID `<code>:<kind>:<type>:<id>` (a person's type empty: `importer:user::1`); the key reads `plugin:<code>:...` as the plan writes. An imported record's map row has `teme_type` `plugin` and `teme_id` the plugin's code. Post types are limited to WordPress's own shape (`^[a-z0-9_-]{1,20}$`) so none holds a colon. `8ab9c6e`.
- **B-07 (draft): lines outside "Owns" that the plan's own tasks need.** The capability needs one line in `packages/teme-contracts/src/Plugins/Capability.php`, the schema's capability enum, and `tests/Unit/Contracts/PluginContractsTest.php`'s list (meaning kept: it names the capabilities). Three new phases need marks in `tests/Feature/Import/ImportAcceptanceTest.php` ("a mark for every step"); a list phase with no lists makes no batch, so `plugin-data`'s mark enables the fixture plugin and catches up. Task 6 needs three lines in `ImportScreen::report()`. Own lines only, nothing removed. `44cd033`, `8ab9c6e`, `3209d9e`.
- **B-08 (draft): validator details the plan doesn't spell.** Also refused: `import.wordpress` with no `imports` ("It asks for import.wordpress but declares nothing to import.", like http without hosts); an `importer` that isn't a class name; a post type of the wrong shape. Post type names count towards the 50. `44cd033`.
- **B-09 (draft): the report's "section per plugin"** is a row per plugin (`<Label> (plugin)`, type `plugin:<code>`) in the report's kinds table, with created, updated, skipped and failed; brought = created + updated. The plugin's failure sentences are the run's messages (`plugin-data.failed`, grouped by code on the screen, each one in the CSV). Chose this over a new screen section, which would need TSX outside the plan's files. `3209d9e`.
- **B-10 (draft): the extension route's shapes.** Meta values are always lists (WordPress meta is multi-valued); `usermeta`/`termmeta` list only records holding a declared key; options' answer always has `objects`. The "too old" sentence is reported once, by `plugin-options`, only when some plugin would receive data. `8a18efe`, `8ab9c6e`.

**Follow-up R7**

The review's findings 1 to 6, each on `import/plugin-socket` alone (nothing merged), each test seen red first:

1. **Consent covers the declared names** (`35ae1e1`). `tests/Feature/Import/PluginDataConsentTest.php`, 4 tests, seen red: `Undefined array key "imports"` in the consent record; the updated plugin's state wasn't `waiting`; `Consent::imports()` didn't exist; `PluginImports::all()` handed over a plugin whose names were never agreed.
2. **Never-sent names in any case, plus `mailserver_pass` and `{table prefix}capabilities`** (`13cd27e`). Six new cases in `PluginDataManifestTest`'s refusals (`User_Pass`, `WP_USER_ROLES`, `site1_user_roles`, `mailserver_pass`, `wp_capabilities`, `WP_2_Capabilities`) and four in `PluginDataCompanionTest`'s, seen red: no sentence, and the fake companion answered instead of refusing. The spec and `docs/architecture/plugins.md` say the same.
3. **Plugins and importers remembered by code for the job** (`a8bd823`). `PluginDataConsentTest` "remembers each plugin and its importer…", seen red: a second `find()` in the same job returned a new instance.
4. **Objects only under declared names** (`0cb2c5e`). `PluginDataPhaseTest` "reports objects only under the names the plugin declared…", seen red: the warning listed `_importer_inbox, _secret, importer_mode`.
5. **A person or term "no longer has the data … declared"** (`4ba0d9c`). `PluginDataPhaseTest` "says, on a catch-up, that a person no longer has the data the plugin declared", seen red: it said "the person 1 whose data Importer brought across".
6. **Docs line** (`286359d`): `import.wordpress` includes reading imported items' titles and status, shown at consent; plus a line that consent is agreed name by name. Docs only, so no test.

- **B-11 (draft): finding 1 reaches beyond the three named files' callers.** `Consent::covers()` now also asks that every declared name was agreed, so `PluginList`, `PluginLoader`, `MigratePlugin` and `MigratePluginsCommand` treat such an update as waiting through it, unchanged. `Consent::added()` gains an `imports` key only when there are new names, so `tests/Feature/Plugins/ConsentTest.php`'s exact shape holds. `EnablePluginInput` carries no names (not in the allowed files), so `EnablePlugin` records the names of the manifest it finds; the version, capabilities, hosts and hooks are still checked against the input. A plugin enabled before this fix holds no names and waits for a new yes. `PluginRecord`'s `$consent` docblock doesn't name `imports` (not allowed); `Consent::agreedImports()` reads it. `35ae1e1`.
- **B-12 (draft): the validator can't know the table prefix,** so it refuses any name ending in `user_roles` or `capabilities`, lowercased; the companion checks `$wpdb->prefix . 'user_roles'` and `$wpdb->get_blog_prefix() . 'capabilities'` exactly, case-insensitively (spec). The fake companion uses the prefix `wp_`. `13cd27e`.
- **B-13 (draft): "for the life of the job" is a scoped binding.** `PluginImports` is bound `scoped()` (one provider line) and `find()` remembers by code; the queue worker forgets scoped instances before each job, so a disabled plugin is seen at the next job. No static state (I-087). `a8bd823`.

The first item under "Left open" (consent covers the capability, not the names) is fixed by finding 1.

**R7 check:** `composer check`: Pint, PHPStan, types, `npm run check` and the themes' build passed; Architecture 139 passed; Unit+Feature 10,680 passed, 2 skipped, 1 failed: `tests/Feature/Media/MediaFiltersTest.php` "finds each month…", a query-plan assertion this part doesn't touch, which passed when run again alone (7 passed).

**I-?? (draft):** none.

**Contracts the plan names:** built as written (`WordPressImporter`, `WordPressRecord`, `ImportScope`, `ImportOutcome`, `@since 0.9.0`). Additions: `WordPressRecord::POST/USER/TERM` and `ImportOutcome::IMPORTED/SKIPPED/FAILED` constants, and `ImportOutcome`'s public `status` and `words` (the plan's sketch had no way to read an outcome). `Capability::ImportWordPress` added last.

**STOP:** none.

**Left open**

- Consent covers the capability, not the declared names: an update that widens `imports` without a new capability starts without asking again. Worth a decision.
- `teme-plugin.schema.json` doesn't describe `imports` (it allows unknown keys); only the capability enum changed.
- The companion's side is specified in `docs/specs/companion-extension-route.md`, not built.

**Try it:** `php artisan test --compact tests/Feature/Import/PluginDataPhaseTest.php` runs a full import, a catch-up, failures, an old companion and a disabled plugin against the `tests/Fixtures/plugins/importer` fixture. On a site: enable a plugin whose manifest asks for `import.wordpress` with an `imports` block, connect a WordPress site whose companion answers API 2, import, and read the plugin's row on the report.

**Changelog lines (for the maintainer)**

- Plugins can bring their own data across from WordPress: a manifest's `imports` declares options, post types with meta, people's and terms' meta, behind the new `import.wordpress` capability, shown with its names on the consent screen.
- New contracts `Teme\Contracts\Import\WordPressImporter`, `WordPressRecord`, `ImportScope`, `ImportOutcome`.
- Imports run three new phases (plugin settings, plugin data, and on a catch-up what WordPress deleted) and give each plugin a row in the report.
