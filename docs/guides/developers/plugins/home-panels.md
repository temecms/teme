---
title: Panels on Home
type: how-to
audience: plugin
summary: How a plugin adds panels to the admin's Home screen, built from blocks of its own area, who sees them, what happens when a block can't be read, and how a person hides or moves a panel.
rests_on: [app/Domain/Plugins/Area/HomePanelDeclaration.php, app/Domain/Plugins/Area/PluginHomePanels.php, app/Domain/Plugins/Area/AreaValidator.php, app/Domain/Dashboard/Queries/Dashboard.php, app/Domain/Plugins/Queries/PluginAreaScreen.php, packages/teme-contracts/schema/teme-plugin.schema.json]
---

# Panels on Home

A plugin can put panels on the admin's Home screen, under a heading of their own, so people see its figures and what it reports without opening its area. A panel is not a screen of its own and draws no HTML: it is built from blocks of the plugin's [area](admin-area.md), the same figures, charts, tables and notices its area already shows.

## Declare the panels

Add `contributes.homePanels` next to the area. Up to three panels, each with:

- `id`: 2 to 40 lower-case letters, digits and hyphens, starting with a letter;
- `title`: the heading on Home, 1 to 40 characters;
- `size` (optional): `small`, `medium`, `large` or `full`. A plugin's panel is Small until a person changes it, unless it names a size;
- `page`: the id of one of the area's pages;
- `blocks`: one to four ids of blocks on that page.

```json
"homePanels": [
    {"id": "ads", "title": "Ads", "size": "medium", "page": "overview", "blocks": ["totals", "daily", "hint"]}
]
```

A block on a Home panel may be a figures block, a chart, a table or a notice. A table shows its first five rows, read only: sorting, search, filters and row actions stay on the plugin's own screen. A block of any other kind, a block of another page, or a block id the page lacks is refused when the plugin is enabled, with the reason in plain words.

The area must exist, and so must its permission, because a panel is for the people who may open the area.

## Who sees a panel

A panel is shown only to people who hold the plugin area's permission, such as `plugin.placard.manage`. Everyone else doesn't see it in Home or in its list of panels to add. Disabling the plugin removes its panels at once.

Panels appear in a section of their own, From plugins, under Home's own panels, and each names its plugin.

## When a block can't be read

Each block is read on its own, under the same budgets as the area's pages: a call that throws, runs over its time or answers in the wrong shape shows one line in its block instead, and the rest of the panel and of Home still works. A panel is marked as failed, with a line and a Try again button, only when every block that reads data couldn't be shown. A notice reads nothing, so it still shows.

## Hiding and moving

A person can hide a panel, move it and change its size in Customize, as they do with core's panels. Their choices are saved as their own preferences. A saved choice for a panel that no longer exists is ignored.
