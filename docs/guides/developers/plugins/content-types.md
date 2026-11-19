---
title: Content types from your plugin
type: how-to
audience: plugin
summary: How a plugin brings its own content types, taxonomies and fields in a teme-types file, what happens on enable, update, disable and removal, and how it takes over types a site already has.
rests_on: [app/Domain/Plugins/Types, app/Domain/Plugins/Operations/ApplyPluginTypes.php, app/Domain/Types/TypeOwners.php, docs/architecture/plugins.md]
---

# Content types from your plugin

A plugin can bring kinds of content of its own, such as recipes or events, with their taxonomies and fields. You describe them in the same file Settings › Types exports, and Teme stores them as the plugin's while it is enabled. No code registers them, and no capability is needed.

## Name the file

Put a teme-types file in your package and name it under `contributes.types`:

```json
"contributes": { "types": "types/teme-types.json" }
```

The path is inside your package, folders and a name of letters, digits, `.`, `_` and `-`, ending `.json`. The file is the teme-types format, version 1, exactly as **Settings › Types › Download teme-types.json** writes it: `{"format": "teme-types", "version": 1, "types": [...], "taxonomies": [...], "fields": [...], "grants": [...]}`. The easiest way to write one is to build the types on a development site and download them.

Keys follow the same rules as any type's, taxonomy's and field's, with no prefix. A plural can't be the name of an admin screen, such as Plugins, Media or Settings, or another type's: Teme refuses it and suggests one, such as "Plugin directory".

Teme reads the file before it enables the plugin. A missing file, one that isn't JSON, one that isn't a teme-types file, or a type the site can't take is refused, and the Plugins screen says why.

## What enabling does

While the plugin is enabled its types, taxonomies and fields work like any other: they are in the menu, the lists and the editor, their items are at their addresses on the site, and search, sitemaps, feeds and the REST API include them. Settings › Types shows each with your plugin's mark and "Comes from" its name, read-only. Nobody changes their keys, names, addresses or fields there, and nobody deletes them: your updates do.

## Taking over types a site already has

A site may already have the type you bring, made by hand or by an import. If a type, taxonomy or field in your file has the same key as one on the site, enabling makes it your plugin's, keeping all its items and values. A field is also found by its type and its handle, keeping the site's own key, because items keep their values under it.

Where the site's version and your file differ, your file wins for what it says, and the enable says what it took over and changed. A field whose kind your file changes, from text to number say, is refused while items hold values in it, since they would be read as another kind; with no values held, it changes. A public type keeps the address pattern the site gave it, and a taxonomy its base, so no live address moves.

## Updates

When a new version's file differs from the one applied, the plugin waits, as it does for a new migration, until someone runs **Run database changes** on its row in **Plugins**, or the operator runs `teme:plugins:migrate`. Then types and fields the file adds are added, and those it changes are updated. A type, taxonomy or field the new file no longer has stays on the site as the site's own, with its items, never deleted, and the run says so.

## Disabling and removing

Disabling the plugin takes its types out of the menu, the editor and the site, whose addresses then answer 404. Their items are kept untouched, and enabling it again brings everything back as it was.

Removing the plugin is refused while any item of its types exists, trashed ones included. The refusal says how many and where, so someone can enable it, delete the items, empty the trash and disable it again first.
