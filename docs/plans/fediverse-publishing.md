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

- [ ] **T1. QueueActivity** from each content event, per type and switch state; nothing in import mode; one delivery per shared inbox.
- [ ] **T2. The object** for Article and Note, imported and new ids, bylines; the Tombstone at 410.
- [ ] **T3. Delivery** with `Http::fake`: each status path; the retry schedule; host skipping after 7 days and resuming; the host-identity rule (a staging host sends nothing); never more than 4 per host.
- [ ] **T4. Replies** create, edit and remove comments through the pipeline; a reply to a reply; comments closed; the throttle; remote HTML with a script becomes plain text.
- [ ] **T5. Sockets**: a fixture plugin with `fediverse.read` receives `ActivityReceived` for a Like; without the capability it receives nothing; its `ActivityHandler` for `Like` runs; it cannot register Follow; `ActorDocumentBuilt` cannot change `id` or `publicKey`; `FediverseSender` delivers signed and the plugin never sees a key.
- [ ] **T6. The alternate link** in each federated item's and author's head; none when the switch is off.

**Quick check:** `php artisan test --compact tests/Feature/Fediverse` and `vendor/bin/phpstan analyse app/Domain/Fediverse packages/teme-contracts/src/Fediverse`.

## Records

(The builder fills this.)
