---
title: Stylesheets for a plugin's output
type: how-to
audience: plugin
summary: How a plugin ships a built stylesheet for its cards and its page insertions, so Teme serves it, wrapped in its own cascade layer, only on a page that shows that output.
rests_on: [app/Domain/Plugins/Styles, app/Http/Site/PluginStylesheetController.php, app/Domain/Cards/Plugin/PluginCards.php, app/Domain/Site/Insertions/PageInsertions.php, app/View/Components/Teme/Document.php, packages/teme-contracts/schema/teme-plugin.schema.json]
---

# Stylesheets for a plugin's output

A plugin's cards and page insertions draw their own markup, and a plugin can't otherwise style it. A stylesheet declared here is built ahead of time and shipped in the package. Teme serves it from its own address, and a page links it only when that page draws the output it styles. A page with no plugin output loads none of them.

## Declaring it

The plugin lists its stylesheets under `styles`, one per output:

```json
"styles": [
  { "output": "card:gallery", "file": "dist/gallery.css" }
]
```

- `output` is `card:` and the card's id, or `insertion:` and the insertion's id. The card must be one the plugin declares. Each output has at most one sheet, and there are at most 40 entries. Anything else is refused at enable.
- `file` is a `.css` file inside the package, relative to its root. A path with `..`, a leading slash or a backslash is refused at enable.

An insertion's id is the part of its key after the plugin's code and a dot, so a key `billboard.top` is `insertion:top`. An id must be lower-case letters, digits and hyphens to be declared.

## How it is served

Nothing is copied at deploy, and nothing is written while the site runs. Teme serves each declared sheet at `/_teme/plugins/{code}/{file}`, and answers 404 unless all of these hold:

- the plugin is enabled, and the manifest declares the file;
- the file is a plain file inside the package, with no link on its path;
- the sheet imports nothing and declares no namespace, and its braces balance.

The sheet is sent wrapped in `@layer teme.plugins`, so a theme's own rules win over it. A leading `@charset` is kept at the top. Its address carries a hash of its content as `?v=`, so a changed file gets a new address, and it is sent with long cache headers.

## What the page does

When a page draws a card or an insertion of a plugin, Teme records that output. The page's head then links that plugin's sheet for the output. Head, body-start and body-end insertions are declared before the head is written, so their sheets are linked in the head. A fixed-edge insertion is drawn after the body, so its sheet is linked once, after the body's end. A stylesheet for an output the page does not draw is not linked, and neither is one Teme refuses.

## Styling in the right place

Write the sheet's rules as they are: Teme wraps them in `@layer teme.plugins`. Read Teme's design tokens as `--teme-*` variables, as core's own cards do. A theme can switch a plugin's stylesheet off by naming it in the theme's manifest under `plugin_styles.off`, as `code:card:<id>` or `code:insertion:<id>`, and then styles that output itself.

## Not in this version

- Scripts. A plugin's scripts are not loaded by this mechanism yet.
- A stylesheet for the whole page. Only cards and insertions have one.
