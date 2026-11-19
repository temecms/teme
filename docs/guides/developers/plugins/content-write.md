---
title: Plugins writing content
type: how-to
audience: plugin
summary: How a plugin granted content.write creates, changes, publishes and schedules items, with their slugs, credits and language versions, always on behalf of a named person.
rests_on: [packages/teme-contracts/src/Content, app/Domain/Plugins/Doors/PluginContentWriter.php, app/Domain/Import/Convert/HtmlDocuments.php, app/Domain/Plugins/Doors/ActingPerson.php, docs/architecture/plugins.md, docs/architecture/operations.md]
---

# Plugins writing content

A plugin that brings content in, such as posts from another service or drafts a person asked it to write, does so through the `ContentWriter` door. Every write goes through the same Operations as the editor's, on behalf of a named person, with that person's permissions: a plugin can never do more than the person it writes for.

## Ask for the door

Ask for the `content.write` capability in `teme-plugin.json`, and for `ContentWriter` in your plugin's constructor. Without that capability Teme doesn't offer the door, and a plugin whose constructor asks for it doesn't start.

## Who a plugin writes for

Each method takes the acting person's ID last. It must be the person behind the work in hand: the person signed in for the request, or, inside a job, the person the job runs as, which for a scheduled job is the administrator who enabled the plugin. Any other ID is refused, and so is a write with no person behind it, such as one for a public visitor or one the operator started from the console. Read the person's ID from what started the work, such as a hook's actor, never from a setting.

The person's own permissions then apply as in the editor: an author can publish only their own items, a contributor can't publish at all, and so on.

## What it can do

- `create(new NewItem(...), $personId)` makes a draft of a type, with a title, a body, fields, terms, a featured image and a language, and answers its `ItemRef`. The body is a Tiptap document, the same JSON the editor stores; fields are keyed by their handle; terms are listed by taxonomy, as slugs or names.
- `change($item, new ItemChanges(...), $personId)` saves a new revision of the item's draft; each part left null stays as it is.
- `publish($item, $personId)` publishes the item's latest revision now.
- `schedule($item, $moment, $personId)` schedules it to publish at that moment.

Each answers the item as hooks carry it. Each write is recorded in the item's history, announced to hooks after it commits, and clears the cached pages that show it, exactly as an editor's would.

## Slug, credits and translations

Three more parts, each set inside the same transaction as the create or the change, so a refusal of any one writes nothing:

- `slug`, on `NewItem` and `ItemChanges`: the last part of the item's address, lower-case letters and digits joined by single hyphens, at most 200 characters. Another item of the type and language already using it is refused. Left out, a new item's slug is made from its title.
- `credits`, on `NewItem` and `ItemChanges`: account IDs to credit, in order, at most 20. On a new item, none credits the acting person; on a change, null leaves them and an empty list returns the credit to the item's owner. Crediting anyone but the acting person needs that person's own right to edit everyone's items of the type.
- `translationOf`, on `NewItem`: the ID of an existing item in another language. The new item joins its language versions. Refused when the two share a language, are of different types, or the other is in the trash.

To link items that already exist, call `linkTranslations()` with their IDs, between 2 and the site's number of languages, and the person's ID. Every version each already has joins one group, under the same refusals.

## When a write is refused

Every refusal is a `WriteRefused` exception, with a message that says why: no person, the wrong person, a permission the person lacks, an item that doesn't exist, a field the type doesn't have, or a value that fails the type's checks. Nothing is written when one is thrown. Catch it where you can tell the person, and let it fail a job otherwise, so the run is marked failed with that message.

## Bodies written as HTML

A body is a Tiptap document, never HTML. When what you bring in is HTML, such as a page from another service or a README turned from Markdown into HTML, ask for `Teme\Contracts\Content\HtmlToDocument` in your constructor. Every plugin is given it, with no capability, because it reads and writes nothing. `convert($html)` answers the document Teme's own importer makes of that HTML: headings, paragraphs, lists, quotes, code, rules, tables and images as the editor holds them, with script, style and unknown markup left out. Links and images stay at the addresses given; hand the document to `create()` or `change()` as the body.

```php
$body = $this->html->convert($html);
$this->content->create(new NewItem(type: 'post', title: $title, body: $body), $personId);
```

## Test it

With the testing kit, write from a job and run it with `runJob()`: the job runs as the administrator who enabled the plugin, so its writes act for them, and `fetch()` shows what the site then answers. See [Testing a plugin or theme](../testing.md).

Next: [Reading content](content-read.md), [Jobs and schedules](jobs.md) for work that runs in the background, [The plugin import socket](import.md), and the generated [contracts reference](../../../reference/contracts.md) for every method.
