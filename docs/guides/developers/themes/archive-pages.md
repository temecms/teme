---
title: Archive pages, author profiles and listing data
type: how-to
audience: theme
summary: What a theme reads on a type's archive page and an author's page, and how its script asks for search suggestions and for any listing's next page as data.
rests_on: [app/Domain/Site/ListingViews.php, app/Domain/Site/ViewModels/ListingView.php, app/Domain/Site/ViewModels/Subject.php, app/Domain/People/PublicProfile.php, docs/reference/theme.md]
---

# Archive pages, author profiles and listing data

A listing template (`archive`, `taxonomy`, `author`, `date`, `search`) gets one `$page`, a [ListingView](../../../reference/theme.md#listingview). This page covers what the site’s people can set for them. It describes `main` today.

## A type's archive page

A public type with an archive page of its own has an editable heading and opening text, under **Site › Types**, on that type's page, in the section *Archive page*. They are stored on the type, and changing them purges that archive's pages only.

- `$page->heading` is the heading: what the editor wrote, or the type's plural name when they left it empty. `$page->subject->title` holds the same words, so a template that prints the subject's title needs no change.
- `$page->introHtml` is the opening text, already drawn, or `null` when there is none. It is drawn by the body's own renderer and is safe to print unescaped.

Both are `null` on every other listing, so check before you print:

```blade
<h1>{{ $page->heading ?? $page->subject->title }}</h1>

@if ($page->introHtml)
    <div class="archive-intro">{!! $page->introHtml !!}</div>
@endif
```

Only types the site made in Site › Types have these. A type a plugin or the code declares has no heading or intro of its own, and keeps its plural name.
