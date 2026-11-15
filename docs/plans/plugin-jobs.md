# Plugin jobs and schedules (v1, wave 1, part B)

**One part, one branch: `plugins/jobs`, from `origin/main`.** A plugin declares jobs and schedules, is granted `jobs`, queues work through a contract, and core runs, tracks, retries and stops it.

Steps use checkbox (`- [ ]`) syntax for tracking.

**Written 2026-11-15** for the repository `temecms/teme`. This plan is lighter (I-569): it writes out the decisions, names, schema, permissions, tests and the delicate code, and describes the rest.

**Spec:** `docs/proposals/plugin-jobs.md` (its Settled section is binding; Provisional items are built as written). `docs/architecture/plugins.md` › Background work, Trust, Lifecycle.

**Bound by:** I-3445 (v1), I-3396 (a wall is a missing socket), I-104 (job kinds, retry and cancel), I-392 (whom work runs as), rule 1 (Operations), rule 4 (queue payloads are Laravel's own; plugin payloads are JSON data only), rule 5 (contracts import nothing from `app/` or Illuminate), rule 6 (after commit), I-3439 (American English), I-569, I-3317.

**New dependencies:** none. **Migrations:** one, adding a column. **Permissions:** none new (`plugins.manage` grants; Tools › Jobs keeps its own). **Operations:** none new; `DisablePlugin` and `EnablePlugin` change; core's `StartJob`, `ReportJobProgress`, `FinishJob`, `FailJob`, `CancelJob`, `RetryJob` are used. **Contracts:** new, in `packages/teme-contracts` (`@since 0.9.0`). **Drafts:** `B-?? (draft)` for plan faults.

**Other parts building at the same time:** part A, plugins writing content (content and media Operations, the `content.write` capability, its door); part C, import fixes (`app/Domain/Import/**`). Both A and this part add **one enum case** to `Capability` and **one block** to `PluginDoors::for()`: add yours only; the maintainer joins the two. Touch nothing else of theirs.

---

## Owns

- `packages/teme-contracts/src/Jobs/` (new): `Jobs.php`, `JobHandler.php`, `JobContext.php`, `JobStopped.php`, `PluginHasJobs.php`
- `packages/teme-contracts/src/Plugins/Capability.php` (one case), `packages/teme-contracts/schema/teme-plugin.schema.json` (`jobs`, `schedules`)
- `app/Domain/Plugins/Jobs/**` (new): `RunPluginJob.php`, `PluginJobKind.php`, `PluginJobs.php`, `PluginJobContext.php`, `DueSchedules.php`, `DeclaredJobs.php`
- `app/Domain/Plugins/Doors/PluginDoors.php` (one block), `app/Domain/Plugins/Doors/PluginHttp.php` (the longer limit inside a long job)
- `app/Domain/Plugins/ManifestValidator.php` (`jobs`, `schedules`), `app/Domain/Plugins/Operations/DisablePlugin.php`, `EnablePlugin.php`
- `app/Console/Commands/RunDuePluginSchedules.php` (new), one line in `routes/console.php`
- one migration: `add_schedule_runs_to_plugins`
- `resources/js/pages/tools/jobs.tsx` and its row component: the plugin mark beside a plugin-owned job
- Tests: `tests/Feature/Plugins/PluginJobsTest.php`, `PluginSchedulesTest.php`, `tests/Unit/Plugins/DeclaredJobsTest.php`, a fixture `tests/Fixtures/plugins/jobs/`, one case in `tests/Browser/Tools/JobsScreenTest.php` (or the jobs screen's existing browser file)

## The decisions, written out

**J1. The capability.** `Capability::Jobs = 'jobs'`. Granted like the others (consent recorded against the version). An update that adds it asks again.

**J2. The manifest.**

```json
"capabilities": ["jobs", "http"],
"jobs": [
  { "key": "write-article", "label": "Write an article", "long": true, "tries": 3, "backoff": [30, 120, 600] },
  { "key": "sync", "label": "Sync with the service" }
],
"schedules": [ { "job": "sync", "every": "hour" } ]
```

Checked by `ManifestValidator`: `jobs` is a list; each `key` matches `^[a-z][a-z0-9-]{0,47}$` and is unique; `label` is 1–80 characters; `long` is a boolean (default false); `tries` is 1–5 (default 3); `backoff` is a list of 1–5 integers, 1–3600 (default `[30, 120, 600]`). `schedules` is a list; each `job` names a declared job; `every` is one of `minute`, `five-minutes`, `fifteen-minutes`, `hour`, `day`, `week`. Declaring `jobs` without asking for `jobs` is an error with a plain sentence. Schema updated to match.

**J3. The contracts** (no Illuminate types; rule 5).

```php
namespace Teme\Contracts\Jobs;

/** Queues one of the calling plugin's declared jobs. Offered only when the plugin was granted `jobs`. @since 0.9.0 */
interface Jobs
{
    /** @param array<string, mixed> $payload JSON-ready data, at most 64 KB. Returns the tracked job's ID. */
    public function queue(string $key, array $payload = [], ?string $label = null): string;
}

/** A plugin's handler for one declared job. @since 0.9.0 */
interface JobHandler
{
    public function handle(JobContext $context): void;
}

/** What a running job may read and report. @since 0.9.0 */
interface JobContext
{
    /** @return array<string, mixed> */
    public function payload(): array;
    public function attempt(): int;
    /** Reports progress; throws JobStopped when the run was cancelled or the plugin disabled. */
    public function progress(int $done, int $total, string $message = ''): void;
}

/** Thrown by progress() so a handler unwinds when its run must stop. @since 0.9.0 */
final class JobStopped extends \RuntimeException {}

/** A plugin with jobs: its handlers, by declared key. @since 0.9.0 */
interface PluginHasJobs
{
    /** @return array<string, JobHandler> */
    public function jobs(): array;
}
```

The plugin's main class implements `PluginHasJobs` beside `Plugin`. A declared key with no handler, or a handler for an undeclared key, is a fault on the Plugins screen (as other contribution faults are), and that job doesn't run.

**J4. The runner.** `RunPluginJob` (a Laravel job, `ShouldQueue`) carries only scalars: plugin code, job key, tracked job ID. The payload lives on the tracked job row (its existing data column, or `payload` JSONB if none: check in Task 0). Queue: `long` jobs on the `long` queue of `database-long`; others on the default queue. `tries` and `backoff` come from the declaration; `timeout` is 120 seconds, or 1800 for `long`. `handle()`:
1. Loads the plugin record. If it isn't enabled, its code is gone, `jobs` isn't granted, or safe mode is on: `CancelJob` with "The plugin is off" and return.
2. Builds the plugin through `PluginLoader` (the same doors as at boot, with the long outbound limit for a `long` job, J6) and finds the handler. None: `FailJob` "The plugin has no handler for :key" and return (no retry).
3. Calls `handle()` inside a try. `JobStopped`: return (the run was cancelled). Any other `Throwable`: `report()`, then rethrow so Laravel retries per `tries`/`backoff`; on the last attempt `failed()` calls `FailJob` with the message's first line (at most 200 characters, no stack).
4. On success, `FinishJob`.

`PluginJobContext::progress()` calls `ReportJobProgress`, then re-reads the record: cancelled, or the plugin disabled, means `JobStopped`.

**J5. Tracking and retry.** `PluginJobKind implements JobKind`, key `plugin-job`, tagged under `JobKinds::TAG`. `StartJob` with kind `plugin-job`, owner = the plugin's code, the label (the declared label, or the one passed to `queue()`), `run_type` = `plugin-job`. `retry()` queues a fresh `RunPluginJob` for the same tracked job (as whoever asked, I-392); `cancel()` deletes nothing (the run stops at its next report). Tools › Jobs shows plugin-owned rows with the plugin's mark (`PluginMark`, as wave 2 uses).

**J6. Long outbound calls.** `PluginHttp` takes an upper limit when built: 10 seconds as today, or 300 seconds when built for a `long` job's run. The per-call `$timeoutSeconds` is clamped to that limit. The 1 MB limit and every other check stay.

**J7. Schedules.** One migration adds `schedule_runs` JSONB (default `{}`) to `plugins`: job key → the time it was last queued. `teme:plugins:run-due-schedules` runs every minute, `withoutOverlapping()->onOneServer()` (one line in `routes/console.php`). For each plugin that is enabled, granted `jobs`, code present and not in safe mode, each schedule is due when `last + interval <= now` (a missing `last` is due at once); it queues the job, then records the time in the same transaction as `StartJob`. Disabled plugins are skipped, so their schedules stop; enabling again resumes them on the next minute.

**J8. Disable.** `DisablePlugin`, after commit, cancels the plugin's `queued` and `running` tracked jobs (`CancelJob` each, as the operator). Uninstall and Remove its data leave tracked job rows to `teme:jobs:prune`.

## Tasks

- [x] **Task 0.** Read the files named above and confirm: where a tracked job keeps data (column name), the jobs screen's files and its browser test, how `PluginLoader` builds a plugin and its doors, how contribution faults reach the Plugins screen. Record differences as `B-?? (draft)` and follow the code.
- [x] **Task 1. Manifest and contracts.** Tests first (`DeclaredJobsTest`, manifest validator cases): each J2 rule refused with its sentence; a valid declaration passes; the schema accepts it; `jobs` declared without the capability is refused. Then the contracts (J3) and the enum case. Quick check.
- [x] **Task 2. The door and the runner.** Tests first (`PluginJobsTest`, with the `tests/Fixtures/plugins/jobs` fixture: one short job that writes a line to its own table or a log, one `long` job, one that throws):
  - granted: `Jobs::queue()` starts a tracked job owned by the plugin, the worker runs it, it finishes;
  - **not granted: the plugin is offered no `Jobs` service, and a hand-made `RunPluginJob` for it cancels and writes nothing** (no handler called, nothing in its table);
  - a disabled plugin's queued run is cancelled, its running run stops at the next progress report;
  - safe mode: nothing runs;
  - a throwing handler is retried per `tries` with the declared backoff, then Failed with its first line; Retry starts it again with the saved payload;
  - a payload over 64 KB, or with an object, is refused by `queue()`;
  - a key with no handler fails without retry; the fault shows on the Plugins screen;
  - outbound inside a `long` job may wait longer than 10 seconds (fake transport records the timeout passed, up to 300), outside a job it can't.
- [x] **Task 3. Schedules.** Tests first (`PluginSchedulesTest`, with `$this->travelTo()`): a due schedule queues its job once per interval, on one server; **a disabled plugin's schedule queues nothing**; enabling again resumes; a plugin not granted `jobs` queues nothing; `schedule_runs` records each run. The migration, the command, the console line.
- [x] **Task 4. Disable and enable.** Tests first: disabling cancels queued and running runs after commit; a refused disable (not allowed) cancels nothing and writes nothing.
- [x] **Task 5. Tools › Jobs.** A plugin-owned job shows the plugin's mark and name. One browser case after `npm run build`: the row is drawn with the mark; axe passes in light and dark.
- [x] **Task 6. Records and the gate.** Fill the Records; the quick check; `composer check` once.

**Quick check:** `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse` on the touched paths, `php artisan test tests/Feature/Plugins tests/Unit/Plugins tests/Architecture`.

## Records

### Commits on `plugins/jobs`

- `b8edf09` feat(plugins): declare jobs and schedules in the manifest
- `18ad20b` feat(plugins): queue and run declared plugin jobs
- `e211520` feat(plugins): queue due plugin schedules each minute
- `b7c7303` feat(plugins): cancel plugin jobs when a plugin is disabled
- `38e1175` fix(plugins): skip cancel when a plugin job already ended
- `1530b30` feat(plugins): show plugin mark on Tools › Jobs rows
- (this) docs(plans): Plugin jobs's records

### Tests seen red (then green)

- **Task 1** `DeclaredJobsTest` — `Failed asserting that two arrays are identical` (expected `[]`, got `jobs isn’t something a plugin can ask for.` before `Capability::Jobs`); later cases: missing validator sentences; `Class "App\Domain\Plugins\Jobs\DeclaredJobs" not found`.
- **Task 2** `PluginJobsTest` — `Tests\Fixtures\Plugins\Jobs\JobsPlugin asks for Teme\Contracts\Jobs\Jobs; a plugin may ask only for what Teme offers plugins.` (before `Jobs` in `PluginServices::OFFERED` / door); then runner/cancel/payload cases until `RunPluginJob` and payload column landed.
- **Task 3** `PluginSchedulesTest` — `The command "teme:plugins:run-due-schedules" does not exist.`
- **Task 4** `PluginJobsTest` "cancels waiting and running…" — expected `Cancelled`, got `Waiting` (before `DisablePlugin` after-commit cancel).
- **Task 5** `JobsScreenTest` "names the plugin beside…" — `Property [rows.0.plugin] does not exist.`

### Architecture

- Before: **139 passed** (1309 assertions).
- After: **139 passed** (1313 assertions). `QueueWindowsTest` now expects `database-long` `retry_after` **1860** (was 360) so a long plugin job's 1800s timeout fits; short `RunPluginJob` default timeout is **75** (plan said 120) so it fits the default queue's 90s window. Baselines unchanged: admin-words **876**, design-system **222**.

### Full gate (`export PATH=/opt/node24/bin:$PATH && composer check`)

- Pint: passed
- PHPStan: OK, no errors
- types / lint / theme build: clean
- Architecture: **139 passed** (1313 assertions)
- Unit+Feature (parallel): **10635 passed**, **2 skipped** (54851 assertions). First run had one flake (`LockOutTest` cut-short cache — empty `running()`; alone and with this part's suites it passes); re-run exit **0**.
- No PR opened (assignment).

### Browser

- After `npm run build`: `tests/Browser/JobsScreenTest.php` — axe light/dark and the new plugin-mark light/dark cases **passed**.

### B-?? (draft)

1. **B-01** — `tracked_jobs` had no data/payload column; added `payload` JSONB (object, default `{}`) so plugin payloads and Retry can keep them. Plan Owns named only `schedule_runs`.
2. **B-02** — Plan J4 short timeout 120 fails `QueueWindowsTest` against `database` `retry_after` 90; short jobs use **75**. Long jobs keep **1800**; `database-long` `retry_after` raised **360 → 1860** (Architecture expectation updated to match).
3. **B-03** — `teme:plugins:run-due-schedules` matches SafeMode's `teme:plugins:` prefix, so `SafeMode::active()` would always skip schedules during the command. `DueSchedules` does not check SafeMode; `RunPluginJob` still cancels under safe mode.
4. **B-04** — No separate jobs-row component existed; added `resources/js/components/jobs/job-label.tsx` (uses existing `PluginMark` from `ui/`, not edited).
5. **B-05** — `CancelJob` has no custom "The plugin is off" message; uses its existing cancelled wording. `RunPluginJob` skips `CancelJob` when the tracked row already ended (DisablePlugin may have cancelled first).

### Changelog lines for the maintainer

- Plugins may declare `jobs` / `schedules`, ask for `jobs`, queue work through `Teme\Contracts\Jobs\Jobs`, and run on core's `RunPluginJob`.
- `tracked_jobs.payload` JSONB; `plugins.schedule_runs` JSONB; `teme:plugins:run-due-schedules` every minute on one server.
- Disabling a plugin cancels its waiting and running plugin-jobs after commit.
- Tools › Jobs shows `PluginMark` beside plugin-owned rows.
- `database-long` retry window is 1860 seconds so 30-minute plugin jobs fit.

### STOP

none
