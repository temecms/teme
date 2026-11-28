---
title: List filters
type: how-to
audience: plugin
summary: How a plugin adds a filter to the admin's content lists, a select of options it declares.
rests_on: [docs/architecture/plugins.md, app/Domain/Plugins/ListFilters, packages/teme-contracts/schema/teme-plugin.schema.json]
---

# List filters

A plugin can add a filter to the admin's Posts, Pages and other content lists: a select with a few options it declares, such as "Review state: Ready, Stale". Choosing an option keeps only the items the plugin's own table marks with that value. The plugin writes the values; Teme draws the select and does the lookup.

## What you need

- A table of your own, listed under `owns.tables` in `teme-plugin.json` and created by one of your migrations, with one row per content item: the item's id and the value the filter reads. A filter can read only such a table: never core's tables, and never another plugin's.
- A plain, valid, non-partial btree or hash index whose first column is the filter column. Teme checks it when the plugin is enabled, and again when the plugin's database changes run (Run database changes, or `php artisan teme:plugins:migrate`). A refusal undoes those changes.
- The filter's column and its item column must be text (`text`, `varchar` or `char`).
- A `contributes.listFilters` entry in `teme-plugin.json`.

No capability is needed: the filter reads only the table and column you declare.

## The manifest entry

```json
// docs/guides/developers/examples/plugins/list-filters/teme-plugin.json
    "owns": { "tables": ["review_scores_states"] },
    "contributes": {
        "listFilters": [
            {
                "id": "review-state",
                "label": "Review state",
                "types": ["post", "page"],
                "options": [
                    { "value": "ready", "label": "Ready" },
                    { "value": "stale", "label": "Stale" }
                ],
                "table": "review_scores_states",
                "column": "state",
                "item": "content_id"
            }
        ]
    }
```

- `id` is 2 to 40 lower-case letters, digits and hyphens, unique within the plugin.
- `label` is the select's name, 1 to 40 characters. It is translated with your plugin's language files, as columns are.
- `types` lists the content type keys the filter applies to. At least one is needed.
- `options` holds 2 to 20 values. Each `value` is lower-case letters, digits, hyphens or underscores, and each `label` is 1 to 40 characters.
- `table` is your table, `column` the column the filter reads, and `item` the column that holds the content id each row belongs to, as text.

A plugin declares at most two filters. A content list shows at most three filters in all, across every plugin. Enabling a plugin, or running its database changes, that would make a list show four is refused, and the message names the list.

## How a filter is matched

A value is matched exactly, the way the column stores it. Teme reads only the options you declared: an address with any other value is treated as if the filter were not set. The filters combine: an item must match every filter chosen. Teme runs one indexed lookup in your table for each filter, which is why the column must lead an index.

## Updating a plugin

Teme offers a filter as it was checked. When a new version changes its filters, the changed filters are checked, and offered, when the plugin's database changes run. Meanwhile the list keeps offering the filters that were checked before. A new version that changes filters without any database change still needs its database changes run for the new filters to be checked and offered.

## Keeping the values current

Teme does not watch your data. Write the row when the value changes, in the same place you save the content (see [Content read](content-read.md) and [Your own hooks](your-own-hooks.md)), and delete it when the value no longer applies. An item with no row matches no option.

## Limits in this release

- Free text, ranges and numbers are not offered: a filter is a select of declared options only.
- A filter cannot be sorted by.
- A migration that drops the index a filter relies on is refused when its database changes run, and the index stays.
- A filter reads one table of the plugin's own. It cannot read another plugin's table or the site's settings.
