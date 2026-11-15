# Plan F2: Fediverse publishing, replies and plugin sockets

**Branch:** `fediverse/publishing`, from **F1's first commit** (the schema, `Signer`, `RemoteFetcher`), rebased onto main as F1 merges. Also needs wave 1's plugin jobs (`docs/plans/plugin-jobs.md`) merged for plugin handlers' job tracking. **Proposal:** `docs/proposals/fediverse.md`. Lighter plan (I-569).

## Owns

- `app/Domain/Fediverse/Publishing/**`, `app/Domain/Fediverse/Replies/**`, `app/Domain/Fediverse/Sockets/**`.
- `packages/teme-contracts/src/Fediverse/**` (new; `@since` the next release; no `App\` or `Illuminate` types, rule 5).
- One alternate-link contributor registered in `DocumentMeta::CONTRIBUTORS` (additions only).
- `docs/guides/developers/` one page, "Extending the fediverse" (if the handbook part is merged; else a stub marked "lands with the handbook").
- Tests under `tests/Feature/Fediverse/Publishing*`, `Replies*`, `Sockets*`.

## Decisions written out

**D1. From content to activity.** A listener on `ContentPublished` (first publication → Create, otherwise Update), `ContentUnpublished`, `ContentTrashed`, `ContentDeleted`, `ContentVisibilityChanged` (→ Delete), for federated content types only, while the switch is on, calls `QueueActivity` (one transaction: the `fediverse_activities` row and one `fediverse_deliveries` row per distinct shared inbox, else personal inbox, of the actor's followers). These events already fire after commit and never in import mode: the import sends nothing. Delete also sets `fediverse_objects.deleted_at`, and the object URL answers 410 with a `Tombstone`.

**D2. The object.** `id` = the item's `fediverse_objects` uri (imported: `/?p=N` or its permalink; new: `/ap/objects/{ulid}`); `type` from settings (`Article` or `Note`); `url` the live permalink; `attributedTo` the first byline with an author actor, else the blog actor; `content` from the feed renderer (`FeedBody`, absolute links at render time, rule 9); `to` Public, `cc` the actor's followers. In `actor_blog` mode the author actor sends the Create and the blog actor sends an `Announce` of it (to verify against the live fixtures from F3 before merging; record the result).

**D3. Delivery (delicate).** `DeliverActivity` (one delivery row): skip if the host is blocked or `skipped`; sign with F1's `Signer`; POST through F1's fetch rules (public addresses, https, pinned address, 5 s timeout); 2xx → `sent`; 410 → remote actor gone, follower removed; other 4xx → `failed`, no retry; 5xx or timeout → retry on the proposal's P1 schedule; at most 4 concurrent per host (a Redis-free limiter on the queue: `WithoutOverlapping` keyed by host with 4 slots, or the job tracking's own; record the choice). Delivery only when the request host equals the actors' host and, outside production, only when `teme.fediverse.deliver_outside_production` is on.

**D4. Replies.** `ProcessInboxActivity` routes Create/Update/Delete whose object is a reply (`inReplyTo` a `fediverse_objects` uri of kind `post`, or of a `reply` on one) to `ReceiveFediverseReply`, `UpdateFediverseReply`, `RemoveFediverseReply`. The reply becomes a comment through the comment pipeline (Discussion rules, `CommentThrottle` plus 10 an hour per remote account, the bound `CommentModerator`, the queue), its HTML converted by `CommentWords` to the restricted comment document (never stored as HTML), `author_name`/`author_url` from the remote actor, `author_email` and `ip` null; the link in `fediverse_objects` (kind `reply`). Comments off or closed on the item → dropped and logged.

**D5. Plugin sockets** (`Teme\Contracts\Fediverse`), exactly as study §6: `ActivityReceived` and `ActivitySent` (notifications, after commit, capability `fediverse.read`, never for a blocked host or failed signature); `ActivityHandler` + `CollectActivityHandlers` (core's own types never offered; handlers run in a queued job and write only through the plugin's Operations); `ObjectBuilder` + `CollectObjectBuilders`; `ActorDocumentBuilt` and `ObjectBuilt` transforms (core re-applies the protected keys after the transform); `FediverseFollowers` (read service); `FediverseSender` (send service, capability `fediverse.send`: core signs, stores and delivers after the plugin's transaction commits). Likes and Announces reach plugins only through these.

## Tasks (tests first; each seen red)

- [x] **T1. QueueActivity** from each content event, per type and switch state; nothing in import mode; one delivery per shared inbox.
- [x] **T2. The object** for Article and Note, imported and new ids, bylines; the Tombstone at 410.
- [x] **T3. Delivery** with `Http::fake`: each status path; the retry schedule; host skipping after 7 days and resuming; the host-identity rule (a staging host sends nothing); never more than 4 per host.
- [x] **T4. Replies** create, edit and remove comments through the pipeline; a reply to a reply; comments closed; the throttle; remote HTML with a script becomes plain text.
- [x] **T5. Sockets**: a fixture plugin with `fediverse.read` receives `ActivityReceived` for a Like; without the capability it receives nothing; its `ActivityHandler` for `Like` runs; it cannot register Follow; `ActorDocumentBuilt` cannot change `id` or `publicKey`; `FediverseSender` delivers signed and the plugin never sees a key.
- [x] **T6. The alternate link** in each federated item's and author's head; none when the switch is off.

**Quick check:** `php artisan test --compact tests/Feature/Fediverse` and `vendor/bin/phpstan analyse app/Domain/Fediverse packages/teme-contracts/src/Fediverse`.

## Records (the builder's)

### Setup and base

- Waited for `origin/fediverse/actors` (first commit) and `origin/plugins/jobs` (its records commit). Branch = the two merged: merge `8e30a0f`. Its conflicts were lists both sides added to (`Capability`, the manifest schema's enum and its `jobs`/`schedules` blocks, `ManifestValidator`'s methods and calls, `PluginContractsTest`'s list): the first stopped the part, then the owner's GO kept every entry from both sides in the order content.read, media.read, http, routes.public, jobs. `1923b39` formats the joined schema.
- F1's later commits merged before tasks: `a578bda` (Settings › Fediverse), `b26c517` (its records). No conflict.
- Base `composer check` at `1923b39`: Architecture **139** passed; Unit+Feature **10731** passed, **2** skipped. Baselines: admin words **876**, design system **222**.

### Tests seen red

- T1 `PublishingActivitiesTest`: 7 failed (no `QueueActivity`, no activities); the paused and import cases passed, as nothing was sent either way.
- T2 `PublishingObjectTest`: 3 failed: the object and the imported address answered 404, the removed one 200 instead of 410.
- T3 `PublishingDeliveryTest`: 13 failed (`DeliverActivity`, `DeliverySchedule`, `RecordDelivery` missing).
- T4 `RepliesTest`: 10 failed (no comment made; no log line; no throttle).
- T5 `SocketsTest`: 8 failed (the plugin did not start, offered no fediverse door; `ObjectBuilt` not applied; no `ActivitySent`). "Tells nothing without fediverse.read" passed when first run: the dispatcher's capability check already refuses it once the hook class exists.
- T6 `PublishingAlternateLinkTest`: 1 failed (no alternate on the item's page); the switched-off case passed.

### Commits

- `feat(fediverse): queue activities when federated items change` (`e1074c8`)
- `feat(fediverse): answer a federated item's object, and a Tombstone at 410` (`2b28e14`)
- `feat(fediverse): deliver queued activities, signed, with retries per host` (`521fc0c`)
- `feat(fediverse): take replies to the site's posts as comments` (`35f082f`)
- `feat(fediverse): open the fediverse's sockets to plugins` (`dcc7f40`)
- `feat(fediverse): link a federated item's object and an author's account in the head` (`f88fcfa`)
- `docs(guides): stub the fediverse page of the developers' handbook` (`aabb870`)
- `docs(plans): fediverse publishing's records` (this one)

### Checks

- Full `composer check` at `b26c517`: Pint, PHPStan, types, lint passed; Architecture **139** passed (1331 assertions); Unit+Feature **10850** passed, **2** skipped (55869 assertions). Baselines unchanged: **876** and **222**. (`resources/js/actions` had to be generated with `php artisan wayfinder:generate --with-form` for F1's new screen; nothing committed.)

### Decisions (draft)

- B-01 (draft). Merge conflicts that were only both sides' list entries: kept every entry (owner's standing permission). `8e30a0f`.
- B-02 (draft). Who sends: options were a setting of this part's own (`actor_mode`) or F1's "Who can be followed". Chose F1's: the blog → the blog sends; each author → the first byline's author account (else the blog); both → the author sends and the blog announces. `object_type` and `types` are read from the group when it holds them, else Article and `['post']`. `e1074c8`, `a578bda`.
- B-03 (draft). Core's listener on the content hooks: listening always broke three merged tests that hold nothing in core listens to `ContentPublished` (`SubscriptionTest`, `JoinTest`, `LoadingTest`). Chose to listen only once the fediverse is on, checked as the app boots and as each queued job starts, so a site that never turns it on builds no hook for it. A worker started while it was off listens from its next job. `dcc7f40`.
- B-04 (draft). A visibility change to public counts as published; republishing after a Delete sends Create again and clears `deleted_at`. `ContentDeleted` sends the Delete only when the object row is still there: a permanent delete cascades it away, so its Tombstone is lost (open item). `e1074c8`.
- B-05 (draft). New provider `FediversePublishingServiceProvider`, one line in `bootstrap/providers.php` (as F1's B-05). `e1074c8`.
- B-06 (draft). F1's signposts filled: `FediverseDocuments::at` answers `ObjectDocuments`, `DocumentRoute` answers a Tombstone with 410, `AcceptFollow` dispatches its delivery and tells `ActivitySent` after commit, `ProcessInboxActivity` sends other activities and a Delete of anything but the sender to `InboxActivities`. `2b28e14`, `521fc0c`, `35f082f`, `dcc7f40`.
- B-07 (draft). Delivery: a job per delivery plus `RecordDelivery` (an Operation the job runs as the site's own work, like `DisablePlugin`). The 4-per-host limit is `WithoutOverlapping` with four lock slots per host, picked by the delivery's ID. A failing host is marked on `fediverse_remote_actors.failing_since`; after 7 days it is tried once a day (`Cache::add`). A 410 marks the accounts behind that inbox gone and ends their follows. `521fc0c`.
- B-08 (draft). A copy elsewhere or outside production: options were marking deliveries skipped or leaving them queued untried. Chose queued with no job (F1's `InboxTest` expects the Accept queued, and the test queue runs jobs at once). New config key `teme.fediverse.deliver_outside_production`. `521fc0c`.
- B-09 (draft). Replies: their own Operations write the comment as `SubmitComment` does (it requires an email). A removed reply goes to the trash with its previous status. The throttle is 10 an hour per remote account; `CommentThrottle`'s keys are empty for a remote reply (no address, no email). `35f082f`.
- B-10 (draft). Sockets: the study's §6 is not in the repository; names follow D5. `@since 0.9.0`. Shared additions: `Capability::FediverseRead`/`FediverseSend`, the schema's enum, `PluginServices::OFFERED`, two `if`s in `PluginDoors` (a decorator broke `JoinTest`'s check that `PluginDoors` is bound), six hooks in `HookCatalogue`; tests' expected lists: `HookContractsTest` 12 → 18 hooks, `PluginContractsTest` +2 capabilities. A fixture plugin `tests/Fixtures/plugins/fediverse`. `dcc7f40`.
- B-11 (draft). Handlers run inside `ProcessInboxActivity`, already a queued job; a failing handler is logged and the rest go on. Types Teme keeps: Follow, Accept, Reject, Undo, Create, Update, Delete, Block, Move, Flag. `FediverseSender` refuses those, objects and accounts of the site's own, an unknown account and a paused fediverse (`SendRefused`); `SendPluginActivity` runs only for an enabled plugin whose consent holds `fediverse.send`, never for a person. `dcc7f40`.
- B-12 (draft). The handbook is not merged: `docs/guides/developers/fediverse.md` is a stub marked "lands with the handbook". `aabb870`.

### Open items

- `PluginWords` has no sentence for `fediverse.read`/`fediverse.send` (nor for `jobs`): the consent screen shows the raw names.
- D2's `actor_blog` Announce is to be checked against F3's live fixtures before merging.
- A permanently deleted item's object loses its Tombstone (B-04).

### Follow-up R7

The review's findings 1 to 9 (7 waived), on this branch only; nothing merged.

Tests seen red, then the fix:

- 1: `PublishingActivitiesTest` "runs plugins’ object and sent listeners without the operator’s power" got `built as operator`/`sent as operator`; "refuses a forged token…" failed with a ReflectionException (no `SiteWork`). `32bc2cf`.
- 2: `SocketsTest` 3 failed: an author sending as the blog was not refused, a visitor not signed in was not refused, the Operation with no person or an editor was not refused. `46930bb`.
- 3: `SocketsTest` "tells a plugin granted fediverse.read of a Like…" ran the handler inline with no tracked job (`TrackedJob::sole()` threw). The off-plugin case was written after the fix. `beb5319`.
- 4: `RepliesTest` "holds an approved reply for moderation again…" (the status stayed approved) and "counts edits toward the ten replies an hour…" (`Edit 10.`, not `Edit 9.`). `26bfd7c`.
- 5: `PublishingActivitiesTest` "addresses a new object on the sending actor’s host…" (the address began with the editor's host). `4c3bd66`.
- 6: `PublishingDeliveryTest` "gives a delivery’s job a day to run…" (`$tries` was 100). `ddf3ff0`.
- 8: `SocketsTest` "takes nothing from a server blocked after its activity was queued…" (a plugin heard the Like). `79f6304`.
- 9: `PublishingAlternateLinkTest` "finds an author’s account in the same queries however many authors have one" (18 queries, not 12) and "links no author’s account while only the blog can be followed". `aa71b8f`.

Decisions (draft):

- B-13 (draft). Finding 1: the site's own content work carries a `SiteWork` token that only `ContentActivities` can issue (a private constructor, checked by caller; issued tokens kept in a container-held `WeakMap`, since `app/` holds no static state). `QueueActivity` accepts it in place of a person, and binds no operator, so plugins' `ObjectBuilt`/`ObjectBuilder`/`ActivitySent` listeners run as nobody.
- B-14 (draft). Finding 2: `PluginSender` acts for the signed-in person; in a console or worker, for the administrator who enabled the plugin (`consented_by`); for a visitor not signed in, nobody, so the send is refused. `SendPluginActivity` allows a person holding `settings.fediverse.manage`, or the person behind the author account being sent as. A plugin job started by a person (`RunPluginJob`, the plugins part) does not expose who started it, so the sender acts as the enabling administrator there.
- B-15 (draft), replacing B-11's first sentence. Finding 3: each matching handler is a tracked job (`fediverse.handler`, owned by the plugin's code, started by the enabling administrator) run by `RunActivityHandler` as that administrator; it fails, running nothing, once the plugin is off, safe mode is on, the consent changed hands or no longer holds `fediverse.read`. A handler's plugin is the running plugin whose namespace holds its class; an anonymous class is left out and logged. The rest of B-11 stands.
- B-16 (draft). Finding 4: an edit of an approved reply goes to the bound moderator; anything but approved, or changed links, holds it (counts, the waiting email, page purge, `CommentStatusChanged`). An edit counts toward the account's ten replies an hour.
- B-17 (draft). Finding 5: an object's address is the sending actor's scheme, host and port plus `/ap/objects/{ulid}`.
- B-18 (draft). Finding 6: `DeliverActivity` has `retryUntil()` a day ahead in place of `$tries`; `failed()` puts a delivery still queued back 15 minutes later (only where the site delivers).
- B-19 (draft). Finding 8: `ProcessInboxActivity` (F1's file, an addition) returns before `ActivityReceived` when the sender's host is blocked.
- B-20 (draft). Finding 9: one query joins accounts, people and current addresses; none while only the blog can be followed. `PublishingAlternateLinkTest`'s setup now sets "Who can be followed" to both, since the setting's stored default is the blog: what the first test proves is unchanged.

Check: `composer check` passed on `aa71b8f`: Architecture 139 passed (1334 assertions); Unit and Feature 10863 passed, 2 skipped (55912 assertions). Baselines unchanged (876, 222). The first run had one failure, `Plugins/DiscoveryTest` "finds the configured folders…", which found another worker's `tests/jobs` fixture plugin; it passes alone and passed on the second full run.

Open:

- Finding 7 is waived by the owner for now: F3 (the fediverse import) runs that check against its live fixtures. Open for F3.
- Finding 10, not filed: `PluginWords` has no `fediverse.read`/`fediverse.send` sentences; a permanent delete loses its Tombstone (both already open items above).


### Changelog lines for the maintainer

- Added: federated posts are sent to followers as Create, Update and Delete, signed, with retries and per-server limits; a staging copy sends nothing.
- Added: a post's fediverse address answers its Article or Note, and a Tombstone (410) once it is taken back.
- Added: replies from the fediverse become comments through moderation.
- Added: plugin sockets in `Teme\Contracts\Fediverse` (0.9.0), with the `fediverse.read` and `fediverse.send` capabilities.
- Added: pages link their fediverse object or author account as an alternate.

