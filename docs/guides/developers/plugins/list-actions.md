---
title: List actions
type: how-to
audience: plugin
summary: How a plugin adds row and bulk actions to the admin's content lists.
rests_on: [docs/architecture/plugins.md, packages/teme-contracts/schema/teme-plugin.schema.json, app/Domain/Plugins/Lists/ListActions.php]
---

# List actions

A plugin can add actions to the admin's Posts, Pages and other content lists. A row action appears in the row's menu, before Trash. A bulk action appears in the bar that shows when rows are selected, and runs on the selected items. Each action queues one of the plugin's own jobs, as the person who clicked.

## What you need

- `jobs` in the plugin's `capabilities`, and the job the action queues declared under `jobs`.
- A `contributes.listActions` entry in `teme-plugin.json` for each action.

## The manifest entry

```json
"contributes": {
    "listActions": [
        { "id": "ping-items", "label": "Ping these", "row": true, "bulk": true, "confirm": "Ping the selected items now?", "job": "ping" }
    ]
}
```

- `id` is 2 to 40 lower-case letters, digits and hyphens.
- `label` is up to 40 characters.
- `row` and `bulk` say where the action is offered. Set at least one.
- `confirm` is an optional sentence the person reads before the job is queued.
- `job` is the key of a job the plugin declares.

A plugin can add at most three list actions, and at most two of them can be row actions.

## What Teme checks

When someone runs an action, Teme checks each item before the job is queued. An item the person can't edit is left out, and the toast says how many were left out. If none is left, nothing is queued. A bulk run takes at most 100 items. A row action runs on one item at a time.

The job receives its payload as `{"action": "<id>", "items": ["<content id>", …]}`. Only the items the person could edit are in it.

Actions run in the job queue, so a second click on the same action and the same items returns the waiting job rather than queuing a second one.
