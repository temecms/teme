---
title: Import readers
type: how-to
audience: plugin
summary: How a plugin teaches Teme's import engine to read another system's export file, so that the engine's plan, runs, map, report and redirects serve that system as they serve WordPress.
rests_on: [app/Domain/Plugins/Readers/ImportReaderDeclaration.php, app/Domain/Plugins/Contributions/ContributionsValidator.php, packages/teme-contracts/src/Import, packages/teme-contracts/schema/teme-plugin.schema.json, packages/teme-testing/src/FakeImportReader.php]
---

# Import readers

Teme's import engine is opened at one end: the end that reads the old site. A plugin can teach it another system, such as a blog platform that exports a file. Everything after reading stays Teme's: the plan, each choice on it, runs, the map of what is already imported, the conversion of content, authors, media, redirects and the report.

A reader supplies only what differs per system. It reads an export file, says what the file holds, and hands over each item in Teme's neutral shape. It writes nothing. Teme's own operations do every write, as the person who started the import.

> **Status.** A reader's file can be uploaded and read. The upload accepts the reader's file types, the reader reads the file, and its items become the rows an import reads, so a plan and a run work on it as they do on a WordPress file. Two limits for now: the whole file is read in one job, so a very large file is not yet supported, and menus come across unplaced, since the import plan chooses where each menu sits The import screen has no picker for a reader's file yet; the upload is reached through the import's upload operation.

## Declaring a reader

The plugin asks for `import.read` and declares its readers under `importReaders`. Each reader has an `id`, a `label` and the file extensions it reads:

```json
"importReaders": [{ "id": "fake-export", "label": "Fake export", "fileTypes": ["json"] }]
```

The `id` is a lower-case word with hyphens, up to 40 characters, and is unique within the plugin. The `label` is up to 40 characters and is shown on the import screen. A plugin declares up to five readers, and each reader up to five file types, written without the dot. When the plugin is turned on, the person agrees to it reading export files, as `import.read` is shown at consent.

The plugin's class implements `Teme\Contracts\Import\HasImportReader` and returns the reader for each ID:

```php
public function importReader(string $readerId): ImportReader
```

A plugin that declares readers and doesn't implement that interface is refused when it starts, and the reverse.

## The reader

`Teme\Contracts\Import\ImportReader` has three methods. Every path is a read-only copy of the uploaded file.

- `probe(path)` says what the file is: its system, its version, its site's address, its languages, and how many items of each kind it holds. It returns an `ImportProbe`.
- `page(path, cursor, limit)` lists items a page at a time. A `null` cursor starts the list. The reader returns at most `limit` items in an order that stays the same between calls, and a `nextCursor` for the next page, or `null` at the end. Core passes cursors back and reads nothing in them.
- `fetch(path, id)` returns one item by its source ID.

A reader that can't read a file throws `ImportReaderRefused::because(...)` with a sentence the person sees in plain words, such as "This file isn’t an export from the system."

## Items

Each item is an `ImportItem`, in Teme's words. Its `kind` is one of the kinds in `ImportKind`: `post`, `page`, `term`, `person`, `media`, `comment` or `menu`.

- `id` is the item's ID in the file, as a string, unique within its kind.
- `status` is one of Teme's statuses: `published`, `draft`, `private`, `scheduled` or `trashed`.
- `publishedAt` and `modifiedAt` are UTC dates as `Y-m-d H:i:s`, or `null`.
- `oldAddress` is the item's address on the old site, so its links and redirects can be kept.
- `content` is the item's content in the source's own markup, and `format` says which markup it is, `html` or `plain`.
- `parentId`, `authorId` and `termIds` name other items by their source IDs.
- `fields` holds what a kind needs beyond these. For a `person` they are `login`, `email` and `displayName`. For a `term` they are `taxonomy`, `name` and `description`. For `media` they are `url`, `mime` and `alt`.

A reader may add further values to `fields` for what its system holds beyond these.

## Comments and menus

Two kinds have fields of their own, new in the next release:

- A `comment` names the post it belongs to with `postId`. Its `status` may be `spam`, which stays spam; `draft` is a held comment, and `published` an approved one. Its author's name, email, URL and IP go in `fields` as `authorName`, `authorEmail`, `authorUrl` and `authorIp`. A reply names its parent comment with `parentId`.
- A `menu` names its title in `title` and its slug in `fields.slug`. Menus come across unplaced: the import plan chooses where each menu sits. Each `menu-item` names its menu with `menuId`, its `itemType` (`post_type`, `taxonomy` or `custom`), the post or term it points at with `objectId`, its `order`, its parent item with `parentId`, and for a custom link its `fields.url`.

## What a reader's items become

Core keeps a reader's people and terms with negative IDs and its posts, pages and pictures with positive ones, in the order the file lists them. Teme's statuses are kept as Teme's words in the reader and translated to the words the import uses inside core. A reader's own markup is converted by core, as WordPress content is; plain text is turned into paragraphs.

## Trying a reader

The testing kit's `Teme\Testing\FakeImportReader` reads a JSON file of `system`, `version`, `site`, `languages` and `items`, where each item names its `kind` and `id`. It is the model to copy: a reader is a class that turns a file into items, and the tests check the items it returns.

## What a reader must not do

A reader only reads. It doesn't write to the site or to the file it was given, and it doesn't fetch anything from the network to answer a call. Core does every write, as the person who started the import.
