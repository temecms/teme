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
