# v1 wave 2: the management API, foundation

**One part, one branch: `api/manage-foundation`, from `origin/main`, after wave 1's part A (`plugins/content-write`) has merged** (it adds `content_revisions.via`, which this part writes).

Steps use checkbox (`- [ ]`) syntax for tracking.

**Written 2026-11-15** for `temecms/teme`. Lighter plan (I-569): decisions, names, schema, permissions, tests and the delicate code are written out; the rest is described.

**Goal.** An outside tool, holding a key an administrator made, can sign in to `/api/manage/v1`, look up terms and people, upload media, and create posts and pages, all through the existing Operations, acting as the key's person.

**Spec:** `docs/proposals/management-api.md` (its Settled section is binding). `docs/architecture/security.md` (elevation, commitment 12; throttles). `docs/architecture/operations.md`. The delivery API's plan (3.1) for the API's conventions: `routes/api.php`, `App\Domain\Api\Problems`, `ApiWords`, `AnswerAsApi`.

**Bound by:** rule 1 (every write through an Operation), rule 4 (no `serialize()`: abilities are JSONB), rule 6 (events after commit), rule 7 (bodies are Tiptap JSON), rule 9 (no absolute site URLs stored), I-091 (problem answers never leak internals), I-485 (the switch at the top of its page), I-3439 (American English), I-569, I-3317.

**New dependencies:** none (no Sanctum or Passport: a key is one hashed row and one middleware). **Migrations:** one, creating `api_keys`. **Permissions:** one new, `api.keys.manage` (administrators only). **Operations:** two new, `CreateApiKey`, `RevokeApiKey`.

---

## What main does today (Task 0 confirms each, with its line)

- `routes/api.php` serves `/api/v1` (read, anonymous, cached) and a catch-all problem for any other `/api/*` address; a part's routes go in `routes/api/*.php` and must be added before the catch-all.
- `App\Domain\Api\Problems` turns anything thrown under `/api` into an RFC 9457 problem with words from `ApiWords`.
- `CreateDraft`, `SaveDraft`, `PublishRevision`, `SchedulePublication`, `UpdateContentDetails`, `UploadMedia`, `UpdateMediaDetails` authorize their input's actor through the Gate. After wave 1 part A, four of them take an optional `via`.
- `BaseOperation` binds `DeniedElevation` outside the admin, so an elevated Operation is refused for any token.
- `BuiltInFields` registers `meta_description`, `seo_title`, `excerpt` and the other Search and social fields by handle. There is no focus-keyword field.
- HTML to Tiptap conversion lives in `App\Domain\Import\Convert` (`BlockConverter`, `Html\HtmlBlocks`) and takes an import `BlockContext`.

## The decisions, written out

**D1. The table.** `database/migrations/2027_06_23_100000_create_api_keys_table.php`:

```php
Schema::create('api_keys', function (Blueprint $table): void {
    $table->ulid('id')->primary();
    $table->text('name');                                  // shown in the list; unique among live keys
    $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // the person it acts as
    $table->jsonb('abilities');                            // list<string>, from ApiAbility
    $table->char('token_hash', 64)->unique();              // sha256 of the secret
    $table->string('token_start', 12);                     // "teme_" + first 7 chars, shown in the list
    $table->timestampTz('expires_at')->nullable();
    $table->timestampTz('last_used_at')->nullable();
    $table->timestampTz('revoked_at')->nullable();
    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
});
```

Deleting the person deletes their keys (a key never outlives its actor). A check constraint keeps `abilities` a JSON array.

**D2. The secret.** `teme_` followed by 40 random base62 characters (`Str::random` is not enough: use `random_bytes` encoded base62). Shown once in the creation answer, never stored, never logged. Lookup: `sha256(secret)` against `token_hash` (unique index), then `hash_equals` on the stored hash. A revoked, expired or unknown key answers the same `unauthorized` problem.

**D3. Abilities.** `enum App\Domain\ManageApi\ApiAbility: string { case SiteRead = 'site.read'; case MediaWrite = 'media.write'; case ContentWrite = 'content.write'; case ContentPublish = 'content.publish'; }`. A route names the abilities it needs; a request needs every one of them AND the person's permission through the Operation.

**D4. Middleware, in order, on `Route::prefix('api/manage/v1')`:** `throttle:api-manage-address` (per IP, 30/min, before reading a key), `EnsureManageApiOn` (the switch; `api_off` problem), `AuthenticateApiKey` (sets the request's user to the key's person and binds the key on the request), `throttle:api-manage` (per key, the setting, default 60/min), `NoStore` (`Cache-Control: no-store`; no ETag). The routes file is `routes/api/manage.php`, required from `routes/api.php` in its own group before the catch-all. These routes never touch the page cache.

**D5. `last_used_at`** is written at most once a minute per key, by a plain query outside any Operation (a technical timestamp, like a session touch; record it under rule 1's allow-list only if the architecture test demands, as a STOP).

**D6. The Operations.** `CreateApiKey` (authorize: `api.keys.manage`; **requires elevation**; validates name, person, abilities ⊆ ApiAbility, optional expiry in the future; returns the secret once; records a security event `api_key.created`). `RevokeApiKey` (authorize: `api.keys.manage`; requires elevation; sets `revoked_at`; event `api_key.revoked`). Both with "writes nothing when refused" tests.

**D7. Creating an item.** `POST /items` with `{type, title, body | html, slug?, terms?: {taxonomy: [ids]}, featured_media?, author?, fields?: {handle: value}, status: draft|published|scheduled, publish_at?}`. Steps: `CreateDraft` (actor = key's person, `via` = `api:<key name>`), then `UpdateContentDetails` for slug/author when given, then `PublishRevision` or `SchedulePublication` when asked and the key has `content.publish`. If a later step is refused, the draft stays and the answer is `202` with `{id, status: "draft", problem: {…}}`. Terms by id only in this release (ids from the terms endpoint). `author` must be a person the key's person may credit.

**D8. HTML bodies (P1).** Task 0 checks whether `BlockConverter` can run with a context that has no import run (no source site, a no-op report). If yes: `html` is converted that way, links and images kept as given. If not: `html` answers a `not_supported_yet` problem, and that is recorded as a B-?? (no STOP).

**D9. Answers.** Success bodies follow the delivery API's envelope where it fits (`data`, `links`); an item answer carries `id`, `type`, `status`, `slug`, `title`, and its public address when published. Media answers carry `id`, `url` (generated, never stored), `alt`, `caption`, `description`.

**D10. The settings page.** Settings › Management API: the switch at the top (I-485), then the keys list (name, person, abilities, created, last used, expiry, revoked), "Create a key" (dialog: name, person, abilities, optional expiry → shows the secret once with a Copy button and "You won't see this again") and "Revoke" (confirm). Built on the design system's settings page pattern and the save bar. Every word through `__()`.

## Tasks

### Task 0: confirm the start
- [ ] Confirm each line of "What main does today" and D8's question; record differences as `B-?? (draft)` and follow the code.

### Task 1: keys, abilities and the Operations
- [ ] Tests first, `tests/Feature/ManageApi/ApiKeysTest.php`: an administrator with a fresh password creates a key and sees the secret once; the row holds only the hash and `token_start`; refused and nothing written: an editor, no fresh password, an ability not in the list, a past expiry; revoke sets `revoked_at`; both write a security event.
- [ ] D1, D2, D3, D6 and the permission. Build; the quick check.

### Task 2: sign-in, the switch and the limits
- [ ] Tests first, `ManageApiAuthTest.php`: `GET /me` with a good key answers the key, person and abilities; no key, a wrong key, a revoked key and an expired key each answer the same `unauthorized` problem; with the switch off, `api_off`; the per-key limit answers `too_many_requests` after the limit; answers carry `no-store`; `last_used_at` moves at most once a minute; an elevated Operation reached through a key is refused.
- [ ] D4, D5, `GET /me`. Build; the quick check.

### Task 3: terms and people
- [ ] Tests first, `ManageApiLookupTest.php`: terms of a taxonomy with id, name, slug, parent; an unknown taxonomy is `not_found`; people who may be credited; both need `site.read`.

### Task 4: media
- [ ] Tests first, `ManageApiMediaTest.php`: multipart upload and raw-body upload store a file as `UploadMedia` does, credited to the key's person; a type `UploadMedia` refuses and a file over the limit are refused and nothing is stored; `PATCH` sets alt, caption and description through `UpdateMediaDetails`; both need `media.write`; another person's media obeys that person's permissions.

### Task 5: items
- [ ] Tests first, `ManageApiItemsTest.php`: a draft with title, Tiptap body, terms, featured media, author and `meta_description` is created by the key's person with `via = api:<name>`; `published` without `content.publish` is refused before anything is written; `published` and `scheduled` with the ability publish or schedule it; a refused publish keeps the draft and answers 202 with the problem; an unknown field handle, a bad body and a term of another taxonomy are refused and nothing is written; `html` per D8.

### Task 6: the settings page
- [ ] Browser test `ManagementApiSettingsTest.php`: switch on, create a key (the secret shown once, Copy), revoke it, axe at 1280 and 375 in both schemes. Build the page (D10). `npm run build`; the quick check.

### Task 7: documents and records
- [ ] `docs/guides/operators/management-api.md`: switching it on, making a key, abilities, the endpoints with one `curl` example each. Fill this plan's Records; the full gate once.

## Quick check

`php artisan test --compact tests/Feature/ManageApi tests/Architecture`, then `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse --memory-limit=2G` on the changed files, and `npx vp check` on changed front-end files.

## Owns

`app/Domain/ManageApi/**`, `app/Http/Api/Manage/**`, `routes/api/manage.php` (and one `require` line in `routes/api.php`), the one migration, the permission's seeder line, `resources/js/pages/settings/management-api.tsx` and its controller and route, `tests/Feature/ManageApi/**`, `tests/Browser/ManagementApiSettingsTest.php`, `docs/guides/operators/management-api.md`. Never the plugin doors (`app/Domain/Plugins/**`), the handbook (`docs/handbook/**`), plugin public routes, the import sockets or insertion points (other wave-2 parts).

## Records

<!-- Filled in while building: commits; each test seen red; B-?? decisions with the options weighed; the full gate's totals; changelog lines. -->

---

## Records (the builder's)

**Setup.** `/tmp/teme-cloud-setup.log`: "2026-11-15T15:23:34Z ran in /home/user/teme as root", "2026-11-15T15:34:33Z finished". `pwd`: `/home/user`, then `/home/user/teme`. Branch `api/manage-foundation`, built on `origin/plugins/content-write` (at `47821d4`, "docs(plans): Plugins write content's records") merged with `origin/main` (`64895ca`).

**Commits.**

| Task | Commit | Message |
|---|---|---|
| 1 | `bbc00a3` | feat(api): management API keys, abilities and their Operations |
| 2 | `a9c68c9` | feat(api): sign in to the management API with a key |
| 3 | `fc62d13` | feat(api): look up terms and people through the management API |
| 4 | `ae2a18a` | feat(api): upload media and change its details through the management API |
| 5 | `4baac01` | feat(api): create, publish and schedule items through the management API |
| 6 | `c9dc3df` | feat(settings): Settings › Management API |
| 7 | `8dfb7aa` | docs(guides): the management API for operators |
| 7 | `e4d49d0` | docs(guides): name Settings › Management API in the editors' settings page |

**Tests seen red.**

| Task | File | Case(s) | First line of the failure |
|---|---|---|---|
| 1 | `ApiKeysTest` | all 13 written then | the ManageApi classes did not exist (`Class … not found`) |
| 2 | `ManageApiAuthTest` | all 9 | `Class "App\Domain\ManageApi\ManageApiSettings" not found` |
| 3 | `ManageApiLookupTest` | 3 of 4 (the case that expects a refusal passed on a 404) | "Expected response status code [200] but received 404" |
| 4 | `ManageApiMediaTest` | 3 of 7 (the refusal cases passed on a 404) | "Expected response status code [200] but received 404" |
| 5 | `ManageApiItemsTest` | not kept: the transcript holds the later failures only (two cases answering 500, fixed in the same task: `UpdateContentDetails` answers a result, not the item) | — |
| 6 | `ManagementApiScreenTest` | all 7, with the page's routes taken out | "Route [settings.management-api] not defined." |
| 6 | `ManagementApiSettingsTest` (Browser) | the flow case, with the routes taken out | "Route [settings.management-api] not defined." |

**Counts.** `DesignSystemTest` baseline 222 lines and `AdminWordsTest` baseline 876 lines, base → end: unchanged. No count raised, no line added. (The page first drew its dialog beside the settings pattern, an R7 offence; the dialog moved inside the pattern instead.)

**Browser files** after `npm run build`, one at a time, each to a file: `ManagementApiSettingsTest` 5 passed (the flow, and axe with the 24-pixel targets at 1280 and 375 in light and dark).

**Full `composer check`** (exit 0). Pint, PHPStan (no errors), types, formatting (429 files) and lint (427 files, none) pass. Architecture: 139 passed. Unit and Feature: 10689 passed, 2 skipped, 0 failed. A whole-base run was started for comparison but stopped: an orphaned earlier run shared its test databases (no totals kept).

**STOP.** None.

### Differences from the plan (B-?? drafts)

- **B-?? (draft) 01: the API's problems are its own.** Found: `ProblemCode`, `Problems` and `ApiWords` belong to the delivery API, outside Owns, and hold none of D4's codes. Options: (a) add codes there; (b) a `ManageApiProblem` of this part's own, in the same RFC 9457 shape, rendered before the `/api` callback; Chose (b): no shared file changes. Codes: `unauthorized` 401 (with `WWW-Authenticate: Bearer`), `forbidden` 403, `api_off` 404, `not_found` 404, `invalid_parameter` 422 (with `errors`), `not_supported_yet` 422, `duplicate` 409. Words in `ManageApiWords`. `bbc00a3`, `a9c68c9`.
- **B-?? (draft) 02: `too_many_requests` is the existing `rate_limited`.** Options: (a) a new code; (b) the code clients of `/api` already handle. Chose (b). `a9c68c9`.
- **B-?? (draft) 03: `SecurityEventType` gains two lines,** `api_key.created` and `api_key.revoked`, which D6 needs; the file is outside Owns, so only those lines. `bbc00a3`.
- **B-?? (draft) 04: the permission reaches existing sites in the one migration,** which creates `api.keys.manage` and grants it to administrators, adding only; plus the `RoleSeeder` line. Options: (a) that; (b) a second migration (the plan says one). Chose (a). `bbc00a3`.
- **B-?? (draft) 05: a provider of its own.** `app/Providers/ManageApiServiceProvider.php` registers the settings group, with one line at the end of `bootstrap/providers.php`. Options: (a) that; (b) a line in `SettingsServiceProvider` (another part's). Chose (a). `a9c68c9`.
- **B-?? (draft) 06: the settings group.** Key `management_api` (a group key can't hold a hyphen), so the page is at `/admin/settings/management_api`, drawn by this part's own routes before `settings/{group}`. It has no `SettingsPage` entry, because `UpdateSettings` refuses a group with a screen. `since` is `0.9.0`, a guess for the maintainers to correct. The group is not sensitive; making a key is, and asks for the password. `a9c68c9`, `c9dc3df`.
- **B-?? (draft) 07: `SettingsPageTest`'s exact settings menu gains the new entry** (one line; the test still proves the whole list). `a9c68c9`.
- **B-?? (draft) 08: the address limit counts failed sign-ins only** (30 a minute, checked before the key is read). Options: (a) every request; (b) failures only. Chose (b): counting every request would cap a busy tool at 30 a minute, below its key's own limit of 60. `a9c68c9`.
- **B-?? (draft) 09: `AnswerManageApi` is outermost, and the group drops `AnswerAsApi` and `MarkCacheable`.** Options: (a) `no-store` added last, inside; (b) outermost. Chose (b): a problem (`api_off` included) carries `no-store, private` too, so the page cache never keeps one, and no ETag is sent. `a9c68c9`.
- **B-?? (draft) 10: a suspended person's key answers `unauthorized`,** as a wrong key does. `a9c68c9`.
- **B-?? (draft) 11: `last_used_at` is touched in `ApiKeys` by one query, at most once a minute,** not through an Operation (D5: bookkeeping, not a change of state). No architecture test asked for an allow-list line. `a9c68c9`.
- **B-?? (draft) 12: a name is unique among live keys, ignoring case,** by a partial unique index on `lower(name)`; a revoked key's name can be used again. `bbc00a3`.
- **B-?? (draft) 13: media has no description.** Found: D9 and Task 4 name `description`; media has title, alt, caption and credit. Options: (a) map description onto caption; (b) take the fields media has. Chose (b): `PATCH` takes `title`, `alt`, `caption`, `credit`, and the answer carries them. `ae2a18a`.
- **B-?? (draft) 14: D8 holds.** `BlockConverter` runs with no import run (one freeform chunk, a site of `https://management-api.invalid`, no known media). Images from elsewhere become links, with the converter's note in the answer's `notes`; links are kept as given, so an absolute link to the site's own address is stored as written (open item 1). `4baac01`.
- **B-?? (draft) 15: an upload the library already holds answers 409 `duplicate`** with the existing item's ID; `allow_duplicate=1` uploads it anyway, as the library's own upload allows. `ae2a18a`.
- **B-?? (draft) 16: `/people` lists the people the key's person may credit,** the same list the editor's author field offers, at most 500. `fc62d13`.
- **B-?? (draft) 17: "an elevated Operation reached through a key is refused" is proved on the binding.** No endpoint calls an elevated Operation, so the test checks that a key's request holds the default `DeniedElevation`. `a9c68c9`.
- **B-?? (draft) 18: the editors' settings page names the new page.** Found: `EditorCompletenessTest` asks `docs/guides/editors/settings.md` to describe every Settings page an administrator can open; that file is outside Owns. Options: (a) one short section; (b) leave the test red. Chose (a). `e4d49d0`.
- **B-?? (draft) 19: the operators' guide lists the page.** `OperatorPagesTest` asks for every page in `OperatorGuide::PAGES` and in the guide's README table; one line in each. `8dfb7aa`.

### Open items

1. Rule 9: an `html` body's absolute links to the site's own address are stored as given (B-14). Turning them into internal links needs the import's address matching, which this part does not own.
2. `docs/reference/settings.md` is not regenerated for the new group; this part may not write it.
3. A wrong method on a management route (such as `PUT /items`) answers the delivery API's `method_not_allowed` problem, whose words say the API only reads. `ApiWords` is outside Owns.
4. `since: 0.9.0` on the group and its settings is a guess (B-06).

### Changelog lines

- Added: the management API at `/api/manage/v1`, off until an administrator turns it on. Outside tools sign in with a key and can look up terms and people, upload media and change its details, and create posts and pages as drafts, published or scheduled, each through the same checks as the admin.
- Added: Settings › Management API, to turn the API on, set the requests a key may make each minute, and make and revoke keys. A key acts as one person with the abilities chosen for it; its secret is shown once.
- Added: revisions written through a key are marked with the key's name.
- Added: the operators' guide's page on the management API.

### How to try it

1. Sign in as an administrator, open Settings › Management API, and switch it on.
2. Create a key for yourself with every ability, and copy it.
3. `curl -H "Authorization: Bearer <key>" http://<site>/api/manage/v1/me`, then post an item: `curl -H "Authorization: Bearer <key>" -H 'Content-Type: application/json' -d '{"type":"post","title":"Hello","html":"<p>Hi</p>","status":"draft"}' http://<site>/api/manage/v1/items`. The draft appears under Posts, its revision marked with the key's name.
4. Revoke the key, and the same request answers 401.
