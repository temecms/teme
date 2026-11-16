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

### Setup and base

- Setup: `/tmp/teme-cloud-setup.log` was absent (`none`); `pwd` `/home/user/teme`. PHP was 8.3, Postgres and Valkey didn't answer, so `bash scripts/cloud-setup.sh` ran once (about 25 minutes); then PHP 8.4.27, `pg_isready` accepting, Valkey `PONG`.
- Base: `origin/import/plugin-socket` e06506c, merged with `origin/fediverse/actors` a073438 (six files conflicted, joined as B-01 says) and `origin/main` 2488d21 (clean). `gapple/structured-fields` v2.3.3 came with the actors branch.
- The full `composer check` ran once, at the end, as the task's CHECKS say; the quick check ran after each task.

### Commits

- `626eb01` merge of `origin/fediverse/actors` (B-01); `4582b8a` merge of `origin/main`.
- `d553daf` feat(fediverse): the companion's fediverse route, read on API 3 and skipped before it (T1, and the spec).
- `b42fe83` feat(fediverse): import the WordPress plugin's accounts, keys, followers, post addresses and settings (T2).
- `2743fbb` feat(fediverse): link imported fediverse replies to their comments (T3).
- `5e3d064` test(fediverse): a catch-up replaces the followers and keeps accounts and keys (T4).
- `e978d68` test(fediverse): compare Teme's answers with a live WordPress plugin site's, field by field (T5, with the fix it found).
- `b4d7d27` docs(guides): the runbook's staging proof for a site with fediverse followers (T6).
- This records commit.

### The full check

`composer check` once, at the end (on `b4d7d27`): Pint and PHPStan clean; Architecture 139 passed (1,325 assertions); Unit and Feature 10,967 passed, 2 skipped, **1 failed** (56,472 assertions, 25 minutes). The failure is `tests/Feature/Console/GenerateManifestReferenceTest` "keeps the committed manifest references in step with the command": `docs/reference/plugin-manifest.md` came from `origin/main` (4f650f6), while the schema's `import.wordpress` and `routes.public` capabilities and `routes` came from the two branches, which had no reference. The generated file is now behind the joined schema. It is fixed by `php artisan teme:docs:manifest`, but `docs/reference` is not a builder's to write, so it is left for the maintainer.

### Tests seen red

- T1 `ImportCompanionTest`: 2 failed, `Target class [App\Domain\Fediverse\Import\FediverseRepliesStep] does not exist`: no steps yet.
- T2–T4 `ImportActorsTest`, `ImportRepliesTest`, `ImportCatchUpTest` were written before the steps but first run after them, all green. Each was then shown red by taking out the code it proves: with the sweep removed, the catch-up's replacing test failed; with the key-pair check skipped, the two key tests failed (the mismatched key was kept; the empty one gave a `TypeError`); with the reply links removed, both replies tests failed.
- T5 `ImportLiveTest`: red first because the run never finished: `fediverse_actors_username_unique` refused the application account, named after the host `notiz.blog`, which the plugin's blog already had (fixed, B-05; `ImportActorsTest` gained a case). Then red with 404 at the author's id (an F1 gap below, now listed in the test).
- `tests/Feature/Import/ImportAcceptanceTest` "has a mark for every step the app registers" went red with the two new steps (B-04).

### Baselines

None changed. `AdminWordsTest` 876 before and after; `DesignSystemTest`'s pinned counts and its 144 contrast pairs unchanged. No baseline line added or removed.

### Decisions (B-?? drafts)

- **B-01 (draft):** joined `import/plugin-socket` and `fediverse/actors` by keeping both sides in six files (`Manifest.php`, `ManifestValidator.php`, `PluginsScreen.php`, `teme-plugin.schema.json`, `Capability.php`, `PluginContractsTest.php`), ours first then theirs, as the owner's note F3b said. `Capability` and the schema's enum and the contract test list `content.read, media.read, http, import.wordpress, routes.public`. In `PluginsScreen`, the two sides had renamed one helper differently (`words()` with imports, `accessLines()` with routes), so the join is `accessLines()` with both: routes, then imports as its last parameter, passed at both call sites. `626eb01`.
- **B-02 (draft):** the companion sends each account's addresses. Options: Teme builds them from the home address and user ID (`/wp-json/activitypub/1.0/actors/N/…`), or the companion sends them from the plugin's own actor objects. Chose the companion: the live site uses the REST prefix `/wp-api/`, and a plain-permalink site uses `?rest_route=`, so built addresses would be wrong, and D2 needs them verbatim. The spec adds to each account `id`, `url`, `inbox`, `outbox`, `followers`, `following`, `shared_inbox`, `preferred_username` and `addresses` (every address of its own the plugin answers, stored as the actor's aliases); to the whole answer `active` (for the switch), `home` (for `/?p=N`) and `followed_by` (the plan's count); and one route with a `kind` parameter for the pages. `d553daf`.
- **B-03 (draft):** where the settings go. **Who can be followed** comes from the actor mode (actor → each author, blog → the blog, actor_blog → both), and `modules.fediverse` is set on when the plugin was active, both through `ImportSettings`, so a value a person changed since is kept. F1's group has no place for the post-as type, the federated types, the accounts' moves (`also_known_as`, `moved_to`) or the accounts the site followed, so each is a message in the run. Switching on through the import makes the blog's and application's accounts as `SwitchFediverse` does, through F1's `MakesLocalActors`, because `SwitchFediverse` needs a person with `settings.modules.manage` and an operator's import has none. `b42fe83`.
- **B-04 (draft):** `tests/Feature/Import/ImportAcceptanceTest.php` isn't in the plan's Owns, but its "a mark for every step" test needs one for each new step. Added two marks, nothing else: `fediverse-replies` alone, and `fediverse` asserting the acceptance site's companion (API 1) is reported once as too old. The test's meaning is unchanged. `b42fe83`.
- **B-05 (draft):** the plugin names the blog after the host by default (`notiz.blog`), as F1 names the application account. The import names the application account `application` when the host's name is taken, and skips it if that is taken too. An account the database refuses is reported without the statement, whose values hold the encrypted key. `e978d68`.
- **B-06 (draft):** what "replaces the followers" means. Each follower is a map row (`fediverse-follower`, the `ap_actor` post ID) stamped with the run. After the last page, every row this run didn't read stops following the imported accounts and its row goes. A follower whose row has a `follow_uri` followed again through Teme's own inbox, so it is never removed. A remote account Teme already holds isn't overwritten by WordPress's older copy. Each document is checked as F1 checks a fetched one (`RemoteActorDocument`, `FetchedKeyOwners::keyIn`), so a document whose key or inbox is on another server is refused and reported. `b42fe83`.
- **B-07 (draft):** the permalink rule. Teme's map keeps only a normalised path (lower case, no slashes), not the permalink the plugin used as the id. So the step asks the companion's `kind=permalinks` for the posts at or below `activitypub_last_post_with_permalink_as_id`; every later post gets `home` + `/?p=N`. Only published posts of a federated type get an address. `b42fe83`.
- **B-08 (draft):** "a later Update from the fediverse finds its comment": F2's reply operations aren't in this base, so the test asserts the lookup they use, the `fediverse_objects` row of kind `reply` at the reply's address, pointing at the comment. Only `https` sources are linked. `2743fbb`.
- **B-09 (draft):** the live fixtures. Neither the plan, the proposal nor the task names the sites being moved. I used the plugin author's own site, `notiz.blog` (actor_blog mode, a blog and an author, a custom REST prefix), trimmed to the fields the protocol needs: no name, summary, pictures, payment links, `mailto:` or `acct:` aliases, or other servers' accounts. The test runs over every folder in `tests/Fixtures/Fediverse/live/`, so a moved site is added as four files (`blog.json`, `blog.webfinger.json`, `author.json`, `author.webfinger.json`) plus its line in `LIVE_GAPS`. A live site's private key stays on WordPress, so the test gives each account a new pair, and proves Teme serves the public half byte for byte as the companion sent it. `e978d68`.
- **B-10 (draft):** what the comparison found but this part may not change is listed in the test as `LIVE_GAPS`, per site, so the test stays green and goes red when F1 fixes one, and the list shrinks. `e978d68`.
- **B-11 (draft):** one Operation, `ImportFediverse`, as D1 says, with five parts (accounts and settings, a page of followers, the sweep, post addresses, reply addresses). Its refusal test writes nothing. `b42fe83`.
- **B-12 (draft):** the run's messages and the steps' labels go through `__()`, as the task's WORDS rule says, though the import's older steps don't. `b42fe83`.

### Findings for the actors part (F1's files, not changed here)

1. **An author whose id is their permalink doesn't answer at it.** The plugin's `use_permalink_as_id` makes an author's id `https://notiz.blog/author/matthias-pfefferle/`. F1 answers only `/?author=N`, `/?p=N`, `/@name`, `/ap/…` and `/wp-json/activitypub/1.0/…`, so that id is 404 for activity JSON. D4 asked for every imported actor or object address. Remote servers that refetch the account by its id lose it.
2. **Only the `/wp-json/` REST prefix is routed.** A site with another prefix (`notiz.blog` uses `/wp-api/`) or plain permalinks (`?rest_route=`) has its inboxes, shared inbox and collections unanswered (POST answers 405). Followers' servers deliver to those cached addresses until they refetch.
3. `endpoints.sharedInbox` is always Teme's `/ap/inbox`, not the imported one (kept in `urls.sharedInbox`). That is harmless only where the old one is routed (point 2).
4. `SwitchFediverse` names the application account after the host, which the plugin's default blog name already is: switching on after such an import breaks on `fediverse_actors_username_unique`. The import avoids this itself (B-05).
5. The actor document carries no `alsoKnownAs` or `movedTo`, so the moves WordPress recorded aren't kept (reported in the run).

### The publishing part's D2 check: the actor_blog Announce

Checked against the live `notiz.blog` blog outbox (one Announce kept, trimmed, in `live/notiz.blog/blog-announce.json`) and F2's `QueueActivity::queue()` on `origin/fediverse/publishing` aa71b8f. They differ:

- **object:** WordPress announces the author's whole `Create`, embedded, with its own id (`/?post_type=ap_outbox&p=N`, its actor the author, its object the `Article` at `/?p=N`). F2 announces the object's address alone, the `Article`'s id as a string.
- **cc:** WordPress addresses the author's followers collection, the accounts mentioned and the author's id. F2 addresses the blog's followers collection.
- **audience:** WordPress sets `audience` to the blog's id on the Announce, the Create and the object. F2 sets none.
- Alike: the actor is the blog's id, `to` is Public, and the object's id is the imported `/?p=N`.

F2's form is the ordinary boost; WordPress's is the group form of an Announce. I changed nothing in F2's files: whether to match WordPress is for the architect.

### Contracts

No change to a contract this plan names. The spec `docs/specs/companion-fediverse-export.md` is new, for the companion repository.

### Left open

- **STOP:** before T5, `git merge origin/import/plugin-socket` (286359d, the socket's review fixes) conflicted in `app/Domain/Plugins/Consent.php`, `app/Domain/Plugins/Operations/EnablePlugin.php` and `app/Domain/Plugins/Queries/PluginsScreen.php`. These aren't two additions at one spot: both sides rewrote the consent record's shape (routes against agreed imports), the same docblocks and the same lines of `added()` and the screen's helper. Joining them is a decision about what a plugin's consent covers, in another part's files. I aborted that merge and built T5 and T6 on the earlier join, which no task of this plan depends on beyond it. This branch still has the socket at e06506c.
- **For the maintainer's join (owner's note F3c):** this branch is built on the socket before its R7 consent fixes (e06506c, not 286359d); the owner left that consent conflict for the maintainer's join, and the R7 fixes are not merged here.
- At that join, `php artisan teme:docs:manifest` must run, so `docs/reference/plugin-manifest.md` matches the joined schema (the full check above).
- The sites actually being moved need their own fixtures (B-09).
- The F1 findings above, and the Announce difference.
- The staging proof (the runbook's F.1–F.9) is for a person to run.

### Try it

1. On a WordPress copy with the ActivityPub plugin active and the companion at export API 3, connect it in **Tools › Import** and run a full import.
2. The report shows **fediverse-actor** and **fediverse-follower** rows. **Settings › Fediverse** lists the accounts with WordPress's followers, and the switch is on.
3. `curl -H 'Accept: application/activity+json' https://<site>/?author=0` answers the blog's account with WordPress's id and key.
4. Then follow the runbook's **If the site has fediverse followers** section.

### Changelog lines for the maintainer

#### Added
- Moving from WordPress's ActivityPub plugin: an import brings the plugin's accounts with their keys, their followers, its settings and the fediverse addresses of posts and replies. Followers keep following the site on the same domain, with nothing to do. Needs the companion's export API 3.
- The migration runbook's staging proof for a site with fediverse followers.
