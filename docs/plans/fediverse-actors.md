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
- [ ] **T7. Switch and settings page** (after the owner approves the drawing): the switch purges pages after commit; paused inboxes answer 503; the page shows actors, followers count, unreachable hosts, blocked servers (add, remove), deliveries summary; refusal tests for every Operation.
- [ ] **T8. Forget and prune.** Forget removes follower rows, the cached profile and the author details of that account's replies; prune removes remote actors unseen for 30 days that follow nothing and wrote no comment.

**Quick check:** `php artisan test --compact tests/Feature/Fediverse tests/Unit/Fediverse` and `vendor/bin/phpstan analyse app/Domain/Fediverse`.

## Records

(The builder fills this.)
