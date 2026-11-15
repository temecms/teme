# Plan F1: Fediverse actors and inbox

**Branch:** `fediverse/actors`, from `origin/main`. **Do not start until wave 2's part E, plugin public routes (`docs/plans/plugin-public-routes.md`), is merged**: F1 registers its routes in E's `PublicRouteRegistry` as owner `core` and relies on E's `PageKey` fix (D4). **Proposal:** `docs/proposals/fediverse.md`. Lighter plan (I-569): decisions, schema, names, permissions, tests and delicate code are written out; the rest is described.

**Bound by:** rule 1 (Operations), rule 2 (no key-value tables), rule 4 (JSONB, nothing serialized), rule 6 (after commit), rule 9; I-058, I-077, I-485, I-3447.

## Owns

- `app/Domain/Fediverse/**` except `Publishing/**` and `Import/**` (F2's and F3's).
- `app/Http/Site/Fediverse/**` (controllers), `app/Http/Admin/Settings/FediverseSettingsController.php`, `resources/js/pages/settings/fediverse.tsx`.
- Migrations: the six tables below.
- `docker/nginx/conf.d/teme-static.conf`, `docker/caddy/teme-static.caddy` (one condition each), `docs/guides/operators/` (one Cloudflare rule paragraph).
- `config/teme.php` (a `fediverse` block), the settings registry entry `modules.fediverse` with `managedBy`.
- Tests under `tests/Feature/Fediverse/` and `tests/Unit/Fediverse/`, fixtures under `tests/Fixtures/Fediverse/`.
- Shared, additions only: the `Capability` enum (`FediverseRead`, `FediverseSend`), `Paths::SITE_ROUTES` (`ap`, `wp-json`), `PublicRouteRegistry` core registrations.

## Schema (first commit, so F2 and F3 start from it)

`fediverse_actors`: `id` ulid PK; `kind` varchar(16) CHECK in (`blog`,`person`,`application`); `person_id` ulid FK people null, unique where not null; `uri` text unique; `username` varchar(100), unique on `lower(username)`; `urls` jsonb (`inbox`, `outbox`, `followers`, `following`); `aliases` jsonb default `[]`; `public_key_pem` text; `private_key` text (encrypted cast, registered as an `EncryptedColumn` so `ReencryptSecrets` covers it); `enabled` boolean default true; timestamps.

`fediverse_remote_actors`: `id` ulid PK; `uri` text unique; `handle` varchar(255) null; `host` varchar(253) indexed; `name`, `profile_url`, `avatar_url` text null; `inbox_url` text; `shared_inbox_url` text null; `key_id` text; `public_key_pem` text; `document` jsonb; `fetched_at` timestamptz; `failing_since`, `gone_at` timestamptz null.

`fediverse_followers`: `local_actor_id` ulid FK; `remote_actor_id` ulid FK cascade; `follow_uri` text null; `since` timestamptz; PK (`local_actor_id`,`remote_actor_id`).

`fediverse_objects`: `uri` text PK; `kind` varchar(16) CHECK in (`post`,`reply`); `content_id` ulid FK null cascade; `comment_id` ulid FK null cascade; `remote_actor_id` ulid FK null; `deleted_at` timestamptz null.

`fediverse_activities`: `id` ulid PK; `local_actor_id` ulid FK; `type` varchar(32); `object_uri` text; `payload` jsonb; `created_at`. Index (`local_actor_id`, `created_at` desc).

`fediverse_deliveries`: `id` ulid PK; `activity_id` FK cascade; `inbox_url` text; `host` varchar(253); `state` varchar(8) CHECK in (`queued`,`sent`,`failed`,`skipped`); `attempts` smallint default 0; `next_attempt_at` timestamptz; `last_status` smallint null; `last_error` varchar(255) null; `sent_at` timestamptz null. Index (`state`, `next_attempt_at`); unique (`activity_id`, `inbox_url`).

`fediverse_blocked_hosts`: `host` varchar(253) PK; `reason` varchar(255) null; `created_at`; `created_by` ulid. (The seventh table: the blocked-servers list is a list, not a setting value, so it can be indexed and joined.)

## Decisions written out

**D1. Signing (delicate; write it as here).** `App\Domain\Fediverse\Signatures\Signer::sign(string $method, string $url, array $headers, string $body, FediverseActor $actor): array` returns the headers to add. It builds `Digest: SHA-256=` + base64(sha256 raw body) for POST, a `Date` in RFC 7231 format, and draft-cavage `Signature: keyId="{actor uri}#main-key",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="{base64}"`, where the signing string is the lower-cased header names and values joined by `\n`, `(request-target)` being `strtolower($method).' '.$pathAndQuery`. Signed with `openssl_sign($string, $sig, $privateKey, OPENSSL_ALGO_SHA256)`; the private key is decrypted only inside `sign()` and never returned, logged or passed on.

**D2. Verifying (delicate).** `Verifier::verify(PublicRequest $request): VerifiedSender` throws `SignatureRefused` (answered 401, no detail) unless: the `Signature` header parses; its `headers` cover `(request-target)`, `host`, `date` and, for POST, `digest`; `Digest` equals the body's SHA-256 (`hash_equals`); `Date` is within 12 hours of now (Mastodon's window is to be confirmed in T0 from its docs; record it); the `keyId`'s owner, fetched through D3, is the activity's `actor` (same URI); `openssl_verify(...) === 1`. On failure against a cached key, re-fetch the actor once (at most once per 5 minutes per actor) and verify again. RFC 9421 (`Signature-Input`/`Signature`, `Content-Digest`) is verified the same way, parsed with `gapple/structured-fields` 2.3.4 (approved by the maintainers on 2026-11-15; add exactly this version).

**D3. Fetching (delicate: SSRF).** `RemoteFetcher::get(string $url, ?FediverseActor $signAs = null): array` uses `App\Domain\Support\Network\PublicAddresses` (public addresses only, the first resolved address pinned, each redirect re-checked), `https` only, 5-second timeout, 1 MB answer limit, at most 3 redirects, `Accept: application/activity+json`, signed as the application actor. The answer's `id` host must equal the host it was fetched from, or it is refused. An inbox URL taken from a remote document goes through the same check before any delivery (F2).

**D4. Routing.** Core registers its entries in `PublicRouteRegistry` (owner `core`): path routes for `/.well-known/webfinger`, `/.well-known/nodeinfo`, `/nodeinfo/2.1`, `/ap/{...}`, `/wp-json/activitypub/1.0/{...}`; conditional entries (`Accept` of `application/activity+json` or `application/ld+json; profile="https://www.w3.org/ns/activitystreams"`) for `/?author={n}`, `/?p={n}`, `/@{name}` and every imported actor or object URL, matched by full URL against `fediverse_actors.uri`/`aliases` and `fediverse_objects.uri`. JSON answers carry `Content-Type: application/activity+json`, `Cache-Control: no-store`, `Vary: Accept`. A browser at `/?author=N` gets a 301 to the author's page; at `/?p=N` a 301 to the item.

**D5. Static files.** nginx and Caddy serve no static file when `Accept` contains `activity+json` or `ld+json`; `StaticSettingsTest` asserts both files carry the condition. The operators' guide gains the Cloudflare "bypass cache when Accept contains activity+json" rule.

**D6. The switch.** `modules.fediverse`, off by default, behind `settings.modules.manage`, the settings registry's first `managedBy` consumer; switching purges every page after commit. Off = paused (proposal Settled 4).

**D7. Operations** (each with a refusal test that writes nothing): `SwitchFediverse`, `ChangeFediverseSettings`, `EnableAuthorActor`, `DisableAuthorActor`, `AcceptFollow`, `RemoveFollower`, `RecordRemoteActor`, `ForgetRemoteActor`, `BlockHost`, `UnblockHost`, `PruneFediverse`. Permission: `settings.fediverse.manage` (new, administrators), except inbox-driven ones, which run as the system actor from `ProcessInboxActivity`.

**D8. Inbox.** `POST` to any actor inbox or the shared inbox: blocked host → 403 before anything else; body over 256 KB → 413; rate limit (120/min per address and per host) → 429; signature (D2) → 401; switch off → 503 `Retry-After: 3600`; else the activity id is de-duplicated (7 days in the cache store), the job `ProcessInboxActivity` is queued and 202 is answered. The job handles Follow (→ `AcceptFollow`, then F2's sender sends Accept), Undo of a Follow (→ `RemoveFollower`), Delete of an actor (→ mark gone, remove follows). Create/Update/Delete of a reply is F2's; everything else is handed to F2's plugin dispatch (stubbed in F1 as "ignored").

## Tasks (tests first; each test seen red for the reason given)

- [ ] **T0.** Read E's merged code, `PageKey`, `PublicAddresses`, `EncryptedColumn`/`ReencryptSecrets`, the settings registry (`managedBy` absent), `Paths::SITE_ROUTES`, the static configs. Confirm Mastodon's signature date window from its docs. Record differences as B-?? (draft).
- [ ] **T1. Schema** (first commit, push at once): migrations and models; tests: each CHECK refuses a bad value; `private_key` is unreadable in the raw row; `ReencryptSecrets` re-encrypts it.
- [ ] **T2. Signer and Verifier.** Unit tests with a fixed key pair: a signed request verifies; each tampering (body, a covered header, the date outside the window, a `keyId` whose owner differs from `actor`, a missing `digest` on POST) is refused; a key change triggers exactly one re-fetch.
- [ ] **T3. RemoteFetcher.** Tests with `Http::fake`: a private address, a redirect to a private address, `http`, an answer over 1 MB, an `id` on another host: each refused; a good actor document parses.
- [ ] **T4. Documents and discovery.** Blog actor at `/ap/blog`, WebFinger for `acct:{username}@{host}` (subject, aliases, `links[rel=self]`), NodeInfo, outbox (latest 20, from `fediverse_activities`), followers (`totalItems` only), following (empty). A conditional `/?author=0` with the activity `Accept` answers JSON; without it, the home page; with a cached home page present, still JSON (E's D4).
- [ ] **T5. Inbox.** The D8 order, each step its own test; Follow creates a follower and queues an Accept; Undo removes it; actor Delete marks it gone; a duplicate id is accepted once.
- [ ] **T6. Static rules and guide.** `StaticSettingsTest` cases for both servers.
- [ ] **T7. Switch and settings page** (after the maintainers approve the drawing): the switch purges pages after commit; paused inboxes answer 503; the page shows actors, followers count, unreachable hosts, blocked servers (add, remove), deliveries summary; refusal tests for every Operation.
- [ ] **T8. Forget and prune.** Forget removes follower rows, the cached profile and the author details of that account's replies; prune removes remote actors unseen for 30 days that follow nothing and wrote no comment.

**Quick check:** `php artisan test --compact tests/Feature/Fediverse tests/Unit/Fediverse` and `vendor/bin/phpstan analyse app/Domain/Fediverse`.

## Records (the builder's)

### Setup and base

- Setup: `/tmp/teme-cloud-setup.log` "2026-11-15T15:23:35Z ran in /home/user/teme as root … 15:34:25Z finished"; `pwd` /home/user/teme. PHP 8.4.27, PostgreSQL on 5433 and Valkey on 6380 answering after `scripts/cloud-setup.sh`.
- Waited for `origin/plugins/public-routes` until its records commit (`e77fc23 docs(plans): Plugin public routes' records`). Branch `fediverse/actors` = that commit merged with `origin/main` (`bdc98d8`): merge `0d92fda`, no conflict.
- The full `composer check` was not run on the base (about 30 minutes in this container); part E's records give its base totals: Unit+Feature **10630** passed, **2** skipped. Architecture on the merged base: **139** passed (1309 assertions).

### T0

- Read E's merged code (`PublicRouteRegistry`, `RouteDispatcher`, `DeclaredRouteController`, `MatchDeclaredRoutes`, `PageKey`), `PublicAddresses`, `EncryptedColumn`/`EncryptedValues`/`ReencryptSecrets`, `Modules` (no `managedBy` yet, as the plan says), `Paths::SITE_ROUTES`, both static configs.
- Mastodon's window, from docs.joinmastodon.org/spec/security: "Use the `Date:` header to check that the signed request was made within the past 12 hours." Built as D2 says: within 12 hours of now, either way.

### Tests seen red

- T1: 7 failed, `relation "fediverse_actors" does not exist`.
- T2: 11 failed, `Interface "App\Domain\Fediverse\Signatures\KeyOwners" not found`; then "refuses a request whose date is outside the window" stayed red (the Signer used `gmdate()`, not the test clock), fixed to `now()`.
- T3: 10 failed, `RemoteFetcher` missing.
- T4: 10 failed, 2 passed (the two 404 cases): `Expected response status code [200] but received 404` (nothing registered). Then the imported-actor cases: `url('/?author=3')` drops the slash before the query (a fault in my test; `Requests::url()` keeps it).
- T5: every `InboxTest` case `… but received 404` (no inbox route). `InboxOperationsTest` was written after its Operations: seen red by planting `return;` at the top of each `authorize()` (3 failed), then restored.
- T6: 3 failed (neither condition nor the guide's paragraph there).
- T8: 4 failed, `Target class [ForgetRemoteActor] does not exist`.
- T7: `FediverseSettingsTest` 21 failed (no `settings.fediverse` route, no Operations); after the backend, 1 failed: `Inertia page component file [settings/fediverse] does not exist`. The Browser file was written before the page was built and ran green once it was.

### Commits

- `feat(fediverse): add the fediverse tables and models` (first commit, pushed at once)
- `feat(fediverse): sign and verify draft-cavage requests`
- `feat(fediverse): fetch remote documents behind the public-address check`
- `feat(fediverse): answer actors, WebFinger and NodeInfo at their addresses`
- `feat(fediverse): take follows, undos and deletes at the inboxes`
- `feat(fediverse): serve no stored page to fediverse requests`
- `feat(fediverse): forget a remote account and prune unneeded ones`
- `fix(fediverse): answer a stored page with no query and let the people table drop`
- `docs(plans): fediverse actors' records`
- Merge of `origin/plugins/public-routes` (R5: `c70cdb8`, `1c4280b`, `8d8e2aa`, `42b11b9`)
- `feat(fediverse): add Settings › Fediverse with its switch` (T7)
- `docs(plans): fediverse actors' records, with T7` (this one)

### Checks

- Full `composer check`, first run: 2 failed, 2 skipped, 10694 passed. `BenchmarkSmokeTest` (a cache hit made one query: `SiteActors::exist()` asked the table while the site had no account) and `People/ReleaseBeforeTest` (B-21). Both fixed in the `fix(fediverse)` commit.
- Full `composer check`, final: Pint passed; PHPStan no errors; types, lint and theme build passed; Architecture **139** passed (1316 assertions); Unit+Feature **10696** passed, **2** skipped (55255 assertions).
- Full `composer check` after the R5 merge and T7: Pint passed; PHPStan no errors; types, lint and theme build passed; Architecture **139** passed (1324 assertions); Unit+Feature **10739** passed, **2** skipped (55511 assertions).
- Browser: `tests/Browser/FediverseSettingsTest.php` alone, after `npm run build`: 6 passed (53 assertions).
- Baselines: `admin-words-baseline.txt` 876 lines and `design-system-baseline.txt` 222 lines before and after; no line added, no count raised.

### STOP

- **RFC 9421 verification is not built.** `gapple/structured-fields` **2.3.4** does not exist: Packagist's newest is v2.3.3 (2026-06-12), and `composer require gapple/structured-fields:2.3.4` finds no such version. Another version would be a dependency the plan doesn't name, so `composer.json` and `composer.lock` are untouched. Draft-cavage is verified as D2 says (Mastodon's default). Needs: the maintainers' word on a version that exists (v2.3.3?), then `Verifier` gains the `Signature-Input`/`Content-Digest` branch.

### T7 built from the approved drawing

The maintainers approved the Settings › Fediverse drawing on 2026-11-15, after the first records; T7 was built then, as part of this part.

- The switch (`modules.fediverse`, off by default) at the top, acting at once, with **Who can be followed** (the blog, each author, or both) under it, saved by the save bar. Then three aligned tables, one row each with one action: **Accounts** (handle `@blog@host`, followers; an author is turned on or off), **Servers not answering** (Block) and **Blocked servers** (Unblock, and a form to block one by name); then the import's status.
- Operations, each with a refusal test that writes nothing: `SwitchFediverse` (settings.modules.manage; switching on makes the blog's and the application's accounts with new 2048-bit keys, once), `ChangeFediverseSettings`, `EnableAuthorActor`, `DisableAuthorActor`, `BlockHost`, `UnblockHost` (settings.fediverse.manage, or the operator).
- Switching purges every page after commit (the modules group's tag); paused, the inboxes answer 503 with `Retry-After: 3600` and the documents still answer; followers and keys are kept.
- Tests: `tests/Feature/Fediverse/FediverseSettingsTest.php` (21) and `tests/Browser/FediverseSettingsTest.php` (axe at 1280 and 375 in the light and dark schemes, targets of 24 pixels or more, nothing scrolling sideways at 375; switch, block, unblock and an author turned on; the locked switch, whose refused PUT writes nothing).

### Left for a follow-up (not a STOP)

- Prune has no schedule or console command (`routes/console.php` isn't in Owns). Pruning sent activities after 90 days (P3) is not in T8 and is left with F2's deliveries.

### Decisions (B-?? drafts)

- **B-01 (draft):** fediverse_blocked_hosts.created_by: plan says ulid, users.id is bigint -> foreignId users nullOnDelete.
- **B-02 (draft):** EncryptedValues (app/Domain/Security) outside Owns: one line added, as the plan's "registered as an EncryptedColumn" requires. Label "Fediverse accounts’ private keys", row "fediverse account".
- **B-03 (draft):** Verifier rebuilds (request-target)'s query from PublicRequest::query (E's contract carries no raw query string): a query encoded differently by the sender would fail. Inboxes carry none.
- **B-04 (draft):** The key's owner comes through a KeyOwners seam (interface) so the T2 tests count fetches; the real one fetches through RemoteFetcher (T3). Unknown algorithm refused; hs2019 read as rsa-sha256.
- **B-05 (draft):** FediverseServiceProvider added to bootstrap/providers.php (one line, shared): core's registry entries need a provider's boot, and the KeyOwners binding.
- **B-06 (draft):** Keys fetched during an inbox request are kept in the cache store (24 h), never written from the HTTP layer; ProcessInboxActivity records the account through RecordRemoteActor. A key's actor document must name the key back (keyId → owner → owner's publicKey.id).
- **B-07 (draft) — conflict with part E's tests:** core's /?author=N + activity Accept entry, always registered, beat the inbound fixture's /?author=0 entry, and E's PublicRoutesTest ("reaches the actor handler", "lets a core-owned entry win") and DeclaredRouteCacheTest went red. Options: change E's tests (another part's, and their meaning) / register the home-page entries only once the site has a fediverse account. Chose the second (SiteActors::exist(), cached, forgotten after the commit that adds or removes an account): E's tests keep their meaning, and a site with no account keeps /?author=N exactly as before. Path entries (/ap/, /wp-json/activitypub/1.0/, webfinger, nodeinfo) are always registered.
- **B-08 (draft):** Browser redirects (D4): /?author=N and /?p=N with N ≥ 1 only, so /?author=0 (the WordPress plugin's blog actor) stays the home page in a browser, as T4 asks; an address the site has no actor or item for goes to the home page (301).
- **B-09 (draft):** Actor types: blog "Group" (as the WordPress plugin's blog actor), author "Person", application "Application". Object documents (/ap/objects/…, /?p=N) are F2's: F1 answers a deleted object's Tombstone and 404 otherwise (signpost Later (I-058)).
- **B-10 (draft):** E's dispatcher sends "Cache-Control: no-store, private" for every non-cacheable entry and drops headers outside PublicResponse::HEADER_ALLOW_LIST, so D4's "Vary: Accept" isn't sent. Not changed (E's contract); no-store makes it moot for caches.
- **B-11 (draft):** Paths::SITE_ROUTES gains ap and wp-json; docs/architecture/addresses.md still lists the old five (not mine to edit).
- **B-12 (draft):** The inboxes are Laravel POST routes registered by FediverseServiceProvider (public group), not declared routes: E's dispatcher answers 413 before any check of ours, its declared-routes limiter allows at most 60 POSTs a minute per address before D8's 403 could run, and it drops Retry-After. Handlers can't keep D8's order (403 → 413 → 429 → 401 → 503) through it. InboxGate (domain) does D8 in order; InboxController only translates.
- **B-13 (draft):** "Runs as the system actor": JobActor can't carry the operator from a job queued by a web request (I-392), so the inbox-driven Operations (RecordRemoteActor, AcceptFollow, RemoveFollower) authorize a null actor only with a VerifiedSender that is the account concerned; RemoveFollower also lets settings.fediverse.manage remove one (for the settings page). A person actor is refused by the inbox-driven ones.
- **B-14 (draft):** settings.fediverse.manage needs a permission migration (2027_07_15_100100_add_fediverse_permission.php) and one RoleSeeder line, as every earlier permission did; the plan names neither file.
- **B-15 (draft):** "queues an Accept": AcceptFollow writes the Accept activity and a queued fediverse_deliveries row; sending it is F2's DeliverActivity (signpost Later (I-058)).
- **B-16 (draft, superseded by T7):** The switch (modules.fediverse) is registered by T7. Until then FediverseSwitch::on() reads it if registered, else off (paused): the inboxes answer 503. Tests register it with Tests\Fixtures\Fediverse\Inbox::switch(), which now writes the stored setting (B-29).
- **B-17 (draft):** Blocked servers match the host or any domain above it (sub.blocked.example is blocked by blocked.example). The claimed host is the Signature keyId's, read before verifying; the verified actor's host is checked again after. A verified body that isn't an activity (no id or type) answers 400.
- **B-18 (draft):** No config/teme.php fediverse block: the limits are constants on InboxGate/FediverseDocuments/RemoteFetcher/Verifier (the proposal's values); F2 may add deliver_outside_production there.
- **B-19 (draft):** The cases are in tests/Feature/Fediverse/StaticRulesTest.php, not tests/Feature/Cache/StaticSettingsTest.php (not in Owns). nginx: the old map now yields $teme_static_page and a new map on $http_accept yields $teme_static_file ("/.teme-none" for activity/ld+json), so the site template and StaticSettingsTest's lines stay as they are. Caddy: one `not header_regexp Accept (?i)(activity\+json|ld\+json)`. Neither server was run here (no nginx or Caddy in the container); `teme:cache:check-static` on a real server is the check.
- **B-20 (draft):** Forget deletes the fediverse_remote_actors row (its follows cascade, its objects keep their addresses with remote_actor_id null) and clears the replies' author_name, author_url and author_email; purges each item whose replies changed, after commit. Prune: fetched_at older than 30 days, no follow, no reply object. Both: settings.fediverse.manage or the operator. No schedule or console command for prune (routes/console.php isn't in Owns): left open.
- **B-21 (draft):** ReleaseBeforeTest (outside Owns) now drops people with CASCADE, which removes only later tables' foreign keys to it (the release before had none); what it proves is unchanged. The other option was no foreign key on fediverse_actors.person_id, against the plan's schema.

- **B-22 (draft):** No `managedBy`. The plan's "first managedBy consumer" would add a property to `Teme\Contracts\Settings\Setting` (packages/teme-contracts, not in Owns), and v1's switch needs no more than the group's purge. Options: add managedBy to the contract / use the existing placement. Chose the placement: `modules.fediverse` is one line in `Modules::group()` and one line in `SettingsPlacement::PLACED`, so Find a setting lands on Settings › Fediverse, while the generic modules address still leads to Discussion.
- **B-23 (draft):** Shared files outside Owns, lines added only: `Modules.php` (the setting and its constant), `SettingsPlacement.php` (one line), `routes/admin.php` (the page's route group, before settings/{group} as the other pages'), `docs/guides/editors/settings.md` (a `### Fediverse` section and the screen, which `EditorCompletenessTest` asks of every Settings page), and `tests/Feature/Admin/SettingsPageTest.php` (one menu line, Fediverse after Languages: the test still proves the menu's order and contents). The settings group is registered from `FediverseServiceProvider`.
- **B-24 (draft):** `since` is `0.9.0` for both settings, following part E's `42b11b9` ("public route contracts arrive in 0.9.0").
- **B-25 (draft) — R5:** merged `origin/plugins/public-routes` before T7 (collisions, the shared load rule, conditional dispatch on any path, the cache key, rate limits). Nothing in F1 relied on the old behaviour: the conditional entries stay registered only once the site has an account (B-07), and the inboxes stay outside the dispatcher (B-12). The fediverse tests and E's public-route tests passed on the merge (139) with no change.
- **B-26 (draft):** The page's tables are ARIA tables (`role="table"`, rows of fixed-width columns shared by all three) rather than `<table>`: there is no table component, the design-system check counts a raw `<table>` (R5), and a new one under `components/ui/**` isn't mine. The blog's row shows its state as its one entry, since **Who can be followed** governs it; each author's row turns that author on or off. Authors are listed while authors can be followed (every person, by the name and slug their author page shows); an author's account is `/ap/people/{person}`, named by their author slug, refused when another account has that name. The plan's "deliveries summary" is the failed-deliveries count per host in **Servers not answering**, as the drawing has no other place for it. The import's status counts the accounts whose address isn't under this site's `/ap/`, and their followers.
- **B-27 (draft):** Choosing the blog alone turns every author's account off (followers and keys kept); choosing authors again leaves each off until turned on, so no author is made followable without a person deciding.
- **B-28 (draft):** The page is behind settings.fediverse.manage; the switch also needs settings.modules.manage (D6) and is shown locked otherwise, as Discussion's is. The operator can't switch (as `SwitchComments`), but can run the other T7 Operations.
- **B-29 (draft):** `Tests\Fixtures\Fediverse\Inbox::switch()` writes the stored `modules.fediverse` value (cmSettings) now that the setting is registered, and `Inbox::followable()` the group's value; the tests that used it keep their meaning.

No contract the plan names changed: E's `Teme\Contracts\Routes` is used as it is. No I-?? ideas drafted.

### Changelog lines for the maintainer

```
### Added
- Fediverse foundations: the site's blog, authors and application can be fediverse accounts, found by WebFinger and NodeInfo, with outbox, followers count and following documents at /ap/ addresses, and an imported WordPress plugin account's old addresses kept.
- Inboxes take Follow, Undo and an account's Delete, verified by draft-cavage HTTP signatures, refusing blocked servers, oversized bodies and floods; a Follow is kept and its Accept queued.
- Remote documents are fetched over https only, with every address checked public and pinned.
- nginx and Caddy serve no stored page to a request asking for activity+json or ld+json; the Cloudflare guide adds the matching rule.
- settings.fediverse.manage for administrators; forgetting a remote account and pruning unneeded ones.
- Settings › Fediverse: switch the fediverse on or pause it, choose who can be followed (the blog, each author, or both), see the accounts and their followers, the servers not answering and what the import brought, and block or unblock servers.
```

For the maintainer: `docs/reference/settings.md` needs `php artisan teme:docs:settings` for `modules.fediverse` and the `fediverse` group (generated, not mine to write).

### Try it

`php artisan test --compact tests/Feature/Fediverse` and `tests/Unit/Fediverse`. By hand: Settings › Fediverse, switch it on (as an administrator), then `curl -H 'Accept: application/activity+json' https://<site>/ap/blog`, `curl 'https://<site>/.well-known/webfinger?resource=acct:blog@<host>'`, `curl https://<site>/nodeinfo/2.1`. Switch it off and the inbox answers 503 while those documents still answer.
