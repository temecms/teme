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

> **Status.** A reader's file can be uploaded from Tools > Import, where a picker lists the readers the plugins declare, and read. The upload accepts the reader's file types, the reader reads the file a page at a time, and its items become the rows an import reads, so a plan and a run work on it as they do on a WordPress file. Limits for now:
>
> - The file is read once, a page at a time under a 45-second deadline per part, through the same `keep()` path a WordPress export uses. A reader must page: it must not hold the whole file in memory. Later-page parents and other references are filled from stored source IDs after the last page, also under the deadline — the reader is not opened again. The import upload size cap applies; there is no separate reader size cap. A reader that repeats a cursor is refused.
> - Menus come across unplaced, since the import plan chooses where each menu sits. Menus stay in the site's default language.
> - What the file names but doesn't hold is named, not guessed: orphan comments and menu items, terms and featured pictures the file lacks, and menu items with nothing to point at. The plan lists each kind with a count and the first few IDs before the run, and the run's report lists them as warnings.
> - An item may carry a `language` (BCP 47 tag) and an opaque `translationGroup`. Language on comments, media and people is ignored. The engine adds languages the site lacks (each named with its label and `/prefix/`), creates each post, page and term in its language, and links items that share a translation group. A language Teme doesn't offer yet lands in the site's default language with a warning. A file may name its default language; when it doesn't, the site's default is kept.

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

`Teme\Contracts\Import\ImportReader` has three methods. Every path is the uploaded file's path. The reader only reads that file and must not write to it, or to anything on the network.

- `probe(path)` says what the file is: its system, its version, its site's address, its languages, and how many items of each kind it holds. It returns an `ImportProbe`.
- `page(path, cursor, limit)` lists items a page at a time. A `null` cursor starts the list. The reader returns at most `limit` items in an order that stays the same between calls, and a `nextCursor` for the next page, or `null` at the end. Core passes cursors back and reads nothing in them.
- `fetch(path, id)` returns one item by its source ID.

A reader that can't read a file throws `ImportReaderRefused::because(...)` with a sentence the person sees in plain words, such as "This file isn’t an export from the system."

## Items

Each item is an `ImportItem`, in Teme's words. Its `kind` is one of the kinds in `ImportKind`: `post`, `page`, `term`, `person`, `media`, `comment`, `menu` or `menu-item`.

- `id` is the item's ID in the file, as a string, unique within its kind.
- `status` is one of Teme's statuses: `published`, `draft`, `private`, `scheduled` or `trashed`.
- `publishedAt` and `modifiedAt` are UTC dates as `Y-m-d H:i:s`, or `null`.
- `oldAddress` is the item's address on the old site, so its links and redirects can be kept.
- `content` is the item's content in the source's own markup, and `format` says which markup it is, `html` or `plain`.
- `parentId`, `authorId` and `termIds` name other items by their source IDs.
- `excerpt` is a post's or page's summary, and `sticky` pins a post to the front page.
- `featuredMediaId` names the media item a post or page uses as its picture.
- `order` is an item's position among its siblings, such as a menu item's place in its menu.
- `language` is an optional BCP 47 tag for the item's language.
- `translationGroup` is an optional opaque word shared by every translation of the item in the file.
- `fields` holds what a kind needs beyond these. For a `person` they are `login`, `email` and `displayName`. For a `term` they are `taxonomy`, `name` and `description`. For `media` they are `url`, `mime`, `alt`, `caption` and `description`.

A reader may add further values to `fields` for what its system holds beyond these. The file may name its default language; the testing kit's fake export uses `defaultLanguage` beside `languages`.

## Comments and menus

Two kinds have fields of their own:

- A `comment` names the post it belongs to with `postId`. Its `status` may be `spam`, which stays spam; `draft` is a held comment, and `published` an approved one. Its author's name, email, URL and IP go in `fields` as `authorName`, `authorEmail`, `authorUrl` and `authorIp`. A reply names its parent comment with `parentId`. A comment whose post is not in the file is not imported, and the report names how many there were.
- A `menu` names its title in `title` and its slug in `fields.slug`. Menus come across unplaced: the import plan chooses where each menu sits. Each `menu-item` names its menu with `menuId`, its `itemType` and the post, page or term it points at with `objectId`, its `order`, its parent item with `parentId`, and for a plain link its `fields.url`. The `itemType` is one of the words in `Teme\Contracts\Import\ImportMenuItemType`: `content` for a post or page, `term` for a term, and `link` for a plain link. A menu item that points at a post, page or term the file doesn't have is still imported, but with nothing to point at, and the report names how many there were.

A term's `fields.taxonomy` is one of the words in `Teme\Contracts\Import\ImportTaxonomy`: `category` or `tag`, or the key of a plugin's taxonomy that Teme has registered. Any other word is refused, so a reader cannot write into a taxonomy Teme keeps for itself.

## What a reader's items become

Core gives each of a reader's items an ID of its own, and keeps it per site and reader: a reader's source IDs are kept apart from every other reader's and from WordPress's, so the same ID in two files is never one item. The IDs are not the file's order. Core keeps a reader's people and terms with negative IDs and its posts, pages and pictures with positive ones. Teme's statuses are kept as Teme's words in the reader and translated to the words the import uses inside core. A reader's own markup is converted by core, as WordPress content is; plain text is turned into paragraphs.

A person with no login becomes an author with a login made from their name, the same way a WordPress author without a login is handled. Two people are never merged under one login: a name already taken gets a suffix. A person with no email address is not imported, and the report names them, so they can be given one or chosen on the plan.

## Trying a reader

The testing kit's `Teme\Testing\FakeImportReader` reads a JSON file of `system`, `version`, `site`, `languages`, optional `defaultLanguage` and `items`, where each item names its `kind` and `id`, and may name `language` and `translationGroup`. It is the model to copy: a reader is a class that turns a file into items, and the tests check the items it returns. A two-language sample lives at `packages/teme-testing/samples/fake-export-languages.json`.

## What a reader must not do

A reader only reads. It doesn't write to the site or to the file it was given, and it doesn't fetch anything from the network to answer a call. Core does every write, as the person who started the import.
