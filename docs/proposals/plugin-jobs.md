# Plugin jobs and schedules (v1, wave 1, part B)

**Status:** for building. **Plan:** `docs/plans/plugin-jobs.md`. **Branch:** `plugins/jobs`.

## Why

A plugin that writes articles with AI, syncs with an outside service or rebuilds a link index does its work in the background, often on a schedule, and some steps wait minutes on an outside service. Today a plugin can only answer hooks and make 10-second outbound calls while a request runs. `docs/architecture/plugins.md` › Background work already decided the shape: jobs and schedules are declared in the manifest, run on Laravel's queue and scheduler, are owned by their plugin, stop when it is disabled, and go when it is uninstalled. This part builds it.

## Settled (binding)

1. **Declared, then granted.** A plugin lists its jobs and schedules in its manifest, and asks for the new `jobs` capability. Without the grant, nothing it declared runs and it gets no way to queue work. Asking for `jobs` in an update widens access, so consent is asked again, as for any capability.
2. **One core runner.** Every plugin job runs through one core queued job, which starts the plugin's handler, tracks it in Tools › Jobs (owner = the plugin's code, shown with its plugin mark), reports progress, and records success or failure. A plugin never defines Laravel job classes of its own.
3. **Data in, never objects.** A job's payload is JSON-ready data (strings, numbers, booleans, lists and maps of them), at most 64 KB. No PHP objects cross the queue from a plugin.
4. **Retries and backoff are declared** per job (tries 1–5, backoff in seconds), with sensible defaults (3 tries; 30, 120, 600 seconds). After the last try the job is Failed, and Retry in Tools › Jobs starts it again with its saved payload.
5. **Long work is allowed, in a job only.** A job may be marked `long`: it runs on the long queue, may take up to 30 minutes, and its outbound calls may wait up to 300 seconds each (an AI writer's image call takes about 180). Outside a job, the outbound door keeps its 10-second limit.
6. **Schedules** name one of the plugin's jobs and how often: every minute, five minutes, fifteen minutes, hour, day or week. Core's scheduler checks them every minute, on one server, and queues what is due.
7. **Disabled means stopped.** Disabling a plugin, its code going missing, or safe mode: no new runs start, its schedules stop, queued runs are cancelled, and a running one stops at its next progress report. Enabling again resumes the schedules; cancelled runs stay cancelled.
8. **Jobs change content only through Operations** (rule 1). Writing content is part A's socket; this part only runs the work.
9. **A failing handler never breaks the worker.** Its exception is reported, the run fails with the first line of the message, and other jobs carry on.

## Provisional (built as written unless the maintainers say otherwise)

- P1. The longest run: 30 minutes for `long` jobs, 2 minutes for others.
- P2. The schedule choices listed in Settled 6 (no free-form cron yet).
- P3. Outbound calls inside a `long` job: up to 300 seconds each; the 1 MB answer limit stays. Streaming answers come later.

## Out (later, each with its trigger)

- Free-form cron expressions (trigger: a plugin needs a time the list can't express).
- Streaming outbound answers (trigger: a plugin needs to show text as it arrives).
- Job chains and batches across plugins (trigger: a real plugin needs one).
- A plugin's own queue or worker (never: one runner, so the host stays in control).
