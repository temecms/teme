# v1 wave 1, part A: plugins write content

**One part, one branch: `plugins/content-write`, from `origin/main`.** Built side by side with part B (plugin jobs and schedules, `plugins/jobs`) and part C (import fixes, `import/v1-fixes`).

Steps use checkbox (`- [ ]`) syntax for tracking.

**Written 2026-11-15** for the repository `temecms/teme`. Lighter plan (I-569): decisions, names, schema, permissions, tests and the delicate code are written out; the rest is described.

**Goal.** A plugin that was granted `content.write` can create and change items, publish and schedule them, and one granted `media.write` can add media, including from an outside address, all through Teme's existing Operations and on behalf of a person.

**Spec:** `docs/proposals/plugin-content-write.md` (its Settled section is binding). `docs/architecture/plugins.md` › Trust (capabilities and the services a plugin receives). `docs/studies/2026-11-11-plugin-api.md` §7 (EmDash: declared capabilities, consent).

**Bound by:** rule 1 (every write through an Operation), rule 5 (contracts import nothing from `app/` or `Illuminate`), rule 6 (hooks after commit), rule 7 (bodies are Tiptap JSON), I-3353 (plugins receive only what their capabilities allow), I-3396 (a wall is a missing socket), I-3439 (American English).

**New dependencies:** none. **Migrations:** one, adding a nullable column. **Permissions:** none new. **Operations:** none new; the existing ones gain a recorded `via`. **Contract:** new interfaces and value types in `teme/contracts`, `@since 0.9.0`.

---

## What main does today (Task 0 confirms each, with its line)

- `Teme\Contracts\Plugins\Capability` has `ContentRead`, `MediaRead`, `Http` only (`packages/teme-contracts/src/Plugins/Capability.php`).
- `ManifestValidator::LATER` lists `content.write` and `media.write` as "no door yet" and refuses them (`app/Domain/Plugins/ManifestValidator.php:36`).
- `PluginDoors::for()` offers `SettingsReader`, `SecretReader`, and `OutboundHttp` when `http` is granted (`app/Domain/Plugins/Doors/PluginDoors.php`); `PluginServices::OFFERED` names those three.
- `CreateDraft`, `SaveDraft`, `PublishRevision`, `SchedulePublication`, `UpdateContentDetails` and `UploadMedia` authorize the input's `actorId()` person through the Gate.

## The decisions, written out

**D1. Capabilities.** Add `case ContentWrite = 'content.write';` and `case MediaWrite = 'media.write';` to `Capability`, and remove both from `ManifestValidator::LATER`. Consent needs no change: a missing capability already blocks enabling until agreed.

**D2. The contracts** (`packages/teme-contracts/src/…`, pure PHP, each with a one-to-three-sentence docblock and `@since 0.9.0`):

```php
namespace Teme\Contracts\Content;

interface ContentWriter
{
    /** Creates a draft and returns it. @throws WriteRefused */
    public function create(NewItem $item, string $actingPersonId): ItemRef;

    /** Saves a new revision of the item's draft with these changes. @throws WriteRefused */
    public function change(ItemRef $item, ItemChanges $changes, string $actingPersonId): ItemRef;

    /** Publishes the item's latest revision now. @throws WriteRefused */
    public function publish(ItemRef $item, string $actingPersonId): ItemRef;

    /** Schedules the item's latest revision. @throws WriteRefused */
    public function schedule(ItemRef $item, \DateTimeImmutable $at, string $actingPersonId): ItemRef;
}

final readonly class NewItem
{
    /**
     * @param  array<string, mixed>  $body  a Tiptap document (`type: doc`)
     * @param  array<string, mixed>  $fields  by field handle
     * @param  array<string, list<string>>  $terms  term slugs by taxonomy
     */
    public function __construct(
        public string $type,
        public string $title,
        public array $body,
        public array $fields = [],
        public array $terms = [],
        public ?MediaRef $featuredImage = null,
        public ?string $language = null,
    ) {}
}

final readonly class ItemChanges { /* the same fields, each nullable: null leaves it as it is */ }

final class WriteRefused extends \RuntimeException {} // its message is one plain sentence
```

```php
namespace Teme\Contracts\Media;

interface MediaWriter
{
    /** Stores a file fetched from an agreed host. @throws \Teme\Contracts\Content\WriteRefused */
    public function fromAddress(string $url, string $actingPersonId, ?string $alt = null, ?string $title = null): MediaRef;
}
```

`ItemRef` and `MediaRef` already exist; reuse them.

**D3. The doors.** `app/Domain/Plugins/Doors/PluginContentWriter.php` and `PluginMediaWriter.php` implement the contracts by building each Operation's input with `actorId: $actingPersonId` and calling it. A refusal from the Operation (authorization or validation) becomes `WriteRefused` with the Operation's sentence; nothing is written (the Operation's transaction already guarantees it). `PluginDoors::for()` adds `ContentWriter::class` when `content.write` is granted and `MediaWriter::class` when `media.write` is granted; `PluginServices::OFFERED` gains both.

**D4. The acting person.** The door takes the person's ID as given; the Operation loads them through `Actors` and checks the Gate. An empty or unknown ID is refused ("A plugin writes on behalf of a person, and none was named."). The door never chooses a person itself.

**D5. Recording the plugin.** Migration: `content_revisions.via` nullable `text`, added (no existing row changes). The doors pass the plugin's code; the Operations write it on the revision they create (an optional `via` on their inputs, null for people). History shows "through :plugin" beside the author when `via` is set (plugin name from the registry, else the code).

**D6. Media from an address.** `PluginMediaWriter` fetches through `PluginHttp` built with the plugin's agreed hosts (the same resolver that refuses private and local addresses), with a cap of `UploadMedia`'s own size limit, into a temporary file, then calls `UploadMedia` with it; the temporary file is removed in a `finally`. A host not agreed to is refused before any request.

**D7. Hooks.** Nothing new: the Operations already notify after commit (`ContentSaved`, `ContentPublished`, `MediaStored`…), now carrying `via` where their payload has room; a plugin's own write still triggers other plugins' listeners as a person's would.

## Tasks

### Task 0: confirm the start
- [ ] Confirm each line under "What main does today"; record differences as `B-?? (draft)` and follow the code.

### Task 1: capabilities and contracts
- [ ] Tests first: `ManifestValidatorTest` accepts `content.write` and `media.write`; `CapabilityTest` (contracts) lists five cases; the contracts' architecture test still finds no `App\` or `Illuminate\` import.
- [ ] Add D1 and D2. Build; the quick check.

### Task 2: the content door
- [ ] Fixture plugin `tests/Fixtures/plugins/writer` (asks for `content.read`, `content.write`).
- [ ] Tests first, `tests/Feature/Plugins/PluginContentWriteTest.php`: a granted plugin gets `ContentWriter`; one not granted doesn't (its constructor asking for it fails to start, as today for `OutboundHttp`); `create` makes a draft with title, body, fields, terms and featured image, authored by the acting person, `via` = the plugin's code; `change` saves a new revision; `publish` and `schedule` work for an editor and are refused for a contributor (the person's permission decides); **every refusal writes nothing** (content and revisions counts compared, no hook fired): no person, an unknown person, a person without permission, an unknown type, invalid fields, a body that isn't a Tiptap document.
- [ ] D3, D4, D5 (migration and the optional `via` on the four inputs). Build; the quick check.

### Task 3: the media door
- [ ] Tests first, `PluginMediaWriteTest.php` (HTTP faked): from an agreed host, a file is stored as `UploadMedia` stores one, credited to the acting person; refused, and nothing written: a host not agreed to (no request made), a private address, a file over the limit, a type `UploadMedia` refuses, no person. The temporary file is gone after each case.
- [ ] D6. Build; the quick check.

### Task 4: History shows the plugin
- [ ] Test: the History screen's data names "through Writer" for a revision with `via`. Build the small view change; `npm run build`; the quick check.

### Task 5: documents and records
- [ ] `docs/architecture/plugins.md`: the two capabilities have doors (one paragraph each, with the acting-person rule). Fill this plan's Records; the full gate once.

## Quick check

`php artisan test --compact tests/Feature/Plugins tests/Architecture`, then `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse --memory-limit=2G` on the changed files, and `npx vp check` on changed front-end files.

## Owns

`packages/teme-contracts/src/Content/{ContentWriter,NewItem,ItemChanges,WriteRefused}.php`, `packages/teme-contracts/src/Media/MediaWriter.php`, `app/Domain/Plugins/Doors/PluginContentWriter.php`, `app/Domain/Plugins/Doors/PluginMediaWriter.php`, the one migration, the optional `via` on `CreateDraftInput`, `SaveDraftInput`, `PublishRevisionInput`, `SchedulePublicationInput` and where those four Operations write the revision, the History view's "through" line, `tests/Feature/Plugins/PluginContentWriteTest.php`, `tests/Feature/Plugins/PluginMediaWriteTest.php`, `tests/Fixtures/plugins/writer/**`, `docs/architecture/plugins.md` (its Trust section).

**Shared with part B, lines added only** (the maintainers join them): `packages/teme-contracts/src/Plugins/Capability.php`, `app/Domain/Plugins/ManifestValidator.php` (`LATER`), `app/Domain/Plugins/Doors/PluginDoors.php`, `app/Domain/Plugins/Seams/PluginServices.php` (`OFFERED`). Never `app/Domain/Plugins/Jobs/**` (part B) or `app/Domain/Import/**` (part C).

## Records

(The builder fills this.)
