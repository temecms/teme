---
title: Search engine plugins
type: how-to
audience: plugin
summary: How an SEO plugin adds checks beside the editor's own, a tab of its own values in Search and social, tags and structured data in the head of public pages, and sitemaps to the sitemap index, and how it reads the FAQ and Steps cards to describe them.
rests_on: [app/Domain/Plugins/Seo, app/Domain/Cards/ArticleCards.php, app/Domain/Cards/Types/FaqCard.php, app/Domain/Cards/Types/StepsCard.php, packages/teme-contracts/src/Editor, packages/teme-contracts/src/Head, packages/teme-contracts/src/StructuredData, packages/teme-contracts/src/Sitemaps, packages/teme-contracts/schema/teme-plugin.schema.json, docs/guides/developers/examples/plugins/seo-sockets]
---

# Search engine plugins

Core already writes a page's title, description, social tags, canonical address, structured data and sitemaps. An SEO plugin adds to that through four sockets, each declared in `teme-plugin.json` and answered by one interface:

- checks shown beside the editor's own, from `HasEditorChecks`;
- a tab of the plugin's own values in the editor's Search and social box, which needs no code;
- tags and structured data in the head of public pages, from `HasHeadMeta` and `HasStructuredData`, and the page's title from `HasPageTitle`;
- sitemaps in the site's sitemap index, from `HasSitemaps`.

Each socket is asked within a time budget, only reads, and fails safe. A plugin that throws, answers too late or answers out of bounds never stops anyone typing, saving or loading a page. Its part is left out, and the failure is recorded as a fault under the plugin, where the plugin's row on the Plugins screen shows it. A call is never stopped part way: core times it, and drops an answer that came back late. A plugin that runs over the budget for a page's tags, its structured data or a sitemap is asked nothing more on that socket for the rest of the request, so the page or sitemap never waits on it twice, and the fault is recorded once.

The handbook's example plugin answers all four, in `docs/guides/developers/examples/plugins/seo-sockets`.

## Checks beside the editor's own

Declare each check under `editorChecks`, at most 20. The plugin must ask for `content.read`.

```json
"editorChecks": [ { "id": "keyphrase", "label": "Focus keyphrase", "types": ["post"] } ]
```

Each `id` is 2 to 24 lower-case letters, digits and hyphens, starting with a letter. The `label` is at most 60 characters, and `types` names the content types the check applies to. Then implement `HasEditorChecks`:

```php
// docs/guides/developers/examples/plugins/seo-sockets/src/SeoPlugin.php
    public function checkItem(CheckedItem $item): array
    {
        $focus = $item->own['focus'] ?? null;

        if (! is_string($focus) || $focus === '') {
            return [new CheckResult('keyphrase', CheckResult::IMPROVE, 'Add a focus keyphrase.', ownField: 'focus')];
        }
```

Core calls `checkItem()` on the server for the version of the item being edited, each time the editor saves or keeps a version, never on each keystroke. `CheckedItem` holds the item's ID, the version's ID, its type and language, its title and slug, its body as Tiptap JSON and as plain text, its fields by handle, its terms by taxonomy, the plugin's own Search and social values in `own`, and the person's ID, language and plugin permissions.

Return a list of `CheckResult`, at most 40. Each names a check you declared for the item's type, a state (`CheckResult::OK`, `CheckResult::IMPROVE` or `CheckResult::PROBLEM`) and a message of at most 300 characters. It may give a `label` of its own, and a place to go: `field`, the handle of one of the item's fields, such as `title`, `excerpt` or `seo_title`, or `ownField`, the key of one of the plugin's own fields. The editor draws the results after its own checks, under the plugin's name, and a result with a place to go is a button that puts the cursor there. No plugin JavaScript runs.

The call has 250 milliseconds. A result for a check that isn't declared for the type, or anything that isn't a `CheckResult`, is left out and recorded. When the call throws or runs late, the editor says the plugin's checks couldn't run this time.

## A tab in Search and social

Declare `searchSection` and core draws a tab of the plugin's fields in the Search and social box, marked as the plugin's. No code is needed.

```json
"searchSection": { "label": "Keyphrase", "types": ["post"], "position": "after-search",
    "fields": [ { "key": "focus", "type": "text", "label": "Focus keyphrase", "maxLength": 60, "summary": true } ] }
```

The `label` is at most 24 characters. A `description` of at most 160 characters may follow it. The `position` is `after-search`, `after-social` or `last`, the default. Give one to eight fields. Each has a `key` of lower-case letters, digits and underscores, starting with a letter, a `label` of at most 40 characters, an optional `description` of at most 200, and a type:

- `text`, at most 200 characters, or the `maxLength` you give, up to 2,000;
- `textarea`, at most 2,000 characters, or the `maxLength` you give;
- `url`, an `http` or `https` address on another site. An address on this site is refused, because the site writes its own addresses at render so they follow it when it moves. Join a path to `HeadPage`'s `home` instead;
- `number`, within an optional `min` and `max`;
- `boolean`;
- `select`, one of its `options`, an object of values and labels.

A field marked `summary` is shown in the tab's one-line summary when the box is closed. Without one, the first field is.

The values are saved with each version of the item, in its fields under `plugin_` and the plugin's code, with hyphens as underscores: `plugin_seo_sockets` for the example. Each is checked as a setting of its type is, and a value that doesn't fit refuses the save, naming the field. An empty value isn't stored. The plugin reads its values as `own` in `CheckedItem` and `HeadPage`. While the plugin is off, its tab isn't shown, and its values stay with the item untouched.

## Tags in the head

A plugin that asks for `pages.head` and implements `HasHeadMeta` adds tags to the head of public pages:

```php
// docs/guides/developers/examples/plugins/seo-sockets/src/SeoPlugin.php
    public function headMeta(HeadPage $page): array
    {
        return ($page->own['noindex'] ?? false) === true ? [HeadMeta::name('robots', 'noindex, follow')] : [];
    }
```

A tag is a `HeadMeta`, made with `HeadMeta::name()` or `HeadMeta::property()`: a name or property and its content, nothing else. Core escapes both and never writes a script. A key is letters and digits, with `:`, `.`, `_` or `-` between them, at most 100 characters, and the content is at most 1,000 bytes. Give at most 20 tags a page. A plugin's tag replaces core's tag of the same name or property.

The `robots` tag is the exception: it is laid over core's, and can only make the page more private. Give any of `index`, `noindex`, `follow`, `nofollow`, `noarchive`, `nosnippet` and `max-image-preview:` with `none`, `standard` or `large`. Your `noindex`, `nofollow`, `noarchive` and `nosnippet` are added to core's directives, and your image preview is kept where it is smaller than core's. Your `index` and `follow` change nothing, so a page core keeps out of search, such as an item of a type hidden from search, stays `noindex`. A value with any other directive is refused and recorded, and core's stands.

`HeadPage` says which page is drawn: its `kind` (`article`, `home`, `latest`, `archive`, `term`, `author`, `date` or `search`), its path and full address, the site's address in `home`, its language, and for an article its type, ID, title, the plugin's own values, and its FAQ and Steps cards. Later pages of a list carry their `pageNumber`.

## The page's title

A plugin that asks for `pages.head` and implements `HasPageTitle` can answer the title of a public page, the words in `<title>`. Core asks `pageTitle(HeadPage $page, string $coreTitle)` with the same `HeadPage` the head tags get and the title core would write, which comes from the item's SEO title or from Search and social's templates. Return a title, or null to leave it to core.

The answer is plain text of 1 to 200 characters. An empty answer, or one over 200 characters, is refused: it is recorded as the plugin's fault and core's title is used. A title is asked within 100 ms, and a plugin that runs over is dropped for the rest of the request, with its slowness recorded once, as for the head tags.

The title the browser shows, the social title and the structured data's name stay one and the same: the plugin's title becomes all three. The exception is an item whose editor typed a social title of their own, which keeps it for sharing.

Only one plugin answers a page. When several are enabled, the first enabled that gives a title is the one; a plugin that answers null leaves the page to the next.

## Structured data

A plugin that asks for `pages.head` and implements `HasStructuredData` adds nodes to the page's JSON-LD graph. Each is a `GraphNode`: an `@id` that is an absolute `http` or `https` address, an `@type`, and plain values, with no `@context`.

```php
// docs/guides/developers/examples/plugins/seo-sockets/src/SeoPlugin.php
        foreach ($page->faqs as $faq) {
            $questions = [];

            foreach ($faq->pairs as $pair) {
                $questions[] = ['@type' => 'Question', 'name' => $pair->question, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $pair->answerHtml]];
            }

            $nodes[] = new GraphNode(['@id' => $faq->nodeId, '@type' => 'FAQPage', 'mainEntity' => $questions]);
        }
```

Core merges the nodes into its graph by `@id` under its own rules, so a node with the `@id` of one of core's adds to it. A node is left out, and recorded, when its `@id` isn't on this site, or when it uses a type or property neither core's list nor the plugin's `structuredData` declaration names. Declare the schema.org types and properties you use beyond core's:

```json
"structuredData": { "types": ["FAQPage", "Question", "Answer"], "properties": ["mainEntity", "acceptedAnswer", "text"] }
```

Give at most 50 nodes a page. Core's own checks on what search engines require still apply to them.

## The FAQ and Steps cards

Core draws the FAQ and Steps cards but adds no markup for them, so that one plugin decides how they are described. `HeadPage` carries them in the order they stand in the body:

- each `FaqCard` has its ID, a `nodeId` to use as its node's `@id`, and its pairs; each `FaqPair` has the question and the answer as plain text, and the answer as HTML of paragraphs, lists, links and emphasis;
- each `StepsCard` has its `nodeId`, its summary, its total time as an ISO 8601 duration or null, its materials and tools, and its steps; each `Step` has its position from 1, its title, its text and HTML, its image's address or null, and its own address on the page.

Every address in them is absolute.

## Sitemaps

A plugin that asks for `sitemaps` declares each sitemap, at most four, and implements `HasSitemaps`, which returns a `PluginSitemap` for each by its `id`:

```json
"sitemaps": [ { "id": "landing", "label": "Landing pages" } ]
```

Core lists each in the sitemap index as `sitemap-plugin-{code}-{id}.xml`. `starts()` returns the key each later page starts at, if the sitemap has more than one page, and each is listed as `sitemap-plugin-{code}-{id}.{key}.xml`. A key is 1 to 26 lower-case letters and digits. `entries()` is asked for the page that starts at a key, or at the beginning for null, and returns at most 1,000 `SitemapEntry`:

- `SitemapEntry::path()`, a path on this site and, optionally, when it last changed;
- `SitemapEntry::item()`, an item by ID, listed at its address only while it is published and listed, with its own last change. As in core's sitemaps, an item of a type hidden from search, or of a type that isn't public, is left out.

Each call has two seconds. Pages are cached and purged as core's are: when a listed item changes, when the plugin is turned on or off, and when its settings are saved.

## What the person agrees to

When the plugin is turned on, the Plugins screen and `teme:plugins:enable` say in plain words what each part may do: check the types named and show what it finds, add a tab to Search and social whose values are saved with each item, add tags and structured data to public pages, and add its sitemaps to the index, naming them.
