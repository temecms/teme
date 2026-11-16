# Plan: media in S3-compatible storage behind a CDN

**One part, one branch: `media/object-storage`, from `origin/main` after the v1 join (`join/v1-wave2`) has merged.** Proposal: `docs/proposals/media-object-storage.md` (its Settled section is binding). Decides I-3456 (adopted 2026-11-15/16); revisits I-390 (backups refused a media disk that isn't a folder).

Steps use checkbox (`- [ ]`) syntax for tracking.

**Written 2026-11-16.** Lighter plan (I-569): decisions, names, schema, permissions, tests and the delicate code are written out; the rest is described.

**Goal.** A site can keep its media in S3-compatible object storage (AWS S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2, MinIO), serve the variants from a CDN or public bucket address, keep image originals in a private bucket that editors reach only through short-lived signed addresses, and move an existing library of tens of thousands of files there with a resumable, verified console command. Nothing in content is rewritten: media is referenced by ID and every address is built at render time (rule 9).

**Bound by:** rule 1 (every state change through an Operation; the move's records go through three short Operations, as `BackupSite` does with `StartBackupRun`/`EndBackupRun`), rule 2 (one row per run, no per-file table), rule 3 (tests on PostgreSQL), rule 4 (JSONB, no `serialize()`), rule 6 (nothing purged inside a transaction), rule 9, `docs/architecture/operations.md` (*The operator*), `docs/architecture/media.md` (*The rules this follows*: nothing lists a storage folder), `docs/architecture/backups.md`, I-569, I-3317 (quick check per task, full suite once), I-3439 (American English).

**New dependency (approved):** `league/flysystem-aws-s3-v3` at exactly **3.35.3** (`composer require league/flysystem-aws-s3-v3:3.35.3`), which brings `aws/aws-sdk-php` (`^3.371.5`). 3.35.3 was the newest release on Packagist on 2026-11-16 (published 2026-08-08). No other package. **Migrations:** one, creating `media_moves`. **Permissions:** none new (the move and the bucket check are the operator's; downloads keep `media.upload`). **Operations:** three new, `StartMediaMove`, `RecordMediaMoveProgress`, `EndMediaMove`.

---

## What main does today (Task 0 confirms each, with its file and line)

- `config/filesystems.php` defines `media` (local, `storage/app/public/media`, `url` fixed to `APP_URL/storage/media`, `visibility` public) and `media-originals` (local, `storage/app/private/media-originals`, no `url`). It also has Laravel's stock `s3` disk, unused by media.
- `config/media.php` names the two disks through `MEDIA_DISK` and `MEDIA_ORIGINALS_DISK`. `App\Domain\Media\MediaDisks` resolves them by role; every media read and write goes through it with streams (`writeStream`, `readStream`, `url`, `delete`). No media code calls `path()` on a media disk except the backups (`MediaInventory::root()`, `MediaRestore::pairs()`).
- Every public media address is built by `MediaDisks::public()->url(...)` at render time (`MediaUrls`, `CardMedia`, `PageHead`, `ImageObjects`, `SitemapImages`, `RedirectLookup`, `ApiMedia`, `MediaDetails`). The content security policy already allows the public disk's origin when it is another host (`PolicySources::media()`, used by `PagePolicy` and `AdminSecurityHeaders`).
- `PathLinkResolver::fileAt()` recognizes a pasted media address only under the *current* public disk's base URL (or its path when that disk is served from this site).
- `MediaItemController::original()` (route `admin.media.original`, `can:media.upload`) streams the original through PHP with `$disk->download()`, whatever the disk is.
- `MediaInventory::root()` throws `BackupRefused` when a media disk isn't a `LocalFilesystemAdapter` (I-390); `MediaRestore::pairs()` reports the same as a problem; `VerifyBackups` reads back a sample of originals from the snapshot by role.
- `App\Domain\Deployment\SecretFiles` lists the variables that may come from a `_FILE`; `docker/compose.dokploy.yml` lists every variable the containers get (`x-teme-environment`); `docker/compose.yml` also reads an `env_file`.
- `docker-compose.yml` (development) runs PostgreSQL and Valkey only: **no MinIO**. Tests use no real cloud.
- Every deploy clears the page cache (`docs/guides/operators/updates.md`, step 8), so an address change made through the environment reaches every cached page with the deploy that applies it.

## The decisions, written out

**D1. The media address is environment configuration, not a setting.** Two variables:

| Variable | Disk | Default | Meaning |
|---|---|---|---|
| `MEDIA_URL` | `media` (on the server) | `APP_URL` + `/storage/media` | The address the server's media are served at, for a pull CDN in front of the site (for example `https://media.example.com`). |
| `MEDIA_BUCKET_URL` | `media-bucket` | none, required when that disk is used | The CDN or public bucket address in front of the public bucket. |

Reasons it is not an admin setting: (1) the address must agree with where the files physically are, which only the operator's storage configuration decides; an administrator changing it while the files aren't there breaks every image at once; (2) the disks, their credentials and `ASSET_URL` are already environment configuration, read before any setting; (3) changing it must clear the page cache, which a deploy already does; (4) the reference systems agree (Craft parses the filesystem's base URL from the environment, emdash reads `S3_PUBLIC_URL` at process start, Winter's storage `path` is config, Ghost's `cdnUrl` is config; pursae lets the environment override and lock its database settings). One address per disk, never one per item: nothing stores a host.

**D2. Two bucket disks, two buckets.** Added to `config/filesystems.php` beside `media` and `media-originals`, which are unchanged except `media`'s `url` reading `MEDIA_URL`:

```php
'media-bucket' => [
    'driver' => 's3',
    'key' => env('MEDIA_BUCKET_ACCESS_KEY_ID'),
    'secret' => env('MEDIA_BUCKET_SECRET_ACCESS_KEY'),
    'region' => env('MEDIA_BUCKET_REGION', 'us-east-1'),
    'bucket' => env('MEDIA_BUCKET'),
    'root' => env('MEDIA_BUCKET_ROOT', ''),
    'endpoint' => env('MEDIA_BUCKET_ENDPOINT'),
    'use_path_style_endpoint' => (bool) env('MEDIA_BUCKET_PATH_STYLE', false),
    'url' => env('MEDIA_BUCKET_URL'),
    // Keys carry the file version (StorageKeys), so a key's bytes never change.
    'options' => array_filter([
        'CacheControl' => 'public, max-age=31536000, immutable',
        'ACL' => env('MEDIA_BUCKET_ACL') ?: null,      // only 'public-read', for providers that need per-object ACLs
    ]),
    'throw' => true,
    'report' => false,
],

'media-originals-bucket' => [
    'driver' => 's3',
    // the same key, secret, region, endpoint and path style as media-bucket
    'bucket' => env('MEDIA_ORIGINALS_BUCKET'),
    'root' => env('MEDIA_ORIGINALS_BUCKET_ROOT', ''),
    // No 'url': a disk without one has no public address (Statamic's rule for a private container).
    // No 'ACL' option: Flysystem's default, private, is always sent.
    'options' => ['CacheControl' => 'private, no-store'],
    'throw' => true,
    'report' => false,
],
```

- **Public access is the bucket's, never the object's.** R2 (custom domain), B2 (a public bucket), AWS (a bucket policy or a CDN with origin access), MinIO (an anonymous-download policy) make a bucket public as a whole. DigitalOcean Spaces serves through its CDN only objects that are public, so `MEDIA_BUCKET_ACL=public-read` exists for it; it is never applied to the originals bucket. Flysystem 3.35.3's `AwsS3V3Adapter::upload()` always sends an ACL (`$options['params']['ACL'] ?? determineAcl()`, default `private`), so "no ACL" means `private`. **Unverified:** whether every listed provider accepts `ACL: private` on a PUT (AWS buckets with ACLs disabled accept it as far as is known; R2, B2 and Spaces not checked). Task 0 reads the installed adapter; the maintainers check by hand on each provider before release (open question Q4).
- **Two buckets, not one with two folders**, because a provider makes a whole bucket public: a CDN in front of one bucket would also serve the originals. The bucket check (D5) and the move (D6) refuse when both disks name the same endpoint and bucket.
- `MEDIA_BUCKET_SECRET_ACCESS_KEY` joins `SecretFiles` (so `MEDIA_BUCKET_SECRET_ACCESS_KEY_FILE` works). The key ID is not a secret.
- Region defaults to `us-east-1` (MinIO's and AWS's default). R2 documents `auto`; whether R2 also accepts `us-east-1` is **unverified**, so the guide tells R2 users to set `auto`.

**D3. Choosing where media lives: `MEDIA_STORAGE`, and named locations.** `config/media.php`:

```php
$locations = [
    'server' => ['public' => 'media', 'originals' => 'media-originals'],
    'bucket' => ['public' => 'media-bucket', 'originals' => 'media-originals-bucket'],
];
$storage = (string) env('MEDIA_STORAGE', 'server');

return [
    'locations' => $locations,
    'disk' => env('MEDIA_DISK', $locations[$storage]['public'] ?? "unknown-media-storage-{$storage}"),
    'originals_disk' => env('MEDIA_ORIGINALS_DISK', $locations[$storage]['originals'] ?? "unknown-media-storage-{$storage}"),
    'image_driver' => env('MEDIA_IMAGE_DRIVER', 'gd'),
    'original_link_seconds' => 300,
];
```

`MEDIA_STORAGE=bucket` switches both roles; `MEDIA_DISK` and `MEDIA_ORIGINALS_DISK` stay as the lower-level overrides, so a site may keep originals on the server and variants in the bucket (`MEDIA_STORAGE=bucket` plus `MEDIA_ORIGINALS_DISK=media-originals`, see D8). An unknown `MEDIA_STORAGE` resolves to a disk name that doesn't exist, so the first use fails loudly ("Disk [unknown-media-storage-…] does not have a configured driver") rather than silently falling back to the server. `App\Domain\Media\MediaLocations` reads `media.locations`: `names()`, `disks(string $name): array{public: string, originals: string}`, `current(): ?string` (the location whose disks are both the configured ones, or null for a mixed site), `publicBases(): list<string>` (each location's public disk `url('')` where it has one).

**D4. Editors download an original through a short-lived signed address.** `MediaItemController::original()`:

```php
$file = $location->original($media) ?? abort(404);

// Illuminate\Filesystem\LocalFilesystemAdapter, the class MediaInventory already tests for.
if (! $file['disk'] instanceof LocalFilesystemAdapter && $file['disk']->providesTemporaryUrls()) {
    $url = $file['disk']->temporaryUrl($file['path'], now()->addSeconds((int) config('media.original_link_seconds')), [
        'ResponseContentDisposition' => 'attachment; filename="'.$file['downloadName'].'"',
    ]);

    return redirect()->away($url, 302)->header('Cache-Control', 'no-store, private');
}

return $file['disk']->download($file['path'], $file['downloadName']);   // the server's disks, as today
```

Same route, same `can:media.upload` gate, same 404. Five minutes: long enough to start a large download (a started download isn't cut off when the address expires), short enough that a copied link is useless soon after. The address is made per request and never stored, cached or logged. `downloadName` is the slugged name and the detected extension (ASCII by construction), so it is safe inside the header. Non-image originals live on the public disk; they redirect the same way when that disk is a bucket, which gives them the attachment disposition. Reference: Payload's storage-s3 (`getFile.ts`, a 302 to a presigned GET after its own access check), October's `Files::getTemporaryUrl()`, Wagtail's document `serve` view (redirect for remote storage).

**D5. `php artisan teme:media:bucket-check`** proves a bucket setup before anything is moved (pursae's "Test connection", `docs/gocdn-features.md`, without its listing call). For each bucket disk: PUT a probe object `_teme-check/{ulid}.txt` with known bytes, HEAD it (size), and then:

- public bucket: GET it through `MEDIA_BUCKET_URL` with Laravel's HTTP client: must answer 200 with the same bytes and a `Cache-Control` holding `immutable` (a missing header is a warning, not a failure: some CDNs rewrite it);
- originals bucket: an unsigned GET of the object's direct address (the S3 client's `getObjectUrl()`) must **not** answer 200; then a GET of a `temporaryUrl()` must answer 200;
- DELETE both probes in a `finally`.

Also refused, before any request: a bucket disk with no bucket, a public disk without `MEDIA_BUCKET_URL`, both disks on the same endpoint and bucket, an `http://` endpoint other than a loopback or private-network host (MinIO in development). Each failure is one plain sentence naming the step and the provider's error code (`SignatureDoesNotMatch` → "the secret key is wrong", `NoSuchBucket`, `AccessDenied`, `AccessControlListNotSupported` → "this bucket doesn't accept ACLs: leave MEDIA_BUCKET_ACL empty", `RequestTimeTooSkewed` → "this server's clock is off"); never a key or secret. Exit 0 when all pass, 1 otherwise. It changes no database state, so it needs no Operation.

**D6. The move: `php artisan teme:media:move`.**

```
teme:media:move
    {--from=server : The location the files are in now}
    {--to=bucket : The location to copy them to}
    {--only= : public or originals, for a site that keeps one role where it is}
    {--catch-up : Only items changed since the last finished copy in this direction}
    {--deep : Read back every copied file and compare SHA-256, not only the originals}
    {--chunk=100 : Media items per chunk}
    {--dry-run : Count the items, files and bytes the rows name, and copy nothing}
    {--status : List the last ten moves and stop}
    {--delete-source : Delete the source's copies, only after a verified move (D7)}
```

It binds `OperatorElevation` and passes a null actor (operator only). It works in this process, never on a queue. It moves both directions (`--from=bucket --to=server` takes a site back off a provider).

- **What it moves is what the rows name, never a listing** (media.md). `App\Domain\Media\MediaFiles::of(object $row): list<array{role: 'public'|'originals', path: string, bytes: int, sha256: ?string}>`: the original (on `originals` for an image, `public` otherwise, with the row's `sha256`) and every variant in `variants` (on `public`, `sha256` null). `MediaInventory::add()` switches to it, so the two never disagree. Trashed items are included: they can be restored.
- **Order and resumption.** Keyset over `media.id` (ULIDs), all rows including trashed, `WHERE id > :cursor ORDER BY id LIMIT :chunk`; a catch-up adds `AND updated_at >= :since` (a sequential scan of `media` is acceptable for an operator's command; no index is added, and Task 0 confirms that every Operation writing a file moves `updated_at`: `UploadMedia`, `ImportMedia`, `UpdateImportedMedia`, `ReplaceMediaFile`, `RecordMediaVariants`). After each chunk, `RecordMediaMoveProgress` moves the run's cursor and adds its counts in one transaction. A run left `running` or `stopped` with the same from, to, roles and scope is resumed from its cursor; the chunk that was cut off is done again, and its files are found present (below).
- **One move at a time.** `MediaMoveLock` takes a PostgreSQL session advisory lock, as `BackupLock` does. Held → refused, exit 2, nothing written. A row still `running` with the lock free was killed, and is resumed.
- **Each file:**

```php
// $file from MediaFiles::of($row); $from and $to are the role's disks.
$size = $to->fileExists($file['path']) ? $to->fileSize($file['path']) : null;

if (! $from->fileExists($file['path'])) {
    // Never there, or already moved and deleted: present on the target counts; else a hole.
    return $size !== null ? $this->verify($file, $to, present: true) : $this->hole($file);
}

$sourceSize = $from->fileSize($file['path']);

if ($size !== $sourceSize) {
    $to->writeStream($file['path'], $from->readStream($file['path']));    // multipart above 16 MB, by the SDK
    if ($to->fileSize($file['path']) !== $sourceSize) {
        return $this->fail($file, 'size differs after copying');
    }
    $copied = true;
}

// Originals: read back and compare with the row's SHA-256, always. Variants: only with --deep,
// against the source's own SHA-256. An ETag is never trusted: it isn't an MD5 for multipart
// uploads, under SSE-KMS, or on every provider.
return $this->verify($file, $to, present: ! ($copied ?? false));
```

  A hole (the file on neither side) is counted and named, never a failure: the move cannot lose what was never there, and the report points at `teme:media:regenerate` for variants. An original whose source SHA-256 differs from the row is a failure ("the server's copy differs from its recorded checksum"), never copied over a good target.
- **Progress.** A progress bar over the rows in scope (counted once at the start), and after each chunk one line: items done of total, files copied, already present, holes, failures, bytes, rate. `--status` prints the last ten runs. The final line says whether the run is verified and what to do next.
- **Stopping.** `$this->trap([2, 15], ...)` (as `WriteExport` and `RunUpdater` do) finishes the current item, records the chunk, ends the run `stopped`, exit 3. Running the same command again resumes.
- **Exit codes:** 0 finished with no failures; 1 finished with failures (each in `problems`, the first 1,000 named); 2 refused (lock, configuration, bucket refused); 3 stopped.
- **Refused before anything is written:** from equals to; an unknown location; the target's public disk without a `url`; both target disks on one bucket; `--delete-source` combined with any copy option.

**D7. Deleting the source copies is a separate, gated run.** `--delete-source` is refused, writing nothing, unless all hold:

1. The site's configured disks are the target's for every role moved (`config('media.disk')`, `config('media.originals_disk')`): the site no longer reads the source.
2. The **latest finished copy run** in this direction has `failures = 0` and started while the site's disks were already the target's (its `site_disks` column).
3. The chain back from it is unbroken: each catch-up's `changed_since` is at or before the previous finished copy's `started_at`, every run in the chain has `failures = 0`, and it ends at a `scope = all` run.
4. No backup, prune or check holds `BackupLock` (`isHeld()`), and no other move holds `MediaMoveLock`.

It then walks the rows again (keyset, resumable, its own run with `kind = delete_source`), and for each file **checks the target once more** (exists, same size as the source) before deleting the source's copy by its known path. A file whose target check fails is kept and counted as a failure. It never lists or deletes a folder (the architecture test forbids `deleteDirectory`), so empty `{id}/v{n}` folders stay; the guide gives the operator a `find … -type d -empty -delete` line. `--dry-run --delete-source` says whether the gate passes and why not.

**D8. Backups when media is in a bucket (revises I-390).** Today a backup run fails when a media disk isn't a folder on the server, "rather than backed up as nothing". From this part on:

- A role whose disk is a folder on this server is backed up exactly as today. A role whose disk is a bucket is **left to the bucket**: the run backs up the database, the app key and the manifest, and succeeds. Nothing is silent: the manifest's `media` part gains `locations` (`{"public": "server" | "bucket", "originals": …}`), `roots` and `snapshot_roots` hold only the server's roles, the originals list (ID, role, path, bytes, SHA-256) is still written for every item, and the run's `figures` gain `media_in_bucket` (files the rows name on a bucket role). The Tools › Backups screen says, when any role is in a bucket: "Your media files are in object storage, so these backups hold their list and checksums but not the files. Turn on versioning or replication at your storage provider."
- `MediaInventory` checks files on a bucket role by nothing (no HEAD per file per night: tens of thousands of requests). `teme:media:move --from=server --to=bucket --catch-up --dry-run` is not a check either; the operator's check of a bucket's completeness is a deep move run, which re-verifies everything and copies nothing that is right.
- `VerifyBackups` reads back a sample only from the server's roles. `MediaRestore::pairs()` restores only roles that are a folder both in the backup and on this server; any other role is reported in the restore's plan as one line ("The image originals were in object storage when this backup was made, so they aren't in it; the restored database expects them in the bucket `…`."), not as a problem that stops the restore. A manifest without `locations` (written before this part) reads as both roles on the server.
- Why not copy the bucket into restic: restic reads only local paths, so every file would be downloaded to a temporary folder each night, which is the cost the bucket was meant to remove. Why not refuse: a site that moved to a bucket would lose its database backups too. The maintainers decide whether this is the default (open question Q1); the alternative the guide offers is the mixed setup in D3 (originals on the server, backed up by restic; variants in the bucket, which `teme:media:regenerate` can remake).

**D9. Addresses pasted into content keep resolving to the item.** `PathLinkResolver::fileAt()` tries every base in `MediaLocations::publicBases()` plus the server's `/storage/media` path, not only the current disk's. So an address copied from the site before the move, or from the CDN after it, still becomes the item's ID (rule 9).

**D10. The schema.** `database/migrations/<date after the newest on main>_create_media_moves_table.php`:

```php
Schema::create('media_moves', function (Blueprint $table): void {
    $table->ulid('id')->primary();
    $table->string('kind', 16);                 // copy | delete_source
    $table->string('from_location', 32);        // a key of config('media.locations')
    $table->string('to_location', 32);
    $table->jsonb('roles');                     // ["originals","public"], sorted
    $table->string('scope', 16);                // all | changed
    $table->timestampTz('changed_since')->nullable();
    $table->boolean('deep')->default(false);
    $table->jsonb('site_disks');                // {"public": "...", "originals": "..."} when it started
    $table->string('state', 16);                // running | stopped | finished
    $table->char('cursor', 26)->nullable();     // the last media ID fully handled
    $table->integer('items_total');
    $table->integer('items_done')->default(0);
    $table->integer('files_copied')->default(0);
    $table->integer('files_present')->default(0);
    $table->integer('files_deleted')->default(0);
    $table->bigInteger('bytes_copied')->default(0);
    $table->integer('holes')->default(0);
    $table->integer('failures')->default(0);
    $table->jsonb('problems')->default(DB::raw("'[]'::jsonb"));  // first 1,000: {id, role, path, reason}
    $table->text('message')->nullable();
    $table->timestampTz('started_at');
    $table->timestampTz('finished_at')->nullable();
    $table->timestampsTz();
    $table->index(['kind', 'from_location', 'to_location', 'started_at']);
});
// CHECK constraints: kind, scope and state in their lists; scope = 'changed' exactly when changed_since is set;
// roles and problems are JSON arrays; from_location <> to_location.
```

One row per run (rule 2); `problems` is bounded, as the backup manifest's missing list is. Model `App\Domain\Media\Models\MediaMove` (casts the JSONB columns; no writes outside the Operations).

**D11. The Operations.** All three accept **only the operator** (a null actor while `Operator::isPresent()`); a person's ID, even an administrator's, is refused, and so is a null actor without the operator. Each is short and writes one row.

- `StartMediaMove` (input: kind, from, to, roles, scope, changed_since, deep, site_disks, items_total): refuses while another run is `running` for the same kind and direction unless the caller passes that run's ID to resume (then it only sets `state = running` again); returns the run.
- `RecordMediaMoveProgress` (input: run ID, cursor, the chunk's counts, its problems): locks the row (`lockForUpdate()` before the state check), refuses unless `running`, moves `cursor` forward only (never back), adds the counts, appends problems up to 1,000 in all.
- `EndMediaMove` (input: run ID, state `finished` or `stopped`, message): locks, refuses unless `running`, sets `finished_at` when finished.

Nothing is purged or dispatched after commit: the move changes no address a page shows (the switch is the operator's environment change and its deploy).

**D12. Container files and the image.** `docker/compose.dokploy.yml`'s `x-teme-environment` gains every `MEDIA_*` variable of D1 to D3 as `${NAME:-}` (`MEDIA_STORAGE` as `${MEDIA_STORAGE:-server}`); `docker/compose.yml` needs nothing beyond its `env_file`, and its header comment names the variables. The workers and the scheduler get the same environment as `web` (they share the anchor): a worker writing variants to the server while `web` reads the bucket would break images.

## Names

- Config: `media.locations`, `media.original_link_seconds`; disks `media-bucket`, `media-originals-bucket`; variables `MEDIA_STORAGE`, `MEDIA_URL`, `MEDIA_BUCKET`, `MEDIA_ORIGINALS_BUCKET`, `MEDIA_BUCKET_ROOT`, `MEDIA_ORIGINALS_BUCKET_ROOT`, `MEDIA_BUCKET_ENDPOINT`, `MEDIA_BUCKET_REGION`, `MEDIA_BUCKET_PATH_STYLE`, `MEDIA_BUCKET_ACCESS_KEY_ID`, `MEDIA_BUCKET_SECRET_ACCESS_KEY` (and `_FILE`), `MEDIA_BUCKET_URL`, `MEDIA_BUCKET_ACL`.
- Classes: `App\Domain\Media\MediaLocations`, `App\Domain\Media\MediaFiles`, `App\Domain\Media\Storage\MediaMover` (the run, outside any transaction, as `BackupSite`), `App\Domain\Media\Storage\MediaMoveLock`, `App\Domain\Media\Storage\MoveGate` (D7's rules, a query), `App\Domain\Media\Storage\BucketCheck`, `App\Domain\Media\Models\MediaMove`, the three Operations and their inputs under `App\Domain\Media\Operations\`.
- Commands: `App\Console\Commands\MoveMedia` (`teme:media:move`), `App\Console\Commands\CheckMediaBucket` (`teme:media:bucket-check`). Both thin.

## Tasks

### Task 0: confirm the start

- [ ] Confirm each line of "What main does today"; record any difference as `B-?? (draft)` and follow the code.
- [ ] After `composer require league/flysystem-aws-s3-v3:3.35.3`, read `vendor/league/flysystem-aws-s3-v3/AwsS3V3Adapter.php` and Laravel's `FilesystemManager::createS3Driver()` and confirm: (a) the disk's `options` become the adapter's default write options (`CacheControl`, `ACL`); (b) an `ACL` option replaces the default `private`; (c) extra disk config keys (for tests, `handler`) reach `new S3Client(...)`; (d) `temporaryUrl()` with `ResponseContentDisposition` signs `response-content-disposition`. Any "no" is a `B-??` with the way chosen.
- [ ] Confirm which Operations that write files move `media.updated_at` (D6). One that doesn't is a `B-??`; the catch-up must not miss it.

### Task 1: the adapter, the disks and the addresses

- [ ] Tests first, `tests/Feature/Media/Storage/MediaDisksConfigTest.php`:
  - `MEDIA_URL` set: a variant's address is under it; unset: under `APP_URL/storage/media`, exactly as today.
  - `MEDIA_STORAGE=bucket`: `MediaDisks::public()` is `media-bucket` and its addresses are under `MEDIA_BUCKET_URL`; `MediaDisks::originals()` is `media-originals-bucket`; `MEDIA_ORIGINALS_DISK=media-originals` keeps originals on the server (mixed).
  - an unknown `MEDIA_STORAGE` fails on first use, naming the value.
  - `media-originals-bucket` has no `url` in its config, and `media-originals` neither (a guard against ever giving originals a public address).
  - writing a variant to `media-bucket` with the AWS SDK's `MockHandler` in the disk's `handler` (no network): the `PutObject` carries `CacheControl: public, max-age=31536000, immutable`, `ContentType: image/webp`, `ACL: private`; with `MEDIA_BUCKET_ACL=public-read`, `ACL: public-read`; a write to `media-originals-bucket` never carries `public-read` whatever `MEDIA_BUCKET_ACL` says.
  - with `MEDIA_BUCKET_ENDPOINT=http://minio:9000` and path style on, the request URI is `http://minio:9000/{bucket}/{key}`; with path style off and an R2-shaped endpoint, the bucket is in the host.
  - `PolicySources::media()` names the CDN origin when the public disk is the bucket (already true; this pins it).
  - `MEDIA_BUCKET_SECRET_ACCESS_KEY_FILE` is read by `SecretFiles` (extend `tests/Feature/Deployment/SecretFilesTest.php`'s dataset by one line).
- [ ] Red: `Driver [s3] is not supported` / class `AwsS3V3Adapter` not found. Then D1, D2, D3, D12, the `SecretFiles` line. Quick check.
- Commit: `feat(media): object storage disks, media addresses and MEDIA_STORAGE`

### Task 2: pasted addresses resolve from every location

- [ ] Tests first, extend the existing `PathLinkResolver` test file: with the bucket active, an address under `MEDIA_BUCKET_URL` and one under `APP_URL/storage/media` both resolve to the item; an address under a host that is no location stays an outside link. Red: the server address is not recognized once the bucket is active.
- [ ] D9 and `MediaLocations::publicBases()`. Quick check.
- Commit: `feat(media): recognize media addresses from every storage location`

### Task 3: signed downloads of originals

- [ ] Tests first, `tests/Feature/Media/Storage/OriginalDownloadTest.php`:
  - originals on `media-originals-bucket` (fake credentials, an endpoint like `https://objects.example.test`, no network: presigning is local): `GET admin.media.original` answers 302; the `Location` is on the endpoint's host, carries `X-Amz-Expires=300`, `X-Amz-Signature`, and `response-content-disposition` with the stored name; the answer carries `Cache-Control: no-store, private`; nothing is written to the log with the address.
  - a non-image's original on `media-bucket` redirects the same way.
  - on the server's disks it streams with `Content-Disposition: attachment`, exactly as today.
  - a person without `media.upload` gets 403 and no `Location`; an unknown ID gets 404.
- [ ] Red: 200 with a streamed body (the controller streams through PHP). Then D4. Quick check.
- Commit: `feat(media): download originals from a bucket through a five-minute signed address`

### Task 4: the bucket check

- [ ] Tests first, `tests/Feature/Media/Storage/BucketCheckTest.php` (`MockHandler` for the S3 calls, `Http::fake()` for the public and unsigned GETs):
  - all steps pass: exit 0, one line a step, the probes deleted.
  - the public GET answers 403: exit 1, "the public address … didn't serve the test file" and the probes still deleted.
  - the unsigned GET of the originals probe answers 200: exit 1, "the originals bucket is public".
  - `AccessControlListNotSupported` on the PUT: exit 1 with the ACL sentence.
  - both disks on one bucket, a public disk without `MEDIA_BUCKET_URL`, an `http://` public endpoint: refused before any request (the `MockHandler` queue untouched).
  - no output line holds the secret key (assert on the whole output).
- [ ] Red: command not defined. Then D5. Quick check.
- Commit: `feat(media): teme:media:bucket-check proves a bucket setup before a move`

### Task 5: the move's records

- [ ] Tests first, `tests/Feature/Media/Storage/MediaMoveOperationsTest.php`:
  - the operator starts, records and ends a run; the cursor only moves forward; problems stop at 1,000 while `failures` keeps counting.
  - **writes nothing when refused**, each Operation: an administrator's ID, an editor's ID, a null actor without the operator bound (assert the table unchanged).
  - a second start in the same direction while one is `running` is refused; resuming by its ID sets it `running` again.
  - the check constraints refuse a `changed` scope without `changed_since` and `from = to`.
- [ ] Red: table missing. Then D10, D11. Quick check.
- Commit: `feat(media): media_moves and the operator's three move Operations`

### Task 6: the move

Test disks: two locations under test config (`config(['media.locations' => […]])`) on `Storage::fake()` disks, so the copy runs on PostgreSQL and the local filesystem with no cloud; a small test double `tests/Support/Media/CorruptingAdapter.php` decorating Flysystem's `LocalFilesystemAdapter` that truncates or flips one byte on write for a chosen path; and one `MockHandler` case for the S3 side.

- [ ] Tests first, `tests/Feature/Media/Storage/MoveMediaTest.php`:
  - a library of images (original + variants, one with share images), a PDF and a trashed image: every file the rows name is on the target with the same bytes; the run is `finished`, `failures` 0; the output's last line says verified.
  - `--dry-run` counts items, files and bytes from the rows and writes no file and no row.
  - **resumable:** a run stopped after its first chunk (the test sends the trap's signal through a hook, or ends the run `stopped` through the Operation and calls again) resumes from its cursor; files of the cut chunk are counted present, not copied twice; totals add up to one full run's.
  - **verified:** the `CorruptingAdapter` damages one original → a failure naming it, not counted copied; damages one variant → passes by size only when the damage keeps the size, and fails with `--deep`; a truncated variant fails without `--deep`.
  - an original whose server copy no longer matches the row's SHA-256 is a failure and is not copied.
  - a file missing on both sides is a hole, named, not a failure; missing on the source but present and right on the target is present.
  - `--catch-up` touches only items whose `updated_at` is at or after the previous finished copy's `started_at`, and records `changed_since`.
  - `--only=public` copies no original of an image.
  - refused (exit 2, no row): from = to, unknown location, the target's public disk without `url`, the move lock held.
  - nothing in `app/` lists a folder (the existing `NoStorageListingTest` still passes; no new allow-list line).
- [ ] Red: command not defined. Then D6 (`MediaFiles`, `MediaMover`, `MediaMoveLock`, `MoveMedia`), and `MediaInventory::add()` on `MediaFiles` (its tests unchanged and green). Quick check.
- Commit: `feat(media): teme:media:move copies a library between locations, resumable and verified`

### Task 7: deleting the source

- [ ] Tests first, `tests/Feature/Media/Storage/DeleteSourceTest.php`:
  - refused, deleting nothing, each alone: the site still configured on the source; the latest copy has a failure; the latest copy started before the switch; a gap in the catch-up chain; a backup holding `BackupLock`.
  - passes after a full copy, the switch (config) and a catch-up started after it: every source file is gone, every target file is still there; a file whose target copy was removed in between is kept and counted a failure.
  - `--dry-run --delete-source` says which rule fails, deleting nothing.
- [ ] Red: the option is refused as unknown. Then D7 (`MoveGate`). Quick check.
- Commit: `feat(media): delete the source's copies only after a verified move`

### Task 8: backups with media in a bucket

- [ ] Tests first, extend `tests/Feature/Backups/BackupSiteTest.php`, `ManifestTest.php`, `RestorePreflightTest.php`, `BackupStatusTest.php` where they pin the refusal (Task 0 lists each test that asserts "isn't a folder on this server"):
  - with both roles in a bucket, a backup run **finishes**: database, app key and manifest snapshots, no media snapshot paths; the manifest's `media.locations` says `bucket` for both and still lists every original with its SHA-256; `figures.media_in_bucket` counts the files.
  - mixed (originals on the server, public in the bucket): only the originals' folder is backed up; the read-back samples only originals.
  - a restore of a backup whose originals were in a bucket restores the server's roles and reports one line for the other, without stopping; a manifest without `locations` restores as before.
  - the Tools › Backups page shows the sentence of D8 when a role is in a bucket and not otherwise (feature test on the page's props; no browser test needed).
- [ ] Red: `BackupRefused: The media disk “media-bucket” isn’t a folder on this server`. Then D8. Quick check plus `tests/Feature/Backups`.
- Commit: `feat(backups): back up the database when media is in object storage, and say so`

### Task 9: documents and records

- [ ] `docs/architecture/media.md`, *Storage*: the four disks, `MEDIA_STORAGE`, the two addresses, two buckets, the signed downloads, the move (one paragraph), and the line "Moving to object storage is a configuration change plus a copy" now pointing at the operator guide. *Not built yet* gains nothing; the parked items stay.
- [ ] `docs/architecture/backups.md`: D8 replaces the "media disk that isn't a folder" refusal (I-390) in *The run* and in the restore's section; the manifest's new keys.
- [ ] `docs/architecture/operations.md`, *The operator*: one sentence naming `teme:media:move` and its three Operations.
- [ ] New `docs/guides/operators/object-storage.md`: when to move; each provider's endpoint, region, path style and how it makes a bucket public (R2: custom domain, `region=auto`, and its `r2.dev` address is rate-limited and not for production; Spaces: `MEDIA_BUCKET_ACL=public-read`; B2: a public bucket and its S3 endpoint; AWS: a bucket policy or CloudFront; MinIO: path style and an anonymous-download policy); the CDN in front; CORS is not needed (the site never fetches media from the browser); the order of a move: `bucket-check` → full copy with the site live → catch-up → stop the workers, catch-up, set `MEDIA_STORAGE=bucket` and deploy, start the workers → catch-up again (the run that unlocks deletion) → weeks later, `--delete-source`, then the empty-folder line; going back with `--from=bucket --to=server`; what backups do (D8) and the mixed setup. Rows in `configure.md` for every new variable and `MEDIA_BUCKET_SECRET_ACCESS_KEY_FILE` in *Secrets from files*; one paragraph in `docs/guides/operators/backups.md`.
- [ ] Fill this plan's Records; `composer check` once.
- Commit: `docs(media): object storage, the move and backups`

## Quick check

`php artisan test --compact tests/Feature/Media/Storage tests/Architecture`, the touched backup tests when Task 8 is in play, `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse --memory-limit=2G` on the changed files, and `npx vp check` on the one changed page (Task 8). `composer check` once at the end (I-3317).

## Owns

- New: `app/Domain/Media/MediaLocations.php`, `app/Domain/Media/MediaFiles.php`, `app/Domain/Media/Storage/**`, `app/Domain/Media/Models/MediaMove.php`, the three Operations and their inputs, `app/Console/Commands/MoveMedia.php`, `app/Console/Commands/CheckMediaBucket.php`, the one migration, `tests/Feature/Media/Storage/**`, `tests/Support/Media/CorruptingAdapter.php`, `docs/guides/operators/object-storage.md`.
- Changed: `composer.json` and `composer.lock` (the one package), `config/filesystems.php`, `config/media.php`, `app/Domain/Deployment/SecretFiles.php` (one line), `app/Http/Admin/Media/MediaItemController.php` (`original()` only), `app/Domain/Addresses/PathLinkResolver.php` (`fileAt()` only), `app/Domain/Backups/MediaInventory.php`, `app/Domain/Backups/Restore/MediaRestore.php`, `app/Domain/Backups/Verify/VerifyBackups.php` (the sample's roles), the Tools › Backups page's props and one sentence on its page, `docker/compose.dokploy.yml`, `docker/compose.yml` (comment), the backup tests named in Task 8, `tests/Feature/Deployment/SecretFilesTest.php` (one dataset line), `docs/architecture/media.md`, `docs/architecture/backups.md`, `docs/architecture/operations.md`, `docs/guides/operators/configure.md`, `docs/guides/operators/backups.md`.
- Not owned: the upload path (`UploadMedia` and friends write through `MediaDisks` already and stay as they are), presigned uploads (I-175, parked), the image service socket (I-3455, parked), the orphan sweep (I-178, parked), `teme:deploy`, `docs/ideas.md` (the architect's).

## Records

<!-- Filled in while building: setup; commits; each test seen red (first line of the failure); B-?? decisions with the options weighed; architecture baselines before and after; the full gate's totals; STOP; changelog lines for the maintainer; try it. -->

**Setup.** `/tmp/teme-cloud-setup.log`: "2026-11-16T05:51:42Z ran in /home/user/teme as root" … "2026-11-16T06:03:33Z finished" (a first run at 05:49:57Z broke on a download and was run again); `pwd` `/home/user/teme`; PHP 8.4.27, PostgreSQL 18 and Valkey answering. Waited for `origin/main` to hold the join/v1-wave2 merge (`e35f71b`, pull request #328); branch `media/object-storage` starts there. Base `composer check` at `e35f71b`: Pint passed; Architecture **139** passed (1357 assertions); Unit and Feature **11577** passed, **2** skipped, **1** failed (`Plugins/DiscoveryTest`, a parallel flake that passes alone), 59956 assertions, 1761 s.

### Commits on `media/object-storage`

- `f0b191e` build(deps): add league/flysystem-aws-s3-v3 at exactly 3.35.3 (Task 0; `composer.json` and `composer.lock` only)
- `d954611` feat(media): object storage disks, media addresses and MEDIA_STORAGE (Task 1)
- `8bf65cf` feat(media): recognize media addresses from every storage location (Task 2)
- `89ab88c` feat(media): download originals from a bucket through a five-minute signed address (Task 3)
- `485d167` feat(media): teme:media:bucket-check proves a bucket setup before a move (Task 4)
- `75faadc` feat(media): media_moves and the operator's three move Operations (Task 5)
- `c86e33a` feat(media): teme:media:move copies a library between locations, resumable and verified (Task 6)
- `6e85ee2` feat(media): delete the source's copies only after a verified move (Task 7)
- `9ddfced` feat(backups): back up the database when media is in object storage, and say so (Task 8)
- `ed1ff11` fix(media): point a move's missing files at its status list, not the regenerate command (B-20)
- `d699692` docs(media): object storage, the move and backups (Task 9)
- this records commit

Task 0 confirmed in the installed 3.35.3: the disk's `options` become every write's options (`CacheControl`, `ACL`); `upload()` sends `$options['params']['ACL'] ?? determineAcl()`, so a disk without `ACL` sends `private`; extra disk keys such as `handler` reach `new S3Client`; `temporaryUrl()` with `ResponseContentDisposition` signs `response-content-disposition`. Every Operation that writes a file moves `updated_at`.

### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|
| 1 | `tests/Feature/Media/Storage/MediaDisksConfigTest.php` | `Disk [media-bucket] does not have a configured driver.` (the adapter was already in its own commit, so not "Driver [s3] not supported"); the `MEDIA_URL` case: `Failed asserting that two strings are identical.` |
| 2 | `tests/Feature/Addresses/PathLinkResolverTest.php` | `Failed asserting that null is identical to Array` (the server's address once the bucket is in use) |
| 3 | `tests/Feature/Media/Storage/OriginalDownloadTest.php` | `Failed asserting that 500 is identical to 302.` (the controller streamed through PHP: a HeadObject to the fake bucket) |
| 4 | `tests/Feature/Media/Storage/BucketCheckTest.php` | `CommandNotFoundException: The command "teme:media:bucket-check" does not exist.` |
| 5 | `tests/Feature/Media/Storage/MediaMoveOperationsTest.php` | `BindingResolutionException: Target class [App\Domain\Media\Operations\StartMediaMove] does not exist.` |
| 6 | `tests/Feature/Media/Storage/MoveMediaTest.php` | `CommandNotFoundException: The command "teme:media:move" does not exist.` |
| 7 | `tests/Feature/Media/Storage/DeleteSourceTest.php` | `InvalidOptionException: The "--delete-source" option does not exist.` |
| 8 | `tests/Feature/Backups/BackupSiteTest.php`, `ManifestTest.php` | `BackupRefused: The media disk “media-originals-bucket” isn’t a folder on this server, so Teme can’t back its files up.` The screen's test failed on the missing `mediaInBucket` prop. The new `MediaCheck` case in `RestorePreflightTest.php` was run against the code before it, but its failure line was not read back (expected: `Undefined array key "public"`). |

### B-?? (draft)

- **B-01 (draft):** the dependency. Options: add it in Task 1's commit, or alone. Chose its own `build(deps)` commit touching only `composer.json` and `composer.lock`, and reverted what `composer require` changed besides the package: the lock's `teme/contracts` version and `plugin-api-version`, and the files `boost:update` regenerated (`AGENTS.md`, `CLAUDE.md`, `boost.json`, the skills). Why: the brief allows that one package and nothing else. `f0b191e`.
- **B-02 (draft):** `.env.example` sets `MEDIA_DISK=media` and `MEDIA_ORIGINALS_DISK=media-originals`, which override `MEDIA_STORAGE`. Options: edit `.env.example` (not owned), or read the overrides with `?:` so an empty value counts as unset. Chose `?:` everywhere in both configs, left `.env.example` as it is, and the guide says to leave them unset. A site made from `.env.example` must delete those two lines before `MEDIA_STORAGE=bucket` works; worth a maintainer's look. `d954611`.
- **B-03 (draft):** the tests' bucket. Options: Guzzle's bare `MockHandler` queue, or a stateful AWS SDK handler. Chose `FakeBucket`, which keeps objects and answers PutObject, HeadObject, GetObject and DeleteObject, so a test reads back what it wrote, as the move needs. `d954611`.
- **B-04 (draft):** `configure.md`'s media section came in Task 1, not Task 9, since `InstallationTest` requires every `SecretFiles` name in the page. Its link to `object-storage.md` waited for Task 9 (`OperatorPagesTest` fails on a link to a missing page). `d954611`, `d699692`.
- **B-05 (draft):** `teme:media:bucket-check` gains `--only=public|originals`, so the mixed setup (no originals bucket) can be checked; refusals exit 1, as D5 says, not 2. `485d167`.
- **B-06 (draft):** the check also refuses an `http://` `MEDIA_BUCKET_URL` on the internet, as D5 does for the endpoint. "Local" means `localhost`, a private or loopback IP, or a name without a dot (a container service, such as `minio`). `485d167`.
- **B-07 (draft):** the migration adds two checks beyond D10's list: `site_disks` is a JSON object, and `finished_at` is set exactly when the state is `finished`; and `MediaMove`'s `site_disks` writes `{}` for an empty array, as the models rule asks of a JSONB object. `75faadc`.
- **B-08 (draft):** each move Operation authorizes inline (operator only). Options: a shared trait (a new file outside Owns) or three short methods. Chose the methods. `75faadc`.
- **B-09 (draft):** `CorruptingAdapter` extends Flysystem's `LocalFilesystemAdapter` (not final) rather than wrapping it, so a test damages a chosen write and everything else is the real local disk. `c86e33a`.
- **B-10 (draft):** the progress bar draws only on a decorated output (a person's terminal); the line after each chunk is always written. Why: in a log the bar is noise. `c86e33a`.
- **B-11 (draft):** `--catch-up` with no finished copy in that direction for the same roles is refused, exit 2: "Run a full copy first". `c86e33a`.
- **B-12 (draft):** a hole is named in `problems` with "missing in both locations" and counted in `holes`, not `failures`. `c86e33a`.
- **B-13 (draft):** a run is resumed only when kind, direction, roles, scope, `changed_since` and `deep` all match; a resumed run keeps the `items_total` counted at its start. `c86e33a`.
- **B-14 (draft):** the stop test drives `MediaMover`'s stopping hook, which the command's trap on signals 2 and 15 sets, rather than sending a real signal to the test process. `c86e33a`.
- **B-15 (draft):** a backup that starts during a `--delete-source` run stops the run (ended `stopped`, exit 3) instead of waiting for it. Why: the gate's promise is that nothing is deleted beside a backup, and waiting inside a long run hides that. `6e85ee2`.
- **B-16 (draft):** the gate reads its chain among finished copies with the same roles; the copy options refused beside `--delete-source` are `--catch-up` and `--deep`. `6e85ee2`.
- **B-17 (draft):** Task 8 needed four files outside Owns: `BackupSite.php` (the media part and the `media_in_bucket` figure), `Restore/MediaCheck.php` (skip a bucket role, which otherwise fails with an undefined key), `Restore/RestorePoints.php` (carry the restore's notes into the plan) and `resources/js/types/backups.ts` (the screen's prop). Options: stop, or treat Owns as missing them. Chose to change them, with only the lines D8 needs, since D8 can't be built without them and no other part owns them. `9ddfced`.
- **B-18 (draft):** with both roles in a bucket there is no folder for restic. Options: no media snapshot (but the run's check expects four parts), or a media snapshot from standard input. Chose `--stdin --stdin-filename media-in-object-storage.json` holding the locations, so every run still has its four parts and its check is unchanged. `9ddfced`.
- **B-19 (draft):** Task 9 adds the new page to `tests/Fixtures/Guides/OperatorGuide.php` `PAGES` and a row and reading-order line to `docs/guides/operators/README.md`, both outside Owns, since `OperatorPagesTest` requires every page in both. The page is marked `unproved`: nobody has moved a site to a real provider. `d699692`.
- **B-20 (draft):** D6 says the report points at `teme:media:regenerate` for holes, but that command remakes only images made with an older variant set and images lacking share images, not a missing variant. Chose to name the holes in `--status` instead, and the guide doesn't claim regenerate remakes them. A command that remakes missing variants is for the maintainers to decide. `ed1ff11`.

Open questions left as the plan has them: Q1 (whether D8 is the default) and Q4 (whether R2, B2 and Spaces accept `ACL: private` on a PUT) are for the maintainers, by hand on each provider.

### Architecture baselines

| Baseline | Before | After |
|---|---|---|
| `tests/Architecture/admin-words-baseline.txt` | 876 lines | 876 lines |
| `tests/Architecture/design-system-baseline.txt` | 222 lines | 222 lines |

### Gate

`composer check` once, at `d699692`: Pint passed; PHPStan no errors; Architecture **139** passed (1357 assertions); Unit and Feature **11656** passed, **2** skipped, **2** failed, 60318 assertions, 1832 s. The two failures pass alone (23 passed): `Plugins/LockOutTest` (plugin loading in a parallel run, like the base's `DiscoveryTest`), and `Site/DocumentShellTest`, whose script needs `URLPattern`, which the run's Node 22 lacks; with `/opt/node24/bin` first on the PATH it passes. Neither touches a file this part changed. Quick checks after each task passed, the last with `tests/Feature/Backups` (542 passed), `tests/Feature/Media/Storage` (64 passed) and `tests/Feature/Guides` (418 passed).

### STOP

None.

### Changelog lines for the maintainer

- Media can live in S3-compatible object storage (Cloudflare R2, DigitalOcean Spaces, Backblaze B2, Amazon S3, MinIO): set `MEDIA_STORAGE=bucket` and the `MEDIA_BUCKET_*` variables. Two buckets, a public one behind a CDN and a private one for originals.
- `MEDIA_URL` puts a pull CDN in front of media kept on the server.
- `php artisan teme:media:bucket-check` proves a bucket setup before anything moves.
- `php artisan teme:media:move` copies the media library between the server and a bucket, in either direction, with the site live: resumable, every original verified by its checksum, `--catch-up` for what changed, and `--delete-source` only after a verified move.
- Editors download an original from a bucket through an address signed for five minutes.
- Media addresses pasted into content keep working after a move.
- Backups: when media is in a bucket, a backup holds the database, the app key and the list of every original with its checksum, not the files, and Tools › Backups says so. Before, such a backup failed.

### Try it

With MinIO or a provider's two buckets set in the `MEDIA_BUCKET_*` variables and `MEDIA_STORAGE` still `server`: `php artisan teme:media:bucket-check`; `php artisan teme:media:move --dry-run`; `php artisan teme:media:move`; set `MEDIA_STORAGE=bucket`, clear the config cache, `php artisan teme:media:move --catch-up`; upload an image and see its address on `MEDIA_BUCKET_URL`; download its original from the admin (a 302 to a signed address); `php artisan teme:media:move --delete-source --dry-run`, then without `--dry-run`; `php artisan teme:media:move --status`; open Tools › Backups and read the sentence; run `php artisan teme:backup:run`. `docs/guides/operators/object-storage.md` gives the order on a live site.
