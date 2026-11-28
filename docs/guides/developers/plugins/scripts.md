---
title: Scripts for a plugin's output
type: how-to
audience: plugin
summary: How a plugin ships a built script for its cards and its page insertions, so Teme serves it and links it only on a public page that shows that output, and only when the site allows the plugin to run code in visitors' browsers.
rests_on: [app/Domain/Plugins/Scripts, app/Http/Site/PluginScriptController.php, app/Domain/Plugins/Styles/PluginStylesheet.php, app/View/Components/Teme/Document.php, packages/teme-contracts/schema/teme-plugin.schema.json]
---

# Scripts for a plugin's output

A card or a page insertion that needs behaviour in the visitor's browser needs a script, and the supported way to ship one is to declare it here. A script declared here is built ahead of time and shipped in the package. Teme serves it from its own address, and a page links it only when that page draws the output it belongs to. A page with no plugin output loads none of them.

Writing an inline `<script src>` into an insertion's markup also works today, but a declared script is the supported way: Teme checks it, serves it under the site's security policy, and lets a theme switch it off.

## Asking for the permission

A plugin's scripts run in visitors' browsers, so the plugin asks for the `site.scripts` capability in its manifest. An administrator sees it at consent as "run its own code in visitors' browsers on the pages that show its output". A plugin that declares a script without asking for the capability is refused at enable.

## Declaring it

The plugin lists its scripts under `contributes.scripts`, one per output:

```json
"scripts": [
  { "output": "card:gallery", "file": "dist/gallery.js" }
]
```

- `output` is `card:` and the card's id, or `insertion:` and the insertion's id. The card must be one the plugin declares. Each output has at most one script, and there are at most 40 entries.
- `file` is a `.js` file inside the package, relative to its root. A path with `..`, a leading slash or a backslash is refused at enable.
- The file must be self-contained. Teme serves exactly the one file it names, with no chunks or imports beside it.

## How it is served

Teme serves each declared script at `/_teme/plugins/{code}/{file}` as `text/javascript`, with `X-Content-Type-Options: nosniff` and a long cache life. Its address carries a hash of its content, so a changed file gets a new address. Anything not declared, a disabled plugin, a path outside the package and a symbolic link all answer 404.

## How it is linked

A public page links a script as `<script type="module" defer>` after the body's end, and only when all of these hold:

- the page draws the script's output;
- the plugin is enabled and asks for `site.scripts`;
- the active theme does not switch the script off.

Scripts come from Teme's own origin, so the site's `'self'` rule admits them. There is no inline script and no `eval`. A plugin that needs to load from another origin names it under its manifest's `csp`, as before, and the administrator's consent shows it.

## Size

A plugin's scripts on one page are measured compressed. Past 20 KB, Teme records a fault for the plugin once for each version, and the Plugins screen warns on its row. It never blocks a script for its size.

## A theme switching one off

A theme can switch a plugin's script off by output, so it does without the script on that output:

```json
"plugin_scripts": { "off": ["gallery:card:gallery"] }
```

Each entry is the plugin's code and the output, `code:card:<id>` or `code:insertion:<id>`.

## Size on the Plugins screen

The Plugins screen shows each plugin's declared scripts as their compressed size, on its row, whether or not the plugin is enabled. Past 20 KB it warns on the row. It never stops the plugin from running.

## What is not in this release

A script's size is measured per plugin, not per script, and only the scripts it declares are counted. The size on a page is the total of the plugin's scripts that page links.
