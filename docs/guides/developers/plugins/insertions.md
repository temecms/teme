---
title: Content inside articles
type: how-to
audience: plugin
summary: How a plugin with content.insert places markup between an article's blocks, such as ads, and what core cleans, caps and leaves out.
rests_on: [packages/teme-contracts/src/Insertions, app/Domain/Site/Insertions, docs/architecture/plugins.md]
---

# Content inside articles

A plugin can place its own markup inside published articles: an ad after the second paragraph, a newsletter box before the last one, a related link after the first subheading. Core decides where each piece can go, cleans it, and leaves it out wherever it doesn't belong. The plugin never edits the article.

The contracts are in `teme/contracts`, under its Insertions namespace, from Teme 1.0.0.

## Ask for content.insert

Add `content.insert` to the manifest's `capabilities`. People enabling the plugin see it as "Add content inside your articles, such as ads". Without it the plugin is never asked, whatever its class implements.

## Implement InsertionContributor

The class named under `plugin` in the manifest implements `InsertionContributor` beside `Plugin`. Teme asks the running plugin itself, once per article page, with two methods:

- `precedence()` answers `"plugin"`. Core's own contributors answer `"site"` or `"core"`, and a plugin's entry wins a key they share.
- `insertions()` takes the article's `ArticleFacts` and answers a list of `Insertion` values, or an empty list to add nothing to this article.

`ArticleFacts` tells the plugin enough to choose: the item's ID and content type, its term IDs by taxonomy, its language, its authors' IDs, and its word and paragraph counts. Answer from those; do nothing slow, because the page waits for the answer.

An `Insertion` holds a key, a `Position` and the HTML. The key is lower-case letters, digits, dots, hyphens and underscores, up to 100 characters, such as `ads.after-2`. Each key is drawn once per article: when two contributors give one key, the first by precedence wins. A contributor that throws is recorded as its plugin's fault, and the article is drawn without it.

## Positions

Each `Position` is made by a named constructor. Only the article's top-level paragraphs and headings count, so a point never falls inside a list, a card, a quote, a table or a code block. A position the article doesn't reach draws nothing.

| Constructor | Where it draws |
|---|---|
| `beforeContent()` | Before the first block |
| `afterContent()` | After the last block |
| `afterParagraph(n)` | After the nth top-level paragraph |
| `everyParagraphs(n, max)` | After every nth paragraph, at most `max` times |
| `beforeHeading(level, nth)` | Before the nth heading of that level, the first by default |
| `afterHeading(level, nth)` | After the nth heading of that level, the first by default |
| `nearEnd()` | Before the last paragraph, in an article of three paragraphs or more |

## The cap

`everyParagraphs()` draws at most ten times in one article, the value of `Position::EVERY_PARAGRAPHS_MAX`. A larger `max` is held to ten rather than refused. The cap is per insertion: a plugin that gives several repeating insertions under different keys can draw more in all, so keep to one.

## What core cleans

Every insertion is cleaned before it is drawn, in HTML, SVG and MathML alike:

- a `<script>` without `src` is removed, with its body;
- every attribute starting with `on`, and `srcdoc`, is removed;
- an `href`, `src`, `xlink:href`, `action` or `formaction` that starts with `javascript:` or `data:text/html` is removed, compared as a browser reads it;
- an SVG `animate` or `set` that would change an `href` is removed.

Everything else is kept as written: a `<style>`, an ad network's `<ins>` with its `data-*` attributes, a `<script src>`, an `<iframe>` and an `<img>`. An inline script is kept only when a person approved its exact text for your plugin; see [Inline scripts](page-insertions.md#inline-scripts).

A `<script src>`, an `<iframe>` and an `<img>` are kept. Their `https` origins are added to that page's content security policy, under `script-src`, `frame-src` and `img-src`, so an ad network's script loads without the site allowing it everywhere.

## Where nothing is drawn

Insertions are drawn only on the public web page of a published article. A preview, the feeds, the Markdown version of a page and the read API never carry them.

Editors can turn them off on one item with the built-in field **No inserted content**, handle `insertions_off`. Stored values use the field's ID, `01K5Z0F1ED00000000000000XF`. Every content type that supports search and social has it. On such an item no contributor is asked at all.

## When your rules change

Pages that asked for insertions are kept in the page cache under the `insertions` tag. Core purges them after it enables or disables a plugin that has `content.insert`, and after someone saves that plugin's settings, so a plugin whose rules live in its own settings needs no `notify()`.

When your rules change any other way, ask for `InsertionsChanged` in your plugin's constructor and call `notify()` inside an Operation. Core purges those pages, and the pages of plugins with `pages.insert`, after that Operation commits, never before. Outside an Operation it does nothing useful and may refuse.

A page is cached whole, for everyone. A random or weighted pick, and anything chosen per visitor, is frozen into the cached copy until it is purged. Choose per device with CSS media queries, never on the server, and per visitor only in the browser, with a script your plugin serves from its own route or an approved inline script.

`ArticleFacts` also carries the article's address, relative, as `path`, from Teme 1.0.0.

## ads.txt

Ad buyers read `/ads.txt`. It belongs to the site, not to a plugin: an administrator writes it under **Settings › Site code**, in the ads.txt box, up to 64 KB. Teme serves it as plain UTF-8 text, and answers 404 while it is empty. The box stays editable even when the host has switched site code off. A plugin has no socket that writes it; tell your users what lines to paste there.

Next: [Content on every page](page-insertions.md), [Hooks](../concepts/hooks.md) and [Plugin settings and secrets](settings.md).
