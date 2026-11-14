# Teme Default

Teme's default theme: a reading-first theme for a publication. It leads with a story, lists the rest in a clean feed, and sets articles for long reading. It needs no JavaScript to read, navigate, search or page through the site.

It meets an accessibility floor: one `<main>`, core's skip link first, labelled menus, one `<h1>` per page with no skipped heading levels, visible focus, and rules for reduced motion and forced colours. `tests/Feature/DefaultTheme/FloorTest.php` checks every template against it.

## What it draws

| Template | Draws |
|---|---|
| `latest` | The latest posts, at the site's address or the posts page's: the lead story and the pinned posts, then the feed, then pages |
| `single` | An article: kicker, title, standfirst, byline, featured image, body, tags, author box, the `after-content` region, the thread (`partials/comments`), related posts |
| `single-page`, `single-page-full-width` | A page: title, featured image and body, the second at the wide measure |
| `archive` | A category, tag, author or date, titled by its subject, then the feed |
| `comments` | A later page of an item's comments: the story named and linked, then the thread |
| `respond` | Core's pages about writing a comment: the reply page, and the answer to a form posted without JavaScript, in core's words |
| `notfound`, `error` | Not found, with a search; anything else, minimally |
| `index` | The floor every theme has; none of this theme's pages lands there |

The header draws the primary menu twice, for wide screens and in a `<details>` disclosure for small ones, and the CSS chooses by the site's width. Search is a plain `GET` form.

## Settings

Changed on **Settings › Design**, and previewed there before they're published.

| Section | Setting | Default |
|---|---|---|
| Site | Header layout: logo left, centred or stacked | Logo left |
| Site | Header and footer colour: the page's or the accent | Page colour |
| Site | Colour scheme: the reader's system, light or dark | The reader's system |
| Latest posts | Header: lead story, or none | Lead story |
| Latest posts | Show pinned posts under the lead story | On |
| Latest posts | Feed layout: list or grid | List |
| Latest posts | Images in the list | On |
| Content | Featured image: wide, full width, text width or hidden | Wide |
| Content | Author box, related posts, reading time | On |
| Archive | Archive header: with description and image, or title only | With description |
| Archive | Author and date on cards | On |

The site's accent colour replaces the theme's red in both schemes; in the dark one Teme lightens it just enough to read (I-457).

## Places

- **Menus:** `primary` (the header) and `footer`, chosen under Content › Navigation or on Settings › Design.
- **Region:** `after-content`, after the article, taking callouts and buttons, at most five, filled under Content › Regions.

## Comments

The theme draws an item's thread from `$page->comments`, and nothing at all when it's null: comments switched off, the item Hidden, or its type taking none. It draws nothing, too, under a story that's closed and has none. Core draws the form (`<x-teme::comment-form>`), each comment's words, and the words of its pages about writing a comment; the theme lays them out. Core's script (1.8b′) finds these hooks, never the theme's classes, so they stay as they are:

| Hook | On | Says |
|---|---|---|
| `data-teme-thread`, `data-teme-depth="{n}"` | the thread's `<ol>` | where conversations go; how deep replies nest |
| `data-teme-comment="{id}"` | each `<li>` | one comment's place, by its ID |
| `data-teme-replies` | a comment's replies' `<ol>` | where its replies go |
| `data-teme-reply="{id}"` | each Reply link | the comment it answers |
| `data-teme-respond` | the form's wrapper (core's) | the one form, which moves |
| `data-teme-form`, `-guest`, `-remember`, `-as`, `-replying`, `-cancel`, `-status` | inside core's form | its parts: the form, the guest fields, saving details, the signed-in name, the reply line, Cancel, the status region |

## Fonts

Newsreader for the text and Instrument Sans for headings and the interface, self-hosted from `public/fonts` (Fontsource 5.3.0 builds). Both are under the SIL Open Font License 1.1: `public/fonts/OFL-newsreader.txt` and `public/fonts/OFL-instrument-sans.txt`.

## Building and serving

The stylesheet is `assets/css/theme.css`, in `@layer teme.theme`, using only the `--teme-*` tokens. Build it into `public/build`, which is committed:

```bash
npm run build:themes
```

`composer check` builds it before the tests, so a stale build can't pass. Serve the theme's public folder, as a deploy does:

```bash
php artisan teme:themes:link
```

`screenshot.png` is the front page of the tests' fixture site at 1200 × 900, shown on Settings › Design.
