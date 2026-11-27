---
title: Stylesheets for a plugin's output
type: how-to
audience: plugin
summary: How a plugin ships a built stylesheet for its cards and its page insertions, so the sheet loads only on a page that shows that output.
rests_on: [app/Domain/Plugins/Styles, app/Console/Commands/LinkPluginStyles.php, app/Domain/Cards/Plugin/PluginCards.php, app/Domain/Site/Insertions/PageInsertions.php, app/View/Components/Teme/Document.php, packages/teme-contracts/schema/teme-plugin.schema.json]
---

# Stylesheets for a plugin's output

A plugin's cards and page insertions draw their own markup, and a plugin can't otherwise style it. A stylesheet declared here is built ahead of time, shipped in the package, and linked into a page only when that page draws the output it styles. A page with no plugin output loads none of them.

## Declaring it

The plugin lists its stylesheets under `styles`, one per output:

```json
"styles": [
  { "output": "card:gallery", "file": "dist/gallery.css" },
  { "output": "insertion:banner", "file": "dist/banner.css" }
]
```

- `output` is `card:` and the card's id, or `insertion:` and the insertion's id. The card must be one the plugin declares. Each output has at most one sheet, and there are at most 40 entries. Anything else is refused at enable.
- `file` is a `.css` file inside the package, relative to its root. A path with `..`, a leading slash or a backslash is refused at enable.

An insertion's id is the part of its key after the plugin's code and a dot, so a key `billboard.top` is `insertion:top`. An id must be lower-case letters, digits and hyphens to be declared.

## Where the file comes from

Build the CSS before you publish the package. Teme doesn't build anything on the server. At deploy, `teme:plugins:link` copies each enabled plugin's declared stylesheets into the public folder and writes a manifest of their content hashes. Each copy is wrapped in `@layer teme.plugins`, so a theme's own rules win over it. A sheet that imports another sheet, or declares a namespace, is refused. A file that is a link, or sits behind one, is refused. A disabled plugin's copies are removed.

## What the page does

When a page draws a card or a page insertion of a plugin, Teme records that output. The page's head then links that plugin's stylesheet for the output, with the copy's content hash as its version. A body insertion's sheet is linked in the head too, because the head is written after the page's insertions are known. A stylesheet for an output the page does not draw is not linked.

## Styling in the right place

Write the sheet's rules as they are: the copy is already inside `@layer teme.plugins`. Read Teme's design tokens as `--teme-*` variables, as core's own cards do. A theme can switch a plugin's stylesheet off by naming it in the theme's manifest under `plugin_styles.off`, as `code:card:<id>` or `code:insertion:<id>`, and then styles that output itself.

## Not in this version

- Scripts. A plugin's scripts are not loaded by this mechanism yet.
- A stylesheet for the whole page. Only cards and insertions have one.
