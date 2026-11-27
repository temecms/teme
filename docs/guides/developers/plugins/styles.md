---
title: Stylesheets for a plugin's output
type: how-to
audience: plugin
summary: How a plugin ships a built stylesheet for its cards, its page insertions or its pages, so the sheet loads only on a page that shows that output.
rests_on: [app/Domain/Plugins/Styles, app/Domain/Cards/Plugin/PluginCards.php, app/Domain/Site/Insertions/PluginMarkup.php, app/View/Components/Teme/Document.php, packages/teme-contracts/schema/teme-plugin.schema.json]
---

# Stylesheets for a plugin's output

A plugin's cards and page insertions draw their own markup, and a plugin can't otherwise style it. A stylesheet declared here is built ahead of time, shipped in the package, and linked into a page only when that page draws the output it styles. A page with no plugin output loads none of them.

## Declaring it

The plugin lists its stylesheets under `styles`, one entry per output:

```json
"styles": [
  { "output": "card:gallery", "file": "dist/gallery.css" },
  { "output": "insertion:banner", "file": "dist/banner.css" },
  { "output": "page", "file": "dist/page.css" }
]
```

- `output` is `card:` and the card's id, `insertion:` and the insertion's id, or `page`. Any other value is refused at enable.
- `file` is a `.css` file inside the package, relative to its root. A path with `..`, a leading slash or a backslash is refused at enable.

There are at most 40 entries.

## Where the file comes from

Build the CSS before you publish the package. Teme doesn't build anything on the server. At deploy, `teme:plugins:link` links each enabled plugin's stylesheets into the public folder. A disabled plugin's link is removed, so its stylesheets stop being served.

## What the page does

When a page draws a card or insertion of a plugin, Teme records that output. The document's head then links that plugin's stylesheet for the output, inside the `teme.plugins` cascade layer, so a theme's own styles still win over the plugin's. A stylesheet for an output that is not drawn on the page is not linked.

## Styling in the right place

Write the sheet's rules inside `@layer teme.plugins`, and read Teme's design tokens as `--teme-*` variables, as core's own cards do. A theme can switch a plugin's stylesheet off by naming it in the theme's manifest under `plugin_styles.off`, as `code:card:<id>`, and then styles that output itself.

## Not in this version

Scripts, and stylesheets for page insertions: a page insertion's output is not recorded yet.
