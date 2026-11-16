# Proposal: media in S3-compatible storage behind a CDN

**Status:** draft, 2026-11-16. **Decides:** I-3456 (adopted 2026-11-15/16: media in S3-compatible storage behind a CDN, in core). **Revisits:** I-390 (backups refuse a media disk that isn't a folder on the server). **Plan:** `docs/plans/media-object-storage.md` (drafted as `media-object-storage.md` beside this file). **Branch:** `media/object-storage`, after the v1 join merges.

## What it is

A site can keep its media files in S3-compatible object storage (AWS S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2, MinIO) instead of on the server's disk, and serve them from a CDN or a public bucket address. Image originals stay private, in a second bucket; editors download one through a link that works for five minutes. An operator moves an existing library, tens of thousands of files, with one console command that can be stopped and resumed, checks every file it copies, and deletes nothing on the server until a verified move and an explicit flag.

## Why it is small

Teme already references media by ID and builds every address at render time (rule 9), and every media read and write already goes through two named disks with streams. So nothing in content is rewritten, no per-file "where is it" table is needed, and the switch is the operator's configuration plus a copy. WordPress needs a plugin with a per-item table and output rewriting for this because its content stores absolute upload addresses; Teme doesn't.

## Settled

1. **The S3 adapter is `league/flysystem-aws-s3-v3` at exactly 3.35.3** (approved), which brings the AWS SDK for PHP. Nothing else is added.
2. **Where media lives is environment configuration, not an admin setting.** `MEDIA_STORAGE=server|bucket` switches both disks; `MEDIA_URL` sets the address of the server's media (for a pull CDN), `MEDIA_BUCKET_URL` the CDN or public address of the bucket. Reasons: the address must match where the files physically are, which only the operator's storage setup decides; disks and their credentials are already environment configuration; a deploy already clears the page cache, which an address change needs. `MEDIA_DISK` and `MEDIA_ORIGINALS_DISK` remain for a mixed setup.
3. **Two buckets: a public one for variants and non-image files, a private one for image originals.** A provider makes a whole bucket public, so one bucket with two folders would expose originals through the CDN. The originals' disk has no public address at all.
4. **Public access is set on the bucket, not on each file.** Files are written with the default private ACL; `MEDIA_BUCKET_ACL=public-read` exists only for providers that need per-object ACLs (DigitalOcean Spaces) and never applies to originals. Every public file is sent with `Cache-Control: public, max-age=31536000, immutable`, safe because each key carries the file version.
5. **Endpoint, region, bucket, folder prefix and path-style addressing are configurable**, so R2, Spaces, B2 and MinIO work with the same disk.
6. **Originals are downloaded through a five-minute signed address**: the same admin route and permission as today, now answering with a redirect when the original is in a bucket, never a public address, never stored or logged. On the server's disk it streams as before.
7. **`teme:media:bucket-check`** proves a setup before a move: writes, reads back and deletes a test file on each bucket, confirms the public address serves it and that the originals bucket does not, and explains provider errors in plain words.
8. **`teme:media:move`**, the operator's command: copies what the database rows name (never a folder listing), in chunks, resumable after a stop, one move at a time; checks every file's size, reads back every original against its recorded SHA-256 (all files with `--deep`); counts files missing on both sides separately from failures; reports progress; works in both directions; records each run through three operator-only Operations in one `media_moves` row per run.
9. **The server's copies are deleted only by `--delete-source`,** and only after a move with no failures that ran while the site already used the bucket, with an unbroken chain of catch-up runs back to a full one, and never while a backup holds its lock. Each file's bucket copy is checked again just before its server copy is deleted.
10. **Backups with media in a bucket** (provisional until Q1 is answered): the run backs up the database, the app key and the manifest and succeeds; a bucket's files are not copied into restic; the manifest still lists every original with its checksum and says where media lived; Tools › Backups says the files are in object storage and asks the operator to turn on versioning or replication at the provider. Restore restores the server's media and says which role was in a bucket.
11. **Addresses pasted into content keep resolving to the item** from any location (server path or bucket address), so a move never turns an internal link into an outside one.
12. **Tests use no cloud**: Laravel's fake disks for the move, the AWS SDK's mock handler for what is sent to a bucket, the HTTP fake for the public checks. The repository's development services have no MinIO, and this part adds none.

## Provisional (built as written unless the maintainers say otherwise)

- P1. The signed download lasts 300 seconds.
- P2. `--chunk` defaults to 100 items; problems kept per run: the first 1,000.
- P3. `MEDIA_BUCKET_REGION` defaults to `us-east-1`; the guide tells R2 users to set `auto`.
- P4. Files are copied one at a time. Parallel copies are a later idea if a large library proves too slow.

## Open questions for the maintainers

- **Q1. Backups when media is in a bucket.** (a) Recommended: back up the database, key and manifest, leave the files to the provider's versioning or replication, and say so on the backups screen (Settled 10). (b) Recommend the mixed setup instead: originals stay on the server, backed up by restic, while only variants (which can be remade) go to the bucket. (c) Copy the bucket into restic each night through a temporary folder: complete but costly in time, disk and transfer. Option (a) changes I-390's refusal; the maintainers decide.
- **Q2. Should the nightly backup also check that each original is still in the bucket** (one request per original, tens of thousands a night)? Recommended: no for now; a `--deep` move run is the full check when wanted.
- **Q3. Old `/storage/media/...` addresses after the server's copies are deleted** (search engines' image indexes, other sites' links): answer them with a redirect to the bucket address (an nginx rule and a fallback route), or let them answer 404? Recommended: park as a later idea and build it before the first site deletes its server copies.
- **Q4. Provider check by hand.** The tests prove what Teme sends, not what each provider accepts. Unverified: that R2, B2 and Spaces accept the default private ACL header on upload, and that R2 accepts `us-east-1` as a region. Recommended: the maintainers run `teme:media:bucket-check` and a small move against R2, Spaces, B2 and MinIO before the release notes name them as supported.
- **Q5. Trim the AWS SDK's unused service definitions** in the image (the SDK documents a Composer script that keeps only named services). It reduces the image size; it is a `composer.json` change, so it needs the maintainers' yes.

## Not in this part

Uploads straight from the browser to the bucket (I-175, parked), on-the-fly image transforms by URL (I-3455, parked), a sweep for orphaned files (I-178, parked: it would list storage), a storage screen in the admin, per-item storage state, Google Cloud Storage or Azure (not S3-compatible).

## References checked

The reference systems were listed (40 folders) and each searched for remote or S3 media storage, signed addresses and offloading. Those that have the feature:

| System | File read | What it teaches |
|---|---|---|
| Craft CMS 5 | `src/base/Fs.php` (`getRootUrl()`), `src/models/Volume.php` (`transformFs`) | A filesystem's base URL is a property of the filesystem, parsed from the environment; transforms can live on a different filesystem from the originals. Matches two disks and env addresses. (Its S3 filesystem is a separate plugin, not in the reference.) |
| Statamic | `src/Assets/AssetContainer.php` (`accessible()`, `private()`, `url()`) | A container is private exactly when its Laravel disk has no `url`; a private container never yields a public address. Adopted for the originals' disk, with a guard test. |
| Ghost | `ghost/core/core/server/adapters/storage/S3Storage.ts`, `compose.dev.storage.yaml` | Config-file storage with a required `cdnUrl`, endpoint and path-style options, multipart above a threshold; old local addresses redirected to the CDN; an S3-compatible container for development (Teme's tests mock instead, since its compose has none). |
| Payload | `packages/storage-s3/src/getFile.ts`, `adapter.ts` | Access-checked route that answers 302 to a presigned GET (`signedDownloads`, default 7,200 s). Adopted, with a shorter life. |
| Strapi | `packages/providers/upload-aws-s3/src/index.ts` | Don't force a public ACL: ACL-disabled buckets and R2 reject it; `baseUrl` for a CDN. Adopted: public access belongs to the bucket. |
| emdash | `docs/src/content/docs/deployment/storage.mdx`, `packages/core/src/storage/s3.ts` | Settings from `S3_*` variables at process start, `publicUrl` for a CDN, path-style on; "a database backup contains media metadata, not the stored files, so back up the storage backend separately"; R2's `r2.dev` address is rate-limited. Supports Settled 2 and 10. |
| pursae (WordPress in Go) | `docs/gocdn-features.md` | Its WP Offload Media equivalent: provider presets, an ACL toggle for ACL-less buckets, `Cache-Control: immutable`, a connection test (PUT, HEAD, public GET, DELETE), a resumable bulk job, "download back", never deleting the only copy, environment settings that override and lock the database's. Taken: the check, both directions, the deletion gate. Left: per-item state and output rewriting, which Teme's ID references make unnecessary. |
| October CMS 4 | `modules/backend/controllers/Files.php` (`getTemporaryUrl()`) | Redirect to a temporary URL for a cloud disk instead of streaming through PHP. |
| Winter CMS | `config/cms.php` (`storage.uploads.temporaryUrlTTL`, `path`) | Temporary-URL lifetime and a full-URL path for media as configuration. |
| Wagtail | `wagtail/documents/views/serve.py` | After its permission check, a document on remote storage is served by redirecting to the storage's URL. |
| Twill 3 | `config/media_library.php`, `src/Helpers/media_library_helpers.php` | `endpoint_type` local or s3, an `acl` option, direct browser uploads to S3 (Teme's I-175, parked). |
| Filament 5 | `packages/forms/src/Components/BaseFileUpload.php` | Private files are shown through `temporaryUrl()` and fall back to `url()` when the driver can't sign. |
| Sharp 10 | `src/Form/Eloquent/Uploads/SharpUploadModel.php` | `temporaryUrl()` for 30 minutes on a private disk. |
| artisanpack-ui-media-library | `docs/installation/requirements.md` | Uses the same adapter (`league/flysystem-aws-s3-v3`) for S3. |
| Drupal | `core/modules/system/src/FileDownloadController.php` | No object storage in core; private files go through an access-checked download controller, the same shape as Teme's original download. |
| WordPress (trunk) | `src/wp-includes/functions.php` (`upload_dir` filter), `src/wp-includes/post.php` (`wp_get_attachment_url` filter) | What WordPress lacks: no object storage in core, only filters; content stores absolute upload addresses, which is why offload plugins must rewrite output. |

Searched and without the feature: acorn, the other artisanpack-ui packages, basehim, capell, emdash-exporter, Joomla 6.2, laradashboard, millicache, MultilingualPress, phprs/kaletacms, Polylang, pubvana, Rank Math, react-starter-kit, Sage, TranslatePress, TypiCMS, webblocks-cms, Yoast SEO.

**Outside (gap only):** the WP Offload Media plugin's own documentation was not fetched; its behavior is known here only through pursae's feature list, which describes itself as covering the same ground. Packagist was read for the adapter's current release (3.35.3, 2026-08-08) and its requirements; the adapter's `AwsS3V3Adapter.php` at tag 3.35.3 was read for its ACL handling.

## Decided (the maintainers, 2026-11-16)

All open questions as recommended:

- Q1 backups: as recommended: a backup holds the database, the site key and the media manifest; media files stay in the bucket, with the provider's object versioning on.
- Q2 nightly check of originals in the bucket: no.
- Q3 redirect old /storage/media addresses: parked until a site deletes its server copies.
- Q4 provider details (R2, Spaces, B2 ACL header; R2 region): checked on the first real bucket; the builder records the defaults as unverified.
- Q5 trimming the AWS SDK from the image: later.
- Media addresses come from the environment, not an admin setting.
