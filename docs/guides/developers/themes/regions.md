---
title: Regions, site code and featured posts
type: how-to
audience: theme
summary: How a theme sets aside regions for the site's own content, where an administrator's own HTML goes, and how to draw pinned posts on the latest posts page.
rests_on: [app/Domain/Regions, app/View/Components/Teme/Region.php, app/Domain/SiteCode, docs/reference/theme.md, docs/reference/theme-manifest.md, themes/default]
---

# Regions, site code and featured posts

A theme draws the site; the people who run it fill it. This page covers the three places where a theme makes room for what they choose: regions, which hold their own content between your markup; site code, their own HTML on every page; and the pinned posts that lead the latest posts page. It describes `main` today.

## Regions

A region is a spot your theme sets aside for the site's own content, such as a box after each article. You declare it; editors write it under **Regions** in the admin, in the same editor as an article, and publish it.

### Declare a region

Add each region under `regions` in `teme-theme.json`, keyed by a slug of lower-case letters, digits and hyphens. The default theme declares one: `"after-content": {"label": "After the article", "cards": ["callout", "button"], "max": 5}`.

- `label` is the name editors see. It is required.
- `cards` lists the cards the region allows beside text. Text is always allowed. Each name must be a card Teme registers, such as `image`, `gallery`, `embed`, `callout`, `snippet`, `button`, `audio` or `video`.
- `max` is the most top-level blocks the region holds, 1 to 50; 50 when you leave it out.

`php artisan teme:theme:check` refuses a region without a label, a card Teme doesn't know, or a `max` outside that range. Teme holds a region's document to its declaration when it is saved and again when it is published, against the declaration as it is then.

### Draw a region

Place the component where the region belongs, naming its slug, as the default theme's single template does: `<x-teme::region name="after-content" />`.

It draws the region's published content with the same renderer as an article, and records its cards for the page's head. It draws nothing for a region that is empty or unpublished, so don't wrap it in markup that would show empty. Outside production it draws a note when you name a region your manifest doesn't declare. See [the region component](../../../reference/theme.md#x-temeregion) in the generated reference.

Each theme's regions are its own. When the site switches themes, the old theme's regions are kept, read-only, and show again if it switches back.

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
