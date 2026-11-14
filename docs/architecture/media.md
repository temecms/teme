# Media

How files arrive, how images are prepared for readers, how editors find and describe them, and how Teme knows where each one is used. The decision is I-166. This document describes the system as built in slice 1.4, with the share images slice 1.9b′ added (*The share images*), and the code is checked against it.

## The rules this follows

- **Media is referenced by ID**, never by path or URL (rule 9). URLs are built at render time from the storage configuration.
- **No request does image work.** An upload stores the original and returns; the resized copies are made on the queue.
- **Nothing lists a storage folder.** What exists is recorded in the database: the original's path and every variant's path are on the item's row. An architecture test forbids listing calls in `app/`.
- **Every change is an Operation** (rule 1), including the queue's: the job does the image work, then records it through an Operation.
- **Files are never executable.** A stored file's extension comes from its detected type — the uploaded name can only choose among that type's own extensions — and only the types below are accepted.

## Storage

Two disks, both configurable (`config/media.php`, `config/filesystems.php`):

| Disk | Holds | Default |
|---|---|---|
| `media` (public) | Image variants, and the files of every non-image item | `storage/app/public/media`, served at `/storage/media` |
| `media-originals` (private) | Image originals | `storage/app/private/media-originals`, never served |

**Image originals are private.** Readers get variants, which are re-encoded and carry no camera metadata, so a phone photo's GPS position never reaches the public site. Editors can download an original from the admin. Every other file — PDF, audio, video — is public as uploaded.

**Keys are opaque and never need a lookup to be unique:**

```
{id}/v{file_version}/{name}.{ext}            the original
{id}/v{file_version}/{name}-w640.webp        a width variant
{id}/v{file_version}/{name}-thumb.webp       the square admin thumbnail
```

`{id}` is the item's ULID; `{name}` is the uploaded file name, slugged and cut to 80 characters, kept for search engines; `{ext}` comes from the detected type. The version segment changes on Replace, so a CDN never serves a stale file.

**The row stores paths, not disks or hosts.** Which disk a path lives on follows from the item's kind; the disk's URL comes from configuration. Moving to object storage is a configuration change plus a copy.

## The schema

```
media         id ulid, kind (image|video|audio|document), mime_type, extension,
              original_filename, name, original_path, file_version int,
              size_bytes bigint, sha256 char(64),
              width, height, animated bool,
              title, alt, caption, credit,
              focal_x, focal_y smallint 0–100 (50, 50 by default),
              variants jsonb {"w640": {"path","width","height","bytes"}, …, "thumb": {…},
                              "social": {…}, "crop-16x9": {…}, "crop-4x3": {…}, "crop-1x1": {…}},   -- the last four: the share images, 1.9b′
              variant_set int,
              processing (pending|ready|failed), processing_error,
              working_uses int, published_uses int,          -- 1.4b
              uploaded_by → users (restrict), timestamps, deleted_at

media_counts  kind, bucket (live|trash), count               -- primary key (kind, bucket)

media_usages  content_id → contents (cascade), media_id → media (cascade),
              scope (working|published), ref                  -- 1.4b; primary key (content_id, scope, ref)
```

This refines study K's sketch in two places. There is no `disk` column, for the reason above. There are two use counters, not one: an image a published page still shows, while the draft has moved on, has published uses and no working uses, and must not look unused.

**One row per item.** Variants live in a JSONB column on that row, not in a table of their own, which would grow as items × sizes. `variant_set` records which registered set the variants were made with, so a changed set is found with an indexed range, never by listing storage.

## Accepted files

The file's **content** is inspected with PHP's `fileinfo`; the name is ignored.

| Kind | Types |
|---|---|
| image | JPEG, PNG, GIF, WebP, AVIF, BMP, HEIC, HEIF — every format Laravel's `image` rule recognises, **if the configured image driver can decode it** |
| video | MP4, WebM, MOV |
| audio | MP3, M4A, Ogg, WAV |
| document | PDF; Word, Excel and PowerPoint, old and new formats; OpenDocument text, spreadsheet and presentation; plain text; CSV (I-168) |

Web pages, SVG, scripts, archives and executables are refused whatever they are named. Office and OpenDocument files download rather than display; text and CSV display as text, which cannot run.

**The stored extension comes from the detected type.** Where `fileinfo` cannot tell formats apart — a CSV from plain text, or the old Office formats from one another — the uploaded name may choose, but only among that type's own extensions.

GD, the default driver, cannot decode HEIC or HEIF. On GD they are refused with a message saying so; with Imagick they are accepted. iPhone browsers already convert HEIC photos to JPEG when uploading.

Limits live in the **Media settings group** (`settings.media.manage`): `max_upload_mb` (default 64) and `max_megapixels` (default 50, the defence against a small file that decompresses into gigabytes). The web server's and PHP's own upload limits must be at least as large; the deployment guide (1.13) says so.

**Duplicates** are found by SHA-256. An upload matching a live item is refused with a *duplicate* answer naming that item; the uploader chooses *Use the existing one* or *Upload anyway*, which repeats the upload with `allowDuplicate`.

## Variants

Registered in code, in `App\Domain\Media\MediaVariants`:

- **Widths** 320, 640, 960, 1280, 1920 and 2560, each made only if the original is wider, plus one at the original's own width when it is under 2560. Never enlarged.
- **A 300 × 300 thumbnail**, cropped around the focal point.
- **Format WebP**, quality 80 (decision 2A). Rotation from the camera's orientation is applied; metadata is stripped.
- **Animated images** are flagged, and their variants are stills made from the first frame; the original stays private like every image original. Whether the public site plays animation is decided with the image card in 1.5.
- **Share images** for an image used as a featured, social or default social image: four more keys in the same map (*The share images*, below).
- **`MediaVariants::SET`** is 1. Changing the widths, the thumbnail or the quality raises it, and `php artisan teme:media:regenerate` remakes every older item, a chunk at a time, resumably. The share images change no `SET`.

The image library is **Intervention Image 4**, the current major version (decision 3A, I-167), wrapped in Teme's own `ImageEngine` interface. Its driver is `MEDIA_IMAGE_DRIVER` (`gd` by default, or `imagick`).

### The processing pipeline

1. `UploadMedia` stores the original and writes the row with `processing = pending` (images) or `ready` (everything else). After commit it dispatches `GenerateMediaVariants`, carrying the uploader as its actor. It goes on the long queue: the connection `database-long`, the same `jobs` table, whose retry window is 360 seconds, past the job's 300; the default connection's is 90. A worker of its own takes it, `php artisan queue:work database-long --queue=long`, beside the usual `php artisan queue:work`. One worker reading both queues wouldn't do: a database worker hands a job to another after its own connection's window. `php artisan dev` starts both; in tests every queue runs inline (`DB_QUEUE_LONG_DRIVER=sync`). `tests/Architecture/QueueWindowsTest.php` holds every queued job 15 seconds inside its connection's window.
2. The job checks the item's `file_version` still matches, copies the original to a temporary file, has the `ImageEngine` make every variant, and writes them to the `media` disk.
3. It calls **`RecordMediaVariants`**, which locks the row, refuses if the version has moved on (a Replace happened meanwhile), stores the variants map, sets `ready`, and returns the paths the new map no longer uses. The job deletes those — and, if the Operation refused, the files it just wrote.
4. When the job's last attempt fails, it records `failed` with the error, through the same Operation. The library shows it on the tile, with *Retry*, which is `RequestMediaProcessing`.

The job runs as the person who caused it. The regeneration command runs as the operator, in its own process, because a queue worker never binds the operator (`docs/architecture/operations.md`, *The operator*).

### The share images

Search engines and social cards want pictures the page's widths don't give, so an image that is *used* as one gets four more (slice 1.9b′; `docs/superpowers/plans/2026-10-08-phase1-slice09b2-share-images.md`, D1 to D3 are I-1094 to I-1096; the rule is I-700 and I-855, which supersedes study Z §4.5's "every image is regenerated once").

- **The four** are keys in the image's one `variants` map: `social` (1,200 by 630, the card Open Graph shows), and `crop-16x9` (1,200 by 675), `crop-4x3` (1,200 by 900) and `crop-1x1` (1,200 by 1,200), which the structured data lists (`MediaVariants::SHARE`). Each is cropped around the focal point and never enlarged: an image narrower or shorter than a share image keeps its ratio at the largest size it allows (`MediaVariants::shareSize()`, `FocalCrop::fit()`). No key starts with `w`, which `MediaUrls::largest()` reads as a width, and `ImageMarkup`'s `srcset` reads only `w\d+` keys, so no page's markup changes. `MediaVariants::SET` stays 1.
- **When they are made.** Only when the image is *wanted* (`ShareImageUse::wanted()`): used as the featured image or the social image by any working or published revision (`media_usages` records both as `field:{key}`), or chosen as the site's default social image (Settings › Search and social, 1.9i). It is one map, one maker, one record: `VariantMaker::make()` adds the four whenever the image is wanted, so every remake keeps them (a replaced file, a moved focal point, a retry, `teme:media:regenerate`), deleting the item deletes them (`DeleteMediaPermanently` deletes every path in the map), and `RecordMediaVariants` discards a set no longer made as it discards any obsolete size. An image nothing uses so costs nothing extra.
- **First use queues the remake, as the person who used it** (I-1095). When `MediaProjection::sync()` adds a usage of the featured or social field to an image whose map has no `social`, it queues `GenerateMediaVariants` after commit with the saver as actor and the item as `usedBy`. The job passes both to `RecordMediaVariants`, which has a second case of authorization for it: an actor who may not change the image may still record its variants when they may edit `usedBy` and that item uses the image so (`ShareImageUse::usedBy()`); everyone else is refused, as before (I-392). An operator's `ReindexMediaUsages` queues the job as the operator, and the job binds the operator itself for a null actor, as an operator's upload does. The default social image is queued when someone chooses it (`DefaultImageCard`, on `SettingsChanged`), as the person who chose it and with no `usedBy`, so `RecordMediaVariants` accepts the result only from someone who may change the image itself, as an administrator may; from anyone else it is refused, and the job's failure is not reported. Nothing is queued for a change no person made, and `teme:media:regenerate` makes the card either way.
- **A race closes itself.** A record that lands without the four while the image is wanted, as when an upload's remake began before the use committed, queues one more remake.
- **An upgraded site gets them with `teme:media:regenerate`,** which also remakes every wanted image that lacks them (`ShareImageUse::lacking()`, the default social image counted).
- **Who serves them.** `PageHead` gives the page's social card the `social` variant once it exists and the widest `w…` width until then (I-855), and the structured data's ImageObject lists the three crops when they have been made (`docs/architecture/themes.md`, *The head*).

## The Operations

| Operation | Does | Authorised by | Slice |
|---|---|---|---|
| `UploadMedia` | Stores a file and creates its item | `media.upload`, or the operator | 1.4a |
| `RecordMediaVariants` | Records a processing result | `update` policy; the editor of an item that uses the image as its featured or social image, for the share images' first use (*The share images*); or the operator | 1.4a, 1.9b′ |
| `RequestMediaProcessing` | Queues processing again | `update` policy | 1.4a |
| `UpdateMediaDetails` | Title, alt text, caption, credit, focal point; a moved focal point remakes the variants | `update` policy | 1.4a |
| `ReplaceMediaFile` | A new file of the same kind under the same ID; the old version's files are deleted after commit | `update` policy | 1.4a |
| `TrashMedia` / `RestoreMedia` | Sets and clears `deleted_at`; files stay | `delete` policy | 1.4a |
| `DeleteMediaPermanently` | Removes a trashed item; its files are deleted after commit | `delete` policy, or the operator | 1.4a |
| `ReindexMediaUsages` | Re-derives the usage projection for a chunk of content; an image it finds used as a featured or social image without its share images is queued as the operator | the operator | 1.4b, 1.9b′ |

The `update` policy passes for `media.edit.any`, or for `media.edit.own` on one's own upload; `delete` likewise with `media.delete.*`.

| Permission | Administrator | Editor | Author | Contributor |
|---|---|---|---|---|
| `media.upload` | ✓ | ✓ | ✓ | — |
| `media.edit.own`, `media.delete.own` | ✓ | ✓ | ✓ | — |
| `media.edit.any`, `media.delete.any` | ✓ | ✓ | — | — |
| `settings.media.manage` | ✓ | — | — | — |

Contributors cannot upload, as in WordPress, but they can pick an existing image in the picker, which only needs `content.create`.

**Counts.** `MediaCounts::apply($before, $after)` moves one item between `(kind, live|trash)` rows inside each Operation, as `ContentCounts` does. `teme:counts:rebuild` recomputes them.

**Trash.** Trashing keeps the files, so restoring brings every use back unchanged (decision 5B). Pages render a trashed item as missing. `teme:trash:purge` deletes items trashed longer than `content.trash_days`, a chunk at a time. **Delete permanently** from the trash is the path for a takedown that cannot wait.

**Deleting a user** reassigns their uploads to the same person their content goes to, and is refused without one, as for content (I-102).

## Alt text (decision 4A)

- **The item holds a default** alt text, caption and credit. Changing it changes every page using the default, at once — the reason for having a default. Item metadata is not versioned.
- **Each use may override it.** A media field's stored value is `{"id": "<ulid>", "alt": null | "…", "decorative": false}`. The override and the *decorative* mark live in the content's fields, so they are versioned with the content (I-144's principle).
- **A use is missing alt text** when it is not decorative, has no override, and its item has no default. Publishing from the list says which images are missing it; it never blocks. The editor's pre-publish check does the same in 1.5.
- The library's **Missing alt text** filter lists images with no default.

## Where media is used (1.4b)

`MediaProjection::sync($content)` is called by every content Operation after `TermProjection::sync()`, in the same transaction, and works the same way:

| Scope | Rows present when | Built from |
|---|---|---|
| `working` | The item is live | The working revision's media fields |
| `published` | The item is live **and** published | The published revision's media fields |

`ref` names where the reference sits: `field:<field key>` now, `node:<node id>` when cards arrive in 1.5. `sync()` writes only the difference and moves `media.working_uses` and `media.published_uses` by exactly the rows it adds or removes. References to items that no longer exist are skipped.

`php artisan teme:media:reindex-usages` re-derives every row from the revisions, a chunk at a time; `teme:counts:rebuild` checks the counters.

**The featured image** is a built-in field, key `01K5Z0F1ED00000000000000F6`, of type `media`, images only. Until the editor arrives it is set from quick edit, with the media picker.

## The library (1.4c)

- **Paging is keyset**, newest or oldest first, by `(created_at, id)`. No totals under a filter or a search.
- **Tabs:** All · Images · Video · Audio · Documents · Trash, with counts from `media_counts`. An empty tab is hidden, except All.
- **Filters:** search, *Mine*, *Unused*, *Missing alt text*, a date range, and *Uploaded for*, an item's ID in the address (`uploaded_for`), reached from the drawer and shown as a chip.
- **Uploaded for** (I-553): an upload made in the editor, in the media modal or by Import images, records the item being edited, but only when the item exists, isn't in the trash and the uploader may edit it; otherwise the file uploads and records nothing. Uploads on the Media screen record nothing. In the editor the modal's box "Uploaded for this {noun}" narrows it to them, and the drawer names what a file was uploaded for, linking to the library narrowed to that item. A private item's title shows only to whoever may edit it, and reads "A private item" to anyone else, in *Used in* and *Uploaded for* alike (`PrivateTitles`, I-1700).
- **Search** is by trigram over file name, title, alt text and caption, through one expression index.
- **Grid or list**, chosen in the URL.
- **Uploads:** one request per file, three at a time, each with its own progress bar, error and *Retry*. A pending tile polls until its variants are ready.
- **The drawer** shows the preview, file name, type, dimensions, size, uploader and date; edits title, alt text, caption and credit; sets the focal point by clicking the preview; lists *Used in*; offers *Replace*, *Download original*, *Retry processing*, *Move to trash* (listing published uses first) and, in the trash, *Restore* and *Delete permanently*.

### Indexes

All partial on `deleted_at IS NULL` unless noted:

| Index | Serves |
|---|---|
| `(created_at, id)` | All, by date |
| `(kind, created_at, id)` | The kind tabs |
| `(uploaded_by, created_at, id)` | Mine |
| `(created_at, id) WHERE working_uses = 0 AND published_uses = 0` | Unused |
| `(created_at, id) WHERE kind = 'image' AND (alt IS NULL OR alt = '')` | Missing alt text |
| `(deleted_at, id) WHERE deleted_at IS NOT NULL` | Trash |
| `(sha256)` | Duplicate detection |
| `(variant_set, id) WHERE kind = 'image'` | Regeneration |
| `GIN (media_search_text(…) gin_trgm_ops)` | Search |
| `media_usages (media_id, content_id, scope)` | *Used in*, one row per content item, in order |

## The benchmark (1.4d)

The data set adds a million media items — 80% images, 10% documents, 6% audio, 4% video — with a featured image on 60% of posts, so some items are used thousands of times and most are used once or never. The runner adds the library's tabs, filters, search and deep pages, the picker, and *Used in* for the most-used item, under the existing budgets (I-148, I-153). It also checks a fixed number of queries per request (check 4): a deep page must cost what page one costs.

**Result (`docs/benchmarks/v0.1.0-alpha.4.md`).** Every query-plan rule passed against a million content items and a million media items together — no sequential scan, no sort of more than a page, and no deep page that ran more queries than its page one. Five scenarios missed their time budget on a loaded development machine; each was checked by hand and traced to host load or the synthetic data set (never a plan that grows with the table), so none blocks the tag (I-153, B-101 through B-104).

## Not built yet

- The image card, galleries, and responsive `srcset` markup (1.5 and 1.6).
- The editor's pre-publish alt-text check (1.5).
- AI alt-text suggestions (1.10).
- Media import from WordPress (1.11).
- Each parked item has its row in `docs/ideas.md` and a `// Later` comment where it will attach (I-169): SVG (I-170), crop and rotate (I-171), tags or collections (I-172), AVIF (I-173), sizes on first request (I-174), presigned uploads (I-175), video, audio and PDF processing (I-176), private media until publication (I-177), an orphan-file sweep (I-178), and bulk media actions (I-179).
