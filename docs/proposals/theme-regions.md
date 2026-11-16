# Proposal: custom HTML and featured-post lists in theme regions

**Status:** draft (2026-11-16). **Decides:** v1 scope item C12 ("theme regions with custom HTML, and featured-post lists"; four of the five sites need it). **Builds on:** I-3127 (adopted 2026-10-28, option A: administrators keep named snippets in Settings › Site code; editors place them by name with a card), I-183 (rejected: a raw-HTML card), I-288 and I-3375 (site code and its page policy). **Plan:** `docs/plans/theme-regions.md`.

## What it is

Regions already work: a theme declares them, editors fill them under Content › Regions with prose and the cards the theme allows, and pages draw them. Two cards are missing:

- **Custom HTML.** An administrator writes the HTML once in Settings › Site code and marks it "Placed with a card". An editor then places it in any region that allows the **Custom HTML** card, by choosing its name. The code is drawn exactly as written, and its inline scripts and the hosts it loads from are added to that page's content security policy, as site code's already are.
- **Featured posts.** A list of posts in a region: either posts chosen by hand (up to 12, in the chosen order), or the latest of a type (posts by default), optionally only those filed under one category or tag, with a count (1 to 12), an optional heading, and images on or off. The theme draws it; core gives a plain default.

## Why

The sites being moved use 15, 11 and 12 Custom HTML widgets (mostly ad units and embeds) and five "featured posts" widgets each. The v1 needs study marked custom HTML as done because a `SnippetCard` exists, but that card shows code as highlighted text; it doesn't place code. Nothing lists posts in a region today.

## Settled

1. **Custom HTML is site code placed by a card, never HTML typed into content.** The card stores only the entry's ID. Only administrators write or change the code, with the password asked again, logged, and switched off everywhere by the host's `TEME_DISALLOW_SITE_CODE`. Editors choose by name and never see the code. (I-183, I-3127.)
2. **The code is not cleaned.** Ad units need their inline scripts; the page's policy allows each by hash, as for site code. The insertion cleaner stays for plugin markup only.
3. **A placed entry is drawn only where a card places it,** never in the head or body by itself. An entry that is off, deleted or switched off by the host draws nothing; the signed-in preview withholds it as it withholds all site code.
4. **The list is stored as data** (IDs, a type, a term ID, a count) and drawn when the page is drawn. Nothing rendered is stored.
5. **No new cache and no new purge.** The page records what the list shows by the page cache's existing tags (each chosen post; the type's list; the term). Publishing, unpublishing or refiling a post, changing the term, republishing the region and changing the code already purge after commit.
6. **Both cards belong in theme regions only.** A post or page holding one is refused; the editor offers them only when editing a region that lists them. So they never reach feeds, search, the API or the Markdown version.
7. **No new permission.** Placing a card is editing a region (`regions.manage`: editors and administrators). Writing code is `site-code.manage` (administrators).
8. **A theme may draw the list its own way** by providing `views/cards/featured-posts.blade.php`; core's version is used otherwise. The default theme provides one and lets its "After the article" region take both cards.
9. **One additive migration:** the site code table accepts the new place.

## No new screen

Content › Regions and the editor's region mode are built and stay as they are. Settings › Site code gains a fourth choice in its existing "Placement" list. What is new is two card views inside the editor, each a card in the writing column plus its settings in the card's panel, built from the existing `components/ui` parts without changing them.

### What the drawing shows (to the maintainers, before Task 5 is built)

Taken from the editor's existing card frame and settings panel (as Button and Code draw them), not designed afresh:

- **Custom HTML, in the column:** the card's label, the chosen entry's name, and a line for its state: on; off ("This code is off, so readers don't see it."); deleted; or the host's switch. With nothing chosen: "Choose the code to show".
- **Custom HTML, settings:** one list of the placed entries by name (marked when off), and, when there are none, "No code is placed with a card yet. An administrator adds it in Settings › Site code." For an administrator, a link to Settings › Site code.
- **Featured posts, in the column:** the heading (or "Featured posts"), a line saying what it lists ("Latest 5 posts in Reviews" or "4 chosen posts"), and the chosen posts' titles.
- **Featured posts, settings:** Heading (optional); a two-way switch, Latest or Chosen; for Latest, Type, Filed under (the term picker) and How many; for Chosen, the content search with the chosen posts as a reorderable list of up to 12; Show images.
- Both at desktop and at 375px wide.

## Open questions for the maintainers

- **Q1. Custom HTML through Settings › Site code (recommended), or a card holding its own HTML?** The brief for this part described "the custom HTML card's own text". I-183 rejected a card holding raw HTML, and I-3127 chose named site-code entries placed by a card. This proposal follows I-3127. The other way, a card holding its own HTML, would let any editor put markup into pages. It would then have to be cleaned like plugin insertions, which removes inline scripts and so breaks most ad units. **Recommendation:** I-3127 as adopted.
- **Q2. Should a featured list leave out the post the page shows** (Joomla offers this)? Not in this part: the region would need to know the page's item. **Recommendation:** park it as a Later idea, and build it when a site's theme asks.
- **Q3. Should both cards also work inside articles?** I-3127 imagined snippets in articles too. Articles need a different policy timing and every output (feeds, API, Markdown) would need a version. **Recommendation:** regions only for v1, with a Later idea.
- **Q4. Twelve as the most posts a list shows,** five by default. **Recommendation:** accept. (WordPress's widget defaults to 5 with no limit; emdash's to 5.)

## Not in this part

Mapping WordPress widgets into regions on import (I-348, parked; the import keeps listing them). Plugin region cards (I-3365, parked). Lists of comments, categories or archives. A sidebar in the default theme.

## References checked

| System | Has it? | Where we read | What it teaches |
|---|---|---|---|
| WordPress (`wordpress-develop-trunk`) | Yes: Custom HTML and Recent Posts widgets, block widgets | `src/wp-includes/widgets/class-wp-widget-custom-html.php` (`update()` keeps raw HTML only with `unfiltered_html`, else `wp_kses_post`); `class-wp-widget-recent-posts.php` (a count, default 5, no top limit, `ignore_sticky_posts`, `no_found_rows`); `class-wp-widget-block.php` | Raw HTML hinges on one capability, which is how stored scripting spreads. We keep the code with administrators only. The recent-posts list is a count and nothing else; ours adds a term and chosen posts |
| Joomla 6.2 | Yes: Custom module, Articles module | `modules/mod_custom/mod_custom.xml`; `modules/mod_articles/mod_articles.xml` (`mode`, `count`, `catid`, `exclude_current`, included and excluded articles, `title_only`) | Chosen-or-by-category in one module is what editors expect; "leave out the current article" is a real need (Q2). Custom HTML goes through the global text filters per group |
| Drupal (`drupal-main`) | Yes: block layout, custom blocks, Views blocks | `core/profiles/standard/config/install/filter.format.full_html.yml` (Full HTML only for `administrator`); `block_content.type.basic.yml`; `core/modules/views/src/Plugin/views/cache/Tag.php` | Unfiltered HTML is an administrator's format. Lists are cached by list tags and set aside when any item of the list changes, as our `list:` tags do |
| emdash | Yes: widget areas with content, menu and component widgets | `packages/core/src/api/schemas/widgets.ts`; `packages/core/src/widgets/components.ts` (`core:recent-posts`: count 5, thumbnails, date); `demos/playground/seed/seed.json` | No raw-HTML widget at all: content widgets are structured text. A recent-posts component with a count and image switch matches our card |
| Ghost | Partly: no widgets; site-wide code injection; themes list posts | `ghost/core/core/frontend/helpers/ghost_head.js` (`codeinjection_head`, site, post and tag); `helpers/get.js` (`{{#get "posts" limit filter}}`) | Code belongs to site-level settings; post lists are the theme's query. Our card gives editors the query without theme code |
| Wagtail | Yes: snippets placed by a chooser | `wagtail/snippets/blocks.py` (`SnippetChooserBlock`) | Placing a reusable item by reference, edited in one place, is the shape I-3127 chose |
| Statamic | Partly: globals; theme tags list entries | `src/Globals/GlobalSet.php`; `src/Tags/Collection/Entries.php` (`limit`, `taxonomy:` filters) | Lists by collection and taxonomy with a limit are a theme tag; no widget areas |
| Craft CMS 5 | Partly: global sets, no widget areas | `cms-5.x/src/elements/GlobalSet.php` | Site-wide content is structured fields; markup stays in templates |
| pursae (WordPress in Go) | Yes: widget areas, as WordPress | `docs/developer/themes/index.md` (widget areas and menu locations); `internal/plugins/sanitize.go` (plugin output cleaned for users without `unfiltered_html`) | Keeps WordPress's capability model and cleans plugin markup, as our insertion cleaner does |
| Capell, WebBlocks CMS, TypiCMS, Twill, October, Winter, Payload, Strapi | No region or widget feature found (Capell's `HasWidgets` is for admin dashboard widgets) | grep for widget areas, custom HTML and recent or featured posts across each tree | Nothing to add |

No outside system was needed.

## Decided (the maintainers, 2026-11-16)

All open questions as recommended:

- Q1 custom HTML: as recommended: a region card places a Site code entry by name (I-3127); no card holds its own HTML (I-183).
- Q2 leaving the shown post out of a featured list: parked.
- Q3 cards in articles: no; regions only in v1.
- Q4 maximum: 12.
- The two card views in the editor are drawn and shown to the maintainers before Task 5 is built.
