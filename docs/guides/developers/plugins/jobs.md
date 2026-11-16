---
title: Jobs and schedules
type: how-to
audience: plugin
summary: How a plugin declares background jobs and schedules, queues a job after a delay or once while one waits, and who its scheduled work runs as.
rests_on: [packages/teme-contracts/src/Jobs, app/Domain/Plugins/Jobs, packages/teme-contracts/schema/teme-plugin.schema.json, docs/architecture/plugins.md]
---

# Jobs and schedules

Work that takes more than a moment, or that must happen on its own every hour or every day, runs as a job: away from the request, tracked, retried when it fails, and listed in **Tools › Jobs** for the people who look after the site.

## What you need

- `jobs` in the plugin's `capabilities`.
- Each job declared under `jobs` in `teme-plugin.json`, with a `key` and a `label`. [The manifest](../concepts/manifest.md#jobs-and-schedules) lists what else an entry may give: `long`, `tries` and `backoff`.
- A plugin class that implements `PluginHasJobs` beside `Plugin`, answering a `JobHandler` for each declared key. A key with no handler, or a handler for a key you don't declare, stops the plugin from starting.

## Queue a job

Ask for `Jobs` in your constructor, and queue a declared job by its key: `$this->jobs->queue('ping', ['url' => $url])`. It answers the tracked job's ID. The payload is JSON-ready data of at most 64 KB; the label people see is the declared one unless you pass another.

The job runs as the person whose work queued it, with that person's own permissions, so a job queued while an editor saves a post can do only what that editor can.

Your handler's `handle()` gets a `JobContext`: the payload, the attempt number starting at 1, and `progress()` to report how far it has got. When the run is cancelled or the plugin disabled, `progress()` throws `JobStopped`; let it unwind.

## After a delay, or once while one waits

Pass `QueueOptions` as the last argument:

- `new QueueOptions(delaySeconds: 30)` starts the run no sooner than 30 seconds from now, up to an hour.
- `new QueueOptions(uniqueKey: 'home')` queues the job once while a run with that key still waits. A second call answers the waiting run's ID and queues nothing; the first payload stands. Once that run has started, the next call queues a new one.

Together they debounce: `new QueueOptions(delaySeconds: 60, uniqueKey: 'sitemap')` on every save sends one ping for a burst of saves. A key is up to 64 lower-case letters, digits and `:._-`, starting with a letter or digit; a delay or key outside those throws an `\InvalidArgumentException`.

## Schedules

List how often a declared job runs under `schedules`: `{"job": "ping", "every": "hour"}`. The choices are `minute`, `five-minutes`, `fifteen-minutes`, `hour`, `day` and `week`. Teme queues the job when it falls due, with an empty payload.

Scheduled work runs as the administrator who enabled the plugin, with that person's normal permissions. When that person can't, the schedules don't run, and the Plugins screen and `teme:plugins` say why on the plugin's row: it was enabled from the command line without naming an administrator, the administrator no longer has an account, or they can no longer manage plugins. Disabling the plugin and enabling it again fixes it.

From the command line, a plugin with schedules is enabled only with the administrator named:

```bash
php artisan teme:plugins:enable your/plugin --agree --as=admin@example.com
```

Without `--as`, or with an email no one who may manage plugins has, nothing is enabled.

Next: [Plugin settings and secrets](settings.md), where actions queue your jobs from a button on your settings page.
