---
title: Your plugin's data in the site export
type: how-to
audience: plugin
summary: How a plugin adds a file of its own to the site's export, so what it keeps leaves with the site's content, and what happens when it fails.
rests_on: [app/Domain/Plugins/Exports, app/Domain/Export, packages/teme-contracts/src/Export, packages/teme-contracts/schema/teme-plugin.schema.json]
---

# Your plugin's data in the site export

The site's export is a folder of files, one for each kind of thing the site holds: people, items, media and so on. A plugin that keeps data of its own can add a file to it, so that data leaves with the rest.

## Declaring it

Declare each file under `exports`, with an `id` and a `noun`:

```json
"exports": [
    { "id": "bookings", "noun": "booking|bookings" }
]
```

The `id` is 2 to 24 lower-case letters, digits and hyphens, starting with a letter. The `noun` says what one line is, for one and for many, with a bar between. A plugin can add at most 8 files. The file is named `plugin_{code}_{id}.ndjson`, with hyphens as underscores: `plugin_sharely_bookings.ndjson`.

## Handing the lines over

The plugin's class implements `HasExportKinds`, whose `exportKinds()` returns one `PluginExportKind` for each declared id, and no others. A `PluginExportKind` answers three things, all as plain values:

- `version()`: the file's format version, 1 to start. Raise it whenever what a line holds changes.
- `fields()`: every key a line may hold, in order, with what it holds, in words.
- `lines(?string $after)`: one page of lines, at most 1,000, as `['lines' => [...], 'next' => '...']`. The first call has `$after` null; each later one gets the `next` of the page before. `next` is null on the last page. A page that says the next one starts where it did is a fault.

Core writes each line as one line of JSON, with `kind` and `version` first. A line never sets those two itself. Values are text, numbers, true or false, null, and lists or maps of those. Read the lines from what the plugin already keeps, a page at a time, so a large site doesn't fill memory.

Keep secrets out of the lines. An export is for taking the site somewhere else, and a secret that leaves the site is no longer one.

## What the export says about it

The export's `manifest.json` lists the file with the others, and its `plugin_kinds` gives the plugin's Composer name and the fields it documented, in the plugin's own words. Only plugins that are enabled when the export runs add a file; a disabled plugin adds nothing.

## When the plugin fails

A plugin that throws, or answers a page in the wrong shape, never stops the export. Its file is dropped, its fault is recorded under Plugins, and the manifest's `left_out` list names the file, so nobody mistakes the gap for a loss. The rest of the export is written as always.

## Coming back in

Teme can't read its own export back in yet: its import reads WordPress's export file. A plugin's file is written so that a later import can hand its lines back to the plugin, but nothing does that today.
