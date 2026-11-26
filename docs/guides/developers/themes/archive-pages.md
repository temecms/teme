---
title: Archive pages, author profiles and listing data
type: how-to
audience: theme
summary: What a theme reads on a type's archive page and an author's page, and how its script asks for search suggestions and for any listing's next page as data.
rests_on: [app/Domain/Site/ListingViews.php, app/Domain/Site/ListingData.php, app/Domain/Search/Suggestions.php, app/Domain/Site/ViewModels/ListingView.php, app/Domain/Site/ViewModels/Subject.php, app/Domain/People/PublicProfile.php, docs/reference/theme.md]
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

## Any listing's page as data

A script can ask for the page of any listing as JSON: the latest posts, a type's archive, a term's archive, an author's, a date's and a search's. Add `format=json` to the page's own address:

```
/category/news/page/2/?format=json
```

It is the same page, so it has the same items, in the same order, with the same visibility and in the same language as the page itself: a draft or a private item never appears, a French archive gives French items only, and a search keeps its `s`. Nothing is queried a second time; the data is built from the view model the page is drawn from. A page that isn't a listing, such as an article, and a page past the last, answer `404` with `{"error": "not_a_listing"}` or `{"error": "not_found"}`. `format` with any other value is ignored.

The answer holds the subject (`kind`, `title`, `url`; `null` for the latest posts), the page's items and its pagination:

| Value | What it is |
|---|---|
| `items[].title`, `url`, `type` | The item's title, address (site-relative, generated from the current site address) and type key |
| `items[].standfirst` | The excerpt someone wrote, or `null` |
| `items[].kicker` | `{name, url, taxonomy}` of its primary category, or `null` |
| `items[].authors` | `[{name, url}]`, main author first |
| `items[].published`, `publishedText` | When it was published, as an ISO 8601 time in the site's time zone, and as words in the item's language |
| `items[].image` | `{id, alt, caption, credit, html}` or `null`; `html` is the `<img>` a card draws, safe to insert |
| `pagination.current`, `total` | This page's number, and how many pages there are (`null` when not known) |
| `pagination.previous`, `next` | The data address of the newer and the older page, or `null` at either end |

The latest posts' lead and pinned posts are on page 1 of the page and not in the data; its feed is, so a homepage keeps its editorial lead and a script updates just the story grid. The section-filtered home feed is the term's archive: ask for `/category/news/?format=json`.

Answers are stored by the page cache apart from the page, and removed with it when an item changes. Reading them costs nothing the page didn't. Below is a whole Load more script in plain JavaScript, with no library. It goes to the site's own address, which the page's content security policy already allows, and it writes titles with `textContent`, never as HTML:

```js
// docs/guides/developers/examples/themes/load-more.js
const list = document.querySelector('[data-load-list]');
const button = document.querySelector('[data-load-more]');

async function loadMore() {
    const address = button.dataset.next + (button.dataset.next.includes('?') ? '&' : '?') + 'format=json';
    const page = await (await fetch(address)).json();

    for (const item of page.items) {
        const link = document.createElement('a');
        link.href = item.url;
        link.textContent = item.title;
        list.append(link);
    }

    if (page.pagination.next === null) {
        button.remove();
    } else {
        button.dataset.next = page.pagination.next.replace(/[?&]format=json$/, '');
    }
}

button?.addEventListener('click', loadMore);
```

The markup it expects is a list container and a button that start from the page's own next address, which a template already has:

```blade
<div data-load-list>…the page's items…</div>
@if ($page->pagination->next)<button data-load-more data-next="{{ $page->pagination->next }}">{{ __('Load more') }}</button>@endif
```

