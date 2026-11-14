---
paths:
  - app/Domain/**/Operations/**
---

# Operations

## Shape

- Extend `App\Domain\Support\Operations\BaseOperation`. Implement `authorize`, `validate`, `perform`. Never override `execute()` — it is `final` so authorization cannot be skipped.
- Input is a `final readonly` class implementing `Teme\Contracts\Operations\OperationInput`.
- Name it as an imperative: `CreateDraft`, never `DraftCreator`.

## Traps

- **Take `lockForUpdate()` before any staleness or state check** inside `perform()`. Checking first and locking later is a race: two callers both pass the check and one silently loses.
- An Operation's own work after the commit, cache invalidation, the page cache's purge and the hooks plugins hear all run **after commit**, in this order: `afterCommit()`, `invalidateCache()`, the purge of what `perform()` told `purgeAfterCommit()`, then the notifications `perform()` gave `notifyAfterCommit()` (I-3353; `afterCommit()` was `dispatchHooks()` before 5.1a). `perform()` may tell the page cache what a change touches, read under its lock; it never purges or invalidates anything itself (owner, 2026-09-27; I-472).
- Revisions are immutable. Saving writes a new revision and moves `working_revision_id`; only publishing moves `published_revision_id`.

## Test

Every operation's feature test includes a case proving it **writes nothing** when authorization fails.
