---
title: Jobs and schedules
type: how-to
audience: plugin
summary: How a plugin declares background jobs and schedules in its manifest, queues them, after a delay or once while one waits, writes their handlers, who a run acts for, schedules its own settings switch and time, every limit a run works within, and what happens when one fails.
rests_on: [app/Domain/Plugins/Jobs, packages/teme-contracts/src/Jobs, packages/teme-contracts/schema/teme-plugin.schema.json, routes/console.php, app/Domain/Plugins/Doors/PluginHttp.php, docs/architecture/plugins.md]
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

## The person a run acts for

`JobContext::personId()` gives the account ID the run acts for: the person who queued it, or for a scheduled run the administrator who enabled the plugin. Pass it to every door that acts for a person, such as `ContentWriter` and `ContentReader`. Don't keep a "write as" setting of your own.

A run the operator queued from the command line acts for nobody: `personId()` throws, and the run fails at once, without another try, with "This run has no person to act for."

## Queue a job

Ask for `Jobs` in your constructor and call `queue('your-key', $payload)`. It answers the run's ID. The payload must be JSON-ready data with string keys, at most 64 KB once encoded.

A job runs as someone. A job queued while a person is signed in runs as that person, and one queued from a Teme console command runs as the operator. With neither, `queue()` refuses: a listener for a public visitor's request, or a public route's handler, can't queue one.

The job is sent to the queue only once the change around it commits, so a run never starts for work that was rolled back. On a site whose queue is `sync`, as on a small site and in tests, the job runs at once, inside the `queue()` call.

## After a delay, or once while one waits

Pass `QueueOptions` as the last argument:

- `new QueueOptions(delaySeconds: 30)` starts the run no sooner than 30 seconds from now, up to an hour.
- `new QueueOptions(uniqueKey: 'home')` queues the job once while a run with that key still waits. A second call answers the waiting run's ID and queues nothing; the first payload stands. Once that run has started, the next call queues a new one.

Together they debounce: `new QueueOptions(delaySeconds: 60, uniqueKey: 'sitemap')` on every save sends one ping for a burst of saves. A key is up to 64 lower-case letters, digits and `:._-`, starting with a letter or digit; a delay or key outside those throws an `\InvalidArgumentException`.

## Schedules

To run a job on its own, list it under `schedules` with the key of a job you declared as `job` and how often as `every`: `minute`, `five-minutes`, `fifteen-minutes`, `hour`, `day` or `week`. The first run is due as soon as the plugin is enabled. After that, each is counted from the last queued run, which Teme records on the plugin's row, so a missed minute never queues two.

Teme's own `teme:plugins:run-due-schedules` queues the due ones. Laravel's scheduler runs it every minute, so the site's scheduler must run, as it does for Teme's own work.

Scheduled work runs as the administrator who enabled the plugin, with that person's normal permissions. When that person can't, the schedules don't run, and the Plugins screen and `teme:plugins` say why on the plugin's row: it was enabled from the command line without naming an administrator, the administrator no longer has an account, or they can no longer manage plugins. Disabling the plugin and enabling it again fixes it.

From the command line, a plugin with schedules is enabled only with the administrator named:

```bash
php artisan teme:plugins:enable your/plugin --agree --as=admin@example.com
```

Without `--as`, or with an email no one who may manage plugins has, nothing is enabled.

## Schedules your settings switch and time

Two optional entries on a schedule hand the choice to the site's administrator, through the plugin's own settings (see [Plugin settings and secrets](settings.md)):

- `enabledBy` names one of the plugin's `boolean` settings. The schedule runs only while it is on, so with no `default` of `true` it starts off.
- `everyFrom` names one of the plugin's `select` settings whose options are all intervals from the list above. The schedule runs as often as the chosen option says, and as often as `every` says if the setting can't be read.

Teme reads these settings each minute, when it checks what is due, so a change counts at the next minute's check. Turning a schedule on runs it at that check when its interval has already passed since its last run. A schedule that names a setting the plugin doesn't declare, or one of the wrong kind, stops the plugin from being enabled.

## Limits

| What | Ordinary | Inside a job declared `long` |
|---|---|---|
| A job's run | 75 seconds | 1,800 seconds |
| An outbound call through `OutboundHttp` | 10 seconds | 300 seconds |
| An outbound answer | 1 MB | 16 MB |
| An outbound request's body | 1 MB | 1 MB |
| A job's payload | 64 KB | 64 KB |
| An AI request | 55 seconds | 600 seconds |
| A schedule's smallest interval | one minute | one minute |

An answer past its limit stops as it arrives and throws `AnswerTooLarge`; nothing past the limit is read.

## Cutting work into runs

A run that needs longer than its limit is cut short and retried, so cut big work into small runs. Do one step in each run and queue the next step from it. Make each step safe to run twice, because a retry repeats it: check what is already done before doing it again. Never wait inside a run to space the steps out: queue the next step with `new QueueOptions(delaySeconds: …)` instead, or let a schedule pick it up.

## When a job fails

When a handler throws, Teme reports the exception to the site's log with your plugin's name, and the run is tried again after its backoff, until its tries are used. The last failure marks the run failed in **Tools › Jobs**, with the first line of the exception's message, cut at 200 characters, so write that line for the person who reads it.

On a `sync` queue the exception also reaches the code that called `queue()`, after the run is marked failed.

Teme cancels a run that hasn't started when the plugin is disabled, has lost the `jobs` capability, or the site is in safe mode, and disabling a plugin cancels its waiting and running jobs.

## Test them

The testing kit runs a job on the test's `sync` queue with `runJob()`, and one pass of the schedules with `runSchedules()`: see [Testing a plugin or theme](../testing.md).

Next: [Plugin settings and secrets](settings.md), where actions queue your jobs from a button on your settings page; [Public routes](public-routes.md); and the generated [Plugin manifest reference](../../../reference/plugin-manifest.md) for every key.
