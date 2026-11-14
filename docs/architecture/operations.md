# Operations

Every state mutation in Teme goes through an Operation. Controllers, API endpoints, queue jobs, console commands and AI tools call operations; none of them touch Eloquent models directly.

This is the enforceable form of the spec's rule that **code sharing is optional, behaviour sharing is mandatory**. `PublishRevision` is the same code whether it was triggered by a button in the admin, a scheduled job, an API client, or an AI agent — so the API, MCP and mobile surfaces cost almost nothing to add later, and none of them can drift from what the editor does.

It is enforced by `tests/Architecture/LayeringTest.php`, not by convention. Agents read test failures reliably and documentation inconsistently.

## How the rule is enforced

Two checks, because each catches what the other cannot:

| Check | Catches | Misses |
|---|---|---|
| `tests/Architecture/LayeringTest.php` | `App\Http` or `App\Actions` **naming** a model class, a query builder or the `DB` facade | Writes to a model the code never names — `$request->user()->update(...)` |
| The PHPStan rule `Tests\PHPStan\Rules\NoModelWritesInHttpLayer` | Any **write method** called on an Eloquent model, Eloquent builder, relation or query builder from `App\Http` or `App\Actions`, by the object's *type* | Reads — which the architecture test covers |

Both were proven by planting a violation and watching the build fail before they were trusted. The first version of the architecture test was not: its probe used Eloquent's base class, the only thing it checked, so it could not find the gap (idea I-097).

## Reading: query classes

Controllers may not name models, so they read through **query classes** in `App\Domain\<Module>\Queries` — for example `CurrentUserData`. A query returns arrays or read-only value objects, **never a model**. That keeps models out of the HTTP layer and out of the browser: the starter kit used to send the entire serialised user model to every page; the admin now receives exactly the fields a query chooses to return.

## The one named exception (I-100)

Laravel Fortify writes its own credential state directly, from its own vendor code:

| Table | Columns |
|---|---|
| `users` | `remember_token`, `email_verified_at`, `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` |
| `password_reset_tokens` | all |
| `sessions` | all |

Only the account's owner, signed in through a browser, ever changes those values, so there is no API, job or AI path that could drift from them. **Everything else about a user — name, email, password, role, suspension — goes through an Operation**, including a password reset: Fortify's reset step is an adapter that calls `ResetPassword`.

The PHPStan rule carries an allow-list of classes that may write despite being in `App\Http` or `App\Actions`. It is empty unless a Fortify integration point makes an entry unavoidable, and **every entry is a maintainer's decision**, recorded against I-100. The allow-list must never be widened to make a failing build pass.

## The operator

Someone with a shell on the server can already read the database and `APP_KEY`, so no check an Operation makes could stop them. Console commands in the `teme:` namespace therefore act as **the operator**:

- They bind `OperatorElevation`, which satisfies elevation.
- They pass `actorId: null`.

An Operation's `authorize()` accepts a null actor **only** when `Operator::isPresent()` is true, meaning `OperatorElevation` is bound. The operator's re-encryption of every encrypted value, on a restore and on a key change (`ReencryptSecrets`, slice 1.12b), is one of these.

`DeleteContentPermanently` and, since 1.8c′, `DeleteCommentPermanently` take a null actor from the operator, as `teme:trash:purge` calls them. API requests and AI tools never bind it, and a null actor from either is refused. A queue job binds it only for an operator's own work, as `GenerateMediaVariants` does for an operator's upload (B-098), and for the share images an operator's `ReindexMediaUsages` finds an image used without (I-1095, `docs/architecture/media.md`, *The share images*): the job is queued with a null actor, binds the operator itself, and `RecordMediaVariants` accepts a null actor only then. That Operation has a second case of authorization for a person, an actor who may not change the image but may edit the item that uses it as its featured or social image, so a writer's first use of an image gets its share images made without the right to change the file. `Elevation` is a scoped binding, so the queue worker forgets it before the next job, and no later job inherits it (issue #53). I-392 replaces the null actor with an explicit operator marker when 1.11 queues operator work: 1.11a built it as `JobActor`, a person's ID or the operator, made only while the operator is present, and every job of an import run carries one. An import run's jobs also work in **import mode**, which holds each Operation's purge of the page cache until the run ends with one purge of everything (`docs/architecture/import.md`, *A run* and *Who may import, and who a run acts for*). This is how `teme:user:create` makes the first administrator before any administrator exists (study G, S12).

**The operator's type work** (slice 2.1a): `php artisan teme:types:import {file}` binds `OperatorElevation` and hands a `teme-types.json` file to `ImportTypes` with a null actor, which accepts it only for the operator. It adds and updates the site's own content types, never removes one, and works while `TEME_TYPES_LOCKED` is on, since the lock exists so types arrive that way. `php artisan teme:types:export` prints or writes the file and changes nothing. The four type Operations, `CreateType`, `UpdateType`, `DeleteType` and `ImportTypes`, authorize `types.manage` or the operator (`docs/architecture/content-types.md`, *Types made on the site*).

**The operator's scheduled work** also has two Operations that only the operator may run, beside `RebuildCounts` and the trash purge. `EraseCommentAddresses` runs daily through `teme:comments:erase-addresses` (`docs/architecture/comments.md`, *Commenters' addresses*). `RecordAddressCounts` runs every minute through `teme:redirects:count` (slice 1.9c; `docs/superpowers/plans/2026-10-07-phase1-slice09c-redirects-and-404-log.md`, D1 to D20 are I-1043 to I-1062): the command binds `OperatorElevation` and calls it with a null actor, and it refuses everyone else ("Only the operator writes the counts."). It adds what readers met since the last run to the tables, each redirect's hits and the 404 log, in batches of 500 (`docs/architecture/addresses.md`, *Redirects*). The command reads the 404 log's size and ignore list from Search and social's settings (`NotFoundLogSection`) and hands them to the Operation as plain values. A site whose scheduler does not run counts nothing.

**A release’s rebuilds** (slice 1.13c, I-3113) are work on existing content that is too big for one SQL statement in a migration, such as moving every address or reindexing search. A migration that needs one calls `ReleaseRebuilds::ask()`, which writes a row to `release_rebuilds` in the migration’s own transaction, so the request stands exactly when the migration does. `ReleaseRebuilds::KEYS` lists the names it knows, and a name it doesn’t know fails the migration. That call is the one write a migration makes outside an Operation, besides filling its own new data. `teme:deploy` queues the requests once the migrations have run, as the operator: `QueueReleaseRebuilds` accepts only the operator, marks every request not yet queued as queued, and after commit puts one `RunReleaseRebuild` job on the queue for each, carrying the operator as its actor (I-392). A request whose job can’t be queued is put back, so the next deploy queues it. `release_rebuilds` is small and true of the site, so a backup keeps its rows.

## Telemetry sinks

Some records exist only to be read by a person later. They never decide anything the application does. Examples:

- the security event log;
- the rate limiter's counters;
- the cache;
- the session;
- the heartbeats (`heartbeats`, I-3112): one row each for the scheduler and every queue, overwritten in place at most every ten seconds. A write that fails is dropped. The Status banners and `teme:doctor` read it to tell a person that something has stopped, never to decide anything;
- the redirects' counts spool (`AddressCounts`, I-1054): a file in the page cache's shared folder to which a reader's request appends one line when a redirect sends it on or an address answers 404. A line that can't be written is dropped, and nothing decides from the spool.

These are written by their own service (for example `App\Domain\Security\SecurityLog`), from listeners and from inside Operations. They are **not** state mutations in rule 1's sense.

The test for a sink is: **if the feature that reads it were deleted, would any other behaviour change?** If yes, it is state, and it goes through an Operation.

A sink is append-only apart from retention pruning, or, like the cache and the heartbeats, overwritten in place. Nothing may make a decision from it without first turning it into state through an Operation.

## The fixed sequence

`App\Domain\Support\Operations\BaseOperation::execute()` runs:

1. **`authorize()`** — throws before anything is read or written
2. **Elevation** — for an Operation implementing `Teme\Contracts\Operations\RequiresElevation`, the bound `Elevation` must confirm a fresh proof of identity (`docs/architecture/security.md`)
3. **`validate()`** — throws before the transaction opens
4. **`perform()`** — inside a database transaction
5. **`afterCommit()`** — after commit: the Operation's own work once the change is saved (named `dispatchHooks()` before slice 5.1a)
6. **`invalidateCache()`** — after commit
7. **The page cache's purge** of what `perform()` told through `purgeAfterCommit()` — after the outermost commit (slice 1.7b, `docs/architecture/page-cache.md`); the CDN's purge, and the drawing again of the pages it emptied, go on the queue from it (slice 1.7d′)
8. **The hooks plugins hear** — the notifications `perform()` gave `notifyAfterCommit()`, delivered last, after the purge, once the outermost transaction has committed; never after a rollback, and held during an import run (slice 5.1a, I-3353)

Steps 5 to 7 run after commit deliberately. Invalidating cache inside the transaction means a rollback leaves the cache purged for a change that never happened — or repopulated from uncommitted state, which is worse because it is silent and permanent.

`execute()` is `final`. A subclass that overrode it could skip authorization, and that is precisely the failure this design exists to prevent.

## Writing a new operation

1. Create a `final readonly` input DTO implementing `Teme\Contracts\Operations\OperationInput`. It carries exactly what the operation needs, including the acting user's identifier.
2. Extend `BaseOperation` and implement `authorize`, `validate` and `perform`.
3. Take a `lockForUpdate()` on any row whose current state you check before writing. Without it, two concurrent callers both read the same value, both pass the check, and one silently loses.
4. Write the feature test first. It must include a case proving the operation **writes nothing** when authorization fails.
5. If the change alters what the public sees, read the footprint of each thing it changes (`App\Domain\Cache\Footprints`) under the lock, before the change and after, and hand both to `purgeAfterCommit()` as a `CacheKeysFor`; `BaseOperation` purges after the outermost commit. A change the public can't see, such as a draft's, hands nothing. Test that the right pages go after commit, and none after a rollback.

`afterCommit()` and `invalidateCache()` are seams, not obligations. Override `afterCommit()` for an Operation's own work once the change is saved, and `invalidateCache()` for a cache other than the page cache; plugins are told through `notifyAfterCommit()`, never an override (slice 5.1a); the page cache is told through `purgeAfterCommit()` (slice 1.7b). Until then the defaults are no-ops and the ordering is already guaranteed.

## The publishing model

`contents` carries two pointers:

| Column | Meaning |
|---|---|
| `published_revision_id` | What the public site renders |
| `working_revision_id` | What the editor is editing |

Saving a draft writes a **new** revision and moves only the working pointer. A published page keeps serving its published revision until `PublishRevision` moves the other one. Nothing is ever rewritten in place; revisions are immutable once written, which is why `ContentRevision` sets `UPDATED_AT = null`.

That single arrangement satisfies the spec's whole §6 publishing requirement:

- Autosaving an edit to a published article cannot change the live page — the published pointer is untouched.
- "Update…" is one pointer assignment.
- "Restore as draft" writes a new working revision and does not touch the published pointer.
- Both the previous and the current published revision remain addressable.

**Outside revisions.** Two things about an item change at once and are never versioned: its Discussion (`SetDiscussion`, I-571) and, since 1.11r, its visibility (`ChangeVisibility`, I-1700, I-2779). Each is for whoever may publish the item and writes with the query builder, so `updated_at` stays: neither is an edit. `ChangeVisibility` reads the item's footprint under the lock before the change and after, and hands both to `purgeAfterCommit()`, so a private item's every trace, and a page stored while a protected item was public, go after the outermost commit (`docs/architecture/page-cache.md`, *Protected items*).

### Field values travel with the body

`fields` lives on `content_revisions`, and `contents.fields` is a projection of the published revision.

Versioning the prose without the field values would restore an article's text while silently keeping today's SEO title and featured image. Statamic, Craft and Payload all version custom field values alongside the body; we found this the hard way, as a defect in the first draft of the spec.

## Conflict detection

Operations that modify existing content take an `expectedRevisionId` and throw `StaleRevisionException` when it no longer matches. This is what stops a second editor's save silently overwriting a first editor's work.

The check is only safe inside the transaction, after `lockForUpdate()`. Checking before the lock is a race, not a check.

## Why not a service layer, repositories, or an action class per controller

An Operation is deliberately narrower than a "service": one class, one mutation, one fixed lifecycle, no incidental helper methods accumulating on it. That narrowness is what makes the architecture test possible — a rule like "controllers do not touch models" is only enforceable if there is exactly one sanctioned alternative.

It is also what makes the AI story real rather than decorative. An operation already declares its input shape, its authorization and its validation, so exposing it as a permission-checked AI tool or an API endpoint is a registration, not a rewrite.
