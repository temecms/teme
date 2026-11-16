---
title: Content on every page
type: how-to
audience: plugin
summary: How a plugin with pages.insert places markup in the head, at the top and bottom of every page, between list items, around a theme's regions and fixed to the screen's edge, and what core cleans, caps and leaves out.
rests_on: [packages/teme-contracts/src/Insertions, app/Domain/Site/Insertions, docs/plans/plugin-sockets-2.md]
lands_with: sockets2/inserts
---

# Content on every page

A plugin can place its own markup on every public page, not only inside articles: an ad network's script in the head, a banner at the top, an ad between the items of a list, a box after a theme's region, a bar fixed to the bottom of the screen. Core decides where each piece can go, cleans it, and leaves it out wherever it doesn't belong. The theme says where; the plugin says what.

This page describes the code on the `sockets2/inserts` branch. The contracts are in `teme/contracts`, under its Insertions namespace, from Teme 0.9.0. For content between an article's paragraphs, see [Content inside articles](insertions.md).

## Ask for pages.insert

Add `pages.insert` to the manifest's `capabilities`. People enabling the plugin see it as "Add content to every page of your site: in the head, at the top and bottom of the page, between items on lists, in the theme’s regions, and fixed to the screen’s edge". Without it the plugin is never asked, whatever its class implements.

## Implement PageInsertionContributor

The class named under `plugin` in the manifest implements `PageInsertionContributor` beside `Plugin`. Teme asks the running plugin once per page with `pageInsertions(PageFacts $page)`, and it answers a list of `PageInsertion` values, or an empty list to add nothing to this page.

`PageFacts` tells the plugin enough to choose:

| Fact | What it holds |
|---|---|
| `kind` | `home`, `article`, `latest`, `archive`, `term`, `author`, `date` or `search` |
| `path` | The page's address, relative, such as `/category/news/` |
| `language` | The page's language tag |
| `type` | The item's content type on an article, else null |
| `contentId` | The item's ID on an article, else null |
| `termIdsByTaxonomy` | The article's terms, or the term a term archive lists |
| `authorIds` | The article's authors, or the author an author archive lists |
| `pageNumber` | 1 on a list's first page, 2 on the next, and so on |

Answer from those, and do nothing slow. Each plugin has 100 ms to answer and all of them together 250 ms; one that throws or runs over is recorded as its plugin's fault and the page is drawn without it, and once the page's budget is spent the plugins not yet asked are skipped and recorded too.

A `PageInsertion` holds a key, a `PagePosition` and the HTML. Keys follow the same rule as article insertions. Each key is drawn once per page: when two plugins give one key, the first by plugin code wins.

## Positions

| Constructor | Where it draws |
|---|---|
| `head()` | In the page's head |
| `bodyStart()` | At the top of the body, after the skip link |
| `bodyEnd()` | At the bottom of the body |
| `afterItem(n)` | After the nth item of a list, 1 to 100; never after the last |
| `everyItems(n, max)` | After every nth item, at most `max` times, at most 5; never after the last |
| `region(slug, where)` | Before or after a region the active theme declares; `where` is `before` or `after`, `after` by default |
| `fixed(edge, width)` | Fixed to the `bottom`, `top`, `left` or `right` edge of the screen; a side rail is 160 or 300 wide |

A position the page doesn't have draws nothing: a list position on an article, a region the theme doesn't declare. A position no page could have, such as item 0 or a rail 200 wide, is refused when it is made.

Between items needs the theme: a list template calls `<x-teme::between-items :after="$loop->iteration" :last="$loop->last" />` inside its loop. Core's default theme does; `php artisan teme:theme:check` warns about a theme whose lists don't.

## The head

The head keeps only what an ad or analytics network needs there:

- a `<script src>` from an `https` address, with `async`, `defer`, `crossorigin` and `type="module"`; its origin joins the page's `script-src`;
- a `<meta name content>`, such as `google-adsense-account`, except the names the page owns (description, robots, viewport, theme color, the social ones and the like);
- a `<link rel="preconnect">` or `<link rel="dns-prefetch">` to an `https` address;
- an inline script someone approved (below).

Anything else in a head insertion is removed: stylesheets, iframes, `http-equiv`, `<base>`, and every other attribute.

## The top, the bottom, lists and regions

These are cleaned exactly as article insertions are; see [What core cleans](insertions.md#what-core-cleans).

## Fixed to an edge

Core draws what is fixed, once, at the end of the body, as an `<aside>` with a label and a close button. One per edge per page, the first by plugin code. A bar at the top or bottom is full width and at most `min(30vh, 250px)` high, and the page is padded so nothing is covered at the end. A side rail shows only on a screen wide enough for the theme's content and the rail on both sides: `content + 2 × (width + 48)` pixels. A visitor's close keeps it closed for the browser tab's session. Nothing moves or animates.

The label is your manifest's `contributes.fixedLabel`, 1 to 24 characters, such as Sponsored; without one it reads Advertisement. A theme can refuse fixed content with `"fixed": false` in its manifest.

## Hosts your content loads from

An ad's iframe or script often loads more from other hosts. Declare them in `contributes.csp`, by kind:

```json
"csp": { "frame": ["frames.example.net"], "connect": ["*.example.net"] }
```

People enabling the plugin see them ("Lets the content it inserts load from: …") and agree to them with the rest; a new host asks again. A page adds them to its policy only where your plugin drew something. `csp` is read only with `content.insert` or `pages.insert`.

## Inline scripts

An inline script in what a plugin inserts is removed, unless a person approved its exact text for that plugin. Ask with the `InlineScripts` door: take it in your plugin's constructor and call `request(name, script)` while a person is using the plugin, for instance when they save its settings. The request waits on **Settings › Site code** until someone who may change site code approves or refuses it, with their password asked again; they can revoke it there later. At most 20 wait per plugin. A plugin can never approve.

Once approved, the script is kept byte for byte wherever your insertions carry it, and its hash joins the page's `script-src`. While the host has switched site code off, no plugin's inline script is kept.

## The page cache

A public page is cached whole, for everyone, under the `page-insertions` tag. Core purges those pages after it enables or disables a plugin with `pages.insert`, and after someone saves its settings, so a plugin whose rules live in its own settings needs no `notify()`. When rules change any other way, call `InsertionsChanged::notify()` inside an Operation.

Because the page is cached whole:

- a random or weighted pick, and anything chosen per visitor, is frozen into the cached copy until it is purged;
- choose per device with CSS media queries, never on the server;
- choose per visitor only in the browser, with a script your plugin serves from its own route or an approved inline script.

## Where nothing is asked

Plugins are never asked on a page not found, an error page, a preview, a password page, the feeds, the Markdown version of a page, or the read API, nor on an article whose editors turned insertions off.

Next: [Content inside articles](insertions.md).
