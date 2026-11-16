---
title: Jobs and schedules
type: how-to
audience: plugin
summary: Who a plugin's background job acts for, and every limit a job's run works within.
rests_on: [packages/teme-contracts/src/Jobs, app/Domain/Plugins/Jobs, app/Domain/Plugins/Doors/PluginHttp.php, docs/architecture/plugins.md]
---

# Jobs and schedules

A plugin granted `jobs` declares its jobs and schedules in `teme-plugin.json`, implements `PluginHasJobs` beside `Plugin`, and queues a job through `Jobs`. Core runs each one, tracks its progress, and retries it as declared.

## The person a run acts for

`JobContext` gives a handler its payload, its attempt and `progress()`, and with `personId()` the account ID the run acts for: the person who queued it, or for a scheduled run the administrator who enabled the plugin. Pass it to every door that acts for a person, such as `ContentWriter` and `ContentReader`. Don't keep a "write as" setting of your own.

A run the operator queued from the command line acts for nobody: `personId()` throws `JobStopped`, and the handler stops.

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

A run that needs longer than its limit is cut short and retried, so cut big work into small runs. Do one step in each run and queue the next step from it. Make each step safe to run twice, because a retry repeats it: check what is already done before doing it again. Never wait inside a run to space the steps out: let a schedule pick up the next step instead.
