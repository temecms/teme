---
title: Addresses, listings and bodies in a template
type: how-to
audience: theme
summary: The values and tags a template can read for the site's own addresses, breadcrumbs, a page's siblings, a type's items, a listing's totals, reading time, search sections, the body's blocks, and for knowing which region a card is drawn in.
rests_on: [app/Domain/Site/SiteAddresses.php, app/Domain/Site/ViewModels, app/Domain/Site/Breadcrumbs.php, app/Domain/Site/PageFamily.php, app/View/Components/Teme/Items.php, app/Domain/Regions/RegionDrawing.php, app/Domain/Cards/DocumentRenderer.php, resources/css/cards, docs/reference/theme.md, themes/default]
---

# Addresses, listings and bodies in a template

This page covers what a template can read beyond the basics, so it needn't count, parse or hard-code. Every value is worked out for the page's language and for what a reader may see: nothing unpublished, private or in the trash reaches a template, and every address is made from the site's current address when the page is drawn, so none is stored. Each type and field is listed in the [theme reference](../../../reference/theme.md). It describes `main` today.

## Theme settings a template reads

`$themeSettings` is the active theme's own settings, key to value, without its menu places. An `image` setting is `{url, alt}` or `null` when none is chosen; a `menu` setting is the menu's tree (each item with `label`, `url`, `current`, and the rest) or `null`; a `links` setting is a list of `{label, address}`; numbers, text, urls and the older types keep the stored value. The list is built once per page.

## The posts page and each archive's address

`$site` holds the address of the page that lists the latest posts, and of every public type's archive page, so a menu or a "see all" link never writes `{{ $site->url }}blog/` by hand:

- `$site->postsUrl` is the posts page's address when Settings › Reading names one, and the home page's while the home page shows the latest posts, in the page's language. It is an empty string while a static front page is shown and no posts page is live in the language, since no page lists the latest posts then, so test it before you link to it.
- `$site->archives` maps each public type's key to its archive page's address, in the page's language. The first public type's, which lists the latest posts, is `$site->postsUrl`, and has no key while that is empty; a type with no archive page has no key. Both are read from the database only when a template reads them, so a page that never does pays nothing.

```blade
<a href="{{ $site->postsUrl }}">{{ __('All posts') }}</a>
<a href="{{ $site->archives['film'] ?? $site->postsUrl }}">{{ __('All films') }}</a>
```

## Breadcrumbs through a type's archive

For a type that has an archive page of its own and doesn't nest, `$page->breadcrumbs` runs home, the archive, then the item, so a directory of plugins reads Home / Plugins / Sharely. Types that nest keep their parents, and the posts, whose archive is the latest posts, keep their category. The structured data's breadcrumb list is made from the same steps.

```blade
@foreach ($page->breadcrumbs ?? [] as $crumb)
    <li>@if ($loop->last){{ $crumb->name }}@else<a href="{{ $crumb->url }}">{{ $crumb->name }}</a>@endif</li>
@endforeach
```

## Siblings, previous and next

For a type that nests, such as pages, `$page` holds the items under the same parent beside the ones it already had:

- `$page->siblings` lists the published items of its type under the same parent, the page itself among them, in menu order, at most 100. Top-level pages are siblings of each other.
- `$page->previous` and `$page->next` are the sibling before and after it in menu order, or `null` at the ends. They are right however many siblings there are.

Each is an item link with its `title`, `url` and `type`. A type that doesn't nest has none. They are read, and the page set aside when a sibling changes, only when a template reads them, so an article template that doesn't use them costs nothing for them.

```blade
@foreach ($page->siblings as $sibling)
    <a href="{{ $sibling->url }}" @if ($sibling->url === $page->url) aria-current="page" @endif>{{ $sibling->title }}</a>
@endforeach
```

## A type's items, from any template

`<x-teme::items type="docs" :limit="20" />` lists a public type's published items in the page's language without a region. `limit` is 1 to 100 and 10 by default; `order` is `newest` (the default) or `menu`, the order a nesting type is arranged in. With no `partial`, it draws a plain list of links. Name one of your theme's own views, such as `partial="partials.doc-list"`, and it draws that with `$items`, each a listing item with its title, address, excerpt, image, authors and fields. It draws nothing for a type that doesn't exist or isn't public. The page is set aside when an item of that type is added, changed or taken off, as any listing is.

```blade
<x-teme::items type="docs" :limit="20" partial="partials.doc-list" />
```

## A listing's exact total

`$page->total` on a listing is the exact number of items it holds across all its pages, for a category, a tag, an author, a date and a type's archive, and the latest posts page: `{{ $page->total }} stories`. It follows the listing's own language and visibility. It is `null` where core doesn't count them all, which is only a listing deeper than the last page core serves, so show it only when it is there. On the latest posts page it counts the lead and the pinned posts as well as the feed.

```blade
@if ($page->total !== null)
    <p>{{ trans_choice(':count story|:count stories', $page->total) }}</p>
@endif
```

## Reading time on every listing item

Each listing item has `readingMinutes`, the same estimate its article has, read from the stored word count, so no body is loaded. It is `null` for a password-protected item, whose length isn't shown any more than its words are. Public listings take no sort order, so there is no shortest-read sort.

```blade
@if ($item->readingMinutes) <span>{{ $item->readingMinutes }} {{ __('min read') }}</span> @endif
```

## Search sections and their counts

A search page's `$page->search` says which section is chosen and how many results each section holds across every page, not only the one shown. A section is a content type with matches. The counts stop at the same limit the total does.

- `section` is the chosen type's key, or `null` for every type; one that isn't a searchable type is ignored.
- `sections` lists each type with matches, in order: its `type`, its `label`, its `count`, its `current`, and its `url`, which filters the search to that type and keeps the query and the language.
- `allUrl` is the search with no section chosen, and `allTotal` the results in every section together.

The page's own `pagination` keeps the chosen section on every page. The search takes it from the address, as `?s=heron&section=film`.

```blade
@foreach ($page->search->sections as $section)
    <a href="{{ $section->url }}">{{ $section->label }} ({{ $section->count }})</a>
@endforeach
```

## The body as blocks

`$page->bodyHtml` stays one string of HTML. `$page->blocks` is the same body as a list of its top-level blocks, so a theme made of sections doesn't parse the HTML. Each block has its `type`, such as `paragraph`, `heading`, `horizontalRule` or a card's name, its `attrs`, such as a heading's `level`, and its `html`, drawn by the renderer `bodyHtml` is drawn by, so the blocks joined are the body. Insertions that plugins place in the body aren't blocks. The blocks are drawn the first time a template reads `$page->blocks`, and the cards in them are recorded for the page then, so a theme that draws only the blocks still gets its cards' styles; a template that reads only `bodyHtml` never draws them twice.

```blade
@foreach ($page->blocks as $block)
    @if ($block->type === 'horizontalRule') </section><section> @else {!! $block->html !!} @endif
@endforeach
```

Block HTML is core's own, safe to print with `{!! !!}`.

## Which region a card is drawn in

A view that draws a core card, such as your `views/cards/featured-posts.blade.php`, gets `$region`, the region's slug as a string, beside `$heading`, `$items` and `$images`, and `$regionInfo`, the region's `slug`, `theme`, `size`, `width` and `height`. A card drawn outside a region has an empty slug. Your own cards' views get the same `$regionInfo`; their `$region` stays the same object as before, which prints as the slug too.

```blade
@php($tag = $region === 'sidebar' ? 'ol' : 'ul')
<{{ $tag }}>@foreach ($items as $item)<li><a href="{{ $item->url }}">{{ $item->title }}</a></li>@endforeach</{{ $tag }}>
```

## Does a region have anything to draw

`@regionHasContent('sidebar')` is true when `<x-teme::region name="sidebar" />` would draw anything: the region's published content, or something a plugin places before or after it. It uses the region the component draws, so asking costs no second read. Use it for a band that belongs to the content, such as a heading or an advertisement's label. It is false for a region the theme doesn't declare, which has nothing to draw.

```blade
@regionHasContent('sidebar')
    <aside class="band"><h2>{{ __('Elsewhere') }}</h2><x-teme::region name="sidebar" /></aside>
@endregionHasContent
```

## Styling a card with ordinary CSS

Core's own card stylesheets sit in the `teme.core` cascade layer, below your theme's. Any rule you write in `@layer teme.theme` restyles a card without `!important` and without a stronger selector, and a rule of yours that matches a card's elements, such as a reset for `figure` or `a`, now applies to it too.
