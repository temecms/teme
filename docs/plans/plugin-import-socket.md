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

(The builder fills this.)
