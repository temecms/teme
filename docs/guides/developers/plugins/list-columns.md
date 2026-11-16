---
title: List columns
type: how-to
audience: plugin
summary: How a plugin adds a column to the admin's content lists.
rests_on: [docs/architecture/plugins.md, docs/guides/developers/examples/plugins/list-columns, packages/teme-contracts/src/Lists]
---

# List columns

A plugin can add its own column to the admin's Posts, Pages and other content lists — an SEO score, a view count, a short note. The plugin declares the column and returns plain values for one page of rows; Teme draws the heading, the cells, the plug mark, the empty state and each person's show and hide choice.

## What you need

- `content.read` in the plugin's `capabilities`. Drafts are listed, so the consent that already says "Read your posts, pages and other content, including drafts" is the one that applies.
- A `contributes.columns` entry in `teme-plugin.json`.
- A class that implements `Teme\Contracts\Lists\HasListColumns` beside `Plugin`.

## The manifest entry

```json
// docs/guides/developers/examples/plugins/list-columns/teme-plugin.json
    "capabilities": ["content.read"],
    "hooks": ["ContentSaved"],
    "contributes": {
        "columns": [
            {
                "id": "score",
                "label": "SEO score",
                "kind": "badge",
                "width": "narrow",
                "types": ["post", "page"]
            }
        ]
    }
```

- `id`: lower-case letters, digits and hyphens, starting with a letter (2 to 40 characters). Unique within the plugin.
- `label`: the heading and the name in Columns (1 to 40 characters).
- `kind`: `text`, `number` or `badge`.
- `width` (optional): `narrow` (100 px), `medium` (128) or `wide` (168). Default: `narrow` for number and badge, `medium` for text.
- `types` (optional): content type keys. Absent means every listed type.
- At most **3** columns per plugin.
- Do not set `sortable`: Teme does not sort a list by a plugin's column yet.

The preference key is `plugin:{code}:{id}`.

## The contract

```php
// docs/guides/developers/examples/plugins/list-columns/src/SeoPlugin.php
final class SeoPlugin implements HasListColumns, Plugin
{
    public function listColumns(): array
    {
        return [
            'score' => new class implements ListColumn
            {
                public function cells(ListRows $rows): array
                {
                    $cells = [];

                    foreach ($rows->rows as $row) {
                        // Read from the plugin's own table, filled on ContentSaved.
                        $cells[$row->id] = Cell::badge('Good', 'ok');
                    }

                    return $cells;
                }
            },
        ];
    }

    public function register(Hooks $hooks): void
    {
        // Fill the plugin's own table on ContentSaved.
    }
}
```

`listColumns()` must return a `ListColumn` for every declared id, and nothing else. A badge's tone is one of `ok`, `neutral`, `info`, `warn` or `danger`.

## How Teme asks

- Only columns the person has turned on in Columns are asked.
- Each shown column is asked **once per page**, with every row on that page (at most 100), in the person's left-to-right order.
- A hidden column is never asked; an empty page asks nothing.

## The budget

- **100 ms** per call. Over that, the cells are dropped and a fault is recorded under the plugin.
- **300 ms** for all plugin columns on the page together. Once spent, the remaining plugin columns are not asked.
- A call that throws leaves its column empty and is recorded the same way.
- The list itself always renders. One line above it names which plugins' columns could not be shown this time.

## Text only, never HTML

Return plain text, a number or a badge label. Teme cleans control and direction-override characters, cuts text to 80 characters and badges to 24, and React draws everything as text. Markup such as `<b>x</b>` is shown as those characters, never interpreted.

## Off until someone turns it on

A plugin column is offered in Columns with its plug mark, and shown only once a person ticks it — the same rule Featured image and Languages follow. When the plugin is disabled, its keys drop out of everyone's list and the rest of their choice is kept.
