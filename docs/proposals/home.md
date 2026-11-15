# Home on the new design system, arranged by each person

| | |
|---|---|
| Status | draft |
| Created | 2026-11-15 |
| Authors | TemeCMS maintainers |
| Issue | none yet |
| Plan | docs/plans/home.md |
| Release | — |
| Replaces | — |

## The problem

Home still wears the old interface. Its panels sit in a fixed two-column list, in an order nobody can change, and a panel nobody needs can't be put away. The quick draft and the site's figures trail under the panels in their own styles. When plugins add panels later, a Home that can't be arranged is where the crowding shows first (I-210).

The design system and the reference design (v10's Home, approved with prototype D2 as I-3401) describe the Home this part builds: a grid of panels in sizes, the site's figures as a row of tiles, and a Customize mode where each person moves, resizes, hides and adds panels.

## What we will build

**The page.** A header with a greeting, one line on what Home is, and the actions: **New {post}** (the main type, for whoever may create one), **Upload media** (for whoever may upload), and **Customize**. Under it, the search engines notice and Getting started, as today. Then the site's figures as a row of tiles, each opening its list. Then **Your work**: core's panels in a grid. Then **From plugins**, a labelled section for panels plugins add, each naming its plugin; the section is drawn only when a plugin has a panel.

**Panels in sizes.** Each panel is Small, Medium, Large or Full width (4, 6, 8 or 12 of 12 columns). On a tablet, Small and Medium are half the width and Large is the full width; on a phone, every panel is the full width.

**The panels:** Waiting for your review, Returned to you, Your items in review, Your drafts, Scheduled, You edited recently, and Quick draft. Each row panel shows up to five items with See all, as today. A panel with nothing in it stays out of the way, as today (I-161), except in Customize, where every panel shows so it can be arranged.

**Customize.** A bar across the top says "Customizing your Home. Move, resize or hide panels. Only you see this," with **Add a panel**, **Reset** and **Done**. Each panel then shows its controls: **Move earlier**, **Move later**, a size control (S, M, L, Full), and **Hide**. A panel can also be dragged by its grip to a new place in its section. Every change is saved at once as the person's own. Hiding answers with a toast with **Undo**. **Add a panel** lists the hidden panels, core's first, then each plugin's under its name. **Reset** returns to the site's default.

**A panel that fails stays a panel.** If one panel's query fails, that panel says so with **Try again**, and the rest of Home is drawn as usual.

## The decisions

1. **Per person, with a site default** (I-210, option B). Each person's order, sizes and hidden panels are their preferences. The site default is the order and sizes the panels declare.
2. **Dragging is never the only way** (I-3441). Move earlier and Move later do the same as a drag, from the keyboard, and each move is announced ("Your drafts moved to position 3 of 7").
3. **Drag and drop uses Pragmatic drag and drop** (I-3441): `@atlaskit/pragmatic-drag-and-drop` 4.0.0 and its hitbox add-on 3.0.0, drawn with our own drop line in the design tokens. Atlassian's ready-made drop indicator is not used, since it brings Atlassian's own styling packages.
4. **A panel moves only within its section.** Core's panels stay under Your work and plugins' under From plugins, so a person always knows which is which (I-200).
5. **Empty panels stay hidden outside Customize** (I-161), so a person with nothing waiting sees one line, not empty boxes.
6. **The site status lives in the top bar** (I-3397). v10's Site status panel is not built, since it would repeat the top bar's control.
7. **Every word is in American English** (I-3439), through the translation helper.

## What other systems do

Every system in the reference folder was searched for a dashboard of panels; these have one that can be arranged or sized:

- **WordPress:** each person's panel order and folded panels are saved in their user meta (`meta-box-order_dashboard`, `closedpostboxes_dashboard`); panels are hidden from Screen Options and dragged between columns; since 5.5 each panel also has Move up and Move down buttons for the keyboard. We take: per person, and buttons beside the drag.
- **Craft CMS 5:** widgets are each person's own records, with a sort order and a column span (`changeWidgetColspan`), added from a menu. We take: sizes as a span, Add a panel.
- **October 4 and Winter:** each person's widgets, order and width (1 to 12) are their preferences; Reset returns to the default, and an administrator can "Make default" for everyone, stored as a system parameter. We take: Reset, and the 12-column span; "Make default" is parked (Settled 4).
- **Payload:** the modular dashboard saves each person's layout as a preference, with widths, dragged with dnd-kit and its keyboard sensor. We take: drag with an equal keyboard way.
- **Filament 5:** widgets declare their order (`$sort`) and width (`$columnSpan`) in code, the same for everyone. We take: declared order and size as the site default.
- **Strapi:** Home has widgets added from a dialog. We take nothing further.

## In, and not in

**In:** the new Home page; the panel grid in four sizes; Customize with move, resize, hide, add, reset and drag; the person's arrangement saved as preferences; the figures as tiles; Quick draft as a panel; the From plugins section and a plugin's mark on its panels; a failed panel's own error with Try again; every word on Home through the translation helper.

**Not in:**
- Plugins registering panels. The section and the registry's owner field are ready; the plugin-facing socket stays parked (I-3384, slice 5.3).
- v10's illustrated plugin panels (Visits, Top posts, Subscribers, Form submissions, Internal links, Image matching): they are pictures of what plugins could add.
- A Site status panel (decision 6).
- A site default set from the admin ("Make this the site default"): parked, see Settled 4.
- Panel settings (a period chooser like v10's Visits, rows per panel).
- A "+ Page" button.

## How it fits

- **Server:** `Dashboard::forUser()` sends, beside today's `panels`, the person's arrangement and a list of every panel they may see (its key, name, size, owner and kind), so Customize can draw and add panels that are empty or hidden. Hidden panels' queries are skipped. A panel's query that throws is reported and sent as failed. The registry's panel gains a declared size and a kind (rows or quick draft). No migration, route, permission or operation: the arrangement is three preferences saved through the existing `UpdatePreferences`.
- **Preferences:** `dashboard.order` (panel keys), `dashboard.hidden` (panel keys) and `dashboard.sizes` (`key:size` entries), each a list checked against the registered panels, like the menu's `nav.order` and `nav.hidden`.
- **Components:** `components/dashboard/` is rebuilt: the panel frame with its Customize controls, the grid, the figures row, the Customize bar, Add a panel, the drag hook. It uses `ui/panel`, `ui/segmented`, `ui/dropdown-menu`, `ui/plugin-mark`, `ui/notice`, `ui/tooltip` and the Page patterns. No design token and no existing `components/ui/` file changes.
- **Accessibility:** controls in Customize are real buttons with names ("Move Your drafts earlier"); at the ends of a section they are `aria-disabled`, never `disabled`, so focus stays put; moves are announced in a polite live region; the drag grip is a pointer extra. Targets 24px (44 on touch); axe clean in both schemes at 1280 and 375.
- **Tests:** feature tests for the arrangement, the skipped hidden queries, a failing panel and the preferences' checks; browser tests for Customize by keyboard, by drag, on a phone, and Home's look in both schemes.
- **Dependencies:** `@atlaskit/pragmatic-drag-and-drop` 4.0.0 and `@atlaskit/pragmatic-drag-and-drop-hitbox` 3.0.0 (Apache-2.0), which bring `raf-schd` 4.0.3 and `bind-event-listener` 3.0.0 (MIT); `@babel/runtime` is already installed.

## Settled

Every item below is confirmed (2026-11-15).

1. **The greeting** reads "Good morning, :name" (or afternoon, evening) by the browser's clock, with today's date on the line under it; the tab's title stays "Home" (confirmed by the maintainers, 2026-11-15).
2. **Default sizes:** Waiting for your review, Returned to you, Your items in review and Your drafts Medium; Scheduled, You edited recently and Quick draft Small; plugins' panels Small unless they declare otherwise (confirmed by the maintainers, 2026-11-15).
3. **Nothing is hidden by default.** Empty panels stay out of the way on their own (confirmed by the maintainers, 2026-11-15).
4. **The site default is the panels' declared order and sizes.** An administrator setting a different default from the admin, as October does, is parked until someone asks for it (confirmed by the maintainers, 2026-11-15).
5. **Customize's changes save at once**, with no Save button. Done only leaves Customize (confirmed by the maintainers, 2026-11-15).
6. **Dragging works only in Customize**, by the grip on each panel's header, so a click on a panel's links never starts a drag (confirmed by the maintainers, 2026-11-15).
7. **No Site status panel** and **no "+ Page" button** (confirmed by the maintainers, 2026-11-15).
