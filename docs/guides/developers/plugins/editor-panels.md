---
title: A panel in the editor
type: how-to
audience: plugin
summary: How a plugin that works on the article being edited adds a panel to the editor, what the panel shows, how its actions start the plugin's work as the person, and how each suggestion is checked, used and undone.
rests_on: [packages/teme-contracts/src/Editor, app/Domain/Plugins/Editor, app/Domain/Plugins/Operations/RunEditorPanelAction.php, app/Domain/Plugins/Operations/SettleEditorSuggestion.php, app/Http/Admin/Contents/EditorPanelController.php, packages/teme-contracts/schema/teme-plugin.schema.json, docs/architecture/plugins.md]
---

# A panel in the editor

A plugin that works on the article someone is editing, such as one that suggests internal links, can add a panel to the editor. The panel is a tool in the editor's bar, opened in the rail beside the article and marked with the plugin's name. It shows a line about the article, the plugin's work on it with its progress, and the changes the plugin suggests. The person uses or discards each suggestion. Core draws all of it and checks every change; no plugin code runs in the browser.

A panel is for work on the article being edited. A plugin that creates articles does that in [its own area](admin-area.md).

The contracts are in `teme/contracts`, under its Editor namespace, from Teme 0.9.0.

## Declare the panel

A panel needs the `content.read` and `jobs` capabilities. Declare up to two under `contributes.editorPanels`:

```json
"editorPanels": [
    {"id": "links", "label": "Link suggestions", "icon": "link", "types": ["post"], "permission": "suggest", "actions": [{"id": "find", "label": "Find links", "job": "find-links"}]}
]
```

- `id`, 2 to 24 lower-case letters, digits and hyphens. The tool's id is `plugin.` then the plugin's code, a dot and the panel's id, such as `plugin.linkfinder.links`.
- `label`, up to 40 characters, and `description`, up to 160, shown at the top of the panel.
- `icon`, one of the Tools links' icons or `sparkles`, `pen-line` and `list-checks`.
- `types`, the content types whose editor shows it, at least one. Enabling refuses a type the site doesn't have.
- `permission`, one of [your permissions](admin-area.md), when only some people should see the panel.
- `actions`, up to three, each with an `id`, a `label`, the `job` it queues, an optional `confirm` sentence, and up to four `fields`: `text` (up to 500 characters), `textarea` (up to 2,000), `select` with its `options`, `boolean`, and `number` with `min` and `max`. Each field has a `key` and a `label`, and may have `required` and a `default`.

People enabling the plugin read, for each panel: "Add a panel to the editor for Posts: “Link suggestions”, which can start its work on the item you’re editing and suggest changes you choose to use."

A person sees the panel when the plugin is enabled with both capabilities, the item's type is listed, they may change the item, and they hold the panel's permission when it names one. They can pin it to the bar beside the assistant and Share, three tools at most.

## Hand over the panels

Your plugin class implements `HasEditorPanels`, and `editorPanels()` returns one `EditorPanel` for each declared id. The plugin doesn't start when the two differ, or when an action names a job you don't declare.

`view()` is asked each time the panel opens, and again every five seconds while its work waits or runs. It is given an `EditorPanelContext`: the item's ID, its working revision, type and language, the person's ID, the admin's language, and which of your permissions they hold. It returns a `PanelView`:

```php
return new PanelView(status: '3 links found', tone: 'ok', suggestions: $suggestions);
```

The status line is up to 200 characters and its tone one of `ok`, `neutral`, `info`, `warn` and `danger`. A view holds up to 20 suggestions, each with an id unique in the view. Answer within 250 ms: read what your job already worked out, never call a service. A view that throws or runs over is recorded as your plugin's fault, and the panel says "This panel couldn’t be shown this time." while its jobs still show.

## Suggestions

A `Suggestion` has an id, a label of up to 120 characters, a detail line of up to 500, and one `SuggestedChange`:

```php
new Suggestion('link-roses', 'Link “feeding roses”', 'To Feeding roses in spring', SuggestedChange::link('feeding roses', $itemId));
```

| Change | What it suggests | What core checks before the person sees it |
|---|---|---|
| `title($title)` | A new title | Up to 512 characters |
| `field($handle, $value)` | A field's value | The type has the field, and the value is of its kind: text, textarea and URL take text, number a number, a yes-or-no a boolean, a select one of its options |
| `terms($taxonomy, $slugsOrNames)` | Up to ten terms to add | The type uses the taxonomy. Terms are found by slug or name; one that doesn't exist yet is created when the person uses the suggestion, by their own rights |
| `link($phrase, $itemId, $occurrence)` | An internal link on a phrase | The item is published for everyone to read, and isn't the article itself |
| `body($document)` | The whole article | The editor's own document validator accepts it |

A suggestion that fails its check is dropped and recorded once as your plugin's fault. Every word reaches the page as plain text, never as markup.

Using a suggestion changes the open draft as one change the person can undo; nothing is saved until they save. A title, a field or terms are set at once. A link wraps the phrase's match, counting only text outside links and code, and stores the item's ID, never its address. A whole article opens first as a proposal with its word differences, and Use replaces the document.

## When a suggestion is used or discarded

Core then calls `settled()` with the suggestion's id and whether it was used. It runs inside core's transaction and has two seconds. Keep what you need, such as links already offered, in your own tables. Throw `WriteRefused` with one plain sentence to refuse: the person reads it, and nothing you wrote is kept. Any other failure, or a call over two seconds, is recorded as your plugin's fault and rolls back too.

## Actions start your job

An action's button saves what the person typed, then queues your job as that person, once while one waits. Its payload holds the panel, the action, the item's ID, the revision the person saw, and the form's values:

```php
$itemId = $context->payload()['itemId'];
```

The job runs as the person, so it reads the draft with `ContentReader` and may ask the site's AI, as [Reading content](content-read.md) and [The site's AI connection](ai.md) show. It leaves its results for `view()` to return as suggestions, or writes a new revision through `ContentWriter`; the editor then tells the person the article changed elsewhere, as it does for any save. Report progress as any job does, in [Jobs and schedules](jobs.md): the panel shows the job's label, progress and message while it waits or runs, and for 15 minutes after it ends.
