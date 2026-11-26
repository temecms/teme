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
```

Print the intro where the page opens, unescaped, since it is already drawn:

```blade
@if ($page->introHtml)<div class="archive-intro">{!! $page->introHtml !!}</div>@endif
```

Only types the site made in Site › Types have these. A type a plugin or the code declares has no heading or intro of its own, and keeps its plural name.

## An author's public profile

On an author's page, `$page->subject` carries what the person chose to show. Each value is empty until they fill it in under **Your account › Profile › On your author page**, and nothing else about the account is ever given to a template.

| Value | Type | What it is |
|---|---|---|
| `$page->subject->role` | `?string` | Their title, such as "Senior editor" |
| `$page->subject->coverage` | `?string` | What they cover |
| `$page->subject->links` | `list<ProfileLink>` | Up to six links, each with `label` and `url`, always an `https://` or `http://` address on another site |
| `$page->subject->publishedCount` | `?int` | How many items the page lists, in the page's language; `null` for a very large list the site doesn't count to the end |

These are `null`, empty or absent on any listing that isn't an author's, and for an author with no account here.

Print them where the page heads an author:

```blade
@if ($page->subject->role)<p>{{ $page->subject->role }}</p>@endif
```

Each link has a `label` and a `url`; print both with `{{ }}`:

```blade
@foreach ($page->subject->links as $link)<a href="{{ $link->url }}" rel="me noopener">{{ $link->label }}</a>@endforeach
```

`publishedCount` is `null` when the count is unknown, so test it with `!== null`, as a count of zero is a count:

```blade
@if ($page->subject->publishedCount !== null){{ $page->subject->publishedCount }} published@endif
```

## Search suggestions for an overlay

A search overlay that shows matches while a visitor types asks `GET /_teme/suggest`. It is read-only, and it gives the first of the public search's own results, so it shows the same items, with the same visibility, as the search page would: nothing unpublished, private or protected.

| Parameter | What it is |
|---|---|
| `q` | What the visitor has typed. Shorter than two characters gives no items; only the first 200 characters are read |
| `lang` | The page's language tag, `$language->tag`. Leave it out, or give the default's, for the default language; one that isn't a shown language of the site is read as the default's |

The answer is JSON, with at most eight items, best match first:

```json
{"query": "heron", "items": [{"title": "Herons return to the creek", "url": "/herons-return/", "type": "post"}]}
```

Each `url` is generated from the site's address when the answer is drawn, so it is correct on every domain. Print `title` as text, never as HTML.

Answers are kept by the page cache for a minute, one for each `q` and `lang`, and an item that changes removes the answers it was in. A reader is allowed sixty drawn answers a minute; past that the address answers 429, so wait for the visitor to pause typing (a debounce of 200 milliseconds is plenty). The page's content security policy already allows the request, since it goes to the site's own address. This is a whole script:

```js
// docs/guides/developers/examples/themes/search-suggest.js
const input = document.querySelector('[data-suggest-input]');
const list = document.querySelector('[data-suggest-list]');
const language = input.dataset.language;
let timer;

input.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        const query = input.value.trim();
        const url = '/_teme/suggest?q=' + encodeURIComponent(query) + (language ? '&lang=' + encodeURIComponent(language) : '');
        const answer = await (await fetch(url)).json();

        list.replaceChildren(...answer.items.map((item) => {
            const link = document.createElement('a');
            link.href = item.url;
            link.textContent = item.title;
            return link;
        }));
    }, 200);
});
```

The markup it expects is an `<input data-suggest-input data-language="{{ $language->tag }}">` beside an empty `<div data-suggest-list></div>`; the full search at `/?s=` stays available for the visitor who presses Enter.
