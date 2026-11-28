---
title: A plugin's own area
type: how-to
audience: plugin
summary: How a plugin declares screens of its own in the admin, an overview of figures and charts, tables of its records and each record's form, what core draws and checks, the permission that opens them, and the PluginArea contract that hands core the data.
rests_on: [packages/teme-contracts/src/Admin, app/Domain/Plugins/Area, app/Domain/Plugins/Operations/SavePluginRecord.php, app/Domain/Plugins/Operations/ActOnPluginRecords.php, app/Http/Admin/PluginAreaController.php, packages/teme-contracts/schema/teme-plugin.schema.json, docs/architecture/plugins.md]
---

# A plugin's own area

A plugin that keeps things of its own, such as ads, forms or redirects, can have screens of its own in the admin: an overview with figures and a chart, a table of its records, and a form for each record. The plugin declares the screens in `teme-plugin.json` and hands core the data; core draws every screen with its own components, checks every value, and runs every write as an Operation. A plugin never draws HTML in the admin.

The contracts are in `teme/contracts`, under its Admin namespace, from Teme 1.0.0. For the settings page every plugin can have, see [Plugin settings and secrets](settings.md).

## Declare a permission

An area opens only for the people who hold its permission. Declare up to three under `contributes.permissions`, each with an `id`, a `label`, a `description` and the `roles` that hold it:

```json
"permissions": [
    {"id": "manage", "label": "Manage ads", "description": "Create, change and switch ads, and see their statistics.", "roles": ["editor"]}
]
```

The roles a plugin may name are `editor`, `author` and `contributor`. Administrators always hold every plugin permission. Enabling the plugin makes the permission, named `plugin.` then the plugin's code then the id, such as `plugin.placard.manage`, and gives it to those roles. People enabling the plugin see each permission and its roles before they agree, and an update that names a new role waits for their agreement again. Disabling keeps the permission; removing the plugin's data deletes it.

## Declare the area

Put the area under `contributes.area`:

- `label` and `description`, shown on its Tools card when it has one;
- `permission`, the id of one of your permissions;
- `menu`, the menu section its entry sits in: `content`, `site` or `tools`;
- `icon`, optional: the Lucide name for the menu entry and Tools card (`plug` when absent); the same set editor panels may use;
- `purges`, the page-cache tags a write clears after it commits: `insertions`, `page-insertions`, or neither;
- `pages`, one to eight, each with an `id`, a `label` and up to eight `blocks` (the ids `records` and `settings` are reserved);
- `records`, up to six kinds of thing the area keeps, each with a form.

The menu entry and the area's heading show the area's own label, with the plugin's name as a plug mark beside it. The admin menu holds five plugin entries at most, in the order the plugins were enabled; a sixth area, and any area whose `menu` is `tools`, gets a card on the Tools page instead.

When the plugin also declares settings, Teme adds a **Settings** tab as the last tab of the area and draws the settings form there; see [Plugin settings and secrets](settings.md#where-they-appear).

Teme checks the area when the plugin is enabled and each time it starts, with one plain sentence for each thing that is wrong. A plugin whose area is wrong doesn't start.

## The blocks

A page draws its blocks in order:

| Block | What it draws | What it declares |
|---|---|---|
| `figures` | A row of figures, each with its change | `items`, each an `id`, a `label` and a `format`: `number`, `percent` or `text`; one to eight a block |
| `chart` | A line or bar chart, with a table of the same numbers for screen readers | `label`, `kind` (`line` or `bar`) and `series`, each an `id` and a `label` |
| `table` | One record's rows, with search, filters, sorting, pages, a row menu and bulk actions | `record`, `columns` (`text`, `number`, `badge` or `link`, `sortable`), `search`, `filters`, `perPage` (up to 100), `rowActions`, `bulkActions` |
| `actions` | Buttons that queue the jobs behind your declared actions | `actions`, ids from `contributes.actions` |
| `notice` | One line of text | `text` and a `tone` |

Each figures, chart and table block loads on its own, after the page, so one slow block never holds the others.

Row actions are `edit`, `duplicate`, `enable`, `disable` and `delete`, and up to two of your own actions marked `"row": true` under `contributes.actions` (never bulk). Bulk actions are the built-in ones without `edit`. Core asks before a delete, and before a custom action that declares `confirm`. A row can leave an action out by naming it in its `unavailable` list. Core re-reads that list before queuing, under the area's time budgets, and refuses a listed action, or any action when your rows can't be read in time; the job may still check.

A `link` column shows an outside address: the cell is `Cell::link($text, $href)`. Core draws only an `https://` address with no user or password, in a new tab with the host under the link; anything else shows as no link. Content lists never use link cells.

## The forms

Each record has an `id`, a `label`, a `plural`, a `titleField` whose value heads its form, and up to eight `sections` of fields, at most 30 a section and 60 a form. A field has a `key`, a `type` and a `label`, and may have a `description`, `required`, `min`, `max`, `maxLength`, `options` and `default`, as settings do.

The field types are the settings types `text`, `textarea`, `number`, `boolean`, `select`, `multiselect`, `email`, `url` and `color`, and these:

| Type | What it holds |
|---|---|
| `code` | Markup in a monospace box, up to 65,535 characters, never run in the admin |
| `datetime` | A date and time in ISO 8601, in the site's time zone |
| `types` | Content-type keys, from core's list |
| `terms` | Term IDs of the taxonomy named in `taxonomy` |
| `region` | A slug of the active theme's regions |
| `repeater` | A list of items, each with its own `fields` |

A repeater gives `fields` (any type but another repeater), `minItems`, `maxItems` (up to 50, 20 when left out), `itemLabel` and `itemTitle`, the sub-field whose value names each item. People add, collapse, remove and move items with Move up and Move down.

A field with `visibleWhen` is shown only while its condition holds, such as `{"location": "after_paragraph"}`. A condition names fields declared before it. A hidden field is never checked, and never sent to your `save()`: what it held stays as it was.

### Scripts in a code field

A `code` field declared `"scripts": true` may hold inline scripts, such as an ad network's `push({})`. Each time the form is saved, core asks for every new inline script to be approved for your plugin, inside the save's transaction, so a refused save asks for nothing. The plugin must hold `content.insert` or `pages.insert`, the only places its scripts would run. Under the field the form shows each script that waits: a person who may change site code approves it there, with their password, and anyone else sees that it waits for approval. Settings › Site code lists every waiting and approved script. A plugin can never approve its own.

The same goes for a code setting declared `"scripts": true` on your settings page.

## Hand core the data

The class named under `plugin` in the manifest implements `HasPluginArea` beside `Plugin`, and its `pluginArea()` answers your `PluginArea`. Core calls it as the signed-in person, with an `AreaContext` holding their ID, language and time zone, and in `permissions` the ids of your own permissions they hold, so you can show most people their own records and holders of a `view-all` permission everyone's:

| Method | What it answers |
|---|---|
| `figures($page, $block, $context)` | `Figure` values by item id: `Figure::number()`, `Figure::percent()` or `Figure::text()`, with an optional change, 0.12 for up 12%. An empty array shows a dash for each figure unless the figures block declares `empty`: `true` uses core's "Figures appear once there is something to count.", a string uses those words, for the "No statistics yet" state. Read `$context->period` for the window's dates in the **site's** time zone |
| `chart($page, $block, $context)` | A `Chart` of labels, at most 366, and one value a label for each series; pass earlier-period values as the optional third `$compare` argument for dashed lines |
| `rows($record, $query, $context)` | A `RowPage` of up to 100 `Row` values for the `TableQuery`'s page, search, filters and sort, and the total when you know it |
| `values($record, $id, $context)` | The record's stored values by field key, or null when there is no such record |
| `save($record, $id, $values, $context)` | Stores the checked values and answers `Saved` with the record's id; `$id` is null for a new one |
| `act($record, $action, $ids, $context)` | Runs `duplicate`, `enable`, `disable` or `delete` on those records |

A row's cells are list `Cell` values by column id: `Cell::text()`, `Cell::number()`, `Cell::badge()`, or `Cell::link()` for a `link` column. Core shows everything you hand it as text, never as markup.

### Record header, tab counts and the statistics period

Implement `HasRecordHeader` beside `PluginArea` to supply a record's status pill and up to three meta lines (`RecordHeader`); return null for title only. A failed header read never becomes an error page.

Implement `HasAreaCounts` to answer counts by page id for the area's tabs. Core loads them after the page; until they arrive other tabs show a quiet "Counting" pill. The open tab still uses its first table's total when you send none. A missing, failed or over-large count shows no number.

Core offers six statistics periods (`Last 7 days`, `Last 30 days` as the default, `Last 90 days`, `This month`, `Last month`, `This year`) when a page has figures or charts. Plugins cannot add periods. The chosen window is `$context->period` (`AreaPeriod`): start, end, and the earlier window to compare, as `Y-m-d` in the site's time zone. Home panels use the last 30 days with no select.

## Budgets and refusals

Each read has 250 ms and a page's reads 1 second together. A read that throws or runs over shows a line in place of its block and is recorded as the plugin's fault. A write has 2 seconds.

`save()` and `act()` run inside core's transaction. Write to your own tables with the database connection as usual, and the write rolls back with everything else when anything refuses. To refuse a save yourself, throw `WriteRefused` with one plain sentence, such as "An ad with this name already runs.": the form shows it and nothing is kept. After the write commits, core clears the page-cache tags in `purges`.
