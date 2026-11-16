# Proposal: plugin sockets, round 2

**Status:** draft (2026-11-16). **Decides:** the sockets for what an outside developer porting a real WordPress ad plugin (Ad Placr) to Teme could not build: a plugin's own admin pages, inline scripts in ad code, more places to insert content, and three smaller sockets (`FINDINGS.md` F-08, F-09, F-11, F-15, F-17, F-18, F-19; `FEATURES.md` S6, A1, A11 to A14, P6 to P9, R4, T7, T8). Checked against Ad Inserter's free and Pro feature lists. **Plan:** `docs/plans/plugin-sockets-2.md`. **Branches:** `sockets2/plugin-area` (A), `sockets2/inserts` (B), `sockets2/small` (C).

## What it is

- **A. A plugin's own area.** A plugin declares an admin area in its manifest (pages, figures, charts, tables of its own records with row and bulk actions, forms with sections, a repeater and show/hide rules) and supplies the data through a small PHP contract of plain values. Core draws every screen with its own components; no plugin JavaScript or CSS runs in the admin. Every write is a core Operation, as the signed-in person, under a permission the plugin declares. The same repeater, sections and show/hide rules work on a plugin's settings page.
- **B. Inserted content beyond the article.** Plugins may add content in the head, at the top and bottom of every page, between items on lists, before or after a theme's regions, and fixed to the screen's edge, all drawn by core. An administrator can approve a named inline script for a plugin (an AdSense unit's `push({})`), whose hash then joins the page's script policy, as Site code does today. A plugin can never approve one itself.
- **C. Three small sockets.** A `custom` route verifier without a secret, a plugin's manifest words translated from its own `lang` files, and schedules that state when they are due, with a command that shows it and a documented way to test it.

## Why

I-3396: a wall an outside developer hits is a missing socket, fixed in core. The Ad Placr port works only by squeezing: five fixed ad slots of 105 settings in one flat list (F-11, F-17), statistics stored but never shown (T7), manual AdSense units that draw but never fill (F-08), no header, footer, list or sticky positions (P6 to P8), a secret setting declared only to satisfy the route validator (F-15), English-only labels (F-09), and a daily job whose schedule could not be tested (F-19). Ad Inserter, the most-used WordPress ad plugin, shows the rest of what an ad plugin needs; the checklist below maps each of its features.

## Settled

1. **Declared, drawn by core, fed by plain values.** A plugin's area is declared in its manifest (`contributes.area`), validated at enable, and drawn by core from data the plugin returns through `Teme\Contracts\Admin\PluginArea` (`figures`, `chart`, `rows`, `values`, `save`, `act`). Values are `Figure`, `Chart`, `Row` with `Cell`s (round 1's list cells reused), never HTML. I-212's declared path; no plugin bundle in this round.
2. **Blocks and limits.** Pages hold `figures` (up to 4), `chart` (line or bar, up to 3 series, 366 points), `table` (a record's list), `actions` (round 1's declared actions), `notice`. Up to 8 pages, 8 blocks a page, 3 record kinds, 60 fields a form.
3. **One menu entry, navigation inside.** The area gets one entry in its task section (Content or Site), or a Tools card; pages switch inside the area (`PluginAreaPage`, More beyond 5). At most 5 plugin menu entries site-wide; a sixth goes to Tools. Budgets place entries; they never refuse a plugin.
4. **Plugin permissions.** A plugin declares up to 3 permissions (`plugin.{code}.{id}`), held by administrators and the roles it names, listed in the consent; an added role waits for consent. Made at enable, kept while disabled, removed with the plugin's data.
5. **Writes are core Operations.** `SavePluginRecord` and `ActOnPluginRecords` check the permission, validate against the declaration, call the plugin inside core's transaction with a 2-second budget, roll everything back on a refusal or a fault, and purge the declared page-cache tags after commit. Delete asks first. Never the operator, never elevation.
6. **Reads under budgets.** 250 ms a call and 1 s a page, each block loading on its own; a slow or failing block says so in one line, the fault is recorded under the plugin, and the rest of the page works.
7. **Fields.** The settings types plus `code`, `datetime`, `types`, `terms`, `region` and a one-level `repeater` (add, remove, move, collapse; up to 50 items). Core draws the pickers for content types, terms and the active theme's regions, so nobody types an ID.
8. **Show/hide rules, one rule everywhere.** `visibleWhen` (every key must match; a list matches any; earlier fields only) works in plugin forms and on plugin settings pages, as it already does for theme settings. A hidden field is neither validated nor changed; the plugin never receives it.
9. **Settings pages grow sections, repeaters and code.** `contributes.settingsSections` splits a plugin's settings page into panels; `repeater` and `code` become setting types. A plugin can't write a `code` value itself.
10. **New places to insert, drawn by core.** Capability `pages.insert`: the head (script sources, a short list of meta names, preconnect links, approved scripts only), the top and bottom of every page, after the nth item or every nth item on lists (themes call `<x-teme::between-items>`; the default theme does), before or after a theme region (also when the region is empty), and fixed to the bottom, top or a side rail, with a close button, a label, no animation, one per edge, rails only where the screen has room. Never on error, password or preview pages, feeds, the API or Markdown.
11. **What a page tells a plugin.** `PageFacts`: the kind of page, its path, language, type, item, terms, authors and page number. Never anything about the visitor. `ArticleFacts` also gains the path.
12. **Inline scripts: approved by a person, by exact text.** A plugin's inline script is kept only when its exact text was approved for that plugin on Settings › Site code by someone who may change site code, with the password asked again, and only while the host allows site code. Requests come from core when a `code` field is saved, or from a door the plugin may call; approval never does. Approve, refuse and revoke are logged in the security log and purge the pages. At most 20 waiting per plugin.
13. **Hosts for inserted content.** `contributes.csp` is read at last, for plugins that insert, and applied only to pages where that plugin's content was drawn; the consent lists the hosts.
14. **The page cache, written down.** A cached page is the same for everyone: a random pick is frozen until the page is purged; choose per device with CSS; choose per visitor only in the browser. Saving a plugin's settings or its area purges the pages it touches; `notify()` is for other changes.
15. **`custom` verifiers need no secret.** When given, the secret must still be declared.
16. **Manifest words translate from the plugin's own `lang` files only**, by their English text, falling back to English; what people typed is never translated.
17. **Schedules are interval-based and say so.** Due on the first pass after enable, then each interval after the last queued run, catching up once. `teme:plugins:schedules` shows the next run and any reason it can't run; `--plugin` limits a pass; the handbook shows a test that fires one.
18. **Contracts follow the contracts rule.** `@since` the next release, nothing from `App\` or `Illuminate\`, value classes grow only at the end. Every write is an Operation with a test that a refusal writes nothing. Every word through `__()`, in American English.
19. **Screens are drawn and shown before they are built.** A's area screens and settings sections, and B's Site code panel, wait for the maintainers' approval of the drawings (below); the builders STOP before those tasks until then.

## Provisional (built as written unless the maintainers say otherwise)

- P1. The numbers in Settled 2, 3, 6, 7, 12 and the plan: 3 permissions, 5 menu entries, 100 rows a table page, 100 ids a bulk action, 4,096 characters a script, rails 160 or 300 wide, a fixed bar at most `min(30vh, 250px)` high.
- P2. The words quoted in the plan, among them `This part of :plugin couldn’t be shown this time.`, `:plugin couldn’t save this. Nothing was changed.`, `:count script waits for an administrator’s approval in Settings › Site code.` and the `pages.insert` consent sentence.
- P3. The fixed bar's label defaults to `Advertisement`; a plugin may set its own, up to 24 characters.
- P4. Route paths `admin/plugin/{code}/…`.

## Open questions for the maintainers (clearly open)

1. **Declared in the manifest, or built in PHP?** Recommended: the manifest (Settled 1), as settings, columns, lists and actions already are: validated at enable, shown in the consent and `teme:plugins` without running plugin code, documented by the reference generator. The other way, value builders in the contracts (`Field::text()`, as `Setting::text()`), is nicer to write and allows structure that depends on data, but runs plugin code to learn the shape. Sharp and Filament declare in PHP; Emdash's Block Kit returns data at run time.
2. **Who approves an inline script, and where.** Recommended: only on Settings › Site code, by holders of `site-code.manage` with the password (Settled 12). The other way adds an "Approve now" tick on the plugin's own form for such a person, which is quicker but puts an approval on a screen a plugin's declaration shapes.
3. **`contributes.csp` versus the reserved `site.output`.** Recommended: read `csp` for plugins that insert, scoped to the pages they drew on (Settled 13); `site.output` stays reserved for site-wide output. Without it, ad networks' frames and connections fail once the policy is enforced.
4. **Fixed positions in this round.** Recommended: bottom, top and two rails, core-drawn, no animation, a close button (Settled 10). Ad Inserter Pro also offers center overlays (popups), corners, scroll triggers, animations and background ads; recommended later, each with its own case, because they cover reading and fight reduced motion.
5. **The menu cap.** Recommended: 5 plugin entries site-wide, the rest in Tools (Settled 3). I-216 asks for a cap and names no number.
6. **Charts without a dependency.** Recommended: core draws line and bar charts as inline SVG with a hidden data table (no new package). The other way is a chart library, which is a dependency decision for the maintainers.
7. **Dragging repeater items.** Recommended: Move up and Move down buttons always; dragging only if `@dnd-kit` is already installed (I-1720). Installing it is the maintainers' call.
8. **Plugin roles at enable.** Recommended: a plugin may name editor, author or contributor to receive its permission at enable, listed in the consent (Settled 4). The other way, administrators only until a Roles screen exists, is safer but leaves an ad plugin's editors out.
9. **Ship order.** Recommended: B now (it waits for nothing), A after round 1's `sockets/plugin-admin` and the drawings, C after round 1's `sockets/outbound-feeds` and `sockets/plugin-admin`. All in the release after round 1 if 0.9.0 is untagged by then.

## The screens to draw (shown to the maintainers before they are built)

Each extends v10's own screens, never a fresh design: `docs/demos/v10/plugin-postlinkly.html` (a plugin's area: header with the plugin mark, in-page tabs with More, figures, panels, tables with row actions) and `settings-imgverse.html` (a plugin's settings page: sections, the side list with "Find a setting", one Save bar).

1. **The area's overview**: the header with the plugin's initials, in-page navigation, a row of up to four figures (with change arrows), a line chart with its legend, an actions panel, a notice; a block that couldn't load; the loading skeleton.
2. **A records table**: search, a filter, a sortable column, a badge column, selection with a bulk bar (Enable, Disable, Delete), each row's menu (Edit, Duplicate, Enable or Disable, Delete), Add, the empty state, paging; phone width as v10's list cards.
3. **A record's form**: sections as panels and the side list, a condition showing a field, a repeater with two items (one collapsed showing its title, one open), Add, Move, Remove, a code field with the "waits for approval" line, the Save bar, the delete confirmation, a refusal at the top.
4. **A plugin's settings page with sections**, a condition, and a repeater setting.
5. **Settings › Site code, "Scripts from plugins"**: a waiting script (read-only code, who asked, Approve and Refuse), an approved one (Revoke), the empty state, the password prompt.
6. **The consent dialog**: permissions and roles, the area's menu place, `pages.insert`, hosts for inserted content.
7. **The public side**: a fixed bottom bar with its label and close button on a phone; a right rail on a wide screen; an insertion between list items in the default theme.

## Ad Inserter checklist

Ad Inserter's features from its local free copy (`readme.txt`, `constants.php`) and its public pages (wordpress.org/plugins/ad-inserter, adinserter.pro features and sticky-ads documentation; Pro read from public pages only). **Edition:** Free or Pro, as those pages split them. **Teme:** *today* (a plugin can do it on Teme now), *this round* (this proposal adds the socket), *later* (with what brings it back), or *not in Teme* (with the reason).

### Insertion positions

| Ad Inserter position | Edition | Teme | How |
|---|---|---|---|
| Before post (above the title) | Free | this round, partly | Before a theme region placed there (`PagePosition::region`); a core position above the title is later, with a theme that needs it |
| After post | Free | this round | After a region (the default theme's `after-content`), or `afterContent` inside the article today |
| Before content / after content | Free | today | `Position::beforeContent()`, `afterContent()` |
| Before / after paragraph N | Free | today | `afterParagraph(n)`; before N is after N−1 |
| Multiple paragraphs, every N | Free | today | `everyParagraphs(n, max ≤ 10)` |
| Random paragraph | Free | today | The plugin picks; frozen into the cached page |
| Relative positions (middle, near end) | Free | today | `nearEnd()`, or a paragraph worked out from `ArticleFacts::$paragraphs` |
| Before / after a heading | n/a (Teme's own) | today | `beforeHeading`, `afterHeading` |
| Before / after image | Free | later | A `Position::afterCard('image', n)`, when an ad plugin asks |
| Avoid ads near images or headings | Free | today, partly | Points never fall inside a card; spacing rules are the plugin's |
| Before / after excerpt (on lists) | Free | this round, partly | `afterItem(n)` / `everyItems(n)` between items; inside a card's excerpt is not offered (the theme owns the card) |
| Between posts on blog pages (in-feed) | Free | this round | `everyItems(n, max)`, `afterItem(n)`, drawn by `<x-teme::between-items>` |
| Before / after / between comments | Free | later | Positions around the comment thread, when an ad plugin asks |
| Above header (after `<body>`) | Free | this round | `PagePosition::bodyStart()` |
| Footer (before `</body>`) | Free | this round | `PagePosition::bodyEnd()` |
| Header code (`<head>`) | Free | this round | `PagePosition::head()` (script sources, allowed meta, preconnect, approved scripts); administrators also have Site code today |
| Before / inside / after any HTML element (CSS selector) | Free | today, in the browser | The plugin's own script, served from its route on the site's origin, may move its ad client-side; core offers declared positions, not selectors (themes differ) |
| Custom hooks (`do_action`) | Free | later | Plugins can't publish hooks yet (Ad Placr S8); theme regions cover theme-chosen places this round |
| Widgets (sidebar) | Free | this round | Before or after a theme region; the region list comes from the active theme |
| Sticky widgets (sidebar stays in view) | Free | this round, partly | Fixed left or right rail; sticking inside a theme's sidebar is the theme's CSS |
| Shortcodes | Free | not in Teme as shortcodes; later as cards | Content is editor JSON (rule 7); I-3127's named snippet card and plugin cards bring manual placement |
| Gutenberg block | Free | later | Plugin cards |
| PHP function call in a template | Free | not in Teme | Themes are looks only and run no plugin code; regions and positions replace it |
| 404 page | Free | later | Error pages carry no insertions now |
| Ajax, RSS, REST insertion | Free | not in Teme | Feeds, the API and Markdown never carry insertions, by design |
| AMP pages | Free | not in Teme | Teme has no AMP |
| Sticky top / bottom (fixed to the screen) | Pro | this round | `PagePosition::fixed('top' | 'bottom')` with a close button |
| Sticky left / right of content, screen edges | Pro | this round, partly | Rails 160 or 300 wide, shown only where they fit; screen-edge versus content-edge choice later |
| Corners, center, "scroll with content" | Pro | later | Each its own case |
| Popup / center overlay with close | Pro | later | Covers reading; needs its own decision (open question 4) |
| Background (skin) ads, parallax | Pro | later | Needs the theme's cooperation |
| Show / hide triggers (scroll, element in view), animations | Pro | later | Reduced motion and reading first; a plugin's own script may trigger its own content today |
| Close button | Pro | this round | Core draws it on fixed insertions |
| Content push (space kept for a sticky block) | Free | this round | Core pads the page for a fixed bar |

### Rules, rotation and loading

| Ad Inserter feature | Edition | Teme | How |
|---|---|---|---|
| Categories, tags, taxonomies, post types, post IDs | Free | today (articles), this round (lists) | `ArticleFacts`; `PageFacts` on every page; area fields `types` and `terms` give pickers |
| URLs and URL query parameters | Free | this round (path) / not in Teme (query) | `path` in both facts; a query never reaches a cached page |
| Logged in / guest, user roles | Free | later | Pages are cached for everyone; a visitor-state socket would need the cache's agreement |
| Cookies, referrers, browsers, operating systems | Free | today, in the browser | The plugin's own script; never on the server for a cached page |
| IP addresses, countries; city and region geotargeting | Pro | later | A geo socket at the edge or a client-side service; not on the server for a cached page |
| Device detection, client-side | Free | today | CSS media queries (Ad Placr does it) |
| Device detection, server-side | Free | not in Teme | Would split the page cache by user agent |
| Scheduling (dates, times, weekdays, fallback) | Free (fallback Pro) | today | The plugin's rules, in the site's time zone; a `datetime` field this round |
| Rotation, server-side | Free | today | The plugin picks; frozen per cached page (Settled 14) |
| Rotation, client-side, timed rotation | Free | today, in the browser | The plugin's own script |
| A/B testing | Pro | today | Versions with weights, counted by the plugin; shown in its area this round |
| Frequency capping | Pro | today, in the browser | The plugin's own script and storage |
| Lazy loading, delayed loading until interaction | Pro | today, in the browser; core flag later | The plugin's script loads the network's script on scroll; a core `lazy` flag later |
| Per-post exceptions | Pro ("individual") / Free (disable on post) | today, partly | Core's "No inserted content" on any item; a per-plugin switch in the editor is later (I-3418) |
| GDPR consent, IAB TCF 2.0 | Free | later | A core consent socket (I-3375's privacy work) |

### Code, networks and reporting

| Ad Inserter feature | Edition | Teme | How |
|---|---|---|---|
| AdSense auto ads (one script source) | Free | today | `<script src>` kept, origin added |
| AdSense manual units, Ad Manager, Media.net, Ezoic placeholders (inline scripts) | Free | this round | Approved inline scripts (Settled 12) and inserted-content hosts (Settled 13) |
| Ads in iframes | Pro | today | `<iframe>` kept, `frame-src` added |
| Banners, images, HTML, CSS | Free | today | Cleaned markup; `<style>`, `<ins>`, `data-*` kept |
| PHP code processing in ads | Free | not in Teme | Code is read-only at runtime |
| Raw HTTP response headers | Free | not in Teme | Core owns response headers |
| Many ad blocks, each with its own settings | Free | this round | Records in the plugin's area, a repeater for versions |
| Copy and paste blocks (clipboard) | Free | this round | Duplicate row action |
| Syntax-highlighting editor, visual editor, code generators | Free | later | A plain monospace `code` field this round |
| Preview of an ad | Free | later | A preview block, when asked |
| Ad label above ads, custom wrapper class | Free | today | The plugin's own markup |
| Impression and click statistics | Pro | today (counting), this round (showing) | Beacon route and own tables today; figures and charts in its area this round |
| Statistics reports as PDF or public pages | Pro | later | |
| Google Analytics or Matomo tracking | Pro | today, in the browser | The plugin's own script |
| Click fraud protection, reCAPTCHA v3 score | Pro | later | |
| Ad-blocking detection, messages, replacement; ad-blocking statistics | Free / Pro | today, in the browser | The plugin's own script and route |
| ads.txt editor | Free | today | Core's Settings › Site code |
| Global custom fields, contextual ads from post data | Free | today, partly | Facts carry type, terms, authors, language, size and path; the title and fields later |
| Settings export and import | Pro | later | |
| Multisite limits | Pro | not in Teme | No multisite |
| Debugging, visualizing positions and blocks | Free | later | A "show insertion points" preview for administrators |
| Remote plugin management | Pro | not in Teme | Plugins are Composer packages; installs go through the installer |

## References checked

Teme's `Reference/` folder holds 40 systems; these bear on this proposal (paths relative to `Reference/`):

- **Emdash** (`emdash/packages/blocks/src/types.ts`): Block Kit, the closest model. Plugins return declared blocks (`header`, `fields`, `table` with column formats including `badge`, `stats`, `chart` with time series, `form`, `actions`, `banner`), a `repeater` element with `min_items`, `max_items`, `item_label`, and field `condition` (`eq` / `neq`). Taken: blocks as data, stats and charts from plain numbers, a one-level repeater, conditions. Not taken: raw chart options passed to a library.
- **Sharp 10** (`sharp-10.x/src/Dashboard/Widgets/SharpFigureWidget.php`, `SharpLineGraphWidget.php`, `src/EntityList`, `src/Commands`, `src/Form/Fields/SharpFormListField.php`, `SharpFormField::addConditionalDisplay()`): server-driven admin in PHP: entity lists with commands, forms with list fields and conditional display, dashboards of figures and graphs. Taken: figures and line/bar graphs, row commands; the PHP-declared alternative is open question 1.
- **Filament 5** (`filament-5.x/packages/forms/src/Components/Repeater.php`, `packages/schemas/src/Components/Concerns/CanBeHidden.php`): Repeater with min/max items, collapsible items and item labels; `visible()`/`hidden()`; hidden fields are left out of the saved state. Taken: hidden is untouched, collapsed item titles.
- **Statamic** (`Statamic CMS/src/Fieldtypes/Replicator.php`, `Grid.php`, `Group.php`, `src/Fields/Field.php`): blueprints with sections, `if`/`unless` conditions, Grid (repeater) and Replicator (sets). Taken: sections and conditions on earlier fields; Replicator's several item kinds are later.
- **Craft CMS 5** (`Craft CMS 5/cms-5.x/src/fieldlayoutelements/BaseField.php`, `src/fields/conditions/`): field layouts with tabs and per-field conditions. Taken: conditions belong to the layout, not the stored value.
- **Payload** (`payload-main/packages/payload/src/fields/config/types.ts`, `ArrayField` at line 1341, `admin.condition` at line 382): array fields with min/max rows and a condition function. Taken: bounds on rows; a function as a condition is not (Teme's rule is data).
- **Strapi** (`strapi-develop/packages/core/content-type-builder/server/src/controllers/validation/schema.ts`): repeatable components. Taken: a repeater is a list of objects of one shape.
- **Twill 3** (`twill-3.x/src/Repositories/Behaviors/HandleRepeaters.php`, `HandleJsonRepeaters.php`): repeaters stored as related rows or as JSON. Taken: a settings repeater as a JSON list (rule 4).
- **October 4 and Winter** (`october-4.x/modules/backend/formwidgets/Repeater.php`, `winter-develop/modules/backend/formwidgets/Repeater.php`, `winter-develop/modules/backend/classes/FormField.php` `trigger`): form widgets declared in YAML, Repeater, `trigger` show/hide. Taken: declared forms; not their plugin-supplied widget code.
- **Drupal** (`drupal-main/core/misc/states.js`): Form API `#states`, conditions evaluated in the browser and repeated on the server. Taken: the same rule on both sides.
- **WordPress** (`wordpress-develop-trunk/src/wp-admin/includes/plugin.php:1398` `add_menu_page`, `template.php:1634` `add_settings_section`): any plugin claims any menu position and draws its own HTML; sections in the Settings API. Taken: sections; not the free-for-all menu (I-216) or plugin HTML.
- **pursae** (`pursae-main/docs/developer/plugins/abi.md`): `register_admin_page` and `register_dashboard_widget` callbacks return HTML strings; `register_settings_page` declares fields. Taken: declared settings fields; not HTML from plugins.
- **Capell** (`capell-main/packages/admin/resources/views/components/schemas/repeater-tabs.blade.php`): Filament-based repeater presentations. Confirms the Filament model.
- **Ad Inserter** (local copy `wp-content/plugins/ad-inserter`, `constants.php` lines 298 to 451, `readme.txt`; public pages adinserter.pro and wordpress.org/plugins/ad-inserter; GPL, read for ideas only, no code copied): the checklist above.
- Checked and not relevant here: Ghost, Joomla, Wagtail, TypiCMS, Pubvana, webblocks, phprs, basehim, laradashboard, the artisanpack packages, acorn and sage, millicache, the multilingual and SEO plugins (Polylang, MultilingualPress, TranslatePress, Rank Math, Yoast), react-starter-kit, emdash-exporter.
- **Missing from the folder:** Ad Inserter Pro's code (read from public pages only, by design), and a dedicated ad-management CMS module; neither is needed for these decisions.

## Not in this round (Later, each with what brings it back)

1. **Plugin interface bundles** (I-212's second path). Back with a plugin whose screen core's blocks can't draw.
2. **Plugin-published hooks** (Ad Placr S8, T6; Ad Inserter's custom hooks). Back with two plugins that need to talk.
3. **Positions around images and comments, the 404 page, above the title.** Back when an ad plugin asks, each a small `Position` or `PagePosition`.
4. **Overlays, corners, animations, background ads, triggers.** Back with their own decision (open question 4).
5. **Visitor state, consent and geography on the server.** Back with a consent socket (I-3375) and an edge-aware cache.
6. **A core `lazy` flag** for insertions. Back when two plugins load their scripts late.
7. **Plugin cards and I-3127's named snippet card** for manual placement. Back with the editor's plugin card socket.
8. **Custom row actions that queue jobs**, nested repeaters, Replicator-style mixed items. Back with a plugin that needs them.
9. **A Roles screen** to change who holds a plugin's permission after enable.

## Decided (the maintainers, 2026-11-16)

All open questions as recommended, except two:

- **Q2 changed: the approval happens where the ad is written.** On the plugin's own form, a code field holding an inline script shows a core-drawn approval control (never drawn by the plugin): a person who may manage site code approves it there, with the password (the 15-minute window); anyone else sees "Waiting for approval" and the people who may approve get a notice. Settings › Site code also lists every approved and waiting script, where any can be revoked. A plugin can never approve its own.
- **Q6 changed: charts use a maintained package, drawn by core for every plugin.** Core draws line and bar charts with `recharts` (3.10.1, MIT, supports React 19; the package behind shadcn/ui's chart component, which the admin's design system follows), wrapped in a core Chart component; plugins hand over data only. Approved as a new dependency.
- Q1 manifest; Q3 `csp` read for inserting plugins, scoped to the pages they drew on; Q4 top, bottom and two rails, close button, no animation; Q5 five plugin menu entries, the rest in Tools; Q7 Move up/Move down, dragging only if `@dnd-kit` is already installed; Q8 a plugin may name editor, author or contributor at enable, listed in the consent; Q9 B now, A after round 1's `sockets/plugin-admin` and the drawings, C after round 1.
