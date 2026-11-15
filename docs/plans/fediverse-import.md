# Plan F3: Fediverse import and the proof

**Branch:** `fediverse/import`, from main **after wave 2's plugin import socket (`docs/plans/plugin-import-socket.md`) is merged**, building on F1's first commit (rebased as F1 merges). It follows the import socket's shapes (companion API versioning, the phase registry), but is a core step, not a plugin. **Proposal:** `docs/proposals/fediverse.md`. Lighter plan (I-569).

## Owns

- `app/Domain/Fediverse/Import/**`; two phase registrations (one line each where core's phases are tagged).
- `tests/Fixtures/Import/FakeCompanion.php` (one new route; additions only), `tests/Fixtures/Fediverse/live/**` (public actor and WebFinger answers), `tests/Feature/Fediverse/Import*`.
- `docs/specs/companion-fediverse-export.md` (new: the companion change, for `github.com/temecms/teme-companion`).
- `docs/guides/operators/` the migration runbook's fediverse section.

## The companion change (spec, for the companion repository)

`GET teme/v1/fediverse`, companion API version 3 (Teme asks only when the probe answers `api >= 3`; otherwise the step reports "update the companion to move fediverse followers" and the run finishes). Same guard as every route: administrators only, application password, HTTPS only. Answers JSON:

- `settings`: `activitypub_actor_mode`, `activitypub_blog_identifier`, `activitypub_use_permalink_as_id_for_blog`, `activitypub_last_post_with_permalink_as_id`, the post-as type, the federated post types, `activitypub_blog_user_also_known_as`, `activitypub_blog_user_moved_to`.
- `actors`: for the blog (`user_id: 0`) and each user with an actor: `user_id`, `user_login`, `public_key_pem`, `private_key_pem` (from `activitypub_keypair_for_0` / `activitypub_keypair_for_{sanitize_title(user_login)}`, unserialized by the companion with `maybe_unserialize`, legacy fallbacks `activitypub_blog_user_public_key`/`_private_key` and user meta `magic_sig_public_key`/`magic_sig_private_key`), `use_permalink_as_id`, `also_known_as`, `moved_to`.
- `followers`: paged (`after_id`, `limit` ≤ 200): each `ap_actor` post's `guid`, `post_content` (the actor JSON), `_activitypub_following` (or legacy `_activitypub_user_id`), `_activitypub_inbox`, `_activitypub_acct`; `_activitypub_followed_by` counted only.
- `reply_sources`: paged: comment id → `source_id` for comments whose `protocol` meta is `activitypub`.

The private keys never appear in the companion's logs, the report, or any other route.

## Decisions written out

**D1. Steps.** `fediverse` at order 1010 (after users, content, comments' settings) and `fediverse-replies` at 710, both through one Operation, `ImportFediverse` (refusal test: writes nothing). Never federates (import mode).

**D2. What 1010 does.** Creates the actors WordPress had, each with its exact `id` and verbatim inbox/outbox/followers/shared-inbox URLs, author actors linked through the import map (`object_type = user`); stores each key pair, the private key encrypted (an actor with no key found is reported and gets a new pair); creates a remote actor and follower row per `ap_actor` followed by a local actor; writes `fediverse_objects` rows for every imported post's WordPress object id (`/?p=N`, or the permalink for posts at or below `activitypub_last_post_with_permalink_as_id`); copies the settings; sets the switch on only when the plugin was active. A catch-up run replaces the followers; keys and actors are never replaced once written. Private keys never go into `import_map.facts`, messages or logs.

**D3. What 710 does.** Links each imported fediverse reply's `source_id` to its comment in `fediverse_objects` (kind `reply`).

**D4. The proof (before any real switch).** Fetch the live actor JSON and WebFinger answers of the sites being moved once (public data only) into `tests/Fixtures/Fediverse/live/`; a test compares Teme's answers field by field: `id`, `inbox`, `endpoints.sharedInbox`, `publicKey.id`, `publicKey.publicKeyPem`, WebFinger `subject`, `aliases`, `links[rel=self]`. Then the staging proof, written as a runbook step for a person: a throwaway Mastodon account follows a WordPress staging site; the staging domain moves to Teme with `teme.fediverse.deliver_outside_production` on; a new post arrives at the account; a reply becomes a held comment; unfollowing works.

## Tasks (tests first; each seen red)

- [ ] **T1. FakeCompanion** gains `teme/v1/fediverse` with the spec's shape; a companion on API 2 makes the step report and skip.
- [ ] **T2. Step 1010**: actors with verbatim ids for each actor mode (`actor`, `blog`, `actor_blog`); keys encrypted and never in the map, messages or logs (assert all three); a missing key → a new pair and a report line; followers; object rows by the permalink rule; settings; the switch only when the plugin was active.
- [ ] **T3. Step 710** links replies; a later Update from the fediverse finds its comment.
- [ ] **T4. Catch-up**: followers replaced, keys and actors untouched.
- [ ] **T5. Field-by-field comparison** against the live fixtures.
- [ ] **T6. The spec file and the runbook section.**

**Quick check:** `php artisan test --compact tests/Feature/Fediverse/Import* tests/Feature/Import` and `vendor/bin/phpstan analyse app/Domain/Fediverse/Import`.

## Records

(The builder fills this.)
