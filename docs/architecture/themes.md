# Themes

The public site is drawn by a theme: a Composer package of Blade templates, a manifest and its own built assets. Core decides what a page is; the theme decides how it looks. **A missing or broken theme never takes the site down**: core's own plain views draw it, and the admin says why. The design is in study B §3a–§3e (`docs/studies/theme-layer-and-appearance.md`) and slice 1.6b's plan (`docs/superpowers/plans/2026-09-22-phase1-slice06b-the-theme-engine.md`); this document describes the system as built and is what the code is checked against.

## What a theme is

A theme is a Composer package of type `teme-theme`, installed where Composer puts it and never written to at runtime (spec §3). It holds:

```
teme-theme.json          the manifest
views/templates/         the templates: index.blade.php at least
composers/               its view composers, if any, autoloaded by its composer.json
public/build/            its own Vite build, if it has assets
```

The default theme's source is `themes/default` in this repository, installed through a path repository as `teme/theme-default` exactly as an outside theme would be (I-394). 1.6b ships only its skeleton; 1.6g builds it out.

## The manifest

`teme-theme.json`, version 1 (I-243). `ManifestValidator` checks the keys 1.6b and 1.6c use and keeps the rest for the parts that bring them into use; `ThemeRegistry` checks what needs the other themes or the theme's classes.

| Key | Rule | Checked by |
|---|---|---|
| `manifest` | `1` | validator |
| `code` | `^[a-z0-9][a-z0-9-]{1,39}$`; unique among themes; for a `theme_paths` folder, the folder's name | validator; registry |
| `name`, `version`, `description` | present | validator |
| `requires.teme` | a constraint Teme's own version (`config('teme.version')`) satisfies, through `composer/semver` | validator |
| `templates` | content type → slug → label; every slug but `default` has `views/templates/single-{type}-{slug}.blade.php` | validator |
| `navigations` | slot → label, such as `"primary": "Header"`: the places the theme draws a menu (**Navigation**, below); a slot is `^[a-z0-9-]+$` | validator |
| `regions` | slug → `label`, `cards` (registered card names) and `max` (1–50; 50 when absent): the places the theme puts the site's own content (**Regions**, below) | validator |
| `settings` | a list of the theme's own settings, each `{key, type, label, description?, fieldset, options?, default, visible_when?}` (**Settings**, below) | validator |
| `assets` | `manifest`, a relative path inside the theme; `entries`, the Vite entries its pages load | validator |
| `composers` | class names; each a `ViewComposer` asking only for `Teme\Contracts` interfaces | validator; registry |
| `per_page` | 1–100; 10 when absent | validator |
| `title_separator` | a string; `–` when absent | validator |
| `layout` | `content` and `wide` widths in pixels, `full` null for the viewport | validator |
| `images` | context → `sizes`: lengths in px, vw or rem, every entry but the last with a `(…)` condition, such as `"content": "(min-width: 720px) 680px, 100vw"` | validator |
| `fonts` | `family`, `file` (relative, inside `public/`, and there), `weight`, `style` (normal, italic, oblique), `preload`; **at most one preloaded** | validator |
| `tokens` | token → CSS value, from a fixed vocabulary (`base`, `contrast`, `accent`, `on-accent`, `muted`, `rule`, the three fonts, `step--1`–`step-5`, `space-1`–`space-8`); a value has no `; { } < >`; **`base` and `accent` are hex colours**, `#rgb` or `#rrggbb` in either case, since Teme computes the accent's contrast from them | validator |
| `tokens_dark` | the same vocabulary and rules, for the theme's dark scheme, which core writes into the shell (below) | validator |
| `screenshot` | a path inside the theme, with no `..`, to a `.png`, `.jpg` or `.webp` image, shown on Settings › Design; a missing file is no error | validator |
| `speculation` | `true` or `false`; a theme may only opt out | validator |
| `breadcrumbs` | `true` or `false`; `false` when absent: whether the theme draws a breadcrumb trail, which is built, and written as structured data, only for a theme that says so (I-1135; **The trail**, below). The default theme sets none | validator |
| `cards.replaces` | registered card names whose core stylesheets the theme replaces with its own | validator |
| `views/templates/index.blade.php` | exists: every theme's floor | validator |

**A theme with any error is broken.** It is listed with its errors in plain words, and never used. A `Manifest` keeps what the file says even then, so a broken theme can still be named.

## Discovery and the cache

`ThemeRegistry` finds every theme once per request or job:

- **Composer's themes,** from Composer's runtime data, `InstalledVersions` (`vendor/composer/installed.php`, which OPcache keeps compiled): each package of type `teme-theme`, at its install path, each once.
- **`config('teme.theme_paths')`,** plain folders for tests and local work, **always read live**, never cached.

`teme:themes:cache` writes Composer's themes, validated, to `config('teme.theme_cache')` (`bootstrap/cache/teme-themes.php`) as a PHP array, and `teme:themes:clear` removes it; `optimize` and `optimize:clear` run them. Without the cache, each boot finds Composer's themes through `InstalledVersions` and validates their manifests; the cache saves the validating (B-226). A theme whose code an earlier one took is listed under its folder's path, broken (B-222).

## The active theme and the fallback

`design.theme` names the theme; the `design` settings group offers the usable ones and is rebuilt from them through `SettingsRegistry::replace()` (`DesignSettingsGroup`). **Nothing reads a setting at boot** (`php artisan migrate` boots before the `settings` table exists), and a test proves it: the application boots against a database that doesn't exist.

`ActiveTheme::get()`, a scoped binding, reads the setting on first use and answers the theme when it is usable, otherwise null. On that read it points the `theme::` view namespace at the theme's `views/`, or at `resources/views/teme/fallback` when there is none, and flushes the view finder's cache, which would otherwise keep the first theme's templates. `forget()` reads again.

**Core's fallback** mirrors a theme's `templates/`: `index`, `latest`, `single`, `archive`, `notfound` and `error`, plain `<main id="main" data-fallback="…">` inside the document shell, reading only `$page`. `error` alone keeps its own whole document and reads nothing that can fail (I-249). `latest` exists so the latest posts never reach `index`, which draws nothing from `$page`, so any page renders there. Core's own views are `teme::`, such as `teme::fallback.templates.error`.

## Resolution

`PathResolver` (`app/Domain/Site`) turns a path into a redirect, or a page with a `ViewIntent` and a view model (1.6a's addresses, `docs/architecture/addresses.md`). `TemplateResolver` turns the intent into at most three candidates; the first that exists wins.

| Kind | Candidates, in order |
|---|---|
| `single` | `single-{type}-{template}` (only when a template is set and isn't `default`) → `single-{type}` → `single` |
| `archive` | `archive-{type}` → `archive` |
| `taxonomy` | `taxonomy-{taxonomy}-{term}` → `taxonomy-{taxonomy}` → `archive` |
| `author`, `date`, `search` | `{kind}` → `archive` |

**A type's archive page** (slice 2.1e, `docs/architecture/addresses.md`, *A type's archive page*) is served as kind `archive` with the intent `ViewIntent('archive', type: $key)`, so a theme's `archive-film` draws the page of the type `film` and `archive` draws every type's that has no template of its own. It is a `ListingView` of the type's published items, newest first, whose heading is the type's plural; the head's title, canonical and robots come from the type's own row in Search and social (`PageHead`).
| `latest`, `notfound` | `{kind}` |
| `error` | `error-{code}` → `error` |
| `comments`, `respond` | `{kind}` → core's own when the theme has none (1.8b's D10) |
| `protected` | `protected` → core's own, `teme::fallback.templates.protected`, when the theme has none (I-2782) |

Every name is prefixed `templates.`. **`index` is the floor** for every kind but `error`, tried last and never listed; an `error` falls to core's `teme::fallback.templates.error`, never the theme's `index`.

**A search** (1.6h, `docs/architecture/search.md`) is `/?s=` and `/page/N/?s=`, of kind `search`, with a `ListingView` like every archive's, its subject of kind `search` and its `search` details filled; a theme with no `search` template draws it with `archive`. The default theme has a `search` template and an `error-429` for the search's rate limit, and core's fallback has a plain `search`.

**The home page** (I-442): with `front_shows = latest` the site's address is `latest`; with `page` it is that page, a `single`, and the posts page is `latest`. `isFront` is true only at the site's address and its numbered pages, and never changes the candidates. The latest posts are only ever `latest`, never `archive`.

Templates receive **view models, never Eloquent models** (spec §5.9), as `$page`: `ArticleView`, `ListingView`, `LatestView`, `NotFoundView`, `ErrorView`, `CommentsPageView`, `RespondView` and `ProtectedView` (**The page**, below). A `notfound` answers 404 in place (I-249).

## Choosing a page's template

The built-in field `template` (`BuiltInFields::TEMPLATE`, I-443) holds the slug of a template the active theme declares for the item's type, or `''` for the theme's default. It applies by feature (`template`), which both core types support; it lives in the revision's `fields`, so it travels with revisions and is published with the rest.

- **Only a new choice is checked** (`TemplateChoice::check()`): in `DraftChecks` (saves and autosaves), `CreateDraft` and `UpdateContentDetails`. A template already stored is never checked again, so saving other edits never fails when a later theme lacks it.
- **`RestoreRevision`** takes back an old revision's template only while the theme offers it; otherwise the working revision's stands (`TemplateChoice::kept()`).
- **Resolving,** a stored template the theme lacks gives a `single-{type}-{template}` candidate that doesn't exist, and the resolver moves on.
- **The editor** shows a Template select in the Publish panel, after Address, only when the theme declares templates for the type (`EditorSession::$templates`). A stored template the theme lacks stays in the select, marked "not in this theme", with a note that the default layout is used and that it comes back if the theme does. *Save as a new draft* leaves such a template out, since a new draft is a new choice.

## Composers

A theme's composers implement `Teme\Contracts\Themes\ViewComposer` (I-251): `views()` names the theme views it serves, with `*` as a wildcard, and `with(ViewContext)` returns data to add. `ViewContext` gives the view's name, the page's kind, `isFront`, the locale (the site language setting's BCP 47 tag, such as `pt-BR`, I-435), and the view's data so far. The contracts carry no framework type (rule 5).

- **One dispatcher** on `theme::*` runs `ComposerRegistry`: core's composers first (`SiteComposer`: `site`, the `SiteView` with the site's name, tagline, address, language and `year`, and `isFront`; then `NavigationComposer`: `navigation`; then `ThemeSettingsComposer`: `themeSettings`, the active theme's own settings, key => value, without its menu places, read through `SiteDesign::themeSettings()`, so a design preview shows its drafts), then the theme's in manifest order, each built once. The list is rebuilt when the active theme changes, never added to, so no composer runs twice. A template reads `$themeSettings['feed_layout']`, never the settings reader.
- **The constructor rule:** every parameter of a theme composer's constructor is a `Teme\Contracts` interface. Discovery marks a theme broken otherwise, naming the class and what it asks for (`ComposerNotAllowed`).
- **Collisions:** a key the view already has, from the page or an earlier composer, throws `ComposerKeyCollision` naming the composer, the key and the view; in production it is logged and the value already there is kept. **A partial keeps what its template was given:** an `@include`d partial and an `@extends` layout get every variable of the view that drew them, so a composer serving both meets its own value again. The registry records, for the page, each value each composer gave each key, and a key the view holds with that same value (`===`), from that same composer, came down from the view that drew it and is kept without complaint. `startPage()` empties the record.

## Assets

A theme's CSS and JavaScript are its own Vite build. `ThemeAssets::tags()` draws the tags for its `assets.entries` through a new `ThemeVite` per theme, which reads the theme's own manifest and serves from `themes/{code}/build`; the admin's shared Vite is never reconfigured. The document shell writes them in each page's `<head>`; `@temeThemeAssets` echoes the same tags for a view that writes its own document. With no manifest and no dev server running, it draws nothing rather than failing (B-230).

`teme:themes:link` links `public/themes/{code}` to each usable theme's `public/` folder (a junction on Windows), replacing a link left from before. Like `storage:link`, it runs at deploy and image build; `public/themes` is ignored by git.

## The page

Slice 1.6c's plan (`docs/superpowers/plans/2026-09-22-phase1-slice06c-the-page.md`) gives the design. **A template computes nothing:** reading time, dates, related posts and the lead image all arrive in `$page`, built in `app/Domain/Site` before the template runs.

### The view models

All are `final readonly` classes in `App\Domain\Site\ViewModels`.

| Model | For | Fields |
|---|---|---|
| `ArticleView` | `single` | `id`, `type`, `title`, `url`, `template`, `standfirst` (a written excerpt only, never generated), `kicker` (the item's primary category, I-869), `authors` (`Person`), `published`, `updated` (only a revision at least a day newer than the first publication), `publishedText`, `updatedText`, `readingMinutes` (238 words a minute), `lead` (`ImageRef`), `leadIsFeatured`, `bodyHtml`, `tags` (`TermLink`), `related` (`{item: ListingItem, reason}`); then `revisionId`, `fields` (that revision's stored fields), `firstImageId` (the first image card at the body's top level), `wordCount` and `revised` (when it was saved), which describe the revision shown to the head and the structured data (I-1125) and which a template has no need to draw; and `breadcrumbs` (**The trail**, below) |
| `ListingView` | `archive`, `taxonomy`, `author`, `date` | `subject` (`Subject`: `kind`, `title`, `description`, `image`, `url`, the subject's own address), `items` (`ListingItem`), `pagination`, `breadcrumbs` |
| `LatestView` | `latest` | `lead` and `featured` (page 1 only), `feed` (a `ListingView`), `breadcrumbs` |
| `Crumb` | one step of a trail | `name`, `url` (site-relative) |
| `ListingItem` | a listing's entry | `title`, `url`, `standfirst`, `kicker`, `authors`, `published`, `publishedText`, `image` |
| `Pagination` | | `current`, `total`, `previous`, `next`, and `pages()`: a window of seven with gaps; page 1's own address when it isn't the base (a thread's is its item at `#comments`) |
| `CommentsView` | `ArticleView::$comments`, a later page | `count` (approved, pings included), `open`, `current`, `total`, `depth`, `items` (`CommentView`), `pagination`, `form` (while open); null while comments are off, the item is Hidden, its type takes none, or it's a preview |
| `CommentView` | one place in a thread | `id`, `kind`, `removed`, `name`, `url`, `byAuthor`, `initials`, `hue`, `iso`, `dateText`, `permalink`, `replyUrl`, `legacyId`, `bodyHtml` (core's, escaped), `isReply`, `parentName`, `pending`, `replies`; never an email address, IP address, browser or moderation record |
| `CommentFormView` | core's form | `action`, `path` (the item's address, never its ID), `parentId`, `parentName`, `askWebsite`, `signedInOnly`, `standalone`, `values`, `errors`, `keepIpDays` |
| `CommentsPageView` | `comments` | `title`, `url`, `kicker`, `comments` |
| `RespondView` | `respond` | `title`, `heading`, `lead` (HTML core built, words escaped), `context`, `hint`, `echoHtml`, `keep`, `form`, `links` |
| `ProtectedView` | `protected` | `title`, `singular` (the type's, lower case), `path` (the address the form is drawn at), `error` (why the last password was turned away), and `lead()`, core's line |

`ArticleViews::published()` builds from the published revision; `ofRevision()` from any, for previews. **Listings read the published revision's title, never a newer draft's.** A listing's items are built in a few queries for the whole page, never one per item, but for each author's address and each card's image, read one at a time until #30 (with 1.7). An author's archive reads its page straight from `contents_author_published`, in published order (1.6h); a date archive counts its items no further than the deepest page served (`PathResolver::LAST_PAGE`, 1,000 pages). Dates are written in the site language (`SiteDates`) and the site's time zone.

- **The latest posts:** the lead is the newest pinned post (the `sticky` field), or else the newest post; up to three more pinned posts are `featured`; the feed leaves all of them out, in one query. The pinned ones come from `contents_pinned_published`, a partial index on the field's JSONB value for published, live items, whose condition the query writes word for word, so any plan can use it (1.6h). Later pages hold the feed alone. A numbered page with nothing on it, here or in a listing, is a 404.
- **Related posts** (`RelatedPosts`, I-258): through the article's rarest shared term first, counted for its type, then the next, until there are three. One query reads each term's newest ten for all the article's terms together (a lateral join), so every article asks the same number however many terms it has (1.6h). The reason reads "Also in {term}" for a hierarchical taxonomy's term and "Also tagged {term}" for a flat one's. Nothing shared means none: **never the latest posts instead.**
- **Comments** (1.8) are an optional module (I-077). While they're switched off, the view models carry no comment data at all, so a theme's comment parts render nothing, not even "Comments are closed" (2026-09-28; I-484). A theme never assumes comments exist. 1.8b built the thread: `ArticleView::$comments`, later pages at `…/comments/page/N` of kind `comments`, and core's pages about writing a comment of kind `respond`; the theme draws them, and core draws the form (`<x-teme::comment-form>`), each comment's words and the answers' words (`docs/architecture/comments.md`). Core's script finds the `data-teme-*` hooks, never the theme's classes (1.8b′).
- **An item protected by a password** (I-1700, I-2782) is a `ProtectedView` of kind `protected` at its address and its later comment pages, for a reader without its key. The theme lays out the page; core draws the form (`<x-teme::password-form>`) and its words, so no theme misstates what happened, as with `respond`. The default theme and core's fallback each have a `protected` template. With the key, the reader gets the `single` page, its thread shown without a form. A protected item's card in a listing has its title, date, author and kicker, never its `standfirst` or `image`. A private item is in no view model at all (`docs/architecture/addresses.md`).
- **The trail** (`Breadcrumbs`, I-295, I-702): `breadcrumbs` is a `list<Crumb>`, built only when the active theme's manifest says `"breadcrumbs": true` (I-1135), and null on any other theme, the default theme included, so a page on it reads nothing for it (I-870). An item's trail is Home, its primary category's parents and the category, then the item; a term's archive's is Home, its parents and the term; an author's or a date's archive's, and the posts page's, is Home and their heading; the latest posts at the site's address, and the item shown there, have Home alone. A search, a 404, an error and a later page of comments have none. Search engines are given the same steps as a BreadcrumbList when there are two or more (*The head*), so the trail a reader sees and the one a crawler reads never differ (I-1136).

### The lead image and the image component

**The lead** (`LeadImage`, I-245) is the featured image when there is one and the theme shows it, otherwise an image card placed before any paragraph, heading or list with text, otherwise none. An image in the trash is never the lead. When the lead is an image card, the renderer gives that card the priority itself; when it is the featured image, the template draws it with `priority`.

**Every `<img>` on the public site comes from `ImageMarkup`,** through `<x-teme::image :id context priority alt decorative class>` in templates, and directly in the image and gallery cards:

- `src` is the variant nearest 1,000 px wide (a tie goes to the wider); `srcset` lists every width variant; `sizes` is the theme's value for the context (`images` in the manifest), or `100vw` with a warning.
- `width` and `height` are the original's, and a focal point other than the middle becomes `object-position`.
- `alt` is the one given, else the media item's; a decorative image has `alt=""`.
- `loading="lazy" decoding="async"`, or, for the priority image, `fetchpriority="high" decoding="async"`.
- A missing or trashed image, or one with no sizes made yet, draws nothing and adds a warning.

The editor's `imageMarkup()` in `render.ts` mirrors it attribute for attribute.

### The document shell

A template writes its markup inside `<x-teme::document>` (I-247), with one `<main id="main">`. The shell, `App\View\Components\Teme\Document`, reads the page from `ComposerRegistry`, where `PublicPage` started it with its intent and view model, and writes, in order:

1. `<html lang data-scheme>`: the site language (an article's own, once items have one), and the active theme's `color_scheme` (`auto`, `light` or `dark`; `auto` when it declares none).
2. `<meta charset>`, the viewport, `<title>` (**The title**, below), then the head's typed entries, every meta tag and then every link (`DocumentMeta`, I-228; **The head**, below). The robots meta is one of them, written when the page has a directive. `Document::$robots` is gone.
3. The one preloaded font, from the theme's public folder.
4. Core's own `<style>`: the layer order, exactly `@layer teme.core, teme.plugins, teme.theme;` (I-228), then, in `teme.core`, the skip link off-screen until focused, its colours `var(--teme-contrast)` on `var(--teme-base)` marked `!important`, so a theme may move and restyle it but never lose its contrast (B-245), and the "Edit" link's style (1.7c, I-163): a pill fixed at the bottom inline-end corner in the same two colours, `!important` alike, its focus ring, and the room that keeps it off the page's last line (`docs/architecture/page-cache.md`, *Who is reading*). It's written on every page the shell draws, with a theme or without. From 1.8b it also holds the comment form's honeypot off-screen, with `.teme-sr-only` for text only screen readers read, both `!important`.
5. The theme's `tokens` as `--teme-{name}` on `:root`, then its `tokens_dark` under `:root[data-scheme="dark"]` and, for `auto`, again inside `@media (prefers-color-scheme: dark)` (I-257); nothing dark for a theme with no `tokens_dark`. When the site's accent is set on Settings › Design, each scheme takes `Contrast::accentSchemes()`'s pair in place of the theme's `accent` and `on-accent`, where they stand, or at the end: the accent itself on light, and on dark its `darkVariant()`, lightened just enough (I-457).
6. The theme's built assets (`ThemeAssets::tags()`).
7. Core's stylesheet for each card the page drew (below).
8. The page's structured data: one `<script type="application/ld+json">` holding the merged graph, written only when some part gave a node (I-1015).
9. `@stack('teme-head')`, then the head placement's fragments, then `</head>` (**Placements**, below).
10. The skip link, `<a class="teme-skip-link" href="#main">`, styled by core (item 4); a theme may add to its style.
11. `@stack('teme-body-start')`, then the body-start placement's fragments, the template's markup, `@stack('teme-body-end')`, then the body-end placement's fragments.
12. Core's small script (1.7c, I-163): the same for every reader. In a browser carrying the `teme_auth` marker, it asks `/_teme/session` who is reading, and adds the "Edit" link for someone who may edit what the page shows, in a navigation landmark that is a child of `<body>` right after the skip link; its style is core's, item 4. A theme ships no JavaScript of its own, and the budget still allows none. 1.8b′ added core's comments script, which finds the hooks in the table below, never a theme's classes; its words are in `docs/architecture/comments.md`. Core's menu script joins them where a heading is drawn (*Navigation*). A theme that draws a thread with no form, a closed one, places `<x-teme::thread-script />` where it draws one, so the script reaches it (1.8c″, D3); a theme without the actions hook gets Edit beside Reply instead (D5).

    | Hook | On | Says |
    |---|---|---|
    | `data-teme-thread`, `data-teme-depth="{n}"` | the thread's `<ol>` | where conversations go; how deep replies nest |
    | `data-teme-comment="{id}"` | each `<li>` | one comment's place, by its ID |
    | `data-teme-replies` | a comment's replies' `<ol>` | where its replies go |
    | `data-teme-reply="{id}"` | each Reply link | the comment it answers |
    | `data-teme-actions="{Name}"` | the actions line of every comment that isn't waiting | where core's script puts Edit, and the writer's name it names the link for; `hidden` while the line holds nothing |
    | `data-teme-count="{n}"` | the thread's heading | the approved comments, which core's script recounts after a comment is posted at once |
    | `data-teme-empty` | the line that says there are none yet | what core's script hides after a comment is posted at once |
    | `data-teme-respond`, `-form`, `-guest`, `-remember`, `-as`, `-replying`, `-cancel`, `-status` | inside core's form | its parts (core's) |
13. Speculation rules prerendering the site's own pages (never `/admin/*`, `/_teme/*`, or an address with a query string, `/*\?(.+)`, as WordPress leaves out: a search prerendered on the way would use up the reader's search limit), **left out when the theme sets `speculation: false` or the reader carries the `teme_auth` cookie** (I-262), whose presence alone decides, read through `SignedInMarker` (1.7a); no session is read.

**The title** is Search and social's, resolved once for the page by `PageHead` (`App\Domain\Site\Head`, I-1031) and drawn by `DocumentTitle::for()`, which only asks it, so the `<title>` and the head's other entries never differ. An item's own SEO title is used word for word. Every other page is its row's template (I-290, I-1032), filled with Teme's tokens: the row is `title_type_{type}` for an item (the posts page is resolved as its page item, I-903), `title_taxonomy_{taxonomy}`, `title_author`, `title_date`, `title_search`, `title_home` or `title_not_found`, so a type or taxonomy registered later gets a row of its own. Empty tokens and the separators beside them are dropped. The separator is the `title_separator` setting, or else the active theme's `title_separator` (`–` when it declares none). A template that names a token Teme hasn't got is refused when it is saved (`TitlesAndListing::problems()`).

| Page | Template, unless the row is changed | Reads as |
|---|---|---|
| An article or a page; "page not found" | `{title} {sep} {site}` | `{title} – {site}` |
| A term's, an author's or a date's archive; a search | `{title} {sep} {page} {sep} {site}` | `{subject} – {site}`; from page 2, `{subject} – Page 2 of 7 – {site}` |
| The site's address, whatever it shows | `{site} {sep} {page} {sep} {tagline}` | `{site} – {tagline}`; from page 2, `{site} – Page 2 of 7 – {tagline}` (I-904) |
| A later page of an item's comments, core's reply pages, an error other than 404 | none: `{title} {sep} {site}` | `Comments on {title}, page 2 – {site}`, the reply page's heading, `Something went wrong – {site}` |

`{title}` is always the page's own heading: an item's title, a term's or author's name, a date, a search's heading ("Search results for “…”", or "Search" when blank), "Page not found", the site's name on the home page (I-1033). The other tokens are `{site}`, `{tagline}`, `{sep}`, `{page}` ("Page 2 of 7", or "Page 2" when the total isn't known; nothing on page 1), `{search}` (the bare phrase, as plain text, I-851), `{term}` (an item's primary category while that is one of its terms, else its first category, I-869), `{author}`, `{type}`, `{excerpt}`, `{date}` and `{year}` (`TitleTemplate`). The rows and the separator are edited in Settings › Search and social (`docs/architecture/settings.md`).

**Card styles load per page** (I-248). The body is rendered while the view model is built, before the template, and `DocumentRenderer` records each card it draws in `CardUsage`. The shell links `resources/css/cards/{name}.css`, each a Vite input, for each card drawn that has one and that the theme's `cards.replaces` doesn't take over, as `<link data-teme-card="{name}">`. `CardUsage` and `PageWarnings` are emptied at the start of each page, as one application may serve several (B-227).

### The head

Whatever a page's `<head>` holds beyond what the shell writes itself comes from **head contributors**, classes implementing `HeadContributor` that a part registers from its own provider's `register()` under the container tag `DocumentMeta::CONTRIBUTORS` (I-1019). `DocumentMeta` (`App\Domain\Site\Document`) asks each for its entries for the page when the shell's view first asks, after the template's markup is drawn, so a region's cards are known (I-1016). A contributor is given a `DocumentPage`, the view intent and the view model, both null outside a page (I-1017), and holds no state. The tests register one for a test with `headWith()`. Slice 1.9 built the head in parts: 1.9a the entries and their registry (`docs/superpowers/plans/2026-10-07-phase1-slice09a-foundation.md`, D1 to D19 are I-1010 to I-1028), 1.9b the values every page resolves (`2026-10-07-phase1-slice09b-head.md`, D1 to D14 are I-1029 to I-1042), 1.9d the feed links (`2026-10-07-phase1-slice09d-feeds.md`, D1 to D20 are I-1063 to I-1082), 1.9g the structured data (`2026-10-08-phase1-slice09g-structured-data.md`, D1 to D20 are I-1120 to I-1139), 1.9i the verification tags and the default social image (`2026-10-08-phase1-slice09i-search-and-social.md`, D1 to D17 are I-1156 to I-1172), 1.9j who publishes the site (`2026-10-08-phase1-slice09j-who-publishes.md`, D1 to D20 are I-1173 to I-1192) and 1.9k the editor's preview of an item's result and card, held to this head's rules (`2026-10-08-phase1-slice09k-editor-search-and-social.md`, D1 to D17 are I-1193 to I-1209).

- **Three typed entries,** each writing its own tag with every value escaped (I-1012): `MetaTag` (a `name` or a `property`), `LinkTag` and `JsonLdNode` (an array with an absolute `@id`, a `@type` and plain values). There is no `http-equiv`, `charset` or `viewport` entry; the shell writes those. A malformed entry throws when it is made, never when the page is drawn.
- **Contributors are read plugin, then site, then core** (`Precedence`), and within one in the order they were registered. **For each key the first entry wins** (I-1013): a meta tag's key is its attribute, `name` or `property`, and its name in any case, a link's is its `rel`, and for `alternate` its `rel` and address, a node's is its `@id`. All the meta tags are written, then all the links, right after `<title>`.
- **A link's `rel` is `canonical`, `prev`, `next` or `alternate`** (`LinkRelation`, I-1014). Never one that makes the browser load something; styles and scripts reach a page through their own channels. A relation another part needs is added with its reason (I-890).
- **Structured data is one graph.** Nodes that share an `@id` merge: the first non-empty value stands, `@type`s are joined, lists are joined without repeats, objects merge deeply. The merged graph then goes through the graph filters, registered under `DocumentMeta::GRAPH_FILTERS` (`teme.structured-data.graph`) and run once a page, plugin, then site, then core, each given what the one before returned: the only way to remove or rewrite another part's node (I-697, I-1129). Core has no filter; the seam is for plugins. Core's tidying follows (I-1130): empty values go at any depth, each `@type` is its names once, references to a node a filter removed go, and then the gate (I-703, I-1127): a node of a type Google gives a rich result that lacks a property Google requires is left out with the references to it, and the reason is kept on `DocumentHead::$withheld` (`Withheld`), which the editor's check says in words (`EditorStructuredData`, 1.9k). The page keeps everything else, and publishing is never blocked. A reference to a node that was never in the graph stays, since it may name another site's. `JsonLd::encode()` writes the graph under `https://schema.org` with `<` and `>` escaped, slashes and Unicode as they are, and the characters U+2028 and U+2029 escaped, so no value can end the script (I-1015, I-698).
- **Core's contributors,** all at `Precedence::Core`, in the order their providers register them: `RobotsMeta`, `HeadTags`, `FeedLinks`, `CoreGraph`. The site's own `VerificationTags` (Search and social's verification pairs, on the home page alone, I-288) is at `Precedence::Site`, so it wins over core's for the same name, which is why the screen refuses the names core writes. A site's or a plugin's entry for the same key wins over core's.

**What core writes, from the values `PageHead` resolved for the page** (`HeadValues`):

| Entry | Rule |
|---|---|
| Robots (`RobotsMeta::directives()`, I-1035) | In this order: while the site discourages search engines, `noindex, nofollow` on every page, whatever else; core's pages about writing a comment (kind `respond`) and a protected item's form (kind `protected`), `noindex, nofollow`; a search, `noindex, follow`, always (I-1034); a 404 or an error, none, since its status keeps it out; a hidden page, `noindex, follow`; any other page, `max-snippet:-1, max-image-preview:large, max-video-preview:-1`, where an item's *Don't show a snippet* takes `max-snippet`'s place, its snippet length sets `max-snippet:N`, and *Don't keep a cached copy* adds `noarchive`. A hidden page drops every default and snippet directive. A page is hidden by its own box, its type's row, its taxonomy's, or the author or date row of Settings › Search and social. The home page and "page not found" have no hide box on their rows (I-1036). Only *Discourage search engines* hides the latest posts at the site's address; a page shown there keeps its own *Hide from search engines*, and its type's row is not asked |
| Description | An item's meta description, then its excerpt, then nothing. The site's address falls back to the tagline; a term's archive to its description, an author's to their bio; a date archive and a search give none. Teme never makes one from the body |
| Canonical (I-1037) | Every listed page points to itself, page N of an archive to page N. An item may name another item, made into its live address when the page is drawn (the resolver records that item's tag, so the page is purged when it moves), or an address on another site. A hidden page, a search, a 404, an error, core's reply pages and a later page of an item's comments print none. The site's address is `/`, whatever item it shows |
| `prev`, `next` | On the pages of a listing |
| Open Graph and `twitter:card` (I-1038) | On items, the home page and archives; never on a search, a 404, an error or a reply page. `og:title` is the social title, then the SEO title, then the heading; `og:description` the social description, then the description; `og:image` the social image, then the featured image, then the first image in the body (read from the stored document), then the site's default social image, with its width, height and alt; `og:type` is `article` for an item that isn't the site's address, else `website`; `twitter:card` is `summary_large_image`. X reads the `og:` tags, so there is no second set |
| Feed links (`FeedLinks`, I-1074) | The site's feed on every page, titled "{site} » Feed"; a term's archive also its own feed, an author's page theirs. No link to a comment feed |
| Structured data (`CoreGraph`, I-701) | The WebSite on every public page, with the site's publisher when it names one (*Who publishes the site*, below). An item: its page, at its own address and never its canonical, as a WebPage, an AboutPage or a ContactPage, its main entity at `#article` when its type or its own choice gives one (a BlogPosting for a post), whose `publisher` is the site's when it is an article, a Person for each author with an address, its primary image, and its trail and its cards' nodes where there are any. A term's, a date's or the latest posts' archive: a CollectionPage; an author's: a ProfilePage and its Person; a search: a CollectionPage and SearchResultsPage. A 404, an error, core's comment pages and a protected item's form: the WebSite alone, and the publisher. The form's title is the item's title through the item row's template, and it has no description, canonical or social tags. No list of the items a page shows is written (I-705). What an item's type says is in `docs/architecture/content-types.md`, *What a type's items are as structured data* |
| Verification tags (`VerificationTags`) | On the home page only, one `<meta name>` for each pair in Settings › Search and social, escaped |

An item's values are read from the revision the page shows: `ArticleView` carries its stored fields and the first image of its body, so a preview's title, description, robots and social tags describe the version shared, and a published page reads no separate statement for them (I-900, I-1125). The posts page is resolved as its page item and reads that item's published fields. The canonical override is stored under the built-in field `canonical` as `{"item": "<content id>"}` or `{"address": "<url>"}`, or nothing for the item itself; an address on the site's own host is refused, since rule 9 stores the item (I-1039). The primary category is a term field on every type with the `seo` feature (I-1040); what it drives is in `docs/architecture/addresses.md`. The editor's Search and social section shows an item's result and card by these same rules: `resources/js/lib/search-preview.ts` mirrors `PageHead`'s and imports nothing at run time, so Node can run it, and `tests/Feature/Seo/EditorPreviewTest.php` publishes items and holds its answers to the page's `<title>`, description and Open Graph tags, row by row (I-1195).

**The social image is the `social` variant when an image has one, and its widest size until then** (I-855). The share images, 1,200 by 630 and three crops, are made when an image is first used as a featured or social image, or as the site's default (`docs/architecture/media.md`, *The share images*), so a card is served as soon as it exists.

**Who publishes the site** is `PublisherNodes` (`App\Domain\StructuredData`), the one place the graph learns what the site is; its settings and checks are in `docs/architecture/settings.md`, *Search and social*. It is scoped and answers once a page, keeping its answer for that page's view model, so `CoreGraph` and `ItemNodes` share it (I-1188). It gives the reference to name as `publisher`, the nodes to add, and the site's other name.

- **Nobody in particular is the default** (I-1175): no node, no `publisher`, and no statement read beyond the `seo` group every page already reads, so a site that sets nothing writes the graph it wrote before.
- **An organization or a local business is one node,** at `{home}#organization` (`NodeIds::ORGANIZATION`) whatever its kind, its `@type` the kind chosen and no second type (I-1177). It holds `name`, which is the site's title when none is given, read at render so a renamed site needs no second change (I-1178), `alternateName`, `url`, `logo`, `sameAs` and `parentOrganization`. A business adds `address` (a `PostalAddress` of the parts given, and none at all when no part is, so the gate leaves the business out), `telephone`, `geo`, `priceRange` and `openingHoursSpecification` (I-1182). A `GeoCoordinates` holds its latitude and longitude as the text typed, since a number would drop the zeros of its precision.
- **The week** (`OpeningHours::specification()`, I-1183) is one `OpeningHoursSpecification` for each distinct opening, its `dayOfWeek` every day it applies to, in the order met: the week's days in order, a day's first opening before its second. A day closed all day is `00:00` to `00:00`, as Google reads it; an opening past midnight is written as it is; a day the week says nothing about gives nothing.
- **The logo** is an ImageObject at `{home}#logo` (`NodeIds::LOGO`), made by `ImageObjects` at the image's widest public size with its caption and credit, and the organization names it. The page records the image's `media:{id}` tag. An image in the trash, or gone, gives no logo node and no reference, and nothing clears the setting (I-1179).
- **Owned by** (I-1181) is a node of its own at `{the owner's website, without query or fragment, ending in a slash}#organization`, the `@id` the owner's own site would give itself. It is an `Organization`, since its kind isn't known, with the owner's profile links as `sameAs`, and the organization names it as `parentOrganization`.
- **A person** is a `Person` at `{their author address}#person` (`NodeIds::PERSON`), with their name and bio from their profile and the publisher's profile links as `sameAs` (I-1180). That is the `@id` their articles and their author page already write, so the nodes merge: one human, one node. The page records `person:{id}`, so their rename, their new bio and their deletion purge it. A person whose account is gone, or who has no author address, leaves nobody in particular, decided at render with nothing written (I-878); a suspended person stays the publisher.
- **Where it is named:** the WebSite's `publisher` on every page, and the `publisher` of an article's main entity (`TypeSchema::ARTICLES` only), each `{"@id": …}`. A page, a listing or a main entity that isn't an article names none. The WebSite's `alternateName` is the organization's other name, and only while the organization goes by the site's own title, since otherwise it isn't the site's (a site setting of its own is I-993). A business stored without an address is left out by the gate, with the references to it.
- **The cost** (I-1188): nobody in particular, or an organization with no logo, reads nothing more; a logo reads one statement, its media row; a person reads one, their name, bio and address together.

What a plugin adds, several locations or an organization's policy links, is in `docs/architecture/plugins.md`.

### Placements

Site-wide markup that is not a typed entry reaches a page through three **placements** core owns: the head, the start of the body and the end of the body (`Placement`). A `PlacementContributor`, registered under `Placements::CONTRIBUTORS` and read with the head's precedence, gives `Fragment`s: a key and raw HTML, written exactly as given, the first fragment of a key winning (I-1018). Core's shell draws them, so no theme can leave one out. **The three Blade stacks stay** for what one request pushes while it draws, such as core's preview bars and a theme's own markup, and at each placement the shell draws the stack first, then the fragments: the head's at its end, after the structured data; the body start's after the skip link; the body end's after the template, before core's scripts.

The only contributor today is site code, an administrator's entries from Settings › Site code, at `Precedence::Site` (`docs/architecture/security.md`, *Site code*). A plugin's fragments, which will lose their `on*` attributes and JSON-LD scripts at the `// Later (I-697)` line in `Placements::for()`, wait for the plugin API (`docs/architecture/plugins.md`).

**Still being built:** the proof and the regenerated reference (1.9l).

### Previews

A preview link renders the shared revision through `PublicPage`, with the `single` intent, its type and its chosen template, so it looks exactly like the published page. A bar pushed onto `teme-body-start` says "Preview of an unpublished version · expires {date}". It carries `X-Robots-Tag: noindex, nofollow` and `Cache-Control: no-store, private`. The link's signature, expiry and revocation still decide access (1.5d).

## Navigation

Menus are content-side data (spec §5.9), built under **Content › Navigation** by anyone with `navigation.manage` (editors and administrators; an add-only migration brought it to existing sites, I-449). A theme declares its places for menus, its **slots**, in its manifest's `navigations`; each slot shows one menu, chosen on the menu's screen.

**The tables.** `navigations` holds a menu's `name` and its `handle`, a slug made from the name once and never changed (1.7's cache tags use it). `navigation_items` holds each item: its menu (a foreign key, cascading), its parent (a self-reference, cascading), its `position` among its siblings, its `kind`, and a `target_id`, `url`, optional `label` and `opens_new_tab`. **An item points by ID** (rule 9): `content` and `term` items store the target's ID, `home` stores nothing, and only a `url` item stores an address, as typed. A `heading` stores its own label and nothing else: it leads nowhere and opens the items under it (I-2628). Check constraints require a `url` item's address, a `content` or `term` item's target, and a heading's label with no target and no address.

**The Operations** (`App\Domain\Menus`; `App\Domain\Navigation` is 1.5a's admin menu), each authorizing `navigation.manage`:
- `CreateNavigation` and `RenameNavigation`: a name of 1 to 120 characters; the handle is made unique in one query, as slugs are.
- `SaveNavigationItems` replaces a menu's items as a whole, in one transaction. A menu is **three levels deep and 200 items long at most**. An item's kind must be known, its content or term target must exist (checked in one query per kind), and an address must be `http(s)://…`, `mailto:`, `tel:` or a path on this site (`//host` is another site, and nothing runs, such as `javascript:`). A cleared label means the target's own title. A heading needs a label and at least one item under it (I-2715), so it never sits at the third level; the Operation keeps its `opens_new_tab` false. Every problem names its item, as "Item 3 › 2: …", keyed as the input is (`tree.2.children.1`) so the editor shows it beside the item.
- `DeleteNavigation` also clears every slot that showed the menu, in every theme.
- `BindNavigationSlot` chooses a slot's menu, or none; anyone who may manage navigation, or the site's design, may.
- `ImportNavigation` and `PlaceImportedNavigation` (I-343) are the importer's, authorized as every import Operation is (`imports.run`, or the operator). The first makes or replaces a menu from WordPress's through `SaveNavigationItems`' own checks and the same writing code, so an import can't write a menu a person couldn't; on a later run it replaces a menu only while it is as the import left it, so a person's edit is never undone. The second shows an imported menu in a theme's place only where the place shows nothing yet.

**Slots are theme settings.** Each usable theme with slots gets a settings group, `theme.{code}` (spec §5.9), registered at boot through `replace()` (`ThemeSlotSettings`), with one **text** setting per slot, `nav_{slot}` (hyphens made underscores), holding a menu's ID or `''`. It's text, not a select of menus, because a select's options are fixed at boot and boot reads no database. The group's `screen` is `navigation.index`: the generic settings form never edits it, **it isn't listed as a Settings tab** (a group whose screen is a route outside `settings.*` isn't), and its address typed by hand redirects to its screen. 1.6f moves it into Settings › Design. `BindNavigationSlot` and `DeleteNavigation` write it through `SettingsWriter`, so the settings history records each change.

**Drawing.** A template writes `<x-teme::navigation slot="primary" label="Main" />` (`App\View\Components\Teme\Navigation`), which draws a labelled landmark (I-253):

```html
<nav aria-label="Main">
<ul>
<li><a href="/">Home</a></li>
<li><a href="/about/" aria-current="page">About</a>
<ul>
<li><a href="https://example.org/" target="_blank" rel="noopener">Partner</a></li>
</ul>
</li>
</ul>
</nav>
```

- **URLs are site-relative**, made at render time from the path table (`Addresses::toUrl()`), never stored: `home` is `/`, and a content or term item takes its target's current path.
- **An item whose target has no address**, being unpublished, trashed, scheduled or deleted, **isn't drawn**, and neither are its children. The editor marks it ("Not shown: the page isn't published").
- **A heading** is drawn as a button that opens the list of its items: `<button type="button" data-teme-heading aria-expanded="true" aria-controls="{id}">Visit</button>` followed by `<ul id="{id}" data-teme-submenu>`, its items, in the same `<li>`. The ids are unique on the page however many times a theme draws a slot (`SlotMenus::nextId()`: `teme-menu-{slot}_{n}`, then `-{position}` for each level). It is **drawn open**, so its items are there with no script. **Core's menu script** (`teme::components.menu-script`, pushed once onto the shell's `teme-core-scripts` stack, only where a heading is drawn, within 1 KB compressed) folds each list as the page loads, opens and folds it from its button, and on Escape folds the open one that holds the focus, putting the focus on its button. **Core's layer** keeps a folded list hidden (`[data-teme-submenu][hidden]{display:none!important}`) whatever a theme's `display` says. In the signed-in preview, which allows only nonced scripts (`AdminSecurityHeaders.php:57`), headings stay open as with no script. A theme opts out by drawing its menus from `$navigation` without including the script. A heading with nothing drawn under it isn't drawn.
- **Labels** are the item's own, else the content's **published** title (never a newer draft's), the term's name, "Home" for the home page, or a `url` item's address. A heading's is always its own.
- **`aria-current="page"`** goes on the item whose URL is the page's request path, which is canonical; an outside address is never current. A menu with nothing to show draws no landmark.
- **Four queries per menu**, however many items: the items, their live content with its published title, their terms, and their paths (`NavigationTree`).
- **`SlotMenus`** holds the active theme's slots and their trees for one page, built once, and is emptied by `start()` in both public controllers (B-227). The core `NavigationComposer` hands the same trees to every theme view as `$navigation`, for a theme that draws its own markup. Each item is `label`, `url` (site-relative, or **null for a heading**), `current`, `opensNewTab`, `heading` (true for a heading) and `children`, the same shape at every level.
- **An unbound slot is loud, never silent** (study B, decision 2): outside production, `<nav data-teme-unbound="{slot}" class="teme-unbound">` says the place has no menu and where to choose one; in production it draws nothing. It adds nothing to the page's warnings, and the Status banner names it.

**A theme that draws its own menus must draw a heading** (an item whose `heading` is true) **as a button that opens its items**, never as a link and never as `href="#"`: a `<button type="button">` with `aria-expanded`, and `aria-controls` naming its list's id, unique on the page; openable from the keyboard; its items reachable with no script. The simplest way is core's: give the button `data-teme-heading` and the list `data-teme-submenu`, draw the list open, and `@include('teme::components.menu-script')`; core's script and layer then fold and open it. A theme that writes `<x-teme::navigation>` gets all of this for free, and styles `[data-teme-heading]` and its `[aria-expanded="true"]` as it likes; the default theme draws it as one of its links with a chevron.

**The screens.** Content › Navigation lists the menus with their item counts and where each is shown. A menu's editor arranges its items as a tree, moved with buttons or Alt and the arrow keys, never past three levels, with focus kept on the moved item and each move announced; drag and drop is parked (I-398). **Add an item** adds the home page or a heading, finds pages and posts with the editor's own content picker, categories and tags with the term search, one group per public type or taxonomy, or takes a web address. A heading's row has no "Open in new tab", says "Needs an item under it" while it has none, and Indent never puts a heading at the third level. **Show in** chooses, for each of the active theme's slots, the menu it shows. The screens read through `NavigationScreens` and write through the Operations.

## Regions

A theme declares **regions** in its manifest's `regions`: places such as "after the article" where the site's own content goes, like a newsletter box or a notice, each with a label, the cards it allows and the most top-level blocks it holds (`max`, 1 to 50, and 50 when the manifest doesn't say). They replace WordPress's widgets (study B §3e) and are filled under **Content › Regions** by anyone with `regions.manage` (editors and administrators; an add-only migration brought it to existing sites, I-449).

**A region's content is content**, of the type `region` (`CoreTypes::REGION`), which isn't `public`, `searchable` or `listed`, and whose abilities are all `regions.manage`. So it has drafts, revisions, the published pointer, card rendering, plain text and references, with no parallel machinery, and, from the declaration alone, no address, no search hit, no built-in fields and no standard list. **One row per theme and region**, slug `{theme}--{region}`, made only by `CreateRegion`, which checks the theme declares the region and has no row for it yet; the live-only unique index on `(type, slug)` refuses a race past that check. It shares `CreateDraft::start()`, the first-revision code, with the region's fixed slug.

**Regions keep to their own path:** `CreateDraft` refuses a type that isn't listed ("Regions are made on their own screen, not as new drafts."), `CreatePreviewLink` one that isn't public ("Regions have no page of their own, so they can't be previewed."), the dashboard's panels show listed types only, as the lists do, `LinkTargets` offers public types only, and a list-columns preference is declared only for a listed type.

**The server holds a region to its declaration** (`RegionRules`): only the cards it allows, at any depth, and at most `max` top-level blocks; prose is always allowed. `DocumentValidator::validate()` takes the rules last, by name (`rules:`), so fix #1's guard keeps passing `allowUnfinishedCards:` by name (I-452). `DraftChecks`, which `SaveDraft` and `SaveAutosave` share, passes them, so a save, an autosave, a checkpoint and a restored revision are all held to them, and `PublishRevision` checks again against the declaration as it is when published, which a theme update may have narrowed ("This region now allows at most 2 blocks."). A region's theme is found from its slug among the installed themes, active or not; a region no theme declares any more is refused, never saved without limits.

**Drawing.** A template writes `<x-teme::region name="after-content" />` (`App\View\Components\Teme\Region`), which draws the region's published revision through `DocumentRenderer`, with the page's `CardUsage`:

```html
<div class="teme-region teme-region-after-content" data-region="after-content">…</div>
```

It draws nothing for a region that's empty or unpublished, and, outside production, a note for a name the theme's manifest doesn't declare. `Regions` reads each published region once per page, and is emptied by `start()` beside `SlotMenus` in both public controllers (B-227). The document shell's `cardStyles()` is a method its view calls when it draws the head, after the template's markup has rendered, so a card first drawn in a region still gets its stylesheet.

**Across theme switches**, a region's row stays: Content › Regions shows the active theme's regions, and the rows kept from other themes underneath, read-only, until their theme returns.

**The editor's region mode.** A region opens in the content editor with `kind: 'region'`: no title, address, template, author, search preview or sharing, no "Save mine as a new draft", a header naming the region and its theme, a back link to Content › Regions, and insert and slash menus offering prose and the region's own cards (`allowedCards`). The editor's schema keeps every card, so a pasted card the region doesn't allow reaches the server, which refuses it with its reason (#18 would catch it in the editor). The screens read through `RegionScreens`.

## Settings

A theme declares **its own settings** in its manifest's `settings`, a list, each `{key, type, label, description?, fieldset, options?, default, visible_when?}`. They are edited on **Settings › Design** (`settings.design.manage`), beside the site's own `design` group (`theme` and `accent`), and read through the settings registry like any other setting: there is no parallel options API (spec §3).

- **`ThemeSettings::register()`** builds each usable theme's `theme.{code}` group at boot, and again in `themeActivate()` in the tests: the manifest's settings first, in manifest order, then its navigation slots as `ThemeSlotSettings::settings()` makes them. A setting without a description is described by its label.
- **`type`** is `select`, `boolean`, `text` or `color` (`#rrggbb`, lower case, or `''`). `key` is `^[a-z][a-z0-9_]{1,40}$`, appears once, and **never starts with `nav_`**, which is kept for slots. `options`, an object of stored value to label, belongs to a select only; `default` must fit the type.
- **`fieldset`** is `site`, `latest` (the latest posts wherever they're served, "Latest posts", I-442), `content` or `archive`: the screen's four sections.
- **`visible_when`** maps settings declared **earlier** to a value or a list of values; the screen hides the setting unless every condition holds. Hiding isn't clearing: a hidden setting keeps its value.
- The validator refuses everything registration would throw on, so a bad manifest makes the theme unusable instead of stopping every request.

**Two names core reads**, when the active theme declares them: **`color_scheme`** (`auto`, `light`, `dark`) for `data-scheme`, and **`featured_image_style`**, whose `hidden` means no featured-image lead (`SiteDesign`, which `Document` and `ArticleViews` ask).

**The accent (I-457).** Empty uses the theme's own `accent` tokens, light and dark. **One accent, checked against the light background:** a colour set on Settings › Design is refused, with the ratio stated, unless it reaches 4.5:1 (WCAG AA, unrounded) against `tokens.base` (white when the theme sets none), and its text colour, the better of `Contrast::ON_COLORS`, reaches 4.5:1 on it. A theme switch is checked against the accent too. **In the dark scheme Teme uses a lighter version of the same colour,** `Contrast::darkVariant()`: the accent unchanged when it already meets both rules against the dark background; otherwise its OKLab lightness rises in steps of 0.01, the hue kept by scaling `a` and `b` together and the chroma cut, by 20 halvings, to what sRGB can show, and the first step that passes is the answer, white ending the walk. It uses only `+ − × ÷`, `**`, `floor`, `min` and `max`, in the same order as `contrast.ts`, so both give the same colour (`#b3261e` on `#141413` becomes `#dd5143`). No colour reaches 4.5:1 against both the default theme's `#fcfbf8` and `#141413`, which is why one accent can't be checked against every scheme. The screen's readout shows both schemes, each with a swatch ("6.32:1 on light ✓ · dark uses #dd5143, 4.68:1 ✓"), the server's `accentSchemes` once the draft holds the accent, `contrast.ts`'s while the owner types. `resources/js/lib/contrast.ts` mirrors `Contrast` for the live readout, word for word; `ContrastTest` runs it in Node through `guardNode()`.

**The screenshot** a manifest declares is served by the admin, since only a theme's `public/` is: `GET admin/settings/design/themes/{code}/screenshot` names only the theme. `DesignScreen::screenshot()` takes the declared path only when it passes the manifest's check, resolves inside the theme's folder, and holds the image its name says (`finfo`); anything else answers 404.

**Drafts.** Every change on the screen is saved as a draft as it is made, in the group row's `draft_values`, **only the keys changed** (`SaveDesignDraft`). Nothing reaches readers until `PublishDesign` writes those keys, and no others, through `SettingsWriter`, so the history records them; `DiscardDesignDraft` drops them. A menu placed on Content › Navigation meanwhile keeps its place, unless the draft changed that same place: then the later publish wins. A slot's menu is checked to exist when saved and again, under lock, when published.

**Preview.** Each section's Preview opens the page it's about (`DesignScreen::previewPath()`: the home page; where the latest posts are served; the newest item the home page lists; its first term in its type's first hierarchical taxonomy) through a link `CreateDesignPreview` signs by hand, as `signedRoute()` does, expiring within the hour, keeping the address's trailing slash. `DesignPreviewLink`, first in the `public` group, checks it, marks the request's `DesignPreview` active, and `SettingsRepository` reads the `design` and `theme.*` groups draft over live, so the active theme follows a drafted switch (`ActiveTheme::forget()` when the request's state differs from the one before). A bad or expired link answers 403, never live values. A preview answers `Cache-Control: no-store, private` and `X-Robots-Tag: noindex, nofollow`, and carries a bar saying what's shown.

## The default theme

`themes/default` is Teme's own theme (1.6g, I-394), ported from the adopted prototype: a lead story and pinned posts, a list or grid feed, articles set for long reading, archives titled by their subject, and a header whose menu and search work with no JavaScript. Its settings, places, region, fonts and build are in its README, `themes/default/README.md`. Its stylesheet is built by `vite.theme.config.ts`, rooted at the theme, into its committed `public/build` (`npm run build:themes`, which `composer check` runs before the tests), and `tests/Feature/DefaultTheme/FloorTest.php` holds every template to study Q's floor.

**A template a slice adds or changes gets a design pass first** (I-468): when the slice is planned, it's drawn in the theme's prototype, `docs/prototypes/2026-09-22-theme-v1.source.html`, checked for hierarchy, grouping and wording, and then built as drawn. Comments (1.8b) were drawn on 2026-09-30: the thread, the form and its messages, with what the theme draws and what core's script adds marked apart. What they leave to settle is in `docs/architecture/admin-design.md`'s design-pass table.

**The budgets are a gate** (I-253): `ManifestTest`'s "builds its assets, and teme:theme:check passes within the budgets" fails the build when the default theme's report has a warning, more than 15 KB of compressed CSS, or any JavaScript at all, and `composer check` builds the theme before the tests, so a stale committed build can't pass. `teme:theme:check` reports any theme's weight.

**The browser check** (1.6h, I-401): `composer check:browser` builds the admin and the theme, runs `teme:themes:link`, then the `Browser` suite in a real Chromium through `pestphp/pest-plugin-browser` and Playwright: axe at level 3 (moderate and minor issues fail too) on every template in the shared `templates` dataset, in the light and the dark scheme, and two editors side by side (`TwoEditorsTest`, I-439). Its tests call `withVite()`, since `TestCase` turns Vite off for PHP tests. `composer check` leaves the suite out (`--exclude-testsuite=Browser`); it runs before every tag, and in CI on every push.

## Seeing what happened

Outside production, every public page the theme renders carries these headers, ASCII, with a Unicode slug's letters percent-encoded:

| Header | Says |
|---|---|
| `X-Teme-Template` | the view that rendered, such as `theme::templates.single` |
| `X-Teme-Template-Candidates` | every candidate tried: `templates.single-page (missing), templates.single (exists)` |
| `X-Teme-Composers` | the composers that ran and the keys each added: `SiteComposer(site, isFront), Greets(greeting)` |
| `X-Teme-Warnings` | only when something went wrong drawing the page, joined by `; `: a missing or trashed image, an image with no sizes made yet, an image context the theme doesn't declare, and on a single page and page 1 of the latest posts, `No priority image on this page` or `N priority images on this page` |

1.6a's `X-Teme-Address` stays. A redirect renders nothing and carries only that one. Outside production every response, a page's or a feed's, also says `X-Robots-Tag: noindex, nofollow`, replacing any value of its own (I-1022; `docs/architecture/page-cache.md`, *The static-file driver*).

With the page cache on (`docs/architecture/page-cache.md`), a public page also carries `X-Teme-Cache` (`HIT`, `MISS`, `STALE` or `BYPASS (reason)`), in production too, and outside production `X-Teme-Cache-Key`, `X-Teme-Cache-Tags` and `X-Teme-Cache-Ttl`. `X-Teme-Cache-Tags` lists every tag the page carries, up to 2,000 characters. A hit carries the headers of the render that stored it.

| Command | Does |
|---|---|
| `teme:theme:resolve {url} {--json}` | the kind, `isFront` and every candidate for an address, without rendering; a redirect says where it goes |
| `teme:theme:check {code?} {--json}` | a theme's errors, its built files compressed, and warnings past study Q §4's budgets (15 KB of CSS, 5 KB of JavaScript); fails on errors |
| `teme:themes:cache`, `teme:themes:clear` | the theme cache |
| `teme:themes:link` | the `public/themes` links |

## Errors

A public route's HTTP error renders through the theme's `error-{code}`, then its `error`, then core's error view (`bootstrap/app.php`, `PublicPage::error()`). **If the theme's error view throws, core's renders instead** (Ghost's rule, I-249), and the failure is reported. Admin and API errors are untouched.

## The Status banner

The Status banner (I-221) is its first home here. A `StatusCheck` says which ability lets someone fix its problem, and answers a `StatusBanner` while the problem lasts: its level, its message in plain words, where to fix it, and its priority. `StatusChecks` shows each person the highest-priority banner among the checks they may act on, shared through Inertia and drawn at the top of every admin screen, with no dismiss button: it goes when the problem does.

`ThemeUsable` is the first check: when `design.theme` names a theme that isn't installed or is broken, people with `settings.design.manage` read that visitors see a plain fallback, with the first error, and a link to Settings › Design.

`NavigationSlotsBound` (1.6d) warns people with `navigation.manage` when a place the active theme draws a menu in has none, naming each place by its label, with a link to Content › Navigation. Its priority, 50, sits below the theme check (100) and above the search rebuild (10), and it reads only the active theme's manifest and its one cached settings group.

`RepresentedPersonGone` (1.9j, I-1187) warns people with `settings.seo.manage` while the site represents a person who has no account: "The person the site represents no longer has an account, so search engines are told nobody in particular publishes it.", with "Choose who publishes the site" linking to that section of Settings › Search and social. Its priority, 40, sits below the menus' check (50) and above the search rebuild (10). `StatusChecks::forUser()` asks the ability first, so nobody else's screen reads the setting. The banner goes when someone else, or nobody, is chosen; the graph has already fallen back to nobody, so the warning is only that the choice needs making again (I-878). The Status page, when it is built (I-157), lists it from the same registry.
