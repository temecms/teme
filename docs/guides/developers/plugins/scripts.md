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

Teme serves each declared script at `/_teme/plugin-scripts/{code}/{file}` as `text/javascript`, with `X-Content-Type-Options: nosniff` and a long cache life. Its address changes with the plugin's version, so ship a changed script in a new version: a file edited in place keeps its old address, and browsers keep the copy they hold. Anything not declared, a plugin that is disabled or never agreed to `site.scripts`, a path outside the package and a symbolic link all answer 404. A refused script is recorded as the plugin's fault once per script, not once per request.

## How it is linked

A public page links a script as `<script type="module" defer>` after the body's end, and only when all of these hold:

- the page draws the script's output;
- the plugin is enabled and asks for `site.scripts`;
- the active theme does not switch the script off.

Scripts come from Teme's own origin, so the site's `'self'` rule admits them. There is no inline script and no `eval`. A plugin that needs to load from another origin names it under its manifest's `csp`. Those origins are allowed when the plugin asks for `site.scripts`, and they join the page's policy for the directives the manifest names, only on pages that link one of its scripts. The administrator's consent shows them.

## A theme switching one off

A theme can switch a plugin's script off by output, so it does without the script on that output:

```json
"plugin_scripts": { "off": ["gallery:card:gallery"] }
```

Each entry is the plugin's code and the output, `code:card:<id>` or `code:insertion:<id>`.

## Size

A plugin's scripts on one page are measured compressed. Past 20 KB, Teme records a fault for the plugin once for each version. The Plugins screen shows each plugin's declared scripts as their compressed size, on its row and in its consent dialog, whether or not the plugin is enabled, and warns past 20 KB. It never stops the plugin from running.

## Traps

- A script that ends with a `//# sourceMappingURL=` line asks the browser for a `.map` file. Teme serves only the declared file, so that request answers 404. Build without the source-map comment.
- A card drawn inside a lazy region fragment gets no script, just as it gets no stylesheet. The script is linked only on a page where the card is drawn in the page itself.

## What is not in this release

The size is measured per plugin, not per script, and only the scripts it declares are counted. The per-page fault is a report for the plugin, not a block, and the maintainers have deferred the rest of the budget work. A script can also be put in an insertion's markup directly, as before, but Teme does not check, serve or switch those, so a declared script is the supported way.
