---
title: Plugins writing content
type: how-to
audience: plugin
summary: How a plugin with content.write creates and changes items through Operations, with their slugs, credits and language versions, always for a named person.
rests_on: [packages/teme-contracts/src/Content, app/Domain/Plugins/Doors/PluginContentWriter.php, docs/architecture/plugins.md, docs/architecture/operations.md]
---

# Plugins writing content

A plugin granted `content.write` asks for `ContentWriter` in its constructor. Every write goes through the Operation an editor's own change would, checked with the named person's permissions: the plugin can do nothing that person couldn't. A refusal throws `WriteRefused` with one plain sentence, and nothing at all was written.

The person is always the one the work acts for: the signed-in person of the request, or inside a job the person `JobContext` names with `personId()`. Never the operator, and never anyone else.

## Create and change

`create()` takes a `NewItem` (type, title, Tiptap body, fields by handle, terms by taxonomy, a featured image, a language) and answers the new draft's `ItemRef`. `change()` takes an `ItemChanges`, whose null parts are left as they are, and saves a new revision of the draft. `publish()` and `schedule()` publish the latest revision now or later.

## Slug, credits and translations

Three more parts, each set inside the same transaction as the create or the change, so a refusal of any one writes nothing:

- `slug`, on `NewItem` and `ItemChanges`: the last part of the item's address, lower-case letters and digits joined by single hyphens, at most 200 characters. Another item of the type and language already using it is refused. Left out, a new item's slug is made from its title.
- `credits`, on `NewItem` and `ItemChanges`: account IDs to credit, in order, at most 20. On a new item, none credits the acting person; on a change, null leaves them and an empty list returns the credit to the item's owner. Crediting anyone but the acting person needs that person's own right to edit everyone's items of the type.
- `translationOf`, on `NewItem`: the ID of an existing item in another language. The new item joins its language versions. Refused when the two share a language, are of different types, or the other is in the trash.

To link items that already exist, call `linkTranslations()` with their IDs, between 2 and the site's number of languages, and the person's ID. Every version each already has joins one group, under the same refusals.

Next: [Reading content](content-read.md), and [Jobs and schedules](jobs.md) for work that runs in the background.
