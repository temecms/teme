---
title: Regions, site code and featured posts
type: how-to
audience: theme
summary: How a theme sets aside regions for the site's own content, where an administrator's own HTML goes, and how to draw pinned posts on the latest posts page.
rests_on: [app/Domain/Regions, app/Http/Site/RegionFragmentController.php, app/View/Components/Teme/Region.php, app/Domain/SiteCode, docs/reference/theme.md, docs/reference/theme-manifest.md, themes/default]
---

# Regions, site code and featured posts

A theme draws the site; the people who run it fill it. This page covers the three places where a theme makes room for what they choose: regions, which hold their own content between your markup; site code, their own HTML on every page; and the pinned posts that lead the latest posts page. It describes `main` today.

## Regions

A region is a spot your theme sets aside for the site's own content, such as a box after each article. You declare it; editors fill it under **Content › Regions** in the admin, where every region is on one screen, card by card, and publish it.

### Declare a region

Add each region under `regions` in `teme-theme.json`, keyed by a slug of lower-case letters, digits and hyphens. The default theme declares one: `"after-content": {"label": "After the article", "max": 5}`.

- `label` is the name editors see. It is required.
- `cards` is optional. Leave it out, or give `"*"`, and the region takes every card, as a WordPress widget area takes any widget: Teme's own, and every card an enabled plugin offers, which your theme never needs to know about. Give a list only where your design needs it, such as a front-page slot built for one Featured posts card: then the region takes text and the cards named. Name Teme's cards by name, such as `image`, `callout`, `button`, `site-code` or `featured-posts`, a plugin's by its key, `code:id`, such as `ads:ad-slot`, and a card your theme brings by its id, such as `about-me`.
- `max` is the most top-level blocks the region holds, 1 to 50; 50 when you leave it out.
- `group` is optional: the heading Content › Regions lists the region under, such as `"Ad placements"`, at most 40 characters. Regions without one are listed under Regions.
- `size` is optional: the space the region gives, width x height in pixels, such as `"300x600"` for a sidebar. Teme tells it to the cards placed there and never refuses a card for it; an ad plugin's card may list that size's ads first.
- `minWidth` is optional: the narrowest viewport, 200 to 4000 pixels, the region is drawn at. See *A region drawn only from a width*, below.

`php artisan teme:theme:check` refuses a region without a label, a card Teme doesn't know, a `max` outside that range, a `group` that isn't a name of 1 to 40 characters, a `size` that isn't two numbers joined by `x`, or a `minWidth` that isn't a whole number from 200 to 4000. A plugin's key is checked by its shape only, since the plugin may not be installed where the theme is checked. Teme holds a region's document to its declaration when it is saved and again when it is published, against the declaration as it is then.

### Draw a region

Place the component where the region belongs, naming its slug, as the default theme's single template does: `<x-teme::region name="after-content" />`.

It draws the region's published content with the same renderer as an article, and records its cards for the page's head. It draws nothing for a region that is empty or unpublished. To leave out markup that belongs around it, such as a heading or a label, wrap that markup in `@regionHasContent('after-content')` and `@endregionHasContent`; see [Addresses, listings and bodies in a template](template-data.md#does-a-region-have-anything-to-draw). Outside production it draws a note when you name a region your manifest doesn't declare. See [the region component](../../../reference/theme.md#x-temeregion) in the generated reference.

A plugin's card is drawn inside `<div class="card-plugin card-plugin-{code}-{id}" data-plugin-card="{code}:{id}">`. Plugins load no stylesheet of their own yet, so style the cards your theme expects, by those classes. See [Cards for a theme's regions](../plugins/cards.md).

Each theme's regions are its own. When the site switches themes, the old theme's regions are kept, read-only, and show again if it switches back.

### A region drawn only from a width

A half-page ad beside an article is meant for wide screens, but hiding it with CSS still loads its script on a phone. Declare the region with `minWidth` and it isn't loaded at all below that width:

```json
"half-page": { "label": "Half page", "size": "300x600", "minWidth": 1440 }
```

Place it as any region, with `<x-teme::region name="half-page" />`. The page carries an empty placeholder, `<div class="teme-region teme-region-half-page" data-region="half-page" data-teme-region-min="1440" data-teme-region-src="…">`, and none of the region's content: no markup, no card, no script. Core's own small script, which the page carries once, whatever the number of placeholders, fetches the content when the viewport is at least `minWidth` wide, once, and puts it in the placeholder, running any scripts in it. If the visitor widens the window later, it loads then. A region with nothing published draws no placeholder and fetches nothing.

What your theme needs to know:

- Style the placeholder as you style the region, by its classes. Before the content arrives it is empty, so give it the `size` the region declares (`min-height`, `width`) if the layout must not move when it fills.
- The fetched content is the region exactly as it would have been drawn in the page, in the page's language, and the page's content security policy already allows its scripts: the page notes their hashes and hosts, as it does for a region drawn in place.
- The fetch is a plain GET to `/_teme/region?name=…&lang=…`. The page cache stores each answer like a page, one for each region and language, and removes it when the region changes.
- Plugins' insertions placed before or after the region are drawn around the placeholder as usual, at every width.

### Cards your theme brings

A theme can bring its own cards for its regions, such as an author bio, declared under `cards.provides` in `teme-theme.json`, at most 12, keyed by an id of 2 to 41 lower-case letters, digits and hyphens, starting with a letter:

```json
"cards": { "provides": { "about-me": { "label": "About me", "description": "A short bio.", "icon": "user",
    "fields": [ { "key": "heading", "type": "text", "label": "Heading", "default": "About me" } ] } } }
```

`label`, `description`, `icon`, `keywords` and `fields` are a plugin card's (see [Cards for a theme's regions](../plugins/cards.md)), except that a field can't be a `choice`, whose options only a plugin's class can give. An id can't be the name of one of Teme's cards, because a region's `cards` names both the same way. The card's key is `{code}:{id}`, such as `my-theme:about-me`; editors find it in Add card under your theme's name, in every region of your theme that takes every card or names it.

Your theme draws each card with its own view, `views/cards/own/{id}.blade.php`; `teme:theme:check` refuses a card without one. The view gets `$values`, the card's fields by key with each missing one filled from its `default`, `$id`, the card's node ID, and `$region`, the region's `slug`, `theme`, `size`, `width` and `height`. The values are as an editor typed them, so print them with `{{ }}`, never `{!! !!}`:

```blade
<aside class="about"><h2>{{ $values['heading'] }}</h2></aside>
```

Teme wraps what the view draws in `<div class="card-theme card-theme-{id}" data-theme-card="{code}:{id}">` and draws nothing when it draws nothing. A theme's card is drawn only while that theme is in use, and only in that theme's regions. When the site switches themes, the regions keep its cards, Content › Regions counts them under the theme they came from, and they show again with that theme. Before a switch, Themes says which of the active theme's cards the switch hides, and in how many regions.

## Site code: the administrator's own HTML

There is no custom HTML card for a region. An administrator who needs their own HTML, such as an analytics tag or a verification meta tag, adds it under **Settings › Site code**, in one of three places: the head, the start of the body, or the end of the body, on every public page.

Your theme does nothing to carry it, as long as every template is wrapped in `<x-teme::document>`, which writes those three places with what core and plugins add there. Core adds what that code needs, its inline scripts' hashes and the origins it loads from, to the page's content security policy. It leaves the code out of the signed-in preview, and out of every page when the host has switched site code off.

## Featured posts on the latest posts page

Editors pin an item with the built-in field **Pin to the top of the latest posts**. The latest posts page, template `latest`, gets them already chosen, as `$page`:

- `$page->lead` is the newest pinned post, or the newest post when none is pinned;
- `$page->featured` lists up to three more pinned posts;
- `$page->feed` is the rest, as a listing with its pagination.

The lead and the featured posts are on page 1 only. Each is a listing item with its title, address, excerpt, kicker, authors, dates, featured image and fields; see [LatestView](../../../reference/theme.md#latestview) and [ListingItem](../../../reference/theme.md#listingitem). The template chooses nothing and counts nothing: it draws what it is given, and draws an empty state when all three are empty.

To let the site hide the featured row, give your theme a setting for it, as the default theme's `show_featured` does, and read it from `$themeSettings`.

Next: [The manifest](../concepts/manifest.md) and the [Theme manifest reference](../../../reference/theme-manifest.md).
