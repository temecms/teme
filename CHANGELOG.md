# Changelog

All notable changes to this project are documented in this file. Versioning follows the scheme in `docs/roadmap.md`.

## [Unreleased]

### Fixed

- Plugin link suggestions skip matches inside a code block (#536).
- Plugin link suggestions match whole words only, not inside longer words (#537).
- Plugin link suggestions link exactly the words they name, even words with letters such as İ that change length when compared (#539).
- A page chosen as the home page shares under the home page's title (as its browser tab shows), not the page's own name such as "Home". A social or SEO title of its own still wins.
- A content type, category or tag can no longer be given an address under `/themes/` or `/teme/`, where the web server serves theme and icon files: such pages were linked but answered "not found".
- A command or a page no longer waits half a minute when the database can't be reached: Teme gives up after 5 seconds (`DB_CONNECT_TIMEOUT`) and says so.
- Home greets you with your whole name, not only the first word.
- Long Settings group names in the side list wrap onto a second line instead of being cut off.
- An empty SEO title's placeholder is only the title the template makes, not "Using the title template:" as well.
- The design catalogue's sections fit inside the column at phone width instead of being clipped.
- The Themes Preview button goes through the catalogue, so it can be translated.
- Plugin table filters offer "All" as their empty choice instead of "Any" plus the label.

- On Plugins, Enable is now a clear primary button, no longer a grey shape that looks like the Disabled label beside it.
- The request sheet for a plugin or theme update says "The newest" instead of the raw version rule "Asked for *", and shows the version once it is known.
- Updating a plugin or theme installed from a Git address, a vendor's source or an upload now uses the source it came from, instead of always saying Packagist; one that was uploaded by hand says to upload the new version.
- Enabling a plugin that brings content types shows a short notice ("Added Showcase and 40 fields. Took over Documentation, keeping their items.") instead of every field by its key.
- The dialog that asks you to enable a plugin lists what it asks for with a check mark on each line, and says its public addresses in words ("Answers a form sent to /…") instead of "POST /…".
- Enabling a plugin from the console whose manifest can't be read now says so and why, instead of "No plugin is named".
- Enabling a plugin whose manifest names a route handler or a verifier that isn't in its code is refused, naming the manifest key, instead of failing later.
- Two plugins that require each other are refused, with a sentence naming the circle, instead of neither being enableable or disableable first.
- A background job of a plugin whose update is waiting for your agreement is cancelled instead of running.
- teme:plugins:schedules shows no next-due time for a schedule that can't run, and says why.
- A plugin that sends the AI something other than text is refused at once with a plain sentence.
- When a plugin's AI task is waiting for you to agree to it again, the refusal says so, instead of saying the plugin declares no such task.
- An inbox item from a plugin whose code starts with a digit is filed under that plugin, with its name and mark, instead of under Teme.
- The limit of 100 plugin notices an hour is counted in one step, so two processes can't both slip past it.

## [1.3.2] — 2026-11-20

### Changed

- The image builds on serversideup/php v5.2.0 (was v4.5.1), for PHP's and nginx's newer security fixes. Its nginx now redirects `/index.php/...` addresses to the address without it, and answers 404 for a PHP script that doesn't exist instead of running a parent file. Nothing to change on a running site: update Teme as usual.

### Fixed

- Teme reads its plugin and theme directory: it shipped without the directory's public key, so it refused every list ("This Teme has no key to check the directory's signature with"). A server that set `TEME_DIRECTORY_PUBLIC_KEYS` itself is unaffected.

## [1.3.1] — 2026-11-20

### Fixed

- Plugins that require `teme/contracts` install again: Teme recorded its own contracts as `dev-main`, so Composer refused any plugin asking for contracts 1.x. They are recorded at their release version, 1.3.0.

## [1.3.0] — 2026-11-19

### Added

- Install plugins and themes from Teme's directory. Plugins › Add plugin and Themes › Add theme have a **Directory** tab: search the reviewed listings, see each one's screenshots, the permissions it asks for and the Teme versions it works with, and install it through the usual request and installer. Teme checks the directory's signature, refuses an older or expired list, and checks every file it reads against the signed list. **Use the directory** in Settings switches it off.
- A plugin's and a theme's manifest can give an `icon` and up to eight `screenshots` with captions; the Plugins screen shows a plugin's icon.
- New doors for plugins in `teme/contracts`: `HtmlToDocument` turns HTML into Teme's own document; `VerifiedDirectory` reads the checked directory (with the `directory.read` permission); `ContentWriter::unpublish()` takes a published item back to a draft; `Submissions` stores what a visitor sends from a public form as an item waiting for review (with the `content.submissions` permission, rate-limited).

## [1.2.0] — 2026-11-19

### Added

- The admin follows the approved design throughout the editor, Settings, People and the account pages, sign-in, Tools and the content screens, in one consistent look:
  - **Editor:** a single writing column, the toolbar's extra tools folded under More, and the side panel's sections redrawn.
  - **Settings:** a Settings hub of cards, each page in one frame with a side list of the Settings pages and the current page's sections, and "Find a setting".
  - **People and your account:** all seven views of People, your own row, the account pages in one frame with Profile, Security, Two-factor sign-in, Notifications and Preferences, and the signed-out pages (sign in, reset a password, the invitation, first run) in plain words.
  - **Error pages:** "not allowed" and "not found" are drawn inside the admin, with the menu kept.
  - **Tools:** the Tools hub under three headings; Jobs with Mine and Everyone's tabs and a state filter; Import's sites with their state and figures while an import runs; Backups with a coloured verdict, the list of kept backups and "Use this backup", and restoring in three steps; Redirects with State, Status and Origin filters and the 404 log as its **Not found** tab; AI activity with the month's figures and a budget bar.
  - **Content:** the lists with thumbnails, a status pill for In review, Parent and Template columns on Pages, and a read-only pages tree; the media library with chips for missing alt text, unused and imported files, and **Edit image** with the focal point and the crops the site makes; comments, categories and tags, taxonomies and types, menus and regions in the same frame.
- Home shows the site's figures in one row of five, Published, Drafts, Scheduled, In review and Comments waiting, across all the content you may list, however many types the site has.
- Themes can read the running Teme version: every theme view has `$teme['version']` and `$teme['release']`.
- A plugin can bring its own content types, taxonomies and fields: `contributes.types` in `teme-plugin.json` names a file in the format Settings › Types exports. While the plugin is enabled they work like any other type, in the menu, the lists, the editor, the site, search, sitemaps, feeds and the REST API.
- Settings › Types shows a plugin's types, taxonomies and fields with its mark and "Comes from" its name, and keeps them read-only: the plugin's updates change them.
- Enabling a plugin takes over a type, taxonomy or field already on the site under the same key, keeping its items and values, and says what it took over and changed. A field made here under another key is matched by its type and handle. Enabling is refused, in words, when a field would change kind while items hold values in it.
- An update's changed types file waits, like a migration, until someone runs **Run database changes**; a type its new file no longer has stays on the site as the site's own, with its items.
- Disabling a plugin hides its types from the menu, the editor and the site, whose addresses answer 404, and keeps every item; enabling it again brings them back as they were.
- Removing a plugin is refused while its types hold items, trashed ones included, saying how many and where.

### Changed

- Lists show 10 rows a page by default (Preferences offers 10, 25, 50 and 100), and show thumbnails by default.
- The date under Home's greeting ends with a full stop before the sentence that follows.
- A type or taxonomy can't take an admin screen's name as its plural, such as Plugins, Media or Settings, or another type's or taxonomy's, in any case: the words name the screen and suggest a name to use instead, such as "Plugin directory". This holds for types made in Settings › Types, imported, declared by code or brought by a plugin. A type already stored with such a name keeps working, and Settings › Types asks to rename it.

### Fixed

- Teme SEO 1.0.1: a better average position on Search Console shows as a good change; posts without a featured image are counted on their own in the Audit; Cornerstone is one line with **Change** in the editor; the area is named Teme SEO; Search Console draws clicks and impressions as two charts; the Audit table reads the problems already stored, so it stays within its time budget on larger sites.

## [1.1.1] — 2026-11-18

### Fixed

- Text fields show a visible focus ring again when clicked or reached with the keyboard.
- A field's label and its "Required" mark have a space between them.
- Signing out goes to the sign-in page instead of showing the public site in a frame over the admin.
- The editor's meta description is a three-line text area again.
- Row actions in lists show without hovering.
- Compare versions keeps the space between a removed word and its replacement.
- The admin's error pages draw in the admin's own font.
- Dates in the admin follow the site's time zone and the admin's language, for example "13 Nov 2026".
- A region's side panel in the editor shows the region's own checks.
- Settings › Updates says to paste the Dokploy API key below, instead of pointing to the page itself.

## [1.1.0] — 2026-11-18

### Added

- Sockets for search engine plugins, in `teme/contracts` at 1.1.0:
  - Editor checks: a plugin's checks run on the server when a version is saved and show after core's own, under the plugin's name.
  - A plugin's own tab in the editor's Search and social section, with its fields drawn by core and saved with each revision.
  - Head tags, JSON-LD nodes that merge into core's graph, and sitemaps of the plugin's own, each with a time budget; a failure is recorded on the plugin's row and the page carries on.
  - The developer handbook's new "Search engine plugins" page, with a worked example.
- The FAQ card: questions that open their answers without JavaScript, with headings that follow the page's outline.
- The Steps card: a summary, total time, materials and tools, then numbered steps with text and an optional image.
- The WordPress import turns Yoast's and Rank Math's FAQ and how-to blocks into these cards.
- Teme SEO 1.0.0, a first-party plugin in `plugins/seo`: internal link suggestions and cornerstone items in the editor, links in and out, orphaned items, a site audit, a titles and descriptions editor, a Search Console overview, and AI link ideas and keyphrase rewrites through the site's AI connection. In each language Teme ships.

### Changed

- The README names the address for reporting security problems.

## [1.0.1] — 2026-11-17

### Fixed

- The image's web container starts again: PHP-FPM, run as the site's own user in a container that starts as root, can open the container's error output. Install 1.0.1; 1.0.0's image was not published.

## [1.0.0] — 2026-11-17

_1.0.0's image was not published (its web container failed the release check). Install 1.0.1, which has every change below._

Teme 1.0. Plugins can now do most of what a site needs from them: reach outside services, keep their own settings, pages, tables and permissions, add to the editor and the public pages, use the site's AI, read and write content, and talk to each other. Plugins and themes install from the admin. Sites can be multilingual for readers, join the fediverse, keep media in object storage, and run on Dokploy or as one container on any host. **Update to 0.8.0-alpha.1 before this one.**

### Plugins

- Outbound requests can post forms, and a plugin may reach the hosts named in its own settings once the administrator agrees
- Feeds: a plugin can add feed links to the site and is told when the site's feeds change, for WebSub hubs and search engine pings
- Plugins can read the site's name, address and language
- Settings and actions: a plugin can save its own settings, run actions from its settings page, and show lists there; settings pages gain sections, conditions and repeaters
- Page insertions: content in the head, at the top and bottom of the body, between the items of a list, beside a theme's regions, and fixed to the screen's edge, as well as inside articles
- A plugin's inline scripts wait for approval before they run, and the people who may change site code are told when one waits
- Root files: a plugin can serve plain-text files at the site's root, listed when the administrator agrees to the plugin
- A plugin's own area in the admin, with its pages, tables, record forms, charts and actions, all drawn by Teme from what the plugin declares; up to five areas in the menu, the rest on Tools
- A plugin can declare up to three permissions of its own, and the administrator chooses which roles hold them
- Plugins can add columns to the content lists
- Editor panels: a plugin can add a panel to the editor that suggests changes to the article, which the writer uses or discards
- The site's AI for plugins: a plugin declares its AI tasks and asks the site's own AI connection, within a budget and a request limit set in Settings
- Content and people doors: plugins read published items, drafts for the person they work for, and the people who write on the site; they create, change, publish and schedule content and upload media for that person
- Jobs and schedules: a plugin declares background jobs and schedules, with progress, delays and one-at-a-time keys; scheduled runs act as the administrator who enabled the plugin
- Public routes: a plugin can answer its own addresses on the public site, with signed inbound posts verified
- Import socket: a plugin can import the WordPress data it declares during an import
- Hooks between plugins: a plugin can declare hooks of its own, send them, and require other plugins
- Notices: a plugin can declare kinds of notice and send them, including to the holders of its own permission
- A plugin can set attributes on the links an article draws
- A plugin's words, its manifest's included, translate from its own language files

### Installing plugins and themes

- Add plugin and Add theme in the admin: from Packagist, Git, a vendor's source, or a ZIP upload
- A separate installer carries each request out: staged, verified, switched in at once, and rolled back if anything fails; installed extensions are rebuilt after a Teme update
- Private Git and vendor sources, with their keys kept out of every report
- Forget a plugin whose code is gone
- `teme:extensions:request` and `teme:extensions:cancel` from the console

### Themes

- Regions take two new cards: Featured posts and Custom HTML
- A theme's manifest can link its home page and its author; only https links are shown
- Templates can read the page's language, its direction, and its other language versions
- The theme checker refuses a page with no H1 or more than one

### Languages

- Settings › Languages: the site's languages, in order
- The editor's Translations section links an item's language versions, and the lists show each item's language with a column and a filter
- Menus can be shown to one language's readers, with a language switcher
- Readers get each page in its language, with hreflang and sitemap alternates
- Settings groups can carry their words in other languages
- The import reads Polylang's languages and translations

### Fediverse

- Settings › Fediverse: people on Mastodon and other fediverse servers can follow the blog, and its authors when you turn that on
- New and changed posts are delivered, signed, with retries per server
- Replies come in as comments, moderated as any other comment
- The WordPress ActivityPub plugin's accounts, keys, followers and settings can be imported
- Plugins can read and send fediverse activities

### Management API

- Outside tools can sign in with a key at `/api/manage/v1` to upload media and create, publish and schedule content, each key acting as one person with only the abilities given to it

### Import

- Other addresses of the old site can be edited on the import screens

### Media

- Media can live in S3-compatible object storage behind a CDN, with originals kept private and downloaded through short-lived signed addresses
- `teme:media:bucket-check` checks the bucket, and `teme:media:move` moves media to it or back, resumable and verified
- ads.txt can redirect to an ad network's file

### Hosting

- Dokploy: install from Dokploy's template list, from the template repository `temecms/temecms-for-dokploy`
- Dokploy: the API key is entered in Settings › Updates, and the updater finds its service by App Name
- Dokploy: an update can bring the template's new compose file, but only one the template has released
- One-container mode: with `TEME_ROLE=all`, one container runs the web server, workers, scheduler, installer and updater, on any host that runs a container with a database and a volume
- The image reads `DATABASE_URL`, and keeps media, the page cache and extensions in one mount at `/var/teme`

### Documentation

- The developer handbook: a page for every plugin socket, a first plugin and a first theme, and the generated contracts reference
- `teme/testing`, a testing kit for plugins and themes, used by the handbook's own examples
- The development site page covers the scheduler and the queue worker
- Screenshots of Teme, made from demo content by a workflow
- A new README that shows Teme and how to run it

### Fixes

- A list's header stays in view while the page scrolls, and row menus use the shared menu (#285, #286)
- Relative plugin and theme paths resolve against the project, and a skipped enabled plugin says why (#339)
- The fields reference lists the No inserted content field (#343)
- Fixes to the fediverse, the import and the language screens found in review before release

### Upgrading from 0.8.0-alpha.1

For operators:

- Back up the site first
- Update the image to `1.0.0`
- The deploy runs 17 migrations after its backup; none breaks the previous release
- On Dokploy, the API key now lives in Settings › Updates; a site on the old Dokploy compose file keeps `TEME_DOKPLOY_TOKEN` working, and a token set on the server still wins

For plugin and theme authors:

- Require `"teme": "^1.0"` in `teme-plugin.json` or `teme-theme.json`

## [0.8.0-alpha.1] — 2026-11-15

The admin's new look: one design system for every screen and the frame every screen sits in, then every screen rebuilt on it, Home arranged by each person, and a plugin's own database tables (slice 5.2's first release). **Update to 0.7.0-alpha.2 before this one.**

### Added

- One look for the admin, light or dark, with Ember as its accent: the design system's colours, type scale, radii and shadows as tokens (`docs/architecture/design-system.md`). More contrast follows the computer's own setting
- Every shared control, overlay and surface restyled; new radio group, switch, field, text button, keyboard key, segmented control, tabs, progress bar and scroll area
- The admin's shell: a sidebar with a search button, folding sections, sub-entries and counts, foldable to an icon rail; between 768 and 1023 pixels the rail, with the full menu opening over the page; below 768 a drawer. A 60-pixel top bar with the page's crumbs (a switcher on hubs and sections), jobs, a site-status button and panel read from the status checks, the inbox and the account; on phones one status button for jobs, the site status and the inbox together. Controls are at least 44 pixels on touch screens
- Shared parts: status pills, term chips with "+N", Soon tags (a planned menu entry can carry one), plugin marks and icons, icon tiles, people's pictures, step bars, one notice, the empty, loading and failed states, a save bar that asks before leaving unsaved changes, and one fresh-password prompt
- Page patterns for lists, forms, settings, hubs and details; Tools › Jobs, a backup place, Settings › Languages and Tools are on them
- A design catalogue at `/admin/_catalogue` on local and test sites only
- A check that holds the admin to its design system, with a list of today's exceptions that may only shrink
- Every screen rebuilt on the design system: settings, people and your account, the sign-in pages, content lists, the editor, the media library, the Tools screens, plugins' own areas, and dialogs and menus that open from the foot of a phone's screen
- One choice-and-search control for tags, people and other lists; typing a new tag creates it where you may
- Content lists: numbered pages (to page 50, then Next and Previous), each row's actions in one menu with Undo after Publish, Trash and Restore, a column chooser, and cards on a phone
- The editor: a handle beside each block to move, duplicate or delete it, a block picker, Tools with up to three pinned to the bar for each person, Search and social as a box with tabs under the article, a compare page, and one media picker shared with every screen
- The media library on one page, with a details panel that saves as you leave each field
- Home, arranged by each person: in Customize, panels move by their grip or with Move earlier and Move later from the keyboard, take one of four sizes, and can be hidden; plugins' panels sit in their own section
- People's pictures, each person choosing their own; someone who manages people can remove one
- Plugins can have their own database tables: their migrations run in one transaction when the plugin is enabled, checked against the plugin's prefix and the tables it declares, and recorded on its row. A plugin with new migrations waits until someone runs Run database changes (or `php artisan teme:plugins:migrate`), and Remove its data… drops exactly the tables recorded for a disabled plugin, or one whose code is gone

### Changed

- The three looks and the high-contrast setting are gone; a look someone chose is no longer read
- Toasts sit at the foot of the page, in the middle
- The admin's icons that pages use at first load share one file, so a screen loads fewer files; the full icon set still loads only when the icon picker or an unknown icon needs it
- The design system's components share one file, so each screen loads far fewer files: the media screen 52 to 22
- Every word a person reads in the admin goes through translation, in American English
- Signing in with the dark scheme no longer fades the admin from light to dark (#283)

### Fixed

- The import no longer counts a dropped table of contents, a listing of other posts, or sharing and related-posts boxes as lost words (#287)
- The import names a refusal from a firewall or Cloudflare's bot check instead of blaming the account (#289)
- Removing a site's comments page by page no longer skips rows on large sites (#265)

### Deploy

- The deploy runs two migrations, after its backup: they add a picture column to accounts and a column recording plugins' migrations, and change no existing row

## [0.7.0-alpha.2] — 2026-11-14

The first release from Teme's public repository, `temecms/teme`, with its image at `ghcr.io/temecms/teme`. It is 0.7.0-alpha.1 with the update check moved to the public repository.

### Changed

- The hourly update check reads releases from `temecms/teme`. `TEME_GITHUB_TOKEN` is optional: it only raises GitHub's limit on requests (#3)
- When the repository can't be found without a token, the update check no longer asks for one

### Deploy

- A site on 0.7.0-alpha.1 or older still checks the old repository, so it won't offer this release by itself. Update it once by hand: set `TEME_IMAGE` and `TEME_UPDATER_IMAGE` to `ghcr.io/temecms/teme:0.7.0-alpha.2` and `TEME_IMAGE_REPOSITORY` to `ghcr.io/temecms/teme`, then deploy. From then on Tools › Updates offers new releases as before
- `TEME_GITHUB_TOKEN` can be removed

## [0.7.0-alpha.1] — 2026-11-13

Slice 2.5a: Bylines, the foundation. An item can credit several people, in order, and a person needn't have an account: a news agency, a guest writer, or a writer who has left. Deleting an account keeps the departed writer's name on their work, and their author page answers at the same address. There is no screen for credits yet: they are set from the console, and the screens come in a later release. With this release the foundations of every area are in place. **Update to 0.6.0-alpha.2 (or 0.6.0-alpha.1) before this one.**

### Added

- An item can credit several people, in order, and a person needn't have an account; deleting an account keeps the departed writer's name on their work (#396). Add a person with `php artisan teme:people:add` and set credits with `php artisan teme:bylines:set`
- API: `authors` is every person an item credits, in order
- Theme view data: `$authors` are the item's credits
- Export: a `bylines` file, and each item's `bylines`
- Privacy: a writer's name is kept on erasure, and exported, by the rule deleting an account keeps it: an item they own with no bylines, or a byline naming them. The answer to an erasure request says so

### Changed

- API: `author` is the first person an item credits. For an item that credits several people, `author` and `?author=` now mean the first credit and any credit, where they meant the owner
- Hooks: `CacheKeysFor::PERSON` names a person by an opaque ID: still the account's ID for anyone with an account, another ID for a person without one. Never read it as a number
- Author pages are faster on sites where one account owns very many items

### Deploy

- After deploying, run `php artisan migrate`: the migrations add the people and bylines tables and one index, and change no existing row
- Rolling back from this release to anything older shows the wrong author on the pages of people without accounts. After rolling back to 0.6.0-alpha.1 or 0.6.0-alpha.2: inviting or importing an account whose slug a person without an account (or a departed writer) holds answers a server error and changes nothing, until you roll forward; and an address recompute this release queued reruns the term and account pages on that release's worker, then ends (harmless)

## [0.6.0-alpha.2] — 2026-11-13

0.6.0-alpha.1 with its image. The tag 0.6.0-alpha.1 published no image: the release's own check of a fresh site expected a page where a new site now shows its holding page until the first account exists. That check now sees the holding page, makes the first account and then asks for the page. Everything in 0.6.0-alpha.1 below is in this release, with two fixes to the editor.

### Fixed

- The editor no longer loses the last words typed before leaving it. Leaving by a link, the way back or the browser's Back button saves first and waits for the save; a long article's last save is no longer dropped; and saves that arrive out of order can't put older words over newer ones
- The editor tells a lost connection from an ended session, and from a save the server refuses, and says which. It asks before leaving while the latest words are saved nowhere, in Teme's own dialog, and never when everything is saved. A save that doesn't answer within twelve seconds asks, where it would have held the page

### Deploy

- After deploying, run `php artisan migrate`: one migration adds a column to the autosaves, and changes no row

## [0.6.0-alpha.1] — 2026-11-12

The foundations release: what the next features are built on, with few new screens. Pages can sit under pages; a site can hold several languages; every block of an article has a permanent ID; a person's data can be exported or erased on request; every email is a declared notice; a new site's first administrator is made in the browser; and every public page carries a content security policy, in report-only to begin with. **Install this release before any later one:** a later release adds a kind of address that this release is the first to answer safely.

Slice 4.5: Page parents, the foundation. A page can sit under another page, with an Order among its siblings. Its address follows its parents (/about/team/), and renaming a page, or hiding or publishing it, moves every page under it, each old address answering with a redirect. A parent that isn't on the site never shows in an address, a trail or the API. Themes that draw a breadcrumb trail get the page's parents, and search engines the same trail; the read API gives a page's parent, its ancestors and its children. A WordPress site imported from now on keeps its page tree; one imported before gets it back with php artisan teme:import:page-parents. Choosing a page's parent in the editor, in Quick edit or in a Pages tree comes in a later release.

Slice 6.1a: The foundation for publishing one site in several languages. Every post, page and term now carries a language, and translations of one item are linked as versions of it, each its own item with its own address under a prefix such as /fr/, its own drafts, schedule and comments. Teme can add, change, hide and remove a site's languages, translate an item into a new draft, tell when a translation is out of date, and choose which content types and taxonomies are translated, but there is no screen for any of it yet: Settings › Languages is a placeholder, and the screens come in a later release. On a site with one language nothing readers see changes; the site's search, sitemaps, feeds, llms.txt and the read API's lists keep to its default language, and a hidden language answers nothing. A page with a parent, or with pages under it, can't be moved to another language.

### Added (4.5)

- Pages' address pattern is `/{parent}/{slug}/` by default: `{parent}` is the slugs of the page's parents that are on the site, and Settings › Addresses checks a pattern that holds it
- Renaming a page, moving it, or publishing, hiding or deleting it moves the addresses of every page under it, each old address sending readers on to the new one; more than 50 pages move in the background, shown in the jobs indicator, and each page answers at its old address until the job reaches it
- A page whose new address is already taken keeps its old one; a move of more than 50 pages names it in Tools › Jobs, an import in its messages, and Bring back in `php artisan teme:import:page-parents --notes`
- Deleting a page for good puts the pages under it under its own parent, in their order
- A WordPress import keeps the site's page tree, and lists the pages it left where they were, with why
- A page's trail runs through its parents, as does its BreadcrumbList for search engines
- The export gives each item its `parent_id` and `position`

### Console

- `php artisan teme:import:page-parents {address}` puts the pages of a site imported before back under their WordPress parents, in the background; `--notes` lists the pages that stayed where they are, with why

### Theme view data

- `ArticleView::$parent`, `$ancestors` and `$children`: the page's parent, the pages above it (the top first) and the pages under it (in their order, at most 100), each an `ItemLink` with its published title, its address and its type; only the ones on the site, and none for a type that doesn't nest

### API

- `parent_id` on every item is its parent's ID while the parent is on the site, else `null`
- `ancestors` on a full item: the items above it that are on the site, the top first
- `/api/v1/items?parent=<id>` lists the items under an item, and `sort=position` (with `parent` only) orders them as the site does
- `/api/v1/types` says `hierarchical: true` for pages

### Deploy (4.5)

- After deploying, run `php artisan migrate`: four migrations add each item's parent and position, and give pages the `/{parent}/{slug}/` address pattern unless a site chose another; no address moves. Nothing needs rebuilding

### Added (6.1a)

- Every item and term has a language, stored only when it isn't the site's default, and translations of one item are linked as its versions, one for each language
- A language other than the default has its own address prefix, such as `/fr/`: its items, terms, archives, search and home page answer under it, and the default language's keep their addresses
- The site's languages, each shown to readers or hidden while it is translated, are added, changed and removed through their own Operations; a hidden language's addresses answer as missing ones, and a language can't be removed while anything is in it
- Translate makes a draft of an item in another language from its published version, with its links pointed at their versions in that language and its terms at theirs, making the missing terms
- Link and unlink versions, move an item to another language (its address follows, the old one redirecting), and see when a translation is out of date with its source, then mark it up to date
- Trashing, restoring, publishing and deleting a version keep its group whole: a version restored when its language is taken comes back on its own, and says so
- Content types and taxonomies are translated until the owner says otherwise; one that isn't keeps every item in the default language, and a taxonomy that isn't shares its terms with every language
- Public pages say their language (`<html lang>` and `Content-Language`)
- The export's `contents` and `terms` (version 2) write each one's `language` and `translation_group`

### Contracts (6.1a)

- `SearchRequest::$language` (`@since 0.6.0`): the language a search is in, null for the default; a `SiteSearch` that ignores it searches every language, as before

### Deploy (6.1a)

- After deploying, run `php artisan migrate`: five migrations, each adding but the last two: the language and translation columns and their constraints, the constraints' validation, eight indexes built concurrently, then the one-language slug index and term key dropped. Nothing needs rebuilding: on a site with one language no search row waits
- Once a site has a second language, rolling back to a release before this one shows its other languages' items in the default language's lists and makes a hidden language readable. Update forwards instead.

### Added (block IDs)

- The editor and the importer give every paragraph, quote, code block, list item, task item and table a permanent ID, as headings and cards have. An article written before gets its IDs the next time it is saved; opening it saves nothing. A pasted or copied block gets a new ID when the article already holds its own. The read API's body JSON carries the IDs; nothing else public prints them
- Rolling back to 0.5.0-alpha.1 or 0.3.0-alpha.1 keeps every article readable and editable; the older editor drops paragraph IDs when it next saves an article, and the assistant's Use is refused on a paragraph saved with an ID until the site is updated again

### Added (5.6, notices)

- Every email and inbox notice Teme sends is one declared kind, and Account › Notifications lists the kinds a person may turn off, by channel, built from that list. The seven emails Teme already sent keep their words and share one layout. Backup, update, invitation and password emails are always sent
- A notice waiting in the queue is not sent to someone who turned its kind off, or was suspended, before it went out
- For plugin authors, a preview until 1.0: the contracts under `Teme\Contracts\Notices` declare a notice kind and send a notice

### Added (5.5, privacy tools)

- A person can ask for a copy of what the site holds about them, or for it to be erased, and whoever runs the site answers from the console: `teme:privacy:export` and `teme:privacy:erase`, each for an email address, and `teme:privacy:prune` daily. A request can be continued if it is interrupted
- Erasing a person keeps their comments' words and takes away the name, email, website, IP address and browser; accounts, the security log and the block lists are kept, each with its reason, and the person is told which. A comment another account wrote with that address is left alone
- A request is kept for a year with its address replaced by a fingerprint after 30 days; an export's files are deleted after 7 days
- What erasure can't reach is written down: backups, site exports already downloaded, the queue, logs, and a comment a connected WordPress import has not yet brought across. Restoring a backup older than an erasure brings the data back
- For plugin authors, a preview until 1.0: a plugin that holds personal data joins through the contracts under `Teme\Contracts\Privacy`
- The two emails that answer a request, the export and the erasure, are notice kinds that are always sent, in the shared layout; a plugin's reason arrives as plain words

### Added (6.4, the public site's security policy)

- Every public page carries a content security policy: scripts run only from the site itself, from Teme's own inline scripts and saved site code (each by its hash), and from the hosts a theme or a site-code entry declares. A script injected into a page is not run
- The policy starts in report-only, which blocks nothing: `TEME_PUBLIC_CSP` is `report-only`, `enforce` or `off`. `teme:csp:reports` lists what browsers reported, and the doctor warns when there is something to look at
- Settings › Site code: each entry has *This code also connects to*, for the outside sites its code reaches; the sites written in the code itself are found and shown
- A theme declares the hosts its pages load from in the `csp` member of `teme-theme.json`, and `teme:theme:check` refuses a template with an inline script or an `on…` handler

### Deploy (6.4)

- After deploying, run `php artisan migrate`. Leave `TEME_PUBLIC_CSP` at `report-only`, read `teme:csp:reports` for a few days, declare the hosts the site really uses, and only then set `enforce`
### Added (the first-run setup page)

- A new site's first administrator is made in the browser, at `/admin/setup`: the site's title, a name, an email address, a password and the time zone. The page asks for a setup code, which the deploy prints at the end of its log and `teme:setup-code` prints again, so a stranger who finds a fresh site can't take it. `teme:user:create` still works
- Until that is done, every public address shows a holding page, "This site is being set up and will open soon.", with a note for the site's owner; search engines are told the site is unavailable for now and not to index it. The read API answers a problem, and feeds, sitemaps and robots.txt a line of text
### Fixed

- A gallery, video, callout or imported-content card that is copied and pasted, or moved by cut and paste, keeps its settings exactly. Before, a list came back as text and text such as "2024" as a number, and the article then refused every save
- A site that has lost some of its media files can be restored again: the restore's safety backup no longer counts files that were already missing as a failure, says how many it lacks, and says so again if the restore later stops. When every media file is missing, which is how a media folder that isn't mounted looks, the restore stops before it replaces anything and says what to check
- Tools › Backups, just after backups are set up, gives one time for the first backup where it gave two that disagreed, and shows the first backup as it happens. It stops asking the server once that backup finishes or fails, and after ten minutes in any case
- Tools › Backups no longer says a read-back failed in the minutes after backups are set up: a read-back waits until a backup has finished, and its run is listed once, not twice
- A restore and a deploy can no longer change the database at the same moment: a restore holds the deploy's lock for as long as it runs, and is refused, before anything is written, while a deploy is running
- An address whose kind this version doesn't know answers "not found" for its page, its feed and its old addresses, and is named in words where an address clash is reported; it was read as the first account's author page. A later release adds a kind of address, so this release must be installed before it: an update that skips this release can't be rolled back onto an older one safely

## [0.5.0-alpha.1] — 2026-11-12

Slice 5.1: The plugin API's first release. A plugin is a Composer package that whoever runs the server adds to the site's build. Teme finds it and checks it, and runs it only once an administrator has enabled it and agreed to what it asks for. An enabled plugin hears eleven hooks after a change is saved, adds its own settings, Tools links and Status banners, and reaches services outside the site only through Teme's door, to the hosts it was granted. **The plugin contract** (5.1-0), **the hooks** (5.1a), **plugins found, checked, enabled and disabled** (5.1b), **a plugin's lists and doors** (5.1d), **the join** (5.1j) and **Site › Plugins** (5.1c). Stopping a part of a plugin that keeps failing, and the generated hooks reference, wait for a later release.

### Added (5.1-0)

- `teme/contracts` publishes the JSON Schema of `teme-plugin.json`, at `schema/teme-plugin.schema.json`, the three things a plugin can ask for (`content.read`, `media.read` and `http`), and the contracts for status checks and outbound requests.
- The permission `plugins.manage`, for administrators.

### Added (5.1a)

- `teme/contracts` publishes the hook contract, `Teme\Contracts\Hooks`, and eleven hooks, each a class of plain values.
- Teme announces changes to content, files, comments and settings. Each announcement is delivered after the change is saved and the page cache purged, and never after a change that was rolled back.
- A listener that fails is reported, and the other listeners still hear the hook.

### Hooks

- The first eleven hooks, a preview until 1.0: ContentSaved, ContentPublished, ContentUnpublished, ContentScheduled, ContentTrashed, ContentRestored, ContentVisibilityChanged, ContentDeleted, MediaStored, CommentStatusChanged, SettingsChanged.

### Added (5.1b)

- Teme finds every Composer package of type `teme-plugin` with a `teme-plugin.json` and checks it: its manifest, the Teme versions it works with, what it asks for, the hooks it listens for, and its code. A plugin may not use Teme's own code, or ask Composer or Laravel to load it on every request, since that would run it while disabled.
- `php artisan teme:plugins` lists the plugins with their version, state and why. `php artisan teme:plugins:enable <name>` says what a plugin asks for, and enables it when run again with `--agree`; `php artisan teme:plugins:disable <name>` disables it.
- Enabling checks the plugin afresh and records what was agreed to, for which version and by whom, in the security log.
- An enabled plugin starts on every request but a fixed list of safe pages and commands: signing in, the Security page and two-factor set-up, Site › Plugins, Tools › Updates, and the console commands that build, migrate or take down Teme. So a plugin that breaks the site can always be disabled.
- `TEME_DISABLED_PLUGINS` on the server keeps the plugins it names from starting, or every plugin with `*`.
- An update that asks for more than was agreed to leaves the plugin disabled until someone agrees: "Update :version is blocked: it asks for more access than you agreed to."
- A banner on Status when an enabled plugin can't run, and why.
- `php artisan optimize` caches the installed plugins (`teme:plugins:cache`), and the site's image does it when it is built.

### Added (5.1d)

- A plugin's `teme-plugin.json` can add settings, shown under Settings › Plugin settings; links on Tools, an outside link opening in a new tab; and status checks, whose banners sit below every banner of Teme's own.
- A plugin reaches outside services only through Teme's door: https to port 443, to the hosts it asked for and was granted, at public addresses only, following no redirect, within 10 seconds and 1 MB.
- A plugin reads every setting that isn't a secret, and only its own secrets.

### Added (5.1j)

- An enabled plugin's listeners hear the hooks, and its settings, Tools links and banners appear. When it is disabled, everything it added is gone from the next request; its settings' values are kept.

### Added (5.1c)

- Site › Plugins, beside Themes, lists every plugin with its state and why, under All, Enabled, Disabled and Needs attention. No plugin runs on this page, so one can always be disabled here.
- Enable opens a dialog that says plugins are trusted code, what the plugin asks to do and what it listens for; Teme asks for your password when you haven't typed it recently. Disable takes one click.
- The Status banner about plugins opens Site › Plugins.

### Added

- The admin's words now come from one list, `lang/en.json`, that PHP and the screens share, ready for other languages later. `__()` and `__n()` from `@/lib/i18n` read it in TypeScript, and a test fails on any new word a screen writes outside them.

### Deploy (5.1)

- After deploying, run `php artisan migrate`: it adds the `plugins` table and gives administrators `plugins.manage`.

## [0.4.0-alpha.1] — 2026-11-12

Slice 4.1: The theme contract. A theme author can now build against a surface that is written down and tested. **The contract on paper** (4.1a): the theme's manifest has a published JSON Schema, a generated reference lists everything a template can read, and a test fails when one of those values changes without a line in this file. **The theme's view data** (4.1b): a template gets a page's fields as part of the page, each listing card carries its item's fields, a term's page knows its parent and children, and no value a template reads is a Carbon or Laravel object. Until Teme 1.0 the contract is a preview: a value may change in a minor release, and each change is named here under *Theme view data*.

### Added (4.1a)

- `teme/contracts` publishes the JSON Schema of `teme-theme.json`, at `schema/teme-theme.schema.json`. Point a manifest's `$schema` at it for an editor's help. A test keeps the schema in step with what Teme checks.
- `php artisan teme:docs:theme` writes the theme reference, `docs/reference/theme.md`: each kind of page and the templates Teme tries for it, every value a template reads, and every `<x-teme::…>` component with its attributes.
- `docs/reference/theme-view-data.json` pins the values the reference lists. A test fails when one is renamed, retyped or removed without a line under *Theme view data* in this file.

### Changed (4.1a)

- A theme whose `teme-theme.json` names `parent` or `extends` can't be used, and Site › Themes says why: "Teme has no parent or child themes: copy the theme you would build on, and change the copy."

### Added (4.1b)

- Each listing card answers `$item->field('handle')` for its item, as an article does. A page reads its cards' fields together, never with one query per card.
- A term's page has the term's taxonomy, its parent and its children that hold published items, as `$page->subject->taxonomy`, `->parent` and `->children`. When a child gains or loses its published items, the term's page changes with it.
- The theme reference has a *Field values* table: what each field type gives a template as its value and its text, and when the field counts as empty.

### Changed (4.1b)

- A theme's composer may not return `language`, `direction` or `alternates`: Teme keeps these names for a page's language. Such a composer stops the page with an error outside production; in production the error is reported and the name is left out.

### Theme view data

- `ArticleView::field()` now gives a `FieldView`: the field's label, type, plain value and the text to print. `{{ $page->field('runtime') }}` still prints it; `->value` is the value itself, `->url` and `->image` what to link or draw.
- `ArticleView::shownFields()` may be called with no place: it then lists the fields shown before the article and after it, in order.
- Dates a template reads (`ArticleView::$published`, `$updated`, `$revised`, `ListingItem::$published` and date fields' values) are PHP's own `DateTimeImmutable`, no longer Carbon. `->format()` works as before.

## [0.3.0-alpha.1] — 2026-11-12

Slice 3.1: The Delivery API. With a switch in Settings › Search and social, apps, other sites and tools can read a site's published content as JSON at /api/v1: items of every public type with their fields, terms, images and body, terms and taxonomies, and the files they show, never anything a reader can't see. It is cached with the pages, so a change shows at once. A second switch publishes /llms.txt and a plain-text copy of each listed item.

### Added (3.1)

- `GET /api/v1/items`: published items of every public type, newest first (or oldest with `sort=published_at`), by cursor (`limit`, `after`, `links.next`), filtered by `type`, `term`, `author`, `published_since` and `published_before`.
- `GET /api/v1/items/{id}` and `GET /api/v1/items/by-path?path=`: one item in full, with its terms, author, featured image, the fields shown on the site, and its body as HTML and as Tiptap JSON with every link at its address now; a protected item without its words; anything a reader can't see answers 404 as if it never existed.
- `GET /api/v1` names the site; `GET /api/v1/types` and `/taxonomies` list the public types (with their archives, taxonomies and shown fields) and taxonomies; `GET /api/v1/terms` and `/terms/{id}` give terms with a count; `GET /api/v1/media/{id}` gives a file while a public item shows it.
- The page cache stores the API's answers like pages, under the same tags, so a publish, an edit or a visibility change purges both; an API address's key keeps its sorted query.
- Warming redraws only pages: API answers, `llms.txt` and `.md` pages wait for their next reader.
- `llms.txt` at the site's address lists the newest published items of each public type with a link to a plain-text copy of each; each listed item and the home page has a Markdown copy at its address ending `.md`; both are off until Publish llms.txt is ticked and nothing is listed while the site discourages search engines.

### Added

- An export now carries the site's own content types, taxonomies and fields as `teme-types.json`, named in the manifest as `types_file`; bring it in first with `teme:types:import`.
- A stored document accepts an `id` on paragraphs, quotes, code blocks, list items, task items and tables, as it did on headings. Nothing makes these IDs yet: this release only accepts them, so the release that makes them can be rolled back safely.

## [0.2.0-alpha.1] — 2026-11-10

Slice 2.1: Custom content types. A site makes its own kinds of content, such as Films or Recipes, without code, and they work like posts and pages. **Types in the database** (2.1a): a site's types are saved on the site, in a file `teme-types.json` that moves them from one site to another, with a lock for a site that changes them only by file. **The type builder** (2.1b): Site › Types makes, changes and deletes them. **Permissions of their own** (2.1c): a type can have its own six. **Archives across types** (2.1d): categories, tags, authors and dates list every type they hold, and Settings › Reading says which types the home page shows. **A listing page for each type** (2.1e). **Pages' own permissions** (2.1f).

Slice 2.2: Custom fields. A type gets fields of its own, such as a release date, a rating or a poster, made on a screen and shown in the editor and on the site. Slice 2.3: Custom taxonomies. A site makes its own ways of grouping content, such as Genres or Studios, beside categories and tags.

### Added (2.2)

- Site › Types › a type › Fields adds, changes, reorders, removes and restores the type's own fields, Posts' and Pages' included: text, long text, number, choice, yes or no, web address, date and time or a date alone, media, and one item of the site
- The editor shows a type's fields in Details, under the body; a required field must be filled in to publish or schedule, never to save a draft
- A field can be included in site search, and the default theme lists the fields set to show, after or before the article, with their labels, leaving out empty ones
- Removing a field hides it and keeps every item's value; restoring it brings both back. A field's kind never changes once it is made
- `teme-types.json` carries a type's fields, and an import adds and updates them and never removes one
- The type's own page says which fields it has, with Manage fields

### Added (2.3)

- Site › Taxonomies lists the taxonomies built into Teme and the site's own, and makes, changes and deletes the site's own: names, flat or nested, the types that use it, its place in the admin, and whether its terms have public pages
- A taxonomy made a moment ago has its term screen, its picker in the editor and its public pages at once
- `teme-types.json` carries a site's taxonomies with its types and fields, in one import
- A banner on Status when a saved taxonomy can't be used, and why

### Changed (2.1f)

- Pages are written by editors and administrators only, as in WordPress: authors and contributors no longer start, edit, publish or delete pages, those they wrote before included; those pages keep their author's name and editors look after them, and a page an author scheduled waits on Home for an editor
- Administrators and editors get the permissions `page.create`, `page.edit.own`, `page.edit.any`, `page.publish` and `page.delete.any` (administrators also `page.delete.own`) on a site that is updated; run `php artisan migrate`

### Added (2.1f)

- Site › Types › Pages says "Pages have permissions of their own."; the editors' guide says pages are written by editors and administrators

### Added (2.1e)

- Each public type with an archive page has a listing of its own, such as `/films/`, newest first, with a feed at `/films/feed/` and an entry in the sitemap
- Settings › Addresses sets each archive page's address and says what holds it when something else does; a moved page's old address sends readers to the new one
- Settings › Search and social sets each archive page's title and hides it from search engines

### Added (2.1d)

- Settings › Reading says which types the home page and the main feed list; a category, tag, author or date page lists every type that belongs there
- Feeds and sitemaps carry the same types as the pages they belong to

### Changed (2.1d)

- A page's `{category}` address comes from its own type's first category-like taxonomy, and only that taxonomy's terms move addresses

### Added (2.1c)

- A type can have its own create, edit own, edit any, publish, delete own and delete any permissions, each role starting with what it may do now; Site › Types shows who holds each
- `teme-types.json` carries those grants, and an import keeps a site's own

### Added (2.1b)

- Site › Types lists the types built into Teme and the site's own, and makes, changes and deletes the site's own: names, icon from every Lucide icon, admin and public address, what it supports, taxonomies, comments, public, search, archive page and one item only
- A type that holds one item only opens that item from its menu entry, and refuses a second
- The types file downloads from Site › Types

### Added (2.1a)

- A site's own content types are kept in the database, and a type made a moment ago has its screens, addresses and settings at once
- `php artisan teme:types:export` and `php artisan teme:types:import`, which write and read `teme-types.json`; an import adds and updates and never removes a type
- `TEME_TYPES_LOCKED=true` stops anyone changing types in the admin, so they change where they are made and arrive by `teme:types:import`
- The permission `types.manage`, for administrators
- A banner on Status when a saved type can't be used, and why

### Deploy (2.1 to 2.3)

- After deploying, run `php artisan migrate`: it adds the tables of types, taxonomies and fields, `types.manage`, the pages' six permissions and a wider address column
- A site whose authors or contributors wrote pages should say so to them before the update: they no longer can

## [0.1.0-rc.19] — 2026-11-10

Two fixes from the first updates a live site made from Tools › Updates.

### Fixed

- Tools › Updates no longer shows a white "404 page not found" box while the site restarts during an update: it keeps what it showed and asks again. It also keeps refreshing at full speed when its tab is in the background
- The default theme works with every Teme before 1.0. It asked for 0.1.x alone, so the first 0.2.0 release would have found it incompatible and drawn sites without their theme

## [0.1.0-rc.18] — 2026-11-10

One fix, found by the first update a live site made from Tools › Updates.

### Fixed

- On a site with the page cache on, a deploy now records itself. Before, Tools › Updates said "No deploy recorded yet" after real deploys, and an update asked for from the admin switched the site to the new release, waited at its first step for an hour, then put the release before back

## [0.1.0-rc.17] — 2026-11-10

A small release, and the first one a live site updates to from Tools › Updates.

### Fixed

- The box that asks for your password again, when inviting someone and on Tools › Backups, no longer says your password confirmation has expired when you had not been asked yet. It says "Type your password to carry on."

## [0.1.0-rc.16] — 2026-11-10

Updating from the admin, on Dokploy. Proved in tests against a stand-in for Dokploy; its first run on a live server follows this release.

### Added

- Tools › Updates: **Update to …** asks for your password, then updates the site and shows each step as it happens. It waits for a quiet moment, when no backup or job is running, for up to 15 minutes, and switches back by itself if the new release isn't healthy
- **Roll back** on Tools › Updates returns to the release before, and a request the updater hasn't started can be cancelled
- An `updater` service in the Dokploy file, which carries the update out through Dokploy: it changes only the image setting and deploys. The Dokploy guide has its settings, the short outage while the service deploys, and what its token can do

### Changed

- The Dokploy file gained the `updater` service and its settings. A site installed from the earlier file replaces its Compose file once, by hand, and adds the settings: see Updating from the admin in the Dokploy guide
- On plain Docker Compose there is no updater yet: Tools › Updates shows the steps to update by hand

## [0.1.0-rc.15] — 2026-11-09

Fixes from the first days on a live server, the standard Dokploy install, and the updater's groundwork.

### Fixed

- Refreshing the editor, or opening it from a pasted address, no longer answers 502: the page no longer sends one preload header per script file, and the image's web server allows larger headers
- Inviting someone works after Teme asks for your password again: the password is asked inside the invite dialog, and the invitation is sent straight after, with its notice or the email's error

### Added

- Teme on Dokploy as one Compose service, `docker/compose.dokploy.yml`, with its guide: its own database and limited login made on every deploy, the passwords in the service's settings, and limits on CPU and memory
- The updater's groundwork: an update can be requested and cancelled from the server's command line, and a separate updater carries it out step by step and switches back if the new release isn't healthy. It isn't connected to a hosting panel yet, so it changes nothing on a server
- A restore ends any update the restored database still holds open

## [0.1.0-rc.14] — 2026-11-08

Distribution, part 1: Teme knows which release it runs and tells administrators when a newer one is out. The first release with its own version in the image and its notes on GitHub.

### Added

- Tools › Updates: the release this site runs, the releases after it with their notes, when Teme last asked for them and what went wrong, the deploys, and how to update by hand. Its Update button comes with the updater, in a later release
- A banner on every admin page, for administrators, when a newer release is out; a warning when one carries security fixes
- An hourly check of Teme's releases on GitHub. It sends nothing about the site, follows the version this site runs (pre-releases until v0.1.0), and can be switched off with `TEME_UPDATES_CHECK`
- Every `php artisan teme:deploy` is recorded: the release, the outcome, how long it took, the migrations and its backup
- Each image knows its own version and commit, and every version tag becomes a GitHub Release whose notes are this changelog's section

### Changed

- A backup taken while an update deploys restores onto the release it came from, and an old backup that says 0.1.0 is never read as v0.1.0
- A development build that isn't a release follows the pre-release channel
- The Settings menu stays in view while a long settings page scrolls
- Importing from WordPress reads media twenty at a time, and rides out about an hour of the old site not answering

### Fixed

- Tools › Updates opens before the first check and when checks are switched off

## [0.1.0-rc.13] — 2026-11-04

Slice 1.13: Deployment and the full test migration. Teme ships as one image with a Compose recipe and updates a site with one command. **The edge and the start** (1.13a): secrets from files, only the proxies named trusted, only the site's own host answered, and HSTS and secure cookies in production. **Health and the doctor** (1.13b): `/up` checks the database, the migrations and the shared folders, and `php artisan teme:doctor` checks the server, with a banner when the scheduler or the queue stops. **`teme:deploy`** (1.13c): an update under a lock, backed up first when migrations wait. **The image, the recipe and CI** (1.13d). **The operators' guides and the migration runbook** (1.13e). **The editors' guide** (1.13f). **The proof** (1.13z) compares two servers line by line after a restore, and gives a version tag the image already proved.

### Changed (Teme’s name and icon)

- Teme’s own name and icon: the README is Teme’s, the project is `teme/teme`, and Teme’s icon marks the admin’s browser tab and phone icon until a site uploads its own, with “Powered by Teme” at the foot of the sign-in screens; a site’s public pages never carry it (I-3287, I-3288)

### Added (1.13z)

- `php artisan teme:site:fingerprint`, which writes each table's rows and what a sample of the site's addresses answer, with the site's own address made neutral; with `--compare`, it asks this site for every address another server's fingerprint lists and says whether the two match
- A version tag on a commit whose image is already built gives that same image its version, digest and all, instead of building it again

### Added (1.13f)

- The editors' guide, `docs/guides/editors/`: signing in, your account, writing, publishing and scheduling, revisions, finding, editing and trashing items on the lists, media, organising, comments, redirects, the main settings, the page cache, the Status banners and backups, each task in the admin's own words and checked against the admin by tests

### Added (1.13e)

- The operators' guides, `docs/guides/operators/`: installing with the Compose recipe, configuring, the database role, sizing, proxies and HTTPS, Cloudflare, Dokploy, Coolify and a plain VPS, updates and rollback, backups, restoring and moving, troubleshooting every check the doctor makes, and the migration runbook; each page says how far it is proved
- `docker/compose.proxy.yml`, Traefik in front of the recipe on a server with no panel

### Added (1.13d)

- Teme's image, built in stages on serversideup/php with PostgreSQL 18's tools, restic and SSH's client checked as it is built; its code read-only and only `storage/` writable; gzip, and year-long caching of files whose address changes with their content
- `docker/compose.yml`, the recipe: one image in the roles deploy, web, worker, worker-long and scheduler, PostgreSQL with a role that isn't a superuser and the spare database for test restores, and Valkey for the page cache if wanted
- The image workflow: the image built, scanned by Trivy with an SBOM, the recipe started and deployed twice at once, the site checked, and the image published to GitHub's registry by commit and by version, never as latest

### Added (1.13c)

- `php artisan teme:deploy`, which checks the server with the doctor, takes a lock a second deploy waits for, backs the site up when migrations wait and backups are set up, migrates, queues the rebuilds a release asks for, restarts the queue's workers, and clears and warms the page cache; with `--check`, `--json` and an exit code for each outcome
- A new site's four roles come from its migrations

### Added (1.13b)

- `/up` answers 503 when the database, the migrations or the shared folders aren't right, and says why only in the log
- `php artisan teme:doctor`, which checks this server and says how to fix what isn't right
- A red banner for administrators when the scheduler has stopped, or queued work waits with no worker to take it

### Added (1.13a)

- Every secret Teme reads may come from a file, as `APP_KEY_FILE` and the others
- In production: the private networks trusted as proxies unless told otherwise, a request for any host but the site's refused, HSTS, Secure cookies and a `__Host-` session cookie; Teme refuses to start without `APP_KEY`, or with sessions kept anywhere but the database

### Changed (1.13d)

- Teme requires PHP's `intl` extension (#136)

### Changed (#152)

- The writing area fills the room, and Search and social starts closed and opens in place

### Changed (#119, Part 1)

- The list header’s filters on one line, the Date control, Screen options as a panel, and the month counts

### Changed (#149)

- Titled panels, a Save bar that says what it saves, and fields that fit their values on every settings page

### Changed (#154)

- Row actions as links under each title, the rest under More, on every list

### Fixed

- The setup script points Playwright's wanted Chromium at the one the sandbox has, whatever the revisions
- The setup script reads PHPStan's commit from composer.lock
- Worker-long runs with the other roles, now the long queue has its own connection
- The spare database empties itself on a fresh session when the restore's own one can't
- The lock lets go by asking even when the work broke its session's transaction

## [0.1.0-rc.12] — 2026-11-02

Slice 1.12: Backup and restore. Every night Teme backs the site up, its database and its media with images in every size, encrypted with restic, to each place backups are kept, a folder on this server, S3-compatible storage or an SFTP server; it checks each backup where it lands, reads a seventh of them back every night and restores the newest into a spare database every week; it opens with a recovery key Teme shows once and never keeps; and `php artisan teme:backup:restore` puts the site back, on this server or on a new one under another address. **The seam** (1.12a) holds the records, the lock and the settings; **re-encrypting secrets** (1.12b); **keys and places** (1.12c); **the backup run** (1.12d); **verification and alerts** (1.12e); **restore** (1.12f); **the export** (1.12g); **Tools › Backups** (1.12h); **Back up now** (1.12i). **The proof** (1.12z) backs up and restores a site of 40,000 images, every step timed.

### Fixed (#188)

- A WordPress password's hash, upgraded at sign-in, keeps the person signed in: Laravel 13.39 records the password's fingerprint at sign-in, and the upgrade changed it

### Changed (#130, #163, #188)

- Laravel 13.39 and the other Composer, npm and GitHub Actions updates since rc.11, every `@radix-ui` package among them

### Changed (#176)

- The admin in plain words, from the wording pass: each screen names the item and the person rather than "article" or "user", and says what happens without internal terms such as restic, prune, tombstone or a console command
- The editor's refusals say what's wrong in a sentence, without the document's path; the side panel reads "Post settings" or "Page settings"
- Notes sit under their section's heading, before the list, rather than above the pager, and links that act are buttons: Open inbox, Set a budget, Change log settings, Choose the home page and others
- The sign-in, password-reset and two-factor messages are Teme's own

### Added (1.12z)

- `php artisan teme:bench:backup`, which builds a synthetic site of images (40,000 unless told otherwise), backs it up, backs it up again after a night’s change through Back up now, reads it back, prunes it and test-restores it, and times every step

### Added (1.12i)

- Back up now on Tools › Backups: a backup that waits its turn and starts within a minute, followed step by step on the screen and in Tools › Jobs, with Cancel and Retry
- The nightly backup is a job in Tools › Jobs, and every backup alert also reaches each administrator’s inbox
- A restore ends the jobs the old server left open

### Added (1.12h)

- Tools › Backups, for administrators: whether the site is safe, in one sentence first; the places backups are kept, with Test, Change and Remove; the latest checks in words; the recovery keys, with Check, Add and Replace; and when backups run and what’s kept
- Setting backups up from the browser: the first place, then the recovery key shown once, with a page to print, and two of its groups typed back
- After a restore, the screen asks whether this server is now the site or a copy, and keeps its places paused until someone says

### Added (1.12g)

- `php artisan teme:export`: the site’s content, people, terms, media records, comments, menus, redirects and settings as NDJSON files with a manifest, and the media’s originals beside them, in the format `docs/reference/export.md` describes; it carries email addresses, never commenters’ IP addresses, password hashes or secrets

### Added (1.12f)

- `php artisan teme:backup:restore`, which puts a site back from any complete backup, on this server or on a new one under another address and `APP_KEY`, behind maintenance mode and after a safety backup, and refuses before it writes anything when the backup can’t be restored here; `php artisan teme:backup:list`; and `php artisan teme:backup:restored`, which says whether this server is now the site

### Added (1.12e)

- Every night a seventh of each place’s backups is read back, and every week the newest backup is restored into a spare database and its rows and 50 of its files compared with its manifest; `php artisan teme:backup:verify`
- The site’s backup health on every admin page, for administrators: red when backups aren’t set up, the newest is too old or a check failed; amber while every place is on this server
- An email to every administrator when backups start failing, at most one a day while they keep failing, and one when they’re fixed

### Added (1.12d)

- Nightly backups, and `php artisan teme:backup:run`: the database and the media, images in every size, with the site’s key and a manifest, to every place, checked where they land, and kept for 7 days, 4 weeks and 12 months; deleting a file waits while a backup runs

### Added (1.12c)

- Places to keep backups: a folder on this server, S3-compatible storage or an SFTP server, each locked with the machine key and the recovery key typed once; a recovery key shown once and never kept, more recovery keys, and a machine key the server keeps; `php artisan teme:backup:setup`, `teme:backup:destinations` and `teme:backup:key`

### Added (1.12b)

- `php artisan teme:secrets:reencrypt`, which writes every value Teme encrypts again under this server’s `APP_KEY`, opening each with an old key; a restore calls it, so a site moved to a new server still reads its two-factor secrets, its keys and its reader passwords

### Added (1.12a)

- The permission to manage backups, for administrators only; `php artisan teme:backup:tools`, which says whether restic and PostgreSQL’s tools are installed at versions Teme can use

### Deploy (the list header)

- After deploying, the operator runs `php artisan teme:counts:rebuild` once on each site, so the month counts behind the lists' Date control take in what was saved while the deploy ran (`--check` reports the drift first)

## [0.1.0-rc.11] — 2026-10-29

Slice 1.11: Import. A WordPress site comes across whole through the Teme Companion plugin, version 0.2.0 or later: its people, categories and tags, files, posts and pages, comments, menus, settings and redirect rules, with every old address kept or sent on, and a report that gives everything an outcome. **The seam** (1.11a) runs an import in the background, item by item, and carries it on from where it stopped; **passwords** (1.11b); **Audio and Video cards** (1.11c); **the downloader** and Import images (1.11d); **people, categories and tags** (1.11e); **the converter** for blocks (1.11f) and for classic content and shortcodes (1.11f, part 2); **Tools › Jobs and the inbox** (1.11g); **files** (1.11h); **the Imported content card** (1.11i); **posts and pages** (1.11j); **comments** (1.11k); **menus** (1.11l); **addresses, settings, redirects and the address check** (1.11m); **the second pass, the fidelity check and catch-up** (1.11n); **Tools › Import** (1.11o); **the header's jobs and bell, Select all matching and Account › Notifications** (1.11p); **Uploaded for** (1.11q); **private and password-protected posts** (1.11r); **links by ID** (1.11s); **menu headings** (1.11t). **The proof** (1.11z) imports a site through every step and asks it for every old address, and times an import of 50,000 posts and pages, stopped and carried on. **Ten fixes** followed the first import of a real site, a local copy of a WordPress publication with 3,578 posts and pages (#139 to #161).

### Fixed (#139)

- Importing a WordPress paragraph block whose HTML holds another paragraph, a list or a table inside its paragraph keeps every word, each part in its own block, as the browser showed it

### Fixed (#140)

- A damaged image is refused with what to do about it, “This file looks like an image but can’t be read”, instead of an unexpected error; on import it’s a warning on the attachment, and the import carries on

### Fixed (#141)

- A download that fails because the other site’s HTTPS certificate isn’t trusted (expired, self-signed or for another name) says so and what to fix, rather than that Teme couldn’t reach the site; it is tried once, never over plain http, and a full import tries it again

### Changed (#143)

- An article may hold 20,000 parts and 1 MB, up from 5,000 and 512 KB, so long name lists import and save; the refusal names the limit it met

### Changed (#144)

- A WordPress subscriber who has published posts comes across as a contributor, keeping their password and their posts, so their byline and author page survive; a subscriber with none still stays behind. On a site imported before this change, the next import brings them across and the next full import gives their posts back

### Fixed (#146)

- An image’s sizes can no longer be made twice at once by two queue workers: they run on a queue of their own, `long`, whose worker waits six minutes before handing a job on. A server now runs two workers: `php artisan queue:work` and `php artisan queue:work database-long --queue=long`

### Fixed (#150)

- Add new opens a new, empty draft in the editor straight away, with the cursor in its title, from Posts, Pages, Home and the command palette; there’s no title to type first
- A draft can be saved before it has a title; it’s listed as “(no title)”, and its address comes from the first title you give it

### Fixed (#151)

- In the editor, the toolbar and the side panel stay in view however long the article is: the article scrolls in its own column, and the side panel scrolls on its own
- The menu folds as the editor opens, unless you unfolded it there; the editor remembers your choice, and every other screen keeps its menu as you left it

### Fixed (#157)

- An import’s messages no longer promise that the next import tries a failed item again: they say a full import does, since a catch-up fetches only what changed on WordPress
- A catch-up’s report says how many items that failed before it didn’t try again, and that a full import retries them

### Fixed (#161)

- The query-plan tests no longer fail now and then when PostgreSQL reads an index by a bitmap scan; those that claim an order rule bitmap scans out

### Added (1.11z)

- `php artisan teme:import:walk {address}`, which asks the site for every old address of an imported WordPress site, as a reader would, and lists any that doesn’t answer
- `php artisan teme:bench:import`, which imports a synthetic site of 50,000 posts and pages and 100,000 comments into a benchmark database, stops it halfway, carries it on, and times every step
- The scale benchmark measures the media library’s Uploaded for filter

### Added (1.11t)

- A menu can hold a heading: a label with no page of its own that opens the items under it

### Added (1.11s)

- A link in an article to a category or tag page, an author’s page or a file in the library is stored by what it leads to, so it keeps working when a category is renamed, the address settings change or the site moves; the editor’s link box finds categories, tags, authors and files as well as articles

### Added (1.11r)

- Private posts and pages, which only the people who may edit them can read, and password-protected ones, which ask readers for a password first; neither appears in feeds, sitemaps, search or related posts, and the page cache never keeps a protected page
- Visibility in the editor’s Publish panel: Public, Private or Password protected

### Added (1.11q)

- Uploaded for: each file remembers the post or page it was uploaded for; the library filters by it, the file’s details name it beside Used in, and the editor’s uploads record it

### Added (1.11p)

- The header’s jobs button, showing background work running or failed, and the bell, showing unread results in your inbox
- Select all matching on the content lists: an action on every item a filter matches, confirmed first and run as one job you can follow in Tools › Jobs
- Account › Notifications, where each person’s notification choices now live, moved from Preferences

### Added (1.11o)

- Tools › Import, step by step: connect a WordPress site, see what will come across and what won’t, make the choices, run the import, check every old address, and read the report, with a file of everything and a file of what needs attention; carry on a stopped import, run a catch-up, or forget the site’s password

### Added (1.11n)

- After everything has come across, links and reusable blocks are resolved to what they lead to; each post and page is compared with what WordPress showed, and one that lost words is flagged with where
- A catch-up brings only what changed on WordPress since the last import, and lists what was deleted there

### Added (1.11m)

- The old site’s address settings come first, then its title, tagline, time zone, date formats, feeds, discussion settings, home page, posts page and posts per page
- Redirect rules come from Redirection, Rank Math, Yoast SEO Premium, Safe Redirect Manager and Simple 301 Redirects, patterns converted where they fit and listed where they don’t
- The address check asks the site for every address WordPress lists, and lists each that doesn’t answer with a page or a redirect

### Added (1.11l)

- Menus come across into Navigation, in the theme’s places you choose; widgets are listed by sidebar

### Added (1.11k)

- Comments, pingbacks and trackbacks come across in their threads, and WordPress’s comment-page addresses lead to their comments

### Added (1.11j)

- Posts and pages come across with their authors, dates, status (scheduled ones with their time), excerpts, categories and tags, primary category, template, sticky, discussion and the SEO plugin’s fields; their old slugs and old dates keep answering

### Added (1.11i)

- The Imported content card holds what Teme can’t convert, cleaned, with Convert, Keep and Delete in the editor

### Added (1.11h)

- Files come across as their originals, never WordPress’s resized copies, each once; every old file address and attachment page leads to the file or to its post

### Added (1.11g)

- Tools › Jobs lists background work with its progress, and lets you retry or cancel it
- An inbox holds each person’s results of background work

### Added (1.11f)

- Posts written in WordPress’s block editor or its classic editor become Teme’s own content; captions, galleries, embeds, audio, video and playlists become their cards, and everything else is listed by name in the report

### Added (1.11e)

- People come across with their roles mapped to the nearest of Teme’s; someone already here is matched by email
- Categories and tags come across with their parents and descriptions

### Added (1.11d)

- Import images: an article pasted from another site offers to bring its images into the library, through a downloader that reaches only public addresses and checks what each file really is

### Added (1.11c)

- Audio and Video cards play a file from the library, with a poster and caption tracks for video and a transcript for audio; Media accepts WebVTT caption files

### Changed (1.11b)

- People who come from WordPress sign in with their WordPress password, which Teme turns into its own kind of password hash at their first sign-in

### Added (1.11a)

- An import runs in the background, each item on its own, and carries on from where it stopped; `php artisan teme:import` for the server’s operator
- The Run imports permission, for administrators

### Fixed (1.11d)

- Pasted images no longer disappear (#5)

### Fixed (since beta.10)

- A menu item typed as this site’s own address still finds the article it names, now that links are stored by ID (issue 31)

## [0.1.0-beta.10] — 2026-10-15

Slice 1.10: AI, and the admin as drawn. **AI** in eight parts: **the seam** (1.10a), one contract for any AI service, a record of every request without its words, and the switch that turns AI off; **services and keys** (1.10b), Anthropic, OpenAI, DeepSeek and OpenRouter; **asking and using** (1.10c), the assistant beside the editor; **Tools › AI activity** (1.10d); **Settings › AI** (1.10e); and three tasks, **rewrite** (1.10f), **alt text** (1.10g) and **metadata** (1.10h). **The parity sweep** in ten parts brings the admin to its design: the shell (1.10p1), the menu and Settings' navigation (1.10p2), settings where they belong (1.10p3), Site › Themes (1.10p4), the content lists (1.10p5), People (1.10p6), Categories and Tags (1.10p7), the editor (1.10p8), Media (1.10p9), and Navigation, Regions and Preferences (1.10p10). **The full pass** (1.10z) compares every screen with the design, proves the AI's acceptance criteria, and measures the AI record at 10 million requests.

### Added (1.10z)

- The scale benchmark seeds 10 million AI requests over 13 months, and measures Tools › AI activity's first page, a page halfway down, a month, a person's month and a task's month at that size, with the categories' and tags' tab counts and the media library's months
- The featured image in the editor shows its licence beside its credit
- Every admin screen is opened in a real browser on each test run, and must open without a script error

### Removed (1.10z)

- The unused navigation-menu component and its `@radix-ui/react-navigation-menu` package

### Added (1.10p10)

- Navigation: each menu's row actions in a menu, breadcrumbs back to Navigation, "Create menu" and "Save menu"
- Regions: rows kept from themes not in use, listed as Unplaced; each region's limit on blocks; the region's name and the theme that uses it as the heading
- Preferences: the look, the colour scheme and high contrast apply as you choose them

### Added (1.10p9)

- The Media screen as designed: an Upload button and a drop anywhere on the page, one upload queue with its progress, Cancel and Clear, Type and month filters, and the details panel always there
- A licence for each file, beside its credit
- Move to trash, Restore and Delete permanently at the foot of the file's details, each confirmed

### Added (1.10p8)

- The editor's state, as a chip in its bar
- Share as a pane of the editor
- One Publish row, with Visibility and an Author select for whoever may change who wrote an item
- The featured image's credit under it, and a "Missing" mark when it has no alt text

### Added (1.10p7)

- Categories and Tags as tabs of one screen, under Posts, each tab with how many it holds; whole branches of categories; the full add form; counts that open the posts they count

### Added (1.10p6)

- People by role, in tabs, with invitations in a table of their own, sent again or revoked from their menu
- Changing a person's role in a dialog; your own row can't be changed, and says why

### Added (1.10p5)

- Posts and Pages: a Preview row action, a Category filter, the In review and Scheduled tabs even at zero, buttons in the bulk bar, a bulk delete that names each item, and each item's state after its title

### Changed (1.10p5)

- "Delete for good" is "Delete permanently" everywhere, and the trash's "Keep trash for" hint says so

### Added (1.10p4)

- Site › Themes: each installed theme with its screenshot and its state, previewed through a draft before it's activated, its settings and menu places, and the regions it places

### Changed (1.10p4)

- Settings › Design is Site › Themes; its old address sends you there

### Changed (1.10p3)

- Trash retention is in Settings › Writing; sign-in rules in Settings › People; Posts per page and the home page in Settings › Reading; the brand colour, with its contrast, in Settings › General; Settings › Media lists the image widths. The old addresses send you on

### Changed (1.10p2)

- Categories and Tags sit under Posts, and Users is People
- Settings is a grouped side menu with "Find a setting"
- The command palette has a Recent group, Settings' pages and an icon on every row

### Changed (1.10p1)

- The shell: the account menu at the top right, "Search or jump to…" on the left, Collapse menu at the sidebar's foot, View site, and the letter mark with the site's name and Teme's version
- The starter kit's leftovers are gone: its account layout, its Light/Dark/System control and its words

### Added (1.10h)

- "Suggest metadata", in the assistant and at the head of Search and social: an SEO title, a meta description, an excerpt, a social title and a social description, each proposed under its own field with Use, and Use all; nothing is replaced until it's used

### Added (1.10g)

- "Suggest alt text" in a library image's details, from the image itself, its title and caption, in the site's language; Use puts it in the field, and the record keeps the old and the new alt text so an administrator can put the old one back

### Added (1.10f)

- Rewrite in the selection menu, with seven choices: Improve, Shorter, Longer, Simpler, Fix spelling and grammar, More formal and More casual, for text within one paragraph, heading or list item

### Added (1.10e)

- Settings › AI: each service's key, set here or by the server, with Check key; a model for each task and an optional fallback, offering only the models that can do the task; the hourly limit and the monthly budget beside this month's spend; a notice when the server switches AI off

### Added (1.10d)

- Tools › AI activity, for administrators: one row a request, with who asked, the task, the item, the outcome, the model and its cost; filters by person, task and month; the month's spend against the budget; failures in plain words

### Added (1.10c)

- The assistant, beside the editor (Ctrl/⌘ J): what will be sent before anything runs, "Working…" with Cancel, the suggestion with removed words struck and new ones marked, Use as one change Undo takes back, and Discard
- Every AI request waits in the queue carrying IDs, never the text; one fallback model when the first can't answer; one plain sentence for each way a request can fail
- Use saves a revision marked as the assistant's, refused when the paragraph changed after the asking; History links it to its request

### Added (1.10b)

- Anthropic, OpenAI, DeepSeek and OpenRouter, through `laravel/ai`; each key kept encrypted, or given by the server (`TEME_AI_ANTHROPIC_KEY` and the others)
- A list of models with their prices, updated each release, and "Another model" with prices an administrator types; costs in US dollars
- An hourly limit for each person (60 by default) and an optional monthly budget

### Added (1.10a)

- One contract for AI services and tasks, each registered by its part; the `ai.use`, `settings.ai.manage` and `ai-activity.view` permissions
- A record of every AI request, its figures only, never its prompt or its answer, kept 12 months
- `TEME_AI=off`, which switches AI off whatever the settings say

### Fixed (since beta.9)

- The export's join is fixed (issue 76)
- The editor keeps the space beside marked text when it saves (issue 78)
- Links without a scheme, or copied from another editor, are repaired (issue 2)
- A level-1 heading, a foreign heading ID or a NaN list start is repaired, not refused (issue 3)
- Links to this site's own address are stored by ID or path (issue 7)
- A pasted h1 becomes an h2, and an h5 or h6 an h4 (issue 10)
- An unpublished region is no longer drawn (issue 43)
- Search hides an item dated ahead until its time comes (issue 44)
- A Valkey that drops packets costs a missed page one connect timeout, not three (issue 52)
- A comment's created_at and updated_at are required (issue 56)
- Three lists give the first page for a before link that can't be decoded (issue 71)
- league/commonmark is 2.10.3, with two security advisories fixed (issue 79)
- A refused settings save never flashes a typed secret into the session (issue 80)
- Five Browser tests wait for what they assert (issue 83)
- An edit to a category or tag keeps the parent and description it doesn't send (issue 84)
- A third item with the same non-English slug gets the next number instead of a database error (issue 85)
- A long non-English slug, title or file name is cut to whole characters (issue 86)
- The benchmark's smoke test keeps its page cache to itself in the parallel gate (issue 106)
- A menu item typed as this site's own address is stored as the home page, the article or the category it names, or a path (issue 31)
- The menu editor's Add an item panel offers the home page (issue 45)
- The Comments screen's row menu says Move to trash, as every screen does (issue 89)
- The content list's review filter chip has a 24-pixel remove button (issue 99)

## [0.1.0-beta.9] — 2026-10-10

Slice 1.9: search engines and sharing. Twelve parts: **the head's foundation** (1.9a), typed head entries any part registers, one structured-data graph, and Settings › Search and social; **the head** (1.9b, 1.9b′), titles from templates, descriptions, robots directives, canonicals and social cards with their images; **redirects and the 404 log** (1.9c, 1.9c′), counted without a write on a reader's request; **feeds** (1.9d); **site code** (1.9e); **sitemaps and robots.txt** (1.9f); **structured data** (1.9g), each type's and item's kind of page, and a post's primary category; **the Tools screens** (1.9h), the page cache and redirects; **Search and social's screen** (1.9i); **who publishes the site** (1.9j); **the editor's Search and social** (1.9k), with core's checks and a confirmation before a first publication; and **the proof** (1.9l), the benchmark at 100,000 redirects and a full 404 log, and the Rich Results Test by hand.

### Added (1.9l)

- The scale benchmark seeds 100,000 redirects and a 404 log of 100,000 addresses, and measures a redirect found among them, a flood of addresses that don't exist, the minute's run that writes a flood into the log, the log's screen and Tools › Redirects at that size, the sitemap index and its deepest page of posts, and an article whose head takes every fallback
- The six kinds of page the Rich Results Test is run on before a release are drawn from fixed fixtures and kept as HTML to paste into it

### Fixed (1.9l)

- The 404 log's later pages open as fast as its first, however full it is
- Searching Tools › Redirects finds a word in 100,000 redirects without reading them all
- Tools › Redirects opens each page of its list without reading every address the site has

### Added (1.9k)

- The editor's Search and social section: the SEO title and description, counted in characters and in about the width search results show; hiding the item from search engines; its canonical, itself, another item or an address on another site; its social title, description and image; and its search result and social card previewed as the page will make them
- Core's checks, plain facts such as a missing description or a title too long for results, in one list: in the section, in the rail's Checks, and in a confirmation before an item first goes live
- A closed Advanced fold with what the item is, its kind of page and article, and its snippet controls
- The Categories box offers the primary category once two are ticked, and says what it changes

### Added (1.9j)

- Search and social's section "Who publishes the site": nobody in particular, an organization of one of six kinds, a local business of one of six with its address, phone, place on the map, price range and opening hours, or a person chosen from the site's users; with another name, a logo, profile links and the organization that owns it
- Every page's structured data names that publisher, as the site's and every article's
- Each content type's kind of page and article can be changed for the whole site
- Administrators are warned when the person who represents the site no longer has an account; until then the site is represented by nobody in particular

### Added (1.9i)

- Settings › Search and social is a screen of its own, its sections in order, with an example under every title template
- The site's default social image, its verification tags and the 404 log's size and ignore list are settings
- Two setting types for any settings group: an image from the media library, and a list of names and values
- Administrators are warned on Home while search engines are discouraged, and on the screen when the copy they're on isn't the live site

### Added (1.9h)

- A Tools section, listing the tools each person may open
- Tools › Page cache: administrators see how the page cache is set up, and clear it
- Tools › Redirects: editors and administrators list, search, add, change, switch on or off, delete, import and export redirects, and work through the 404 log beside them, making a redirect or a 410 from any of its addresses
- Just after a published item is trashed, its message offers a redirect from the address it had

### Added (1.9g)

- Every public page carries one structured-data graph built from its own data: the site, the page and what it is about (a post's blog posting, an archive's collection, an author's profile, a search's results), its main image with its credit, and, where the theme draws one, its breadcrumb trail
- A part of the graph missing what Google requires is left out, with a reason an editor can read; plugins add to the graph and change it through one filter
- Each content type, and each item within its type's choices, says what kind of page and article it is
- A post's primary category sets its address, its section label and its trail

### Added (1.9f)

- Teme writes `/robots.txt`: crawlers kept out of `/admin/` and `/_teme/`, an administrator's extra lines checked line by line, the sitemap's address, and, at one switch, AI training crawlers asked to stay away
- `/sitemap.xml` indexes the home page, each public content type, each public taxonomy and the authors, in pages of at most 2,000 addresses, listing exactly what each page's head lets search engines list, with when each last changed and its images; a small stylesheet shows any of them as a table
- WordPress's and Yoast's sitemap addresses send readers on; while the site discourages search engines, every sitemap answers "not found" and robots.txt names none

### Changed (1.9f)

- `public/robots.txt` is gone: Teme answers `/robots.txt` itself

### Added (1.9e)

- Settings › Site code: administrators add named pieces of code to the head, the start or the end of every public page, each switched on or off; each save asks for the password again, and each change goes to the security log with its before and after, listed on the screen
- `TEME_DISALLOW_SITE_CODE` keeps site code off and the screen read-only, for hosts that forbid it

### Added (1.9d)

- RSS feeds at `/feed/` and at a category's, tag's or author's address followed by `/feed/`, every WordPress spelling of them answered as subscribers need: the newest 1 to 100 items, 10 by default, in full or as excerpts, with the featured image and every card in a form feed readers show
- Every public page links the feeds that apply to it, and Search and social has a *Feeds* section
- An item's comment feed answers 410: Teme has none

### Added (1.9c′)

- Each redirect counts how often it sends readers on, and every address nothing answered goes in the 404 log, one row an address, never with an IP address or a browser, the least recently seen making room when it is full; neither writes to the database while a reader waits
- Redirects are imported from a CSV file of up to 5,000 lines, each line checked as one added by hand, and exported as CSV or as rules for nginx

### Added (1.9c)

- Redirects from an address the site no longer has to an address, an item or a media file, or answering 410 or 451: exact, or with placeholders such as `[slug]`; checked for chains and loops when saved, and never answering in place of a live page
- While a redirect's item or file isn't there, its address answers "not found", and the redirect waits for it

### Added (1.9b′)

- An image used as a featured or social image gets a 1200×630 social card and three 1,200-pixel crops the first time it is used so, made in the background; `teme:media:regenerate` makes them for images already featured

### Added (1.9b)

- Every public page's title comes from a template with Teme's own tokens, and its head carries its description, its robots directives, its canonical, `prev` and `next`, and its Open Graph and social card
- Every item has the fields behind them: its SEO title and description, hiding it from search engines, its canonical, and its social title, description and image
- Search and social's first section, "Titles, and what search engines may list"

### Added (1.9a)

- Settings › Search and social, built from the sections each part registers, and its first switch, *Discourage search engines*
- Every public page's head is drawn from typed entries that core, themes and plugins register, with one structured-data graph and three places for code the whole site carries
- Every response outside production says `X-Robots-Tag: noindex, nofollow`
- The page cache stores the XML and plain text a route marks cacheable
- `feed`, `sitemap.xml` and `robots.txt` are addresses no item can take

## [0.1.0-beta.8] — 2026-10-08

Slice 1.8: comments. Eight parts: **storage, submission and moderation** (1.8a), one row a comment with its item's counts, each item Open, Closed or Hidden, and a moderator judging each arrival; **the thread and the form** (1.8b, 1.8b′), threads paged by conversation, each comment's own address, and core's form and script, working without JavaScript; **the Comments screen** (1.8c, 1.8c′, 1.8c″), moderation by view, item and keyboard, Quick edit, the inline reply, Empty spam and Empty trash, the menu badge, and the thread's Edit link; **Settings › Discussion and the emails** (1.8d), the comments switch, the moderators' and authors' emails with their opt-outs, and commenters' addresses erased after 60 days; and **one item's Discussion and the proof** (1.8d′), the editor's row, Quick and Bulk edit, and the benchmark at 40,000 comments.

### Added (1.8d′)

- The editor's Publish panel has a Discussion row: Open, Closed or Hidden, with what each means to readers and when new comments stop; whoever may publish the item changes it, and the change takes effect at once
- Quick edit sets an item's Discussion, and Bulk edit sets it for many items at once beside Status, reporting each item it couldn't change and why
- The scale benchmark seeds 40,000 comments and measures the long thread, the Comments screen, Empty spam, the trash purge and the erasure of commenters' addresses; its report names the indexes each scenario's plans read

### Changed (1.8d′)

- Changing an item's Discussion never changes when it was last updated
- Erasing commenters' addresses reads an index of the comments that still keep one, so the daily run never reads every comment

### Added (1.8d)

- Settings › Discussion: comments switched on or off for the whole site at the top, by administrators, and every Discussion setting below, in five groups; switched off, the page keeps the settings and shows the switch alone
- Whoever looks after a post or page's comments is emailed when a comment waits for approval, and the author when a comment on their post or page is approved; neither email carries an IP address, a browser or an email address, and nothing in a comment can become a link in it
- Each person can turn either email off in Account › Preferences, under Notifications
- Commenters' IP addresses and browsers are erased 60 days after they arrive, or as many as Settings › Discussion says, and a comment's details say when

### Added (1.8c″)

- Whoever looks after an item's comments sees "Edit" on each comment in its public thread, opening the comment's details on the Comments screen; it is never on a waiting comment, a tombstone or a ping
- The default theme draws a place for it on every comment that isn't waiting, hidden while empty; a theme without that place gets Edit beside Reply
- A closed thread that has comments now carries core's script, so Edit reaches it
- After a comment is posted at once, the thread's "No comments yet" line goes and its heading counts the new comment; a comment that waits changes neither

### Added (1.8c′)

- Quick edit on the Comments screen corrects a comment's name, email address, website and words, and keeps an imported comment's formatting unless its words change
- A reply written from the Comments screen approves the comment it answers, and records no IP address or browser
- Empty spam and Empty trash delete what the list showed when it was opened, narrowed by its item and search, and comments left in the trash past "Keep trash for" are deleted permanently each day
- The Comments screen's keys, J, K, X, A, U, S, T, R and Q, act on the row that has focus

### Added (1.8c)

- A Comments screen for whoever looks after comments: views with counts, a search over the words, names, email addresses and websites, and the list narrowed to one post or page; authors see the comments on their own posts and pages, and only editors and administrators see IP addresses
- Comments are approved, unapproved, marked as spam, trashed and restored one at a time or in bulk, each with Undo, and deleted permanently after a confirmation that says which keep a place in their thread
- A comment's details and the moderator's judgement open in a drawer, from the list or from the comment's own address
- The menu counts the comments waiting, Home shows the same figure, and Posts and Pages have a Comments column

### Added (1.8b′)

- With JavaScript, the comment form posts without leaving the page, says what happened in its status region, and puts the comment in the thread; errors come back in the form, the words kept
- Reply moves the one form under the comment answered; Cancel or Escape brings it back, and Ctrl or Cmd with Enter posts
- A reader's own comments that wait show to them alone, found by a random key their browser keeps, never by their email address; a comment judged spam reads the same
- The form saves a reader's name, email address and website in their browser when they tick the box, and shows a signed-in person the name they comment under

### Added (1.8b)

- Readers see an item's comments under it, oldest first, fifty conversations a page, with later pages at `…/comments/page/2/`: replies indented three steps on a phone and four on a wider screen, then marked "In reply to", tombstones where a comment was removed, WordPress's pingbacks on one line, and the story's author marked "Author"
- Readers comment with no JavaScript: a name, an email address that's never shown, and, while the site asks, a website; each outcome answers with a page of its own that keeps what they wrote, and a comment judged spam reads as one awaiting moderation
- Each comment has its own address, `/_teme/comment/{id}`, which finds its page; Reply opens a page of its own
- The page cache sets a thread's pages aside when a comment arrives, and removes the page showing one taken down
- Settings: "Comments per page" and "Ask guests for their website", which Settings › Discussion will show (1.8d)

### Added (1.8a)

- Comments are stored, with their threads, and each post and page is open, closed or hidden for them: posts start open and pages closed, as Settings › Discussion will let you change (1.8d). Nothing shows on the site yet (1.8b)
- A comment is checked before it's kept: five in ten minutes from one address, a hold list and a block list, links, and a first comment from a new browser waits for approval, while whoever looks after the item's comments is approved at once
- Moderators approve, hold, mark as spam, trash, restore and delete comments for good; a deleted comment with replies leaves "This comment was removed" in its place
- `php artisan teme:counts:rebuild` checks and fixes each item's comment counts too

## [0.1.0-beta.7] — 2026-09-30

Slice 1.7: the page cache. Five parts: **the cache core** (1.7a), a public page answered from a stored copy before anything else runs; **dependencies and invalidation** (1.7b), exactly the pages a change shows removed after it commits; **stampedes, staleness and the session endpoint** (1.7c), one request drawing a page while the others get a stale copy, lives capped by the clock, and the "Edit post" link; **scale-out** (1.7d, 1.7d′, 1.7d″), the Redis store, Cloudflare purged by tag after warming, and the static-file driver; and **proof** (1.7e), #30's fix, the singleton rule and the benchmark with the cache.

### Added (1.7a)

- A full-page cache for readers who aren't signed in, off until 1.7b (`PAGE_CACHE_ENABLED=true` turns it on): a public page is answered from a stored copy before anything else runs, and `X-Teme-Cache` says whether it was a hit, a miss or passed by, and why
- Signed-in people carry a `teme_auth` marker, set at sign-in and cleared at sign-out, and always get pages drawn afresh
- `php artisan teme:cache:clear` removes every stored page; `teme:cache:prune`, run every minute, removes expired ones

### Changed (1.7a)

- With the page cache on, a stored page stays available while the site is in maintenance mode

### Added (1.7b)

- The page cache knows what each page shows: the article, its author, terms and images, the lists it's in, and the menus, regions, settings and design around it. Publishing, unpublishing, trashing or editing any of them removes exactly the pages that showed it, once the change is saved
- A page drawn while something it shows changed is never stored
- `php artisan teme:cache:purge` removes stored pages by tag, or every page at an address with `--url=/about/`
- Administrators see a Status banner when the queue worker, the scheduler or another server can't reach the page cache's folder

### Changed (1.7b)

- The page cache is on by default, except on a developer's machine (`APP_ENV=local`), so theme changes show at once there; `PAGE_CACHE_ENABLED` decides whatever the environment. A page still lives a minute at most

### Added (1.7c)

- Editors who are signed in see an "Edit post" or "Edit page" link on the site, at the bottom corner of the article or page they may edit. Readers see nothing, and every reader still shares the same stored page
- When a post is published, the lists it joins are kept and shown for the moment it takes to draw them again, rather than drawn by every reader at once
- When many readers ask for a page that isn't stored, one request draws it while the others wait a moment for it
- An item given a published date in the future appears on time, and the year in the footer changes at midnight on New Year's Eve, on the site's clock

### Changed (1.7c)

- A stored page lives an hour, not a minute (`PAGE_CACHE_LIFETIME`); a search's page ten minutes. A CDN or proxy in front is still told to keep a page a minute at most
- While the page cache is switched off, `teme:cache:prune` empties it, so switching it on again starts afresh

### Added (1.7d)

- Several servers can share one page cache: with `PAGE_CACHE_DRIVER=redis`, pages are kept in Valkey and found by what they show, and each page's lock sits beside it, so one server draws a page while the others wait or are given its stale copy. It needs the phpredis extension
- `docker compose up -d valkey_test` starts the Valkey the test suite uses; CI runs one too

### Changed (1.7d)

- A page drawn while something it shows changes is never stored, however far apart the clocks of the servers involved: purges are ordered by number, not by time

### Added (1.7d′)

- With Cloudflare in front of the site (`PAGE_CACHE_CDN=cloudflare`, its zone ID, and an API token that may only purge), each page tells Cloudflare what it shows and how long it may keep it, and every change is purged there too, gathered on the queue within the plan's rate
- After a change, the pages it touched are drawn again on the queue before the CDN is purged, so the first reader finds them stored (`PAGE_CACHE_WARM=false` turns it off)
- `php artisan teme:cache:warm` draws and stores the home page, every archive and the newest items, for after a deploy
- Administrators see a Status banner when the CDN refuses the page cache's purges

### Changed (1.7d′)

- The page cache keeps its pages in `storage/page-cache`, out of `storage/framework`, so a container's own tmpfs never hides them from the queue worker and the scheduler

### Added (1.7d″)

- An optional static-file driver (`PAGE_CACHE_DRIVER=static`): nginx or Caddy serves each stored page to readers who aren't signed in as a plain file, with no PHP at all. The settings for both are in `docker/`, and `php artisan teme:cache:check-static` asks the web server whether it follows them
- A page's file goes the moment a change removes the page, and two minutes before its life ends
- With Cloudflare in front, a page the web server served as a file is purged by its address

### Added (1.7e)

- The scale benchmark measures the page cache at a million articles: hits, misses, stale answers and waits on the file and Redis stores, changes with a CDN, with warming and with the static-file driver, and a budget for a hit (10 ms)

### Changed (1.7e)

- Code of Teme's keeps nothing one request or job learns in anything shared by the whole process: the people a scheduler pass or a Bulk Edit batch acts as are forgotten with the job or request, whatever a caller forgets

### Fixed (issue 30)

- A listing page, the front page and an article's related posts read their authors' addresses and their cards' images once, however many cards they show

## [0.1.0-beta.6] — 2026-09-27

Slice 1.6: the public site, the first release that publishes one. Eight parts: **addresses** (1.6a), every item, term and author at an address of the site's choosing, with old ones redirecting; **the theme engine** (1.6b), themes as packages with templates chosen by rule; **the page** (1.6c), the document shell and the view models themes draw; **navigation** (1.6d), menus placed in a theme's places; **regions** (1.6e), the site's own content in a theme's places; **Settings › Design** (1.6f), the theme, its settings and the accent, previewed before publishing; **the default theme** (1.6g), reading-first and needing no JavaScript; and **search** (1.6h), with an automated accessibility check of every template and the public site measured at a million articles.

### Added (1.6a)

- Every published post, page, category, tag and author has a public address, set in Settings › Addresses: WordPress's Permalinks presets or a pattern of your own from `{slug}`, `{year}`, `{month}`, `{day}`, `{id}`, `{category}` and `{author}`, a base for each taxonomy and for authors, and whether the home page shows the latest posts or a page you choose, with another page listing the posts
- Every earlier address keeps answering with a permanent redirect to the current one, keeping its query string, after a new slug, a new category or a change of settings
- The public site answers every address: posts and pages, category, tag and author listings, year, month and day archives, their later pages, and a "not found" page in place for anything else
- An address written another way — in capitals, without its trailing slash, with doubled slashes — redirects to its one true form instead of failing
- A change that would put two things at one address is refused, naming both, whether it comes from a slug, a term or the settings
- Changing the address settings moves every address in the background, with its progress on the screen
- *View on site* in the editor, and links between articles that follow an article to its current address
- Slugs may contain letters and numbers from any language, so an imported site keeps its addresses (I-397)

### Changed (1.6a)

- Public pages set no cookies
- When the home page is a page, that page's own address redirects to the site's address, so the home page has one address

### Added (1.6b)

- The public site is drawn by a theme: a Composer package with a `teme-theme.json` manifest, templates and its own built assets. Teme's default theme is one such package, a plain skeleton until 1.6g
- A theme with a mistake in its manifest, a template it declares but lacks, or a composer that asks for more than it may, is set aside with its errors in plain words, and the site keeps working on core's own plain pages
- A banner across the top of the admin tells administrators when the site's theme can't be used, and why, until it is fixed
- Editors choose a page's template, such as Full width, from those the active theme offers; the choice travels with revisions, and a template a later theme lacks is kept and marked, while the page uses the theme's default
- Outside production, each public page names the template that drew it, the ones tried before it and the composers that added to it; `php artisan teme:theme:resolve` explains any address without drawing it, and `php artisan teme:theme:check` checks a theme and measures its assets
- A public page's error is drawn by the theme's error template, or core's plain one when the theme's fails

### Changed (1.6b)

- `composer/semver` is a production dependency, now at 3.5 (I-445)

### Added (1.6c)

- A site language, set in Settings › General: it is the language browsers and screen readers are told, the language dates are written in, and how search matches words. Changing it rebuilds search in the background (I-435)
- Posts can be pinned: the newest pinned post leads the latest posts, with up to three more beside it, ahead of the feed
- Each article's page shows its reading time, a written standfirst, its section and tags, its authors, when it was published and, a day or more later, when it was updated
- Related posts under each article, found through the terms it shares with them, rarest first, each saying why it is related; an article that shares nothing shows none rather than the latest posts
- An author's bio, written on their Profile, shows on their author page and under their articles
- Every image on the public site is drawn one way: sized for the theme's layout, with its dimensions, its focal point, and exactly one image loading first on an article and on the first page of the latest posts
- Core writes each public page's document: its title, its language, a skip link to the content, the theme's colours and preloaded font, and prerendering of the next page a reader is likely to open
- Card styles load only on pages that have those cards, and a theme can replace them with its own
- A preview link shows the article exactly as it will look published, through the theme, marked as a preview and never listed by search engines

### Changed (1.6c)

- Theme discovery reads Composer's compiled package list, so it no longer decodes `installed.json` on every request (B-226)
- Administrators of existing sites receive the two permissions added since their site was set up, for Settings › Addresses and Settings › Design, through a migration that only adds (I-449)

### Fixed (#1, tables)

- An article with a table can be saved again, a table inserted from the toolbar included. A cell's alignment is kept and drawn on the site as in the editor, and a pasted table keeps its merged cells, up to the 1,000 columns and 65,534 rows a browser allows
- A test built from the editor's own schema now fails the build whenever the editor can make something the site would refuse to save; what it still finds is tracked as issues #2 to #4

### Added (1.6d)

- Content › Navigation: editors and administrators build menus as trees of up to three levels, pointing at pages, posts, categories, tags, the home page or any web address, moved with buttons or the keyboard
- A theme declares places for menus, such as a header and a footer, and each menu's screen chooses which places show it
- The public site draws each chosen menu as a labelled landmark, with the page being shown marked for screen readers; an item whose page isn't published, or is in the trash, is left out, and a menu item always follows its page to its current address
- A place with no menu says so while the site is being built, shows nothing to visitors, and is named in the admin's Status banner until it has one
- Existing sites give editors and administrators the new navigation permission through a migration that only adds (I-449)

### Added (1.6e)

- Content › Regions: a theme's places for the site's own content, such as a box after each article, filled by editors and administrators with the same editor as posts, and published with revisions like everything else
- A region takes only the cards its theme allows, and no more blocks than it says, when saved, when autosaved and again when published, so a theme update that narrows a region is noticed before anything is published
- The editor opens a region without the title, address, sharing and article settings a region doesn't have, and offers only the region's cards
- The public site draws each published region where the theme places it, with its cards' styles; a region never has a page of its own, never appears in lists or as a link target, and its content is kept when the theme changes
- Existing sites give editors and administrators the new regions permission through a migration that only adds (I-449)

### Added (1.6f)

- Settings › Design: one screen for the theme, the site's accent colour, the theme's own settings in four sections — Site, Latest posts, Content and Archive — and the menu in each of the theme's places
- Themes declare their own settings in their manifest, with options that hide when they don't apply; a setting's value is kept while it's hidden
- An accent colour that people couldn't read against the site's background, in any colour scheme it shows, or with text on it, is refused, saying by how much; the screen shows the contrast as you choose
- Every change is kept as a draft, saved as you make it, and reaches readers only when you publish; Discard drops it, and every published change appears in the settings history
- Preview opens each section's page on the real site with the draft shown, through a link that expires within the hour and is never cached or indexed; a theme switch can be previewed too
- A menu chosen on Content › Navigation while a draft waits keeps its place, unless the draft changed that same place
- Themes show a screenshot on the screen, and can name their colour scheme and whether articles lead with their featured image

### Added (1.6g)

- Teme's default theme: a reading-first theme for a publication, with a lead story and pinned posts, a list or grid feed, articles set for long reading, and archives titled by what they list; it needs no JavaScript to read, navigate, search or page through the site
- A dark scheme that follows the reader's system, or that the owner chooses; the site's accent colour is checked against the light background, and the dark scheme takes a lighter version of it, worked out automatically (I-457)
- The owner changes the header's layout and colour, the latest posts' header and feed, the featured image's width, the author box, related posts, reading time and the archive header, and previews each before publishing
- Every page carries a skip link that stays hidden until it's needed, whatever the theme
- Every template the theme draws is checked for one main area, one heading at the top, headings in order, labelled menus and described images

### Changed (1.6g)

- Settings › Design shows the accent on both colour schemes, and no longer refuses an accent that reads well on the light background but not on the dark one

### Added (1.6h)

- Readers search the site's published posts and pages at `/?s=`, WordPress's own address, with each match highlighted in a short snippet, the newest 1,000 matches ranked by relevance, and pages that keep the search, so a search costs the same however large the site grows
- Search understands what readers type: "an exact phrase", -a word to leave out, and or
- Search pages are left out of search engines' indexes, and one reader may search thirty times a minute
- A theme without its own search page shows searches with its archive template
- Every default-theme template passes an automated accessibility check in a real browser, in the light and the dark scheme, and two editors working on one article are checked side by side; both run before every release and on every change in CI

### Changed (1.6h)

- A callout card is a note, not an aside, so a callout in an article no longer counts as a separate region of the page for screen readers
- The scheduler and Bulk Edit read each person they act for once, with their roles and permissions, instead of once per item
- Links to search results and other addresses with a query string are no longer prerendered as a reader heads for them, so they don't use up the reader's searches
- Public pages stay quick on large sites: listings find each item's address through its index, the front page finds pinned posts in their own index, an author's pages and a year's pages no longer count or sort everything they hold, and related posts ask one query whatever the article's number of tags

## [0.1.0-alpha.5] — 2026-09-25

### Added (1.5·0)

- Content types and taxonomies are declared in two registries: posts and pages, categories and tags are entries, and a later type gets its rules, permissions, built-in fields and admin screens from its declaration
- Each term's counts are kept per content type as well as in total
- Two content types can each have a field with the same handle

### Changed (1.5·0)

- Pages have no categories or tags, as in WordPress (I-417): the Pages screen shows no term pickers, columns or bulk term actions, and existing page–term links were removed
- An item can no longer be changed through another type's address, and a bulk action skips an item of another type, saying why

### Changed (1.5a)

- The admin is built to one design system: an achromatic palette, a single accent used only for the primary action and keyboard focus, and panels that are regions divided by a rule rather than floating cards
- One button, one input, one select, one table, one tab strip and one pager, shared by every screen, at a density meant for working rather than for browsing
- Every list has an empty state, a way back through its pages, and a labelled actions menu on each row
- Anything that cannot be undone now asks first; moving to the trash still does not, because it can be undone
- Errors are tied to their fields for screen readers, and every control has a label that names it
- Dark mode is a designed palette rather than an inversion: surfaces are visible, and nothing is drawn in a colour that cannot be read

### Added (1.5b)

- Cards are declared once: an image, gallery, embed, callout, code or button card carries its own attributes, HTML, search text and references
- Every saved document is checked against the schema on the server, and anything unknown is refused rather than quietly dropped
- Articles render on the server from their stored JSON, with internal links resolved to today's addresses and embeds shown in a sandboxed frame
- Each revision records its plain text and word count; search covers article text, not only titles
- Media used inside articles, and links between articles, are recorded and kept up to date

### Added (1.5c)

- The editor: write an article with every control on a visible toolbar, insert images, galleries, embeds, callouts, code and buttons, and see each one as it will appear
- Work is saved as you type, kept separately for each person, and gathered into readable revisions at checkpoints rather than on every keystroke
- When someone else is editing the same article you are told while you write, and if they save first you are shown what happened and given your text — never silently overwritten
- Internal links store the article, not its address, so moving the site does not break them
- A focus mode hides the chrome when you want to write

### Deploy (1.5c)

- After deploying, the operator runs `php artisan teme:content:rebuild-derivations` once on each site, so older revisions get their plain text and word count and published items their search text (`--check` counts what is missing first)

### Added (1.5d)

- Every version of an article is listed with who changed it and by how much, and any two can be compared side by side
- Restoring an older version adds a new one rather than erasing what came after, and publishes nothing until you say so
- An unpublished article can be shown to someone without an account through a link that expires after two days, can be revoked at any time, and opens nothing else
- The writer can see which preview links exist and whether they have been opened
- A title in the Posts and Pages lists opens the editor, and Add new opens the new draft there
- Text pasted from Word or Google Docs arrives without their formatting clutter, and a YouTube, Vimeo or Spotify address pasted on an empty line becomes an embed

### Added (1.5e)

- Schedule an article to appear at a chosen time, or prepare an update to a live article that lands at a chosen time without changing what readers see until then
- Scheduled publications survive a restart and a missed window: if the site was down when the time came, the article still publishes and the record says it was late
- A writer without permission to publish can submit work for review; an editor approves exactly the version that was submitted, or returns it with a reason the writer sees
- Times always say which zone they mean
- The editor's checks also warn when an article has no featured image, and name any link that leads to a deleted or unpublished article; neither stops you publishing
- Comparing two versions also lists the fields and categories that changed, so a reviewer sees a new SEO title or a moved category

### Added (1.5f)

- A command palette on ⌘K that finds an article by a word in its text, goes to any screen, and runs the common actions
- Each person keeps their own editor and list preferences, which follow them between machines and change nothing for anyone else

### Added (1.5g)

- A quick draft box and a getting-started checklist for a new site on the Home screen
- A Home screen that starts with your own work — waiting for your review, returned to you, in review, your drafts, scheduled, and what you edited last — and hides anything with nothing in it

### Added (1.5h)

- Sort Posts and Pages by title, author or date from the column headers
- Screen options: each person chooses the columns and how many rows the lists show
- A month filter, and Bulk Edit for each taxonomy's terms (categories and tags on Posts), author and status
- Each person can hide, reorder and pin their own menu entries

### Changed (1.5h)

- The command palette finds articles by their title, closest match first, and its **Search in text →** opens the list's search of the published text, which stays fast at any size

## [0.1.0-alpha.4] — 2026-09-22

### Changed (1.3e)

- The internal domain code moved from `App\Cms` to `App\Domain`; nothing else changed

### Added (1.4a)

- Files are checked by their content, not their name, and stored under keys that never collide; image originals are kept private
- Images get WebP copies at six widths and a square thumbnail, made on the queue so no upload or reader waits
- Media details — title, alt text, caption, credit and focal point — and replacing a file while keeping every use of it
- Media trash, restore and delete for good, with the daily purge; exact counts by kind
- Settings › Media: the largest upload and the largest image
- `php artisan teme:media:add` and `php artisan teme:media:regenerate`

### Added (1.4b)

- A featured image for posts and pages, chosen from quick edit with a searchable media picker, with alt text for that one use (never a decorative mark, I-237)
- Teme records where every media item is used, in drafts and on the live site, and keeps the counts exact; `php artisan teme:media:reindex-usages` re-derives the record
- Publishing names any image that has no alt text; it never blocks

### Added (1.4c)

- The Media screen: grid or list, tabs by kind with exact counts, search across file names, titles and alt text, filters for your own, unused and missing-alt-text files, and paging that stays fast at any size
- Uploads of many files at once, each with its own progress, error and retry, and a choice when a file is already in the library
- A drawer to describe a file, set its focal point, see where it is used, replace it, download the original, and move it to the trash — told first if a live page shows it

### Added (1.4d)

- The scale benchmark covers a million media items: the library's tabs, filters, search and deep pages, the picker, and where an item is used
- Every content tab's sort is now checked for walking its index in order, as the content-management design promises

## [0.1.0-alpha.3] — Slice 1.3: Content management

### Added (1.3a)

- Content can be reverted to a draft, moved to the trash, restored exactly as it was, and deleted for good
- Trashing frees an item's address for new content; restoring into a taken address adds a number and says so
- Exact counts per status and per author, kept up to date by every change; `php artisan teme:counts:rebuild` checks them
- Settings › Content: how long the trash is kept (30 days by default), with a daily purge

### Added (1.3b)

- Categories — nested — and tags, with screens to add, rename and delete them
- A post's categories and tags are saved with each version of it: editing them on a published post changes nothing live until the update is published, and restoring an old version restores them too
- Each category and tag shows how many published items use it, kept up to date by every change

### Added (1.3c)

- Posts and Pages screens: status tabs with exact counts, filters by author and date, title search, and Newer/Older paging that stays fast at any size
- Quick edit — title, address, author, categories, tags and published date — which publishes the change when nothing else is waiting, and otherwise keeps it in the draft
- Row actions and bulk actions on up to 100 selected items, each checked on its own, with anything skipped listed and explained
- Start a new draft from its title
- Titles and category and tag names sort in the same order on every database, whatever its operating system

### Added (1.3d)

- The field registry: SEO title, meta description, excerpt, canonical URL and "hide from search engines" on every post and page, stored under keys that never change
- `php artisan teme:docs:fields` generates `docs/reference/fields.md`
- The scale benchmark: `php artisan teme:bench:seed` and `teme:bench:run` prove the admin stays within its time budgets at a million items; the first result is recorded in `docs/benchmarks/`. The first million-item benchmark ran on a busy development machine; its timings are provisional until a run on a quiet host.
- Category and tag filters stay fast on large sites: each category and tag now also counts the live items it is on, and the list uses that to choose how to search

### Changed (1.3d)

- Category names in the Categories screen and pickers are found by a direct lookup rather than a scan of every category

### Changed (1.2e)

- Every class in `app/` now says what it is for, and a test keeps it that way; comments that cite a document are checked to point at one that exists
- Settings pages link to every settings group you may manage
- Account pages read sign-in security status through a query class
- CI runs on the Node version pinned in `.nvmrc`

## [0.1.0-alpha.2] — Slice 1.2: Admin shell

### Added (1.2a)

- The admin at `/admin`, on Laravel's React starter kit: sign-in, password reset, email verification and optional two-factor sign-in, on TypeScript 7
- Account pages — Profile, Security, Preferences — where every change goes through an Operation (`UpdateProfile`, `ChangePassword`, `ResetPassword`)
- `CurrentUserData`: the browser receives only chosen fields of the signed-in user, never the model
- The admin shell: sidebar, skip link, drawer below 1024 px, the interface palette
- CI on PostgreSQL 18

### Added (1.2b)

- The settings registry: settings are declared once in code, stored one row per group as typed JSONB, and changed only through `UpdateSettings`
- Settings › General — site title, tagline, administration email and time zone — generated from the registry
- Every settings change is recorded: who, what, before and after (secrets by name only)
- Shared form field components, which content fields will reuse
- `php artisan teme:docs:settings` generates `docs/reference/settings.md`

### Added (1.2c)

- Passwords are hashed with Argon2id; older bcrypt hashes still verify
- Password rules come from Settings › Security: a minimum length (15 by default), and new passwords are checked against known breaches without sending them
- Sign-in and password reset never reveal whether an account exists
- Sign-in limits that strangers cannot use to lock someone out: a browser that has signed in before is not held back by failures elsewhere
- Sensitive changes — security settings, your email address — ask you to confirm your password if you have not in the last 15 minutes
- Sessions end after 2 idle hours and 24 hours at most; remember-me lasts 14 days; changing or resetting a password signs out every other session
- The admin sends a strict Content Security Policy

### Added (1.2d)

- **Users**: invite people by email — they choose their own password — change roles, suspend, and delete with their content reassigned; the last administrator cannot be removed
- `php artisan teme:user:create` and `teme:user:reset-link` for the server's operator, even when email does not work
- Settings › Security can require two-factor sign-in for chosen roles; none are required on a new site
- A security event log — sign-ins, failures counted per hour, password, two-factor, role and security-setting changes — with its viewer planned for phase 6

### Changed (1.2a)

- The architecture test now forbids concrete models in `App\Http` and `App\Actions`, closing a gap proven on 18 September 2026 (I-097)
- A PHPStan rule forbids model writes from the HTTP layer, by type (I-099)
- Passwords: minimum 15 characters, maximum 256, no composition rules

### Removed (1.2a)

- Public registration (I-101) and self-service account deletion (I-102)

## [v0.1.0-alpha.1] — Slice 1.1: Foundation

Cannot yet publish a public site.

### Added

- Laravel 13.32 application skeleton, running on PostgreSQL 18 (Docker for the database, Laravel Herd for local PHP)
- Pint, PHPStan/Larastan (level 6) and Laravel Boost, wired into a `composer check` gate
- Architecture tests (`tests/Architecture/LayeringTest.php`) mechanically enforcing that `App\Http` never touches Eloquent models directly
- `teme/contracts` package (`Teme\Contracts\Operations\Operation`, `OperationInput`) — the extension surface, dependency-free of the application and of Laravel
- `App\Cms\Support\Operations\BaseOperation` — the fixed authorize → validate → perform (transaction) → hooks → cache lifecycle every state mutation follows
- Four editorial roles (`administrator`, `editor`, `author`, `contributor`) with `spatie/laravel-permission`, and the five `content.*` permissions later policies depend on
- `contents` / `content_revisions` schema: one row per content item, JSONB `document` and `fields`, immutable revisions, circular FK pointers for `published_revision_id` / `working_revision_id`
- `Content` and `ContentRevision` Eloquent models
- `CreateDraft`, `SaveDraft`, and `PublishRevision` operations, with `ContentPolicy` — a draft can now be created, edited repeatedly (writing a new revision each time, never touching a published pointer), and published, entirely through permission-checked Operations
- `StaleRevisionException` — a second editor's save is refused rather than silently overwriting a first editor's work
