---
title: Reading content
type: how-to
audience: plugin
summary: How a plugin with content.read lists the site's published items a page at a time, and reads an item's draft and the queue of unpublished items for the person its work acts for.
rests_on: [packages/teme-contracts/src/Content, app/Domain/Plugins/Doors/PluginContentReader.php, app/Domain/Contents/Queries/PublishedItems.php, app/Domain/Contents/Queries/QueuedItems.php, docs/architecture/plugins.md]
lands_with: sockets3/content-doors
---

# Reading content

A plugin granted `content.read` hears the content hooks, and can ask for `ContentReader` in its constructor. The reader does three things: it lists what any visitor can open, it reads one item's draft for the person the plugin's work acts for, and it lists the unpublished items that person may edit. It never hands a plugin a model, a query or a table.

## List published items

`published()` takes an `ItemQuery` and answers an `ItemPage`: up to 200 items, newest first, each an `ItemRef` with its ID, type, title, status, address and language. The address is absolute and made when the page is read, so it follows the site to a new domain.

Only what a visitor can open is listed: published, not in the trash, public (not private and not behind a password), of a type with public addresses, in a language readers are shown. Drafts never appear here.

`ItemQuery` filters, each optional:

- `types`: content-type keys, at most 10; none for every public type.
- `language`: one of the site's language tags, such as `fr`; none for every shown language.
- `terms`: term slugs by taxonomy, at most 10 in all. An item matches when it is filed under any of the slugs named for each taxonomy.
- `publishedSince`: only items published at or after that moment.
- `limit`: 1 to 200, 100 when not given.

To read on, pass the page's `next` as the next query's `after`, with the same filters, until `next` is null. The cursor is opaque: build nothing from it, and keep it only as long as the walk. A cursor the reader didn't give, a language the site doesn't have, or a value outside these bounds throws PHP's InvalidArgumentException.

## Read a draft

`draft()` takes an item's ID and the person's account ID, and answers an `ItemSnapshot` of the item's working revision, as the editor holds it: the body (its Tiptap document), the fields by handle, the terms as slugs by taxonomy, the slug, the accounts it credits in order (none when it credits its owner), and its other language versions as `ItemRef` values. The `ItemRef` carries the item's status (draft, review, scheduled or published), and `scheduledFor` says when it is scheduled to publish, in UTC, or is null.

The person must be the one the work acts for: the signed-in person of the request, or inside a job the person `JobContext` names with `personId()` (see [Jobs and schedules](jobs.md)). They must also be allowed to edit the item. Otherwise the reader throws `ReadRefused` with one plain sentence, and nothing is read. A run of the operator's has no person, so it can't read a draft.

## List the queue

`queue()` takes a `QueueQuery` and the person's account ID, and answers a `QueuePage`: the unpublished items that person may edit, oldest first, each a `QueuedItem` with its `ItemRef` and `scheduledFor`. It lists what was waiting before the plugin was enabled as well, so a plugin that keeps its own queue can start from the site's.

`QueueQuery` takes the statuses to list, any of `draft`, `review` and `scheduled` (all three when not given), content-type keys (at most 10; none for every type), and a `limit` of 1 to 200. An editor who may edit anyone's items sees them all; an author sees their own. Items in the trash are never listed. Read on with `next` and `after` as for published items. The same person rule applies: another person or the operator gets `ReadRefused`, and an unknown status, a cursor the reader didn't give or a value outside the bounds throws InvalidArgumentException.

`ContentSaved` stays small: it tells which item was saved and by whom. A listener that needs the words reads the draft with the hook's `actorId`, which is the signed-in person of that request.

Next: [Plugins writing content](content-write.md), to write back what the plugin made.
