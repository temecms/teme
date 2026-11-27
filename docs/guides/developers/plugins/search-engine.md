---
title: A search engine
type: how-to
audience: plugin
summary: How a plugin answers the site's searches from its own engine, such as an outside index, with core's search standing in whenever the engine fails or is slow.
rests_on: [app/Domain/Search/RoutedSiteSearch.php, packages/teme-contracts/src/Search/HasSearchEngine.php, packages/teme-contracts/src/Search/SiteSearch.php, app/Domain/Plugins/Slots]
---

# A search engine

A plugin can replace core's search with its own engine, such as an outside index. It fills the `search` provider slot, so the administrator chooses it in Plugins › Provider choices.

## Declaring it

The plugin lists the slot under `provides`:

```json
"provides": ["search"]
```

Its class implements `HasSearchEngine`, whose `engine()` returns a `SiteSearch`. The engine answers each search with a `SearchHits`. Each hit is a content ID and a snippet. The site checks each item's visibility before showing it, so an index that still holds a draft never shows it.

## When core answers instead

If no engine is chosen, or the engine fails, or it takes longer than 300 milliseconds to answer, core's PostgreSQL search answers. The engine's failure is recorded once, and the visitor sees the normal results.

## Cached pages

A search page draws the lists of the types it searches, and it is purged when those lists change, whichever engine answered it.
