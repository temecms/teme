# Admin design

The design system the admin is built to: its tokens, its type, its density, and the patterns every screen repeats.

**The admin is a tool, not a page.** That sentence is the whole standard, and every number below follows from it. The values are not taste: they come from `docs/studies/admin-interfaces.md` (study M), which read the source of nine working admin interfaces — Emdash, Filament 5, Craft 5, Ghost, Payload, Twill 3, October 4, Wagtail and Laradashboard — and recorded what they measure. Where this document states a number, study M holds the evidence for it.

It was settled before the editor rather than polished at the end (option B, 2026-09-19). Slice 1.5a brings the existing screens into line.

## The five rules

Everything else is detail.

1. **The neutral ramp is achromatic.** Five of nine reference admins are at or near zero chroma. The two with a visible tint — Craft and Laradashboard — are the two that read as dated or as a dashboard template.
2. **One accent, doing two jobs**: the primary action and the focus ring. Nothing else. Laradashboard spends its accent a dozen ways and is the clearest example of the look this project rejected.
3. **Panels are regions divided by a hairline, not cards.** No radius, no shadow, no border box. Five of seven content systems do this; the two that use cards are a framework and a dashboard template.
4. **Serif belongs to content, never to chrome.** Across all nine admins, serif appears twice, and both are content. In Teme it is the post title and the body text, and nowhere else.
5. **Shadows belong to things that float** — menus, popovers, dialogs, drawers. A panel that does not float does not cast a shadow.

## Tokens

Defined once in `resources/css/app.css` and used by name. No component writes a raw colour.

The token names are the existing shadcn/Tailwind set already in that file; only the values change. The `L` column is the OKLab lightness, listed so that *zero chroma* is checkable rather than assumed — authors may write either form, but a ramp value with chroma in it is a bug.

### Neutral

| Token | Light | L | Dark | L | Use |
|---|---|---|---|---|---|
| `--background` | `#F6F6F7` | .973 | `#141414` | .191 | The application canvas |
| `--card`, `--popover` | `#FFFFFF` | 1.0 | `#1C1C1D` | .226 | Surfaces that sit on the canvas |
| `--muted`, `--secondary` | `#F1F1F2` | .958 | `#242425` | .261 | Table headers, inset areas, disabled fills |
| *(hover)* | `#EBEBEC` | .940 | `#2C2C2E` | .293 | Row and menu hover |
| `--foreground` | `#1A1A1A` | .219 | `#EDEDEE` | .946 | Titles and body text |
| `--muted-foreground` | `#5C5C5C` | .475 | `#A5A5A7` | .722 | Descriptions, labels, supporting text |
| *(faint)* | `#8A8A8A` | .634 | `#7A7A7C` | .580 | Identifiers, counts, timestamps |
| `--border` | `#E4E4E5` | .919 | `#2C2C2E` | .293 | The hairline that divides regions |
| `--input` | `#CFCFD1` | .855 | `#3D3D3F` | .360 | Control boundaries — stronger than a divider |

A divider and a control boundary are different jobs and take different values. Emdash makes every rule identical with a single global `border-color`; the effect is that nothing in the chrome competes with content.

### Accent and state

| Token | Light | Dark | Use |
|---|---|---|---|
| `--primary` | `#0A6273` | `#48ADC2` | **The primary action and the focus ring. Nothing else** |
| `--primary-foreground` | `#FFFFFF` | `#05222A` | Text on the accent |
| `--ring` | `#0A6273` | `#48ADC2` | Keyboard focus, 2px, offset 1px, full strength |
| *(link)* | `#1D4ED8` | `#8AB0FF` | Links in prose. A link is not a primary action |
| *(select)* | `#EEF3FD` | `#1E2533` | Selected table rows |
| `--success` | `#18794E` | `#5FC393` | Published, saved |
| *(success tint)* | `#E7F3EC` | `#14302A` | The background behind a success message |
| `--warning` | `#8A5300` | `#E0AE60` | In review, attention, missing alt text |
| *(warning tint)* | `#FAF0DD` | `#322614` | The background behind a warning |
| `--destructive` | `#B42318` | `#F08076` | Errors and irreversible actions |
| *(destructive tint)* | `#FBEAE8` | `#3A1B19` | The background behind an error |

**The accent is `#0A6273`** — hue ~189, 7.0:1 on white (I-188). The hue sits deliberately between the published green at 155 and the link blue at 226, because a CMS shows a status dot beside an accent constantly.

**Selected rows take their own colour, not the accent tint.** Ghost's source carries a comment on exactly this: a darker grey would compete with row hover, so selection gets a light blue of its own.

**Dark mode re-defines the ramp; it does not flip the light one.** Ghost re-defines with the chroma halved. Laradashboard keeps one ramp and pairs steps in markup, which is why its dark surfaces are inconsistent across three different components.

### Shape

| Token | Value | Use |
|---|---|---|
| `--radius-sm` | `2px` | Chips, badges, inline code |
| `--radius` | `4px` | Buttons, inputs, selects, menu items |
| `--radius-lg` | `6px` | Popovers, dialogs, drawers, the table's outer frame |
| *(panels)* | `0` | Panels have no radius, because they are not boxes |

The reference values run 2px (Twill), 3–5px (Craft), 5px (Payload), 6–8px (Ghost, Emdash). Filament's 12px panel is the most SaaS-looking figure in the whole set. Laradashboard, which defines no radius tokens at all, ended up with four different panel radii across four wrappers and a fifth in its own written guidelines — which is the argument for tokens in one sentence.

### Depth

One shadow, for things that float:

```css
--shadow: 0 8px 24px rgb(0 0 0 / .10), 0 1px 2px rgb(0 0 0 / .06);
```

### Looks (I-198)

**Dark chrome is the default look**: a dark neutral sidebar (`#1D1D1F`, `#0B0B0C` in dark mode) with light labels, light content, and the dark-mode teal (`#48ADC2`) for the active item's bar and the focus ring inside it. It is the pattern WordPress and Craft editors already know. Two other looks stay as personal schemes: **Standard** (a white sidebar with a hairline, a grey canvas and white working surfaces) and **Quiet** (one white surface everywhere, 14px interface text, 32px page padding, fewer dividers). **High contrast** is a separate switch under any look. A look is only token values, including a few structure tokens (`--ui-size`, `--page-pad`, `--cell-y`, `--list-y`, `--h1-size`, `--section-size`, `--divider-soft`). No component may know which look is on, and a test forbids it. Each person picks theirs in Preferences (1.5f); the look was chosen from a side-by-side comparison.

## Type

**IBM Plex, self-hosted, with the system stack behind it** (I-196). One family drawn as three: Plex Sans for the interface, Plex Serif for the writing surface, Plex Mono for identifiers. One decision and one licence, and the family's three voices already agree with each other.

Six of the nine reference admins ship a chosen face — Ghost, Filament, Payload and Twill use Inter, Emdash uses Noto Sans, Laradashboard uses Outfit. Only Craft, Wagtail and October use the system stack. Plex rather than Inter because Inter is the face this study found everywhere, and an early complaint was that the admin looked like everything else.

How it ships, and each rule has a reason:

| Rule | Why |
|---|---|
| **Bundled with core**, from Fontsource — `@fontsource-variable/ibm-plex-sans`, `@fontsource/ibm-plex-serif`, `@fontsource/ibm-plex-mono` | Vite copies the files into the build. No font service is ever contacted: the admin works on a server with no internet access, and Google Fonts sends every visitor's IP address to Google |
| **OFL-1.1** | It can ship inside Teme's MIT code |
| **The system stack stays behind every family** | Laradashboard removed its fallback, so its whole interface waits on a file. Teme's text is readable before Plex arrives |
| **`font-display: swap`** and a `unicode-range` per alphabet | Text shows at once, and a browser downloads Latin Extended only when a page has a character that needs it |
| **Plex Sans is the variable file** | One file covers every weight the interface uses, including 650 |
| **Serif: 400, 400 italic and 600. Mono: 400 and 500** | What the screens actually use, and no more |

In the prototype the whole set is about 240 KB for Latin and Latin Extended, and a page loads only the parts it draws: the Posts screen fetches no serif at all.

| Role | Size | Weight |
|---|---|---|
| Page title (`h1`) | 22px | 600 |
| Section heading | 15px | 600 |
| Body, table cells, controls | **13px** | 400 |
| Labels, table headers | **11px** | 500 |
| Hints, counts, timestamps | 11–12px | 400 |

Eight of the nine admins sit at 11–14px and cluster on 13–14. Nothing but Laradashboard uses 16px chrome. If 13px feels small, that feeling is the difference between a tool and a landing page.

**Labels are sans, sentence case, and never mono uppercase with tracking.** No product in the set does that; it reads as designed rather than built.

**Monospace is for identifiers** — slugs, IDs, file names, counts, timestamps. Not for labels.

**Serif** is Plex Serif with a Georgia fallback, and it appears on exactly two things: the post title in the editor, and the body text in the writing surface.

## Density

| | Value | Evidence |
|---|---|---|
| Control height | **34px**, small **28px** | Ghost 32, Craft 34, Filament 36 |
| Table rows | **44px** — cells 12px × 16px | Emdash 44, Payload 48, Filament and Laradashboard 56 |
| Table header row | 36px — cells 9px × 16px | |
| Page padding | 24px, section gap 16px | Emdash 24 |
| Content width | 1180px | |
| Sidebar | **240px**, collapsed 56px | Wagtail 200, Craft 226, Laradashboard 290, Ghost 300, Filament 320 |
| Top bar | 52px | |
| Spacing | **A 4px grid**, no odd steps | Craft's 14px and 18px steps are the counter-example |

Touch targets stay at 44 × 44px on small screens regardless of the control height above.

## Layout

**The shell** is a sidebar, a top bar, and a scrolling content area. The sidebar's active item takes a muted background, the foreground colour, and a 2px accent bar inset on its leading edge — not accent-coloured text, which is Laradashboard's mistake.

**A list screen** is: a page header (title, line, then Screen options and the primary action at the right), tabs with counts, one line of labelled filters that apply at once with Clear filters once any is in force, one bordered table region, a pager (I-3129). On a phone the filters are a line of chips that scrolls sideways, each opening a sheet. The table is the one place a bordered, rounded container is correct — Emdash does exactly this, and it reads as one object rather than a stack of cards.

**An edit screen** is a writing column and a right rail.

| | Value |
|---|---|
| Rail width | **360px**, resizable 320–480, sticky, its own scroll |
| Below 1024px | The rail becomes a sheet over the content |
| Writing area | Fills the room between the menu and the rail; text keeps a 692px measure, centred; tables, images and embeds take the full width (I-3121) |

Every content-first CMS in the study has a right rail: Payload 325, Craft 350, Emdash 368 resizable 320–480, Ghost 420, Wagtail a resizable third. Sticky with independent scroll is what makes it useful on a long form — Laradashboard's classic form is the one that omits this, and it is a proportional grid column rather than a considered width. The editor is a workspace the window's height: its header and toolbar stay in view, and the writing column and the rail each scroll on their own; the menu folds to its icons as it opens, unless the person unfolded it there, which the editor remembers in its own cookie.

**The rail is a pinned action bar over labelled sections.** The action bar holds Preview, Save and Publish and does not scroll away. Below it: Publish, Taxonomies, Checks. **Publish never collapses** and holds the article's summary, as Gutenberg's Summary panel does: status, visibility, publish date or schedule, address (the slug, edited in a small popover with the full URL shown), author, and a revisions count that opens History (I-230). Other sections may collapse. Taxonomy lists scroll past about 240px, with a search field once there are more than eight terms and checked terms listed first. Assistant, Document overview and History are panes (one at a time). Section headers are 48px. Delete sits last, behind a rule.

**Selecting a card swaps the rail** to that card's settings, and returns when it is deselected. This is Emdash's mechanism, and it is also how the AI panel and any future inspector arrive without new chrome.

## The writing surface

The editor is a **labelled form field** — a label above, a control background and border around it, a focus ring on `:focus-within`. This is the point a review correction turned on: even Emdash, the most content-first system in the study, keeps the editor inside a field, and that is what makes the screen read as an admin rather than a published page.

The tension between "a tool" and "a place to write" is relieved by a **focus mode** that hides the chrome, not by removing the chrome. Emdash and Ghost both do this.

| | Value |
|---|---|
| Measure | 692px, centred in the writing area (I-3121) |
| Body | 16px / 1.6, serif |
| Post title | 28px, serif |
| Order of the column | Title → Excerpt → Featured image → Body → Search and social (I-230) |
| Toolbar | **One row, sticky, always one line** (I-231, I-235, I-238): block type, bold, italic, underline, strike, the two lists, blockquote, link, table, undo, redo, **+ Insert** (every card, filterable), and one **Aa** menu for align, highlight, clear formatting, subscript, superscript, inline code and text colour. When the column is too narrow, controls fold into Aa one at a time (table, blockquote, lists, strike, underline); bold, italic, link and + Insert never fold. It never wraps and never scrolls sideways. The assistant is opened from the editor's top bar, not the toolbar |

A toolbar that scrolls sideways hides controls off its right edge, which is the same complaint as hiding them behind the slash menu; one that wraps grows a second line, which was rejected on sight (I-235). Folding into a named menu keeps every control one click away. Everything the editor can do is reachable from the toolbar, the slash menu, or both; cards are reached from + Insert and by typing /.

**The column is laid out as the article reads** (I-230). The excerpt sits under the title because that is where readers see it; the featured image follows, with Replace… and Remove, and Replace opens the same media library as every other image — Library first, then sources such as stock-photo plugins (I-201). Search and social sits at the foot of the column, full width, and starts closed: the search result at Google's size, a small social card, where the description comes from, and one line for the search checks. Edit opens its fields in place, Search beside Social, with Advanced closed under them, holding Hide from search engines and the canonical address; Done closes it (I-3124). An SEO plugin adds its checks inside it.

**The editor's top bar launches panels** (I-234). The bar that shows ← Posts, the status and the saved state carries, on its right, icons for the panes — Document overview, History, the Assistant — then up to three plugin icons each person pins, and a ⋮ menu listing every panel with Pin and Unpin. This is Gutenberg's top bar. An icon opens a pane in the rail (one at a time) or a plugin's full-screen workspace; it never adds to the formatting toolbar.

**Document overview** (I-232): characters, words, time to read, headings, paragraphs, images and links, and a clickable outline that flags skipped heading levels. A compact live count sits at the foot of the editor.

## AI

Three surfaces, chosen by scope (I-190). Laradashboard is the only product in the study with any AI, and it has all three:

| Scope | Surface |
|---|---|
| Site-wide | The command palette |
| This document | A rail section |
| This selection | The bubble menu on the selection |

**Quiet until asked.** No animated dot, no gradient pill, no permanent advertisement. An AI panel is a panel.

## Patterns

| Pattern | Rule |
|---|---|
| **Page header** | `h1` title, one-line description, primary action on the right |
| **Tabs** | One component. The active tab takes the foreground colour and a 2px accent underline; counts beside the label |
| **Filters** | A labelled button per filter, showing its value ("Category: Hiking"); a choice applies at once; one Date control: the months with their counts, then Choose dates… for an exact range (I-3129, I-3131) |
| **Row actions** | Links under the title, 24px tall, shown on hover and keyboard focus and always on a touch screen, their line's space kept; past four, the first three and the destructive one show and the rest wait under More, a menu that never locks the page (I-3130) |
| **Bulk bar** | Appears when rows are selected and says how many. Muted background and a hairline — not an accent panel |
| **Quick edit** | A side drawer, not an inline form |
| **Detail drawer** | 480px on desktop, full width on mobile, 6px radius, shadowed |
| **Confirmation** | A dialog for anything that cannot be undone. Moving to the trash needs none, because it can be undone |
| **Empty state** | Two: *nothing yet*, with the primary action; *nothing matches*, with a way to clear the filters |
| **Pager** | *Newer* and *Older* as outline buttons, the same everywhere |
| **Notices** | Toasts for results, dismissible, linking to the affected item |
| **Forms** | Label, control, hint, error, in that order. Errors tied to their field for screen readers. Settings forms up to 960px: each group a titled panel with its one line, one column; a Save bar at the foot of the window, "Save {page} settings", ending with its form; a section that saves on its own keeps its Save in its panel's footer (I-3128). A checkbox sits beside its words; a number field is 88px with its unit. |

## Room for plugins

Every admin surface is drawn from a list of entries, never from hand-placed buttons, so plugins added in phase 5 look native and cannot crowd a screen. The ten surfaces, their budgets and the rules are in `docs/architecture/plugins.md`. Four of them change how core screens are built today:

- **The editor toolbar holds core formats only.** Anything else goes in the selection bubble's More group.
- **The rail shows its sections, or one pane.** The card inspector is a pane; a plugin's pane replaces the sections the same way.
- **Work that needs room gets a workspace,** a full screen opened from the document that returns to it — never a crowded rail section.
- **Every list shows a fixed number of entries and puts the rest under More:** row actions, bulk actions, Insert, commands.

Design tokens stay CSS variables, because plugin interface code reads them.

**Growth (study P, I-220, I-221).** The main menu is a registry like the settings navigation: a closed set of sections, at most two levels, and hubs (Settings, Tools, Reports) that take new areas before the top level does. Its overflow is the hubs, the command palette and each person's hiding, never a More section. Badges count only what needs action. Notices have three homes: a toast for a result, the Status page plus one banner slot for what blocks work, and an inbox for background results. Nothing prints at the top of every screen.

**The Tools hub is built** (slice 1.9h; `docs/superpowers/plans/2026-10-08-phase1-slice09h-tools-screens.md`, D1 to D16 are I-1140 to I-1155). Unlike Settings it has a page: `/admin/tools` lists, as cards, the tools the person may open, Page cache and then Redirects, each behind an ability of its own (`ToolsHub`, a `NavHub`; I-1142). The menu's Tools entry (the Site section, order 800 between Users and Settings, icon `wrench`) leads there, is left out for someone who may open none, and the page answers 403 to them. No card carries a badge, since nothing in Tools has a count that needs action yet. Plugins add their pages in phase 5 (I-220), at a `// Later (I-220)` line in `ToolsHub`. The design pass's differences from v9: the hub lists the two tools built, not eleven, each arriving in the part that builds it (I-081); v9's permission line and its sentences about plugins are notes for whoever reads the prototype, and the permission names are not v9's `tools.*` (I-1024); "Tools" is the first step of each tool's breadcrumb, with the tool's own name as its heading. **Tools › Page cache** drops v9's "Pages saved" (I-950: a store can't say without reading every tag row or listing a keyspace); its "Kept in: Redis" becomes words for each driver; it adds how long pages live (I-500) and the CDN in front when there is one; "Last cleared by hand" becomes "Last cleared here", naming who, because the operator's command records nothing; and while the cache is off it says so and has no button. The two tools' words, differences and Operations are in `docs/architecture/page-cache.md` and `docs/architecture/addresses.md`.

**Site › Types is built** (slice 2.1b to 2.1e; `docs/superpowers/plans/2026-11-05-phase2-slice21b-type-builder.md`, I-3300 to I-3304, designed before it was built). `/admin/types` is a menu entry under Site, for `types.manage`: a list of code's types (read-only, marked built into Teme) and the site's own, and a page for each type, `types/show.tsx`, whose Permissions panel (slice 2.1c) offers "Give this type its own" or "Use the standard content permissions", says what each role starts with when it is saved, and lists the type's six with the roles that hold them. The icon picker offers every Lucide icon behind a lazy chunk. The type's public address is not edited here: the page links to Settings › Addresses, which gained a "{Plural} archive page" row for each type with an archive and a line naming what holds its address when something does (slice 2.1e, in the warning colour at the small size). Settings › Reading gained "Types on the home page and in the main feed", and its note about the theme's own number moved straight under *Items per page* (slice 2.1d, I-3306). The screens' words, the lock's notice and the refused list are described in `docs/architecture/content-types.md`, *Site › Types*.

## Accessibility

Every page has one `h1`. Every control has a label that names it. Errors are announced and tied to their field with `aria-describedby`. Nothing depends on hover. Motion respects `prefers-reduced-motion`. Text meets 4.5:1 and controls 3:1 — the accent is 7.0:1 on white, and the focus ring is drawn at full strength.

## Where the admin stands

An audit on 2026-09-19, re-pointed at this standard on 2026-09-20. Slice 1.5a fixes these.

**Token bugs, found reading `resources/css/app.css`:**

1. `--destructive-foreground` is `#b42318`, the same value as `--destructive`. Nothing renders wrongly today — `alert.tsx` and `dropdown-menu.tsx` use it as red text on a pale background, and `button.tsx` dodges the token entirely with a hard-coded `text-white` — but the name promises "the colour that goes *on* destructive", and the first component to believe it will draw red on red. A hard-coded `text-white` in a themed button is the same bug wearing a hat.
2. In dark mode `--card` is `oklch(0.145 0 0)`, identical to `--background`, so a surface is distinguished from the canvas only by its border. Under this standard panels stop being cards anyway, but the two tokens still need different values.
3. In dark mode `--primary` is `oklch(0.985 0 0)`, near-white: the starter's inverted primary, which must become the accent.
4. The light ramp is blue-tinted (`#f6f7f9`, `#182230`, `#526071`, `#d9dee7`); dark is already zero chroma, so only light needs correcting.
5. The accent is `#5b3fd6`, a violet inherited from the starter kit.
6. No `--success` or `--warning` tokens; components use raw green and red.
7. `--radius` is `0.5rem` (8px), against 4px for controls.
8. The sidebar pair whose text is invisible in dark mode.

**Drift from the standard:**

9. Page titles are 20px `h2`s with no `h1` (`components/heading.tsx` renders `text-xl` inside an `h2`).
10. Controls are 36px high (`button.tsx`, `size.default: h-9`); the target is 34px. The `lg` size is 40px and `sm` is 32px.
11. The focus ring is drawn at half strength and 3px wide — `focus-visible:ring-ring/50 ring-[3px]` in `button.tsx`. It should be the ring token at full strength, 2px, offset 1px.
12. Three pages copy their own select styling instead of sharing one control — `pages/content/index.tsx` defines a local `selectClass` constant.
13. `ui/card.tsx` is `rounded-xl … shadow-sm` — Filament's 12px shadowed card, the exact figure study M identifies as the SaaS tell, sitting in our own component library.
14. The table markup is copied across three screens; the content list has no Status column.
15. Three different tab styles; two different pagers, one with no way back.
16. No empty states on any list.
17. Errors are not tied to their fields.
18. The invite form's role label points at nothing; the row role select has no label.
19. Deleting for good, deleting a term and disabling two-factor sign-in happen without confirmation.
20. Decorative shadows on static elements, and page padding that varies.
21. Raw neutral colours that bypass the tokens.
22. Unused starter-kit layouts, and the starter's "Platform" label in the sidebar.

Quick edit moves from an inline form to a drawer, and row actions into a menu, in the same part.

## The parity sweep list (I-440)

An audit on 2026-09-25 of the built admin (1.5h branch) against the adopted prototype, v9 with Dark chrome (I-229). Presentation is settled in one sweep once the screens stop changing: **the last part of slice 1.10**, before 1.11 and the rc tags. Only the shapes later code plugs into are pulled forward, to the slice that first needs them. Gaps that a planned slice or a recorded decision already covers are not repeated here.

**Pulled forward:**

- **Menu badges** — a core badge provider on the navigation registry (`BadgeProvider`, asked once a request, a parent's count adding its children's). Comments is the first provider (1.8c, D19; I-220, I-273); Posts' review count waits for a "Waiting for review" entry, which the built menu doesn't have.
- ~~**The menu's shape**~~ — *moved into the sweep (item 13) on 2026-09-27:* 1.6d's re-check never settled it, Navigation and Regions went in anyway, and the reason to pull it forward lapsed (I-440 note).
- **Decorative on Attachment details** — `media.md` sets decorative per use; I-237 and v9 also put it on the library item. A decision is needed before 1.10 is planned, because 1.10's alt-text suggestions attach there.

**For decision at the sweep:**

- **Row actions: a menu or inline links.** Decided 2026-10-29: links under the title (I-3130), reversing I-1315.

**The sweep:**

1. The shell: account menu top right, search on the left ("Search or jump to…"), a slot for the bell (the inbox arrives in 1.11, I-221), "Collapse menu" in the sidebar foot, and the `inset` sidebar variant dropped (`app-sidebar.tsx:48`).
2. The brand mark: a letter mark with the site name and a "Teme 1.0 · production" line, replacing the Laravel logo in the sidebar and on the sign-in screens (`app-logo.tsx`, `auth-simple-layout.tsx`).
3. Lists: a Preview row action; a Category filter control (the server already accepts `?category=`); the In review and Scheduled tabs shown even at zero.
4. Editor: a status chip in the editor bar; the Visibility control and an Author select in Publish; the featured image as v7's compact panel with its credit and a "Missing" alt chip.
5. Media screen: an Upload button with a page-wide drop instead of the drop box, a month select, v9's columns, and the Licence field; the modal's upload progress, Cancel and Clear, and a details panel that is always present.
6. Categories and Tags screens to I-225: tabs, the full add form, indented rows, and linked counts.
7. People: role tabs, an invite dialog, and a separate Invited table.
8. Settings: a grouped side menu and "Find a setting", instead of tabs — before the plugin settings groups multiply.
9. The palette: a Recent group, "Settings ›" hub entries, and icons.
10. Starter leftovers: the account layout and its copy, the starter Light/Dark/System control (the look lives in Preferences), "Log in to your account", and the dead `app-header.tsx`, `app-header-layout.tsx` and `placeholder-pattern.tsx`.
11. **Settings › Design becomes Site › Themes**, as v9 draws it (its `themes` entry under Site, `themes.manage`): the installed themes with their state (Active, Available, Broken, Incompatible), Activate, Preview, and the active theme's settings and menu places, which 1.6f built under Settings › Design because the spec (§5.9) placed theme settings there before v9 was adopted. Move the screen and its route, amend spec §5.9 and study B, and keep `/admin/settings/design` redirecting. Installing and removing themes stays with the installer (I-211, I-010). Raised 2026-09-26. **The Themes screen also carries v9's "Regions in {theme}" section** (2026-09-26): each region the active theme declares, with its label and a link to edit it in Content › Regions, and the **Unplaced** list of regions the active theme lacks (#20, I-228). Regions are still *edited* under Content › Regions, where editors work (I-395); the Themes screen is the theme manager's overview. *Correction 2026-09-27 (the 1.6 audit):* v9 does **not** put the accent on its Themes screen; see item 12.
12. **Settings placements as v9 draws them** (2026-09-27): the accent becomes **Brand colour in Settings › General** ("Themes read this; they do not own it": v9's `brand-colour` setting in `admin-v9.source.html`), with I-457's contrast readout; **Home page shows** and the home and posts page choices move from Settings › Addresses to **Settings › Reading** (v9's `home`, `front-page` and `posts-page` settings, `cat: 'reading'`); **Posts per page** lives there too, as the site setting #35 creates before the importer. Keep the old addresses redirecting.
13. **The menu's shape** (moved here from the pulled-forward list): Categories and Tags filed under Posts, and "Users" becomes "People", as v9 draws them (v9's `posts.terms` and `people` menu entries in `admin-v9.source.html`; cited by key, since line numbers move whenever v9 is redrawn).
14. Then a full screen-by-screen pass against v9, because the screens built in 1.6–1.10 will add their own gaps.

Found on 2026-09-27, while the built features were drawn into v9 (*What v9 doesn't show yet*, below). v9 is the target:

15. **Sign-in rules in Settings › People**: the build's separate Settings › Security screen becomes a "Sign-in rules" section there, with `settings.security.manage` still its permission.
16. **Trash retention in Settings › Writing**: the build's Settings › Content group ("Keep trash for").
17. **Settings › Media shows the fixed image widths** as information, beside the upload and pixel limits.
18. **Navigation as v9 draws it**: row buttons rather than a row menu, breadcrumbs rather than "← Navigation", and "Create menu" and "Save menu".
19. **Regions as v9 draws them**:
    - rows kept from other themes listed as "Unplaced";
    - each region's block limit shown ("At most N blocks");
    - "Region: X, used by Y" as the page heading.
20. **Changing a person's role in a dialog**, with your own row's button locked and saying why, instead of a side panel from a row menu.
21. **Looks that apply as you choose them** in Preferences, rather than on Save.
22. **Permission names as v9 has them**: `users.manage` becomes `people.manage`, with item 13's "People"; `settings.design.manage` and `themes.manage` become one, with item 11's Site › Themes.

From the second batch, 2026-09-28. v9 is the target, unless an item's design pass (I-468) finds the build's shape better; then v9 is redrawn to it:

23. **Share as a panel of the editor**, as v9 draws it, rather than the build's "Share" dropdown in the header.
24. **Publishing decisions**: v9 keeps them in the Publish panel's rows and its bottom buttons. The build pins an action bar at the top of the rail (Save, Schedule update, Update, Return, Approve and schedule…, Approve). The design pass weighs the pinned bar, which is always in view. *Slice 1.9k added (I-840, I-1208):* Publish, Schedule and Submit for review on an item that isn't live open a confirmation with core's checks, where v9 confirms every decision; updating a live item and approving act at once.
25. **Scheduling**:
    - v9 has one "At a set time…" row with presets and a single date and time field, confirmed in the pre-publish dialog.
    - The build has separate Date and Time fields with their own confirm button. *Slice 1.9k:* the confirmation opens for scheduling an item that isn't live only; scheduling an update to a live item acts at once.
26. **The save state**: the build has more states ("Saved 09:41", "Unsaved changes", "Not connected", "Not saved"). v9 has Saving… and Saved, plus "Changed by someone else". The design pass settles one set.
27. **The content lists**:
    - inline row actions ("Move to trash") rather than a row menu ("Trash");
    - bulk-bar buttons rather than a select with "Apply to N";
    - a bulk delete confirmation that names each item;
    - status as a suffix to the title, as WordPress shows it, rather than a column.
28. **Pages' tabs**: v9 has All, Published, Drafts, In review and Trash. The build uses the shared content list's tabs (Mine, Scheduled and the rest).
29. **The media library's filters**: v9 has a type select and All and Trash tabs. The build has type tabs (All, Images, Video, Audio, Documents, Trash).
30. **The media drawer**: v9 puts Move to trash, Restore and Delete permanently in the drawer's footer, with a confirmation dialog. The build puts every tool in one row in the body, and confirms trashing inline.
31. **One term for deleting forever**: content says "Delete for good" and media says "Delete permanently". Choose one for both (better-writing: one term per action). *Adopted 2026-10-01 and confirmed the same day* (I-646): "Delete permanently"; comments use it from 1.8c, and this sweep changes the content screens' labels and the "Keep trash for" hint.

## Screens flagged for a design pass (I-468)

v9 settles what each screen holds and where it lives. Some of its screens are only that: an inventory of what was decided, stacked panel by panel. **Every screen gets a design pass when its slice is planned, before it's built** (I-468): its hierarchy, grouping and wording are checked against the design skills and the reference systems' equivalent screens, and it's redrawn in v9, so the better screen is what gets built and the parity sweep measures against it. A parity sweep item gets the same pass before it rebuilds a screen. The screens below are the ones already known to need more than a check.

| Screen | Built in | What the pass must fix |
|---|---|---|
| Tools › Backups (I-364) | 1.12 | **No verdict first:** the one question, "is the site safe?", needs a single summary line before any detail. **Settings sit apart from what they set:** destinations are listed in one panel and added from another, and the time is far from "Next backup". **Shallow controls:** "Keep: daily 7, weekly 4, monthly 12" doesn't explain itself, and a destination can't be edited, tested or removed. **Five panels of equal weight.** Raised 2026-09-27.<br>**Built:** designed before it was built (I-468), with the verdict first (I-2409); the screen is described in `docs/architecture/backups.md`, *Tools › Backups*. Differences from v9: (1) the lead is one sentence and its level, an icon and words ("Needs attention", "Needs a look", "All well"), with the reasons under it; the newest backup's line and "Next backup {when}. {What's kept}" with *Change* sit in the same block (I-2977); (2) five panels of equal weight become a lead and four sections: "Where backups are kept", "Latest checks", "Recovery keys" and "Restoring"; (3) the time and what's kept open in place beside the next backup, the hour a select of the 24 hours, not a time input, and the three counts inside the sentence they make, with how pruning works under it (I-2981, I-2982); (4) "destinations" are "places", and each has Test, Change, Remove and *What it holds*; Add a place sits under the list, not in Settings (I-2983); (5) "storage used" is not recorded, so a place's *What it holds* measures it on request (I-2986); (6) the checks are the newest of each kind at each place, newest first, a sentence each (I-2984); (7) Check my recovery key tells which places a key opens and its label, not a length test; Add another key, Replace beside each label and Add my key to new places are new (I-2985); (8) Replace shows the new key on the key page, once, with *Print this key* and no *Copy*, then asks for two groups back, and the old key keeps working until they are right, where v9's dialog said "I’ve kept it safe" (I-2975); (9) v9 draws no setup, so the setup page, the SFTP place's second step and the key page are new, and a person who wrote a key down and closed the tab can finish from the screen (I-2976); (10) the permission is `backups.manage`, administrators only, not `settings.manage` or `tools.backups`, and the password is asked first where a secret will be typed (I-2974); (11) after a restore the lead is the pause, red, with its two answers, "This server is now the site" and "This is a copy: remove these backup places" (I-2990); (12) *Back up now* is left for later (I-1714), and a site with no backup yet is told to run `php artisan teme:backup:run`; (13) the hub entry is first in Tools, with the `archive` icon where v9 has `box` (I-2989); (14) the banner and the alert email both lead here with "See the backups" |
| Settings › Discussion and the comments switch (study S §3.10, I-077) | 1.8 | Drawn as a first draft on 2026-09-28. **Decided 2026-09-28:** the switch stays at the top of this page (I-485), and while comments are off, readers see nothing of them (I-484). **Decided 2026-09-30:** the switch is on by default, for new and existing sites (I-574); a type's new items start Open or Closed, never Hidden (I-572); the hold and block lists match the text, name, email address, website, IP address and browser, each line anywhere, ignoring case (I-573). v9 draws all three. Settle:<br>• **A setting from another group.** The switch is stored in study H's `modules` group, behind `settings.modules.manage`, but shown on this page, and switching it off hides the rest of the page.<br>• **The details:** the ranges drawn (close after 0–3650 days, depth 1–10, 1–200 a page, 0–100 links, IP kept 1–365 days); how the settings are grouped. *Adopted 2026-10-01 and confirmed the same day*: administrators hold both permissions, and the switch is the page's first section, read-only without `settings.modules.manage` (I-685); the ranges and fieldsets as drawn (I-686). Also settled for 1.8d: the two emails' words (I-689, I-690) and where the personal opt-outs live (I-691). **Built:** 1.8d, as E1 and E2 drew it; the opt-outs as Account › Preferences' "Notifications" fieldset (E7) until 1.11's Notifications page |
| Tools › Redirects and the 404 log (I-297, I-298, I-287) | 1.9 | Drawn as a first draft on 2026-09-28. Settle:<br>• **(Open) Who manages redirects.** v9 gives `tools.redirects` to editors. Only administrators may point a redirect at another site (study T §3.8); v9 lets anyone else switch such a redirect off or delete it, but not save it. *Decided 2026-10-01 (I-694):* editors and administrators, pointing anywhere; a redirect that leaves the site is marked, and every change recorded.<br>• **Where the 404 log lives.** v9 makes it Redirects' second view, and keeps a row once a redirect is made from it, marked "Redirected".<br>• **Loops and chains with patterns.** v9 follows a target with a sample value. A strict overlap check would refuse most one-segment redirects, since `/[slug]` reaches every one-segment address. v9 refuses chains both ways, and counts switched-off redirects too.<br>• **The rest:** a From address that a live page answers (v9 only hints); a redirect to an unpublished or trashed item (not drawn); the item picker, a plain select that won't scale; the CSV format, and nginx export leaving switched-off redirects out; the status labels<br>**Decided 2026-10-07 ("all recommendations"):** the 404 log is a tab beside Redirects, a row once redirected stays, marked "Redirected", and each row has "Mark as gone", a 410 in one click (I-871); Tools › Redirects as drawn, with the CSV and nginx exports as set, except that the item picker is a search box (I-872); a file in the media library may be a target (I-860).<br>**Built:** 1.9h, Part 2, under the Tools hub (below), with the design pass's differences from the drawing: (1) the list shows every state in 1.9c's words, not only On and Off (I-857, I-858), and a redirect to an item deleted for good says "Its item was deleted"; (2) editors may send readers to another site too, so v9's refusal and its hint "Only an administrator can send readers to another site" go, and the list marks such a target "Another site" (I-694); (3) the item select is a search box, each match showing its type and "not published" when it isn't, and a third choice, "A file in the media library", opens the media modal (I-872, I-860); (4) a row's three links are the admin's Actions menu; (5) "Last hit" and the log's "Last seen" are the date and time on the site's clock; (6) the hint reads "Hits are counted every minute", and the log's "Counts are written every minute, so the newest take a moment to appear" (I-1054); (7) the list pages Newer and Older, the log forward only, "First page" and "Next page"; (8) a no-match list says "Clear filters"; (9) the toast after switching off or deleting says the redirect sends nobody on, not that the address answers "not found", which is not so when a page or a pattern answers it; (10) exporting shows no toast, and the import dialog says the file may have an `on` column; (11) the drawer gains a description under its title, and a redirect that waits says what for after it is saved; (12) "Mark as gone" beside "Create redirect" on every row a redirect doesn't answer; (13) the log’s link "Change the log’s size and what it ignores" goes to Search and social’s section for whoever holds `settings.seo.manage`, where the log’s size and ignore list are settings (I-874, I-970). **Not built:** the command palette's "Add a redirect" and "Clear the page cache" (I-951). The offer after trashing (I-856) is in the trash toast of one item, for whoever holds `redirects.manage`, with "Add a redirect for its address" opening the drawer (I-1151) |
| Settings › Search and social (study T §3.14) | 1.9 | Drawn as a first draft on 2026-09-28. Settle:<br>• **Tokens.** None stands for the search phrase, so v9's search results title reads "Search results {sep} {page} {sep} {site}". What `{title}` means on archives, and `{term}` on a single post (v9: the primary category). `{page}` relies on repeated separators being collapsed, where Yoast's carries its own.<br>• **Rows:** one for all taxonomies; whether the home page may be hidden.<br>• **Keys:** v9 names the group `seo`, the built fields' feature name (`BuiltInFields`), with `settings.seo.manage`; `blog_public` imports into its "Discourage search engines" (study H's note).<br>• **The rest:** the "Nobody in particular" choice v9 adds to who the site represents, and whether the logo defaults to General's; no image when the default social image is empty; ranges not decided (1–100 feed items, 1,000–100,000 rows in the 404 log); the robots.txt lines accepted (User-agent, Allow, Disallow, Sitemap, `#`) and whether to keep its preview; the list of AI crawlers (five examples; *settled 2026-10-01 by I-707:* Cloudflare's eight training crawlers, training only); no Home warning while search engines are discouraged, and no notice on non-production copies<br>**Decided 2026-10-07 ("all recommendations"):** one title-and-hide row for each taxonomy, made from the registry (I-853); no hide box on the home page's row (I-854); two warnings while search engines are kept away, one on Home for administrators and one on the screen when the copy isn't the live site (I-873); the numbers and the layout as drawn, with "Who publishes the site" a section of this page (I-874); and, while the site is discouraged, robots.txt unchanged but for its `Sitemap:` line (I-883).<br>**Built:** 1.9i, the screen at the group's own address (`docs/architecture/settings.md`, *Search and social*), keeping v9's order and layout with these differences: (1) one row for each public taxonomy, not "Category and tag archives" (I-853); (2) no hide box on Home's row, and a note in its place (I-854); (3) no hide box on search results, which are always hidden (I-1034); (4) `{search}` listed, and every default built on `{title}`, the page's own heading (I-851, I-852); (5) a hide box labelled with the setting's whole label, "Hide posts from search engines", so what is seen is what a screen reader reads; (6) the home row also shows its second page, "Page 2 shows as: …" (I-904); (7) the notice reads "Search engines are asked to stay away from the whole site."; (8) "Who publishes the site" is drawn by 1.9j, below; (9) the default social image uses the featured image's control, a preview with *Replace* and *Remove*; (10) a verification error is one message under the list that names the row, the names core writes are refused, content holding a `<`, as a pasted whole tag does, is refused and a name may appear once; (11) robots.txt stays as it is while discouraged and a line under its preview says why (I-883); (12) the AI switch names Teme's ten crawlers, and the preview shows the `Content-Signal` line; (13) both warnings; (14) an empty title row is saved and reads as its default, its input showing the default; (15) "Open the 404 log" for `redirects.manage` holders, since Tools › Redirects is built (I-970); (16) number errors are the generic form's own words, as Settings › Discussion shows them, not v9's "Enter a whole number from 1,000 to 100,000." (I-971)<br>**Built, Who publishes the site:** 1.9j, in two parts (`docs/superpowers/plans/2026-10-08-phase1-slice09j-who-publishes.md`, D1 to D20 are I-1173 to I-1192), drawn by the design pass of 2026-10-08, since v9 drew one select, a name and a logo before study Z and the answers that followed. The "Nobody in particular" question is settled: it is the default (study T §3.5), and the logo has no default, because Settings › General has no site logo yet; this one will default to it when it does (I-875, I-228). Differences from v9: (1) fourteen choices in two groups, not three: "Nobody in particular"; "An organization" (Organization, News media organization, Corporation, NGO, Educational organization, Government organization); "A local business" (Local business, Store, Restaurant, Legal service, Accounting service, Medical business); and "A person" (I-876, I-889); (2) nobody in particular until set, where v9's demo says an organization; (3) Name, with the site's title as its placeholder, Other name, Logo with its size, and Profile links (I-875); (4) a Person chosen from the people with an account, and a note when their account is gone (I-878); (5) "The business", a fieldset of its own: the address in five fields, phone, latitude and longitude side by side, price range, and the weekly table of opening hours (I-708, I-877); (6) "Owned by", a fieldset of its own: the owner's name, website and profile links (I-725); (7) the hint reads "Search engines are told it publishes the site and every article on it.", since the WebSite names the publisher too; (8) each field is drawn only for the kinds it describes, and one a kind hides keeps its value (I-874); (9) "Page and article types" is a section of its own after it, on the generic controls (I-888); (10) a banner on every admin screen of whoever holds `settings.seo.manage` while the person the site represents has no account, in I-221's banner slot until the Status page exists (I-878, I-157). The weekly table is a row a day with *Opens* and *Closes*, a *Closed* box and a second opening a day, and passes axe in both schemes with every target 24 pixels or more; at 375 pixels nothing scrolls sideways and axe passes there too. How it is built is in `docs/architecture/settings.md` and `docs/architecture/themes.md` |
| Settings › Site code (I-288) | 1.9 | Drawn as a first draft on 2026-09-28. Settle:<br>• **Where it lives** (v9: a settings page of its own) and the permission's key (v9: `site-code.manage`, administrators only).<br>• **Saving:** whether it asks for the password again, and whether `on*` attributes are stripped, as they are from plugins' fragments.<br>• **The record:** v9 lists each change with its before and after on the screen and in Activity, for administrators, until the security log viewer (6.4).<br>• **The host's kill switch:** v9 keeps the entries visible, read-only.<br>Settled already: a change purges every stored page, since every page carries the slots (1.7b's `*`)<br>**Decided 2026-10-07 ("all recommendations"):** a page of its own under Settings, as drawn (I-863); saving asks for the password again (I-864); the code is kept exactly as written, `on*` attributes included, with a note when it holds structured data (I-865); each change is listed on the screen with its before and after until the log viewer, and the entries stay visible and read-only while the host switches site code off (I-866).<br>**Built:** 1.9e, as drawn, with these differences: (1) the address is `/admin/settings/site-code`, not `settings/code`; (2) no hub card, because the admin has no Settings hub page: Site code is the last tab, and the card's description is the heading's; (3) the notice adds the password, "Saving may ask for your password, and every change is logged, with what it was before"; (4) the placements are "Start of the body" and "End of the body", described as "At the start of `<body>`, on every public page." and "At the end of `<body>`, on every public page.", not the drawing's "straight after `<body>` opens", since the skip link comes first and core's scripts last; (5) a note on an entry that holds a JSON-LD script; (6) the Changes panel says the security log keeps each change for a year, not "every one"; (7) no read-only-in-production mode and no Tools › Activity entry, which aren't built, and no command palette action (I-930); (8) "Nothing changed." when a save changes nothing; (9) four server-side errors the drawing doesn't show, for a name over 120 characters, code over 65,535, code a page can't carry, and an entry deleted while it was open; (10) a deleted person is "a removed account"; (11) the drawer is the shared sheet and the delete confirmation the shared confirmation dialog. Tested in a real browser with axe in both schemes, the password asked, and the host's lock (`docs/architecture/security.md`, *Site code*) |
| The editor's Search and social section (I-147, I-230, I-286, I-290 to I-295) | 1.9 | Drawn as a first draft on 2026-09-28. Settle:<br>• **(Open) Who sets "Hide from search engines" and the canonical.** v9 lets anyone who can edit the item, contributors included. *Decided 2026-10-01 (I-693):* as drawn, anyone who can edit the item.<br>• **Primary category:** v9 puts it in the rail's Categories box once two or more are ticked, with the breadcrumb it gives. Does it also drive the address's `{category}` token? v9's addresses still use the first category. *Decided 2026-10-07 (I-869):* it also sets the category in the address and the section label above the title; built in 1.9g, with the field and its rule (`docs/architecture/addresses.md`), and the box that sets it in 1.9k (below).<br>• **Core's checks:** in the section and in the pre-publish dialog, beside the rail's Checks item (group 5 below).<br>• **The rest:** "Canonical address" as the label, and a hidden item keeping its canonical but printing none; widths judged in pixels (580 and 920, outside estimates, not decisions); the social fields' limits (200 and 320, the SEO fields')<br>**Decided 2026-10-08 (I-840):** a confirmation before an item's first publication only; updating a live item and approving stay one click.<br>**Built:** 1.9k, in two parts (`docs/superpowers/plans/2026-10-08-phase1-slice09k-editor-search-and-social.md`, D1 to D17 are I-1193 to I-1209), drawn by the design pass of 2026-10-08. **The section** is at the foot of the writing column, full width, with its fields beside the previews when the column is at least 672 pixels wide and one column below (I-1203). It sets the SEO title and description with counts in characters and in about the pixels results show, of 580 and 920 (measured in the browser; past the width the count says "Results will cut it." and the preview cuts the text, and nothing refuses a long title; I-1197); *Hide from search engines*; the canonical as this item's own address, another item found by title (any item but this one that isn't in the trash, kept by its ID) or an address on another site (I-1198); and the social title, description and image (I-1199). The browser checks an outside address by the server's rule and sends it only once it passes, since the server refuses the whole autosave over a bad canonical; a chosen item that is in the trash or gone is let go when the editor opens, with a note. The search result and the social card are made by the page's own rules and held to the page by a test (`docs/architecture/themes.md`, *The head*; I-1195). The server gives the editor a `seo` prop (`EditorSearchAndSocial`): the title row, the tokens the screen can't know, whether the site or the type keeps the item out of search, the host the canonical is compared with, the default social image, what the type says its items are, whether its address follows the primary category, and whether the theme draws a trail. **Core's checks are one list**, `coreChecks()`: Title, Description, Title in results, Excerpt, Featured image, Alt text, Internal links, Links, Search engines and Structured data. The section shows its six search checks; the rail's Checks and the confirmation show them all (Excerpt and Featured image only for a type that has them), each a label and a fact, never a score, and a warning blocks nothing (I-880, I-1200, I-1201). That settles the Checks item of group 5 below. **The Advanced fold** is closed at the section's foot, its trigger naming what is set: Page type, Article type (from the type's own main entities), *Don’t show a snippet*, *Snippet length* and *Don’t keep a cached copy*, each with the registry's description; the empty choice names the type's default, "Default for its type (Blog post)", which is the site's choice for the type (I-1204, I-879). **The primary category** is a select in the Categories box of the type's first hierarchical taxonomy once two or more of its terms are ticked, its empty choice "First ticked: {name}"; its hint says what the choice changes: the section label above the title and in lists, the category in the address where the pattern uses `{category}`, the trail where the active theme draws one, and, on a published item whose address follows it, that updating moves the address and the old one leads to the new. A stored choice that is no longer ticked is kept and ignored (I-1205, I-881). **The structured-data check** reads what the gate would leave out of the item's page, one sentence a node ("Recipe markup left out: no image."), from `GET /admin/content/{content}/structured-data?revision=…` (`content.structured-data`, `EditorStructuredData`), for whoever may edit the item; a draft with no address is checked at the address it would take, asked when the editor opens, after each new working revision and before the confirmation (I-1206, I-703). **The confirmation** (I-1208, I-840) opens for Publish, Schedule and Submit for review on an item that isn't live: the editor saves first, asks the structured-data check, and shows "Publish this post?" with the whole list worded for publishing, and the button publishes, schedules or submits exactly the revision checked. Cancel leaves a plain save. Update, Schedule update, Submit update and Approve go at once; Approve decides on the revision that was submitted, never on what is on the screen. Anyone who may edit an item sets the section's fields, a contributor's saved with their draft (I-693); no Operation, permission or migration is added (I-1207). Differences from v9: (1) the canonical's item is a search box with each match's type and "not published", not a select of every post, which would not scale (I-872); (2) its errors are the server's words; (3) a result that won't be listed is not faded, which fails WCAG 1.4.3, and a note above it says why; (4) the first body image is an image card at the body's top level, as the page reads it, not a gallery's; (5) the social image is a small thumbnail with *Replace* and *Remove*, not a "Media #13" chip; (6) Search and social hiding a whole type is said in the note and the checks (I-853); (7) the confirmation is for a first publication only, and its Categories row is not in the shared list; (8) the primary category's hint names what it changes, not v9's trail "Home › News › Local", which the default theme does not draw (I-870); (9) the Advanced fold, which v9 doesn't draw; (10) the SEO plugin's score panel is not drawn: a `// Later (I-286)` line in the section marks where phase 5 draws it. Tested in a real browser with axe in both schemes and 24-pixel targets. That was the editor's first axe run, and it found two faults outside the section: no level-one heading, now a screen-reader-only `h1`, "Edit: {title}", and, in the dark scheme only, the sidebar's group labels read mid-fade while the editor folds the sidebar, which the test now waits out |
| Account › Notifications and the inbox (I-221, study P) | 1.11, with the inbox | v9 draws more than is decided. Decided: a writer who submitted work is told in the admin when it's published, scheduled or returned (study L decision 5, I-181), which 1.5 built only for returns, as Home's "Returned to you"; the inbox for background results (I-221); the comment emails, each with a personal opt-out (I-271, I-483). **Drawn, not decided:** "Someone comments on my post" as an inbox item, and "Also email me: never, a daily digest or each notification", where review emails are parked (I-184). Writers are told more than WordPress tells them (2026-09-28): WordPress emails nothing when a post goes live |
| The Discussion row in the Publish panel (I-274) | 1.8 | Drawn as a first draft on 2026-09-28. **Decided 2026-09-30 (I-571):** only whoever may publish the item changes it, so contributors see it read-only, as v9 now draws it. Settle: a popover of described choices (as Gutenberg does) rather than a select; whether Quick edit and Bulk edit set it, as WordPress's do. *Adopted 2026-10-01 and confirmed the same day*: a select with the meaning under it, as drawn (I-687); Quick edit and Bulk edit set it (I-688). **Built:** 1.8d′, as E3 and E4 drew it: the row after Visibility, saved at once, with when new comments stop; Quick edit's select, disabled for someone who may not publish; Bulk edit's select offered only with the type's publish ability (D8), its lines "discussion set to Closed" and "discussion already Closed" (I-829) |
| The Comments screen (I-224, study P §10.6, study S) | 1.8 | Drawn as a first draft on 2026-09-28. **Decided 2026-09-28 (I-483):** whoever may publish an item looks after its comments: editors and administrators everywhere, authors on their own items, never contributors. Their own comments there skip approval, and the hold and block lists with it. Authors also get the "comment waits" email for their items. v9 draws it (2026-09-28): an author's list, counts, actions and Home figure cover their own items only; Empty spam stays with editors and administrators; a Discussion setting says who skips the checks. Settle:<br>• **What authors see of a commenter:** *decided 2026-09-28 (I-270, option B):* the name and email; IP addresses and browsers stay with editors and administrators. WordPress shows the email and IP address to anyone who can edit the comment (`column_author()`). v9 draws it: no address, no browser and no search by address for authors.<br>• **What an author's list shows.** WordPress lists every comment an author can read, with actions only on their own items' comments; v9 lists their own items' comments alone. *Adopted 2026-10-01 and confirmed the same day* (I-641): their own items' comments alone, as drawn.<br>• **The permission's shape:** *decided 2026-09-30 (I-570, option A):* one permission, `comments.moderate`, for editors and administrators on every item, and the own-items right derived from publishing, as I-483 words it; whoever looks after an item's comments is approved at once there. v9's `comments.moderateOwn` and `comments.skipApproval` are gone.<br>• **Words that read as site-wide:** the `comments.moderate` label (say "on every post and page"), Home's "Comments waiting" beside site-wide figures, and an author's "Email me when a comment waits for approval" (say "on my posts and pages"). *Adopted 2026-10-01 and confirmed the same day* (I-642), with the words given there.<br>• **What authors may do on their items:** *settled by I-570 (1.8a builds it):* everything a moderator may do there, as in WordPress, including deleting permanently and moderating an editor's comment.<br>• **Quick edit** edits plain text, as readers write it, but a stored comment may hold bold, quotes or code (I-267), as an imported one can; saving flattens them. *Adopted 2026-10-01 and confirmed the same day* (I-643): the document is replaced only when the text changes, and a comment that isn't plain says so first; a rich editor is parked (I-644).<br>• **Emptying:** there's no Empty trash; do trashed comments follow "Keep trash for"? Empty spam clears the whole site's spam, even when the list is narrowed to one item. *Adopted 2026-10-01 and confirmed the same day* (I-645): trashed comments follow "Keep trash for"; Empty spam and Empty trash, as WordPress has both, delete what the list shows as it was when opened, authors' included on their own items.<br>• **The rest:** the delete-forever term (parity sweep item 31); whether staff replies record an IP; how the details panel opens; how imported pings show (I-277). *Adopted 2026-10-01 and confirmed the same day*: "Delete permanently" (I-646); no IP or browser for replies written in the admin (I-647); a drawer, also opened from an address (I-648); a Pingback or Trackback chip, with no Reply or Quick edit (I-649). Also settled: the public thread's Edit link (I-651) and the totals per view (I-652).<br>• **Built:** 1.8c built the screen as drawn, with the views and their counts, the search, the list narrowed to an item, moderation with Undo alone and in bulk, Delete permanently, the details drawer and its address, and the menu's badge, Home's figure and the Comments column (`docs/architecture/comments.md`, *The Comments screen*); each row's actions are the built lists' Actions menu, since small always-shown buttons fail WCAG 2.2's target size (D26). 1.8c′ built Quick edit, the inline reply, Empty spam and Empty trash, the trash purge and the keys; the public thread's Edit link is 1.8c″'s |
| The comment thread and form on the public site (study S §3.4 to §3.8, I-276), in the theme's prototype | 1.8b | **Drawn in `theme-v1` on 2026-09-30** (I-468), and checked. **Built in 1.8b, with no JavaScript, on 2026-09-30; core's script in 1.8b′.** axe passed in both schemes and at 375px, once the name and time links were raised to 24px targets (WCAG 2.2, 2.5.8). THEME draws the thread with no JavaScript: initials, three indent steps and then "In reply to", tombstones, one-line pings, pages at `…/comments/page/N`, and the empty and closed lines. CORE draws the form, its honeypot, the privacy line and the answer pages' words. Core's small script adds your saved details, the signed-in name, your waiting comments, the one moving reply form, and the posted result, announced in a status region. **Decided 2026-09-30:** spam reads to its writer as held (I-592); one uncached answer page per outcome, the reader's text kept (I-593); the one form moves under the comment answered (I-594); three indent steps on phones, then "In reply to" (I-595); "Ask guests for their website" is a site setting, on as in WordPress, never a theme's switch (I-596); the story's own author is marked "Author" (I-597). The prototypes draw all six. Settle:<br>• **For planning:**<br>– WordPress's `comment-page-N` addresses, redirected only when WordPress's paging came across. → 1.11 (1.8b's D12).<br>– A browser that sends no Fetch Metadata: refuse a foreign `Origin` from a browser without the sign-in marker; core's script fills `_token` when signed in. → `GuardCommentPost` (1.8b's D7).<br>– Routes: `/_teme/session` gains the commenter's name; `/_teme/comment/{id}/reply` is new, for the reply page without JavaScript; `/_teme/comments/pending` returns theme-drawn HTML, including the writer's own comments approved since the page was stored. → the reply page in 1.8b (Task 8); the others 1.8b′'s.<br>– The form: the `data-teme-*` hooks, the contract between theme and script; a honeypot name browsers won't fill in; field errors in the drawn words. → 1.8b's Tasks 7 and 9, the honeypot `subject` (D8), the words (D14).<br>– Also open: numbered pages need a count of top-level comments; whether the count includes pings; a reply at the depth limit loses which comment it answered; a post after comments were switched off keeps the reader's text; "Signed-in readers only" isn't drawn. → 1.8b's D2, D4, D11, Task 7's 422 page, D16.<br>• **The documents disagree:**<br>– `comments.md:143` fetches `pending?content={id}`, but `page-cache.md:350` says a page never carries an item's ID. → by path (1.8b′); study S amended.<br>– `comments.md:152` says the writer sees their own approved comment, but `:143` fetches pending ones only. → 1.8b′'s endpoint returns both; study S amended.<br>– `comments.md:145` says the cached form needs no token, but `page-cache.md:352` says a browser without Fetch Metadata does. → D7; study S amended.<br>– `comments.md:182` shows the consent box always, but saving details needs the script. → drawn `hidden`, shown by 1.8b′; study S amended.<br>Settled by the pass: readers never see "item" in a refusal ("This page takes no comments.", "Comments here are closed."), and the rate limit's wait is plural-aware; both changed in the 1.8a plan before its Task 7 |

## What v9 doesn't show yet (the sweep of 2026-09-27)

A read-only sweep of the adopted ideas, the studies, the roadmap and the spec against v9 found about fifty gaps. Each group was checked against v9's source and the code. v9 was drawn on 2026-09-22 and hasn't followed the slices since, so much of what's missing is already built.

**1. Built in the admin, missing from v9, or drawn the way that wasn't chosen.**
- Content › Navigation: menus, the tree editor and slot binding (1.6d; spec §5.9).
- Content › Regions, where regions are edited (1.6e; I-395). v9 edits placements on the Themes screen instead.
- Settings › Addresses: presets, tokens, bases, the recompute's progress, and the home and posts page pickers (1.6a; I-393, I-396).
- The editor:
  - the Template select (1.6b, I-443);
  - the Share panel's signed preview links (1.5d, I-181);
  - the presence line and the stale-save dialog (1.5c, study L);
  - scheduling an update to a live article (1.5e, I-181).
- Review: Approve, and send back with a note (1.5e).
- Trash: Restore and Delete permanently for posts and pages (1.3). Media trash with Restore (1.4, I-166 5B), where v9 still refuses deletion while a file is in use, option A.
- The media drawer: focal point, Replace file, Download original and Retry (1.4, I-166).
- People: the Contributor role (spec §6), and changing a person's role.
- Settings:
  - the Security group (1.2);
  - per-group permissions, `settings.{group}.manage` (1.2), where v9 has one `settings.manage`;
  - trash retention days (1.3);
  - media limits as settings (1.4, study K §7), where v9 shows them fixed by the server.
- Home: Quick draft (1.5g, I-240).
- Preferences: the three looks and high contrast (1.5f, I-198), which v9 keeps in its demo controls.

*Batch 1 drawn in v9 on 2026-09-27:*
- **The screens:** Navigation, and Regions with the Themes screen's overview; Addresses, and Reading's home and posts page pickers.
- **The settings:** trash days, the media limits, sign-in rules, and per-group permissions.
- **People:** the Contributor role, Change role…, Quick draft, and the looks in Preferences.
- **Corrected to what's decided:** authors publish their own work, as the build and WordPress have them, and the demo opens in Dark chrome, the default look. Where the build differs from v9, the parity sweep list has items 15 to 22.
- **Decided (I-482), superseded by I-3313 in slice 2.1f:** in phase 1, authors and contributors created pages too, since one permission covered every content type. Pages now have permissions of their own and are written by editors and administrators alone, as in WordPress. v9 still draws the old rule.
- **Batch 2 is next:** the editor's Template select, preview links, the presence line and stale-save dialog, scheduling an update, and review; Trash's restore and delete; the media trash and the drawer's tools.

*Batch 2 drawn in v9 on 2026-09-28, which completes this group:*
- **The editor:** the Template select; Share, with preview links; the presence line and the stale-save dialog, which the demo controls show on demand; scheduling an update to a live article; and review (Approve, "Approve and schedule…", and Return with a note, which the writer sees). Home gains "Returned to you".
- **Trash:** posts and pages restore and delete for good, one at a time or in bulk.
- **Media:** a Trash tab, with trashing naming the live pages that use a file first. This replaces v9's refusal while a file is in use, option A, which I-166 rejected.
- **The media drawer:** the focal point by click or the arrow keys, Replace…, Download original, and Retry processing.
- **Pages:** authors and contributors wrote pages (I-482); since 2.1f only editors and administrators do (I-3313).
- **Where the build differs:** the parity sweep list has items 23 to 31.
- **Bugs the drawing found in the build:** #45 (a menu can't be given a Home link), #46 (media Delete permanently has no confirmation), #47 (the focal point can't be set from the keyboard), #48 (Share never says who made a link), #49 ("Compare with the published version" for an article never published), #50 (the stale-save dialog calls every item an article).

**2. Decided for slices still ahead.**
- 1.8, Comments:
  - Settings › Discussion and the module switch (study S §3.10, I-077);
  - the Publish panel's Discussion row (I-274);
  - on the Comments screen: Quick Edit, Empty Spam, bulk moderation, the spam provider's signals, and tombstones (study P §10.6, study S).
  - *Drawn in v9 on 2026-09-28 (batch 3), as a first draft.* It adds a Comments column on Posts and Pages, and switching comments off hides everything about them. The points it leaves open are in the design-pass table above. The decisions of the same day (I-483 to I-485) were drawn in after batch 4.
- 1.9, SEO:
  - Tools › Redirects in core (study T §3.8, I-078), where v9 shows a demo plugin;
  - Settings › Search and social, about 30 settings (study T §3.14), where v9 shows a demo plugin's group, with feed options there rather than in Reading;
  - the editor's noindex, canonical, social title and description, and primary category (I-147, I-292 to I-295);
  - the 404 log with Create redirect (I-298);
  - the site code slots (study T, decision 3).
  - *Drawn in v9 on 2026-09-28 (batch 4), as a first draft.* The example SEO plugin keeps only its scoring (I-286), and Classic Redirects' old page no longer collides with core's. The points it leaves open are in the design-pass table above.
- 1.10, AI:
  - Tools › AI activity and the `ai.use` permission (study U §3.5, I-312);
  - Suggest metadata, Suggest alt text, and the rewrite presets (I-321);
  - Settings › AI per task, with limits and a budget (I-315, I-320).
- 1.11, Import:
  - the import's steps and report (study V §4, I-343, I-345), stubbed in v9;
  - the Audio and Video cards (I-446);
  - "Select all *n* matching" as a job (I-143);
  - the Import images prompt and the Imported content card (I-453, I-329);
  - import and export beside each list (I-227);
  - password-protected visibility (I-342);
  - "Uploaded for" (I-553): the editor's media picker filter "Uploaded for this post", a library filter, and a line in the media drawer beside "Used in"; built in 1.11q.

**3. Shown in v9 but not decided.** *Taken out of v9 on 2026-09-27; the permissions screen now shows each role's permissions without editing them.*
- Pages' Parent column and picker (study Y §4.1; I-239 is parked).
- Saving a role's permissions: roles can't be edited yet (study F §7, I-449).
- Per-role default menus (I-217, left for later).
- The palette's site-wide questions for the assistant (study U §3.3: phase 1 has three AI tasks).

**4. Later phases, drawn when each is planned.** Their decisions don't yet say which screen they live on:
- the content-type builder (2.1);
- the security log viewer and the admin IP allow-list (6.4);
- the template inspector (4.2);
- scoped API tokens (3.2), where v9 says a token acts as you;
- WXR export (6.5), the Fediverse switch (phase 3), the update channel (6.2);
- plugin states such as quarantined (phase 5).

**5. Small.**
- The editor rail's Checks section, which this document places differently from v9. *Settled by slice 1.9k (I-880, I-1200):* the rail's Checks, the Search and social section's search checks and the confirmation before a first publication read one list, `coreChecks()`, each check a label and a fact, as v9 draws the section's.
- List row states: Missed schedule, and who's editing (study N §6).
- The Themes screen's shape, which is already sweep item 11.

## Two traps worth knowing

Both were found while building the prototype, and both will recur in the real code.

**A grid track without `minmax(0, 1fr)` is stretched by any oversized child.** One wide image card in the editor silently widened every field in the column from 700px to 770px and made the whole editor scroll sideways. It happened at two nesting levels. Any grid that contains content of unknown width needs `grid-template-columns: minmax(0, 1fr)`.

**A toolbar set to `overflow-x: auto` hides controls.** It looks fine in a wide window and quietly truncates in a narrow one. Wrap instead.
