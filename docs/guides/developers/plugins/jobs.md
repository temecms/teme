---
title: Jobs and schedules
type: how-to
audience: plugin
summary: How a plugin declares background jobs and schedules in its manifest, queues them, writes their handlers, and what happens when one fails.
rests_on: [app/Domain/Plugins/Jobs, packages/teme-contracts/src/Jobs, packages/teme-contracts/schema/teme-plugin.schema.json, routes/console.php]
---

# Jobs and schedules

Work that takes longer than a request, or that should happen on its own every hour, runs as a job. A plugin declares its jobs in `teme-plugin.json`, gives Teme a handler for each, and queues them through the `Jobs` door. Every run is tracked: people see it in **Tools › Jobs**, with its progress, and can cancel it there.

## Declare the jobs

Ask for the `jobs` capability, and list each job under `jobs` in the manifest:

- `key`, the name you queue it by: lower-case letters, digits and hyphens, starting with a letter, at most 48 characters;
- `label`, the name people see for its runs, up to 80 characters;
- `long`, true for work that may take up to 30 minutes; a run is otherwise stopped after 75 seconds;
- `tries`, how many times a run is tried before it is marked failed, 1 to 5, 3 by default;
- `backoff`, the seconds to wait before each new try, 1 to 3,600 each, 30, 120 and 600 by default.

A long job runs on the long queue, and each outbound call it makes may take up to 5 minutes instead of 10 seconds.

## Give Teme the handlers

Implement `PluginHasJobs` beside `Plugin`. Its `jobs()` answers a `JobHandler` for each declared key; a run whose key has no handler fails at once. Teme builds your plugin with its doors for every run, so a handler can use what your constructor was given: build the handlers there, passing them the doors they need.

A handler's `handle()` receives a `JobContext`: `payload()` is what the job was queued with, `attempt()` counts the tries from 1, and `progress($done, $total, $message)` shows how far it is in **Tools › Jobs**. Call `progress()` as you go. When the run was cancelled, or your plugin was turned off while it ran, `progress()` throws `JobStopped`; let it unwind, and the run ends quietly.

## Queue a job

Ask for `Jobs` in your constructor and call `queue('your-key', $payload)`. It answers the run's ID. The payload must be JSON-ready data with string keys, at most 64 KB once encoded.

A job runs as someone. A job queued while a person is signed in runs as that person, and one queued from a Teme console command runs as the operator. With neither, `queue()` refuses: a listener for a public visitor's request, or a public route's handler, can't queue one.

The job is sent to the queue only once the change around it commits, so a run never starts for work that was rolled back. On a site whose queue is `sync`, as on a small site and in tests, the job runs at once, inside the `queue()` call.

## Schedules

To run a job on its own, list it under `schedules` with the key of a job you declared as `job` and how often as `every`: `minute`, `five-minutes`, `fifteen-minutes`, `hour`, `day` or `week`. The first run is due as soon as the plugin is enabled. After that, each is counted from the last queued run, which Teme records on the plugin's row, so a missed minute never queues two.

Teme's own `teme:plugins:run-due-schedules` queues the due ones. Laravel's scheduler runs it every minute, so the site's scheduler must run, as it does for Teme's own work.

A scheduled run acts as the administrator who enabled the plugin, with that person's permissions. A plugin enabled from the console with `teme:plugins:enable` records no person, and its schedules are then skipped without a word; enable it from the admin to have them run. The maintainers track this as [issue 334](https://github.com/temecms/teme/issues/334).

## When a job fails

When a handler throws, Teme reports the exception to the site's log with your plugin's name, and the run is tried again after its backoff, until its tries are used. The last failure marks the run failed in **Tools › Jobs**, with the first line of the exception's message, cut at 200 characters, so write that line for the person who reads it.

On a `sync` queue the exception also reaches the code that called `queue()`, after the run is marked failed.

Teme cancels a run that hasn't started when the plugin is disabled, has lost the `jobs` capability, or the site is in safe mode, and disabling a plugin cancels its waiting and running jobs.

## Test them

The testing kit runs a job on the test's `sync` queue with `runJob()`, and one pass of the schedules with `runSchedules()`: see [Testing a plugin or theme](../testing.md).

Next: [Public routes](public-routes.md), and the generated [Plugin manifest reference](../../../reference/plugin-manifest.md) for every key.
