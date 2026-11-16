---
title: The site's AI connection
type: how-to
audience: plugin
summary: How a plugin asks the site's own AI connection for the tasks it declares, and what Teme records and limits for it.
rests_on: [docs/architecture/plugins.md, packages/teme-contracts/src/Ai, packages/teme-contracts/schema/teme-plugin.schema.json]
---

# The site's AI connection

A plugin never ships its own AI key. It declares the AI tasks it needs, the administrator agrees to them when enabling it, and the plugin asks the site's own connection through `SiteAi`. Teme sends each request through the model an administrator chose for that task in Settings › AI, asks the fallback once when the first model can't be reached, records the request under the plugin, and counts its cost against the site's budget and the plugin's own.

## What you need

- `ai` in the plugin's `capabilities`. The consent reads "Use your site’s AI connection (Settings › AI) for:", then the label of each task and what it sends.
- One to eight entries in `contributes.aiTasks` in `teme-plugin.json`.
- A constructor parameter typed `SiteAi`. Teme gives it only to a plugin granted `ai` that declares at least one task.

## Declaring a task

Each entry in `contributes.aiTasks` has:

- `id`: lower-case words joined by hyphens, at most 24 characters. This is what you pass to `SiteAi::ask()`.
- `label`: the task's name in the consent and on Settings › AI (1 to 40 characters).
- `description`: what it does, up to 160 characters. Optional.
- `needs`: what a model must be able to do, one or more of `text`, `images-in` and `structured-output`. Settings › AI offers only models that can do all of it.
- `sends`: what the task sends to the AI service. `brief` is only what the plugin writes itself, `published` is published text only, and `draft` is an item's unpublished text.
- `maxOutputTokens`: the most tokens one answer may hold, 1 to 64,000.

An update that adds a task, sends more than a task did, or needs more waits until an administrator agrees again. A narrower task needs no new agreement.

A task is off until an administrator chooses its model. Settings › AI shows each plugin's tasks under Plugins, with the plugin's own switch, its monthly budget and how many requests it may make in an hour (120 unless changed).

## Asking

Build an `AiAsk` and pass it with the ID of the person the work acts for:

```php
$answer = $this->ai->ask(new AiAsk('article', $instructions, ['brief' => $brief]), $personId);
$text = $answer->text;
```

- `instructions` is the system message, up to 100,000 characters.
- `inputs` are named in lower-case letters and underscores. Teme places each one inside its own element and tells the model that what is inside is material to work on, never instructions. Put anything a person or another site wrote in `inputs`, never in `instructions`.
- `schema` is a JSON schema describing an object, only for a task that needs `structured-output`. The answer then comes back in `data` instead of `text`.
- `maxOutputTokens` may not pass the task's declared maximum.
- `timeoutSeconds` is 1 to 55, or up to 600 inside a job declared `long`.
- `itemId` names the item the request is about, when there is one. It is recorded with the request.

The person must be the one the work acts for: the person who queued the job, or for a schedule the administrator who enabled the plugin. A request for anyone else, or from a run that acts for nobody, is refused.

## When it can't be asked

`ask()` throws `AiRefused` before anything is sent when the task isn't declared, the request goes past its declaration or its time, AI is off for the site or for the plugin, the task has no model, the model can't do what the task needs, the site's or the plugin's budget for the month is spent, or the plugin has reached its requests for the hour. Its `reason` is a short code and its message one plain sentence. Nothing is recorded and nothing is spent.

It also throws `AiRefused` when the AI service fails after the request was sent. That request is recorded, and any tokens the service billed for it count.

Call `standing()` with a task's id to learn before asking whether it can be asked now, why not in a sentence you can show on your own pages, and what the plugin has spent this month:

```php
$standing = $this->ai->standing('article');
```

## What Teme records

Every request that was sent is recorded in Tools › AI activity under the plugin: the person, the task, the model, the tokens, the cost and how it ended. The instructions, the inputs and the answer are never stored. The cost adds to the site's month and to the plugin's month, and Tools › AI activity can show one plugin's requests and each plugin's totals.

The budgets and the hourly limit are soft: requests already running when one is reached may pass it a little.

## Long requests

A request inside a job declared `long` may wait up to 600 seconds for its answer, and the job's own run may last 1,800 seconds. Outside a long job, a request longer than 55 seconds is refused. Teme returns the whole answer at once; nothing is streamed to the plugin.
