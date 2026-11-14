# The remaining admin screens on the new design system

| | |
|---|---|
| Status | accepted |
| Created | 2026-11-14 |
| Authors | TemeCMS maintainers |
| Issue | none yet |
| Plan | docs/plans/remaining-screens.md |
| Release | — |
| Replaces | — |

## The problem

Six screens are not covered by the other parts: Themes, Types, Taxonomies, Navigation, Regions and the Inbox. They work, but each keeps its own header, spacing, buttons and dialogs from before the design system. The responsive pass also found small faults on them: counts that link to lists are too small to tap, the "+N" chip on Regions is 32px wide, the Inbox's item buttons are 23px tall, and on Navigation each item's move buttons wrap under its field on a phone.

This proposal moves all six onto the page patterns and fixes those faults. It adds no new features.

## What we will build

**Themes.** A page with the active theme first, then the others, as cards. Each card offers Preview, Activate and a link to the theme's settings. While previewing, the page shows the preview bar with "Stop previewing". Design changes waiting to go live show a bar with Publish and Discard, and Discard asks first. Links go to Regions, Navigation and Settings where a theme uses them. On a phone the cards are one column.

**Types.** A list of every content type, marked Code or Made here, with its item count. A type opens as a form page with sections: Names, In the admin, On the site, In the editor, Fields, Permissions and, for a type made here, Delete this type. A code-declared type is shown locked, with the reason. A type's Fields page is a sortable list with a "From Teme" group, "Removed fields" with Restore, and a sheet to add or change a field. Counts that open a list are made 44px to tap.

**Taxonomies.** The same shape as Types: a list, then a form page with Names, Terms, In the admin, On the site and Delete. "Used by" names the types.

**Navigation.** A list of menus with item counts and where the theme shows each. A menu's page is a tree of items with "Show in" places, "Add an item" and "Rename the menu" in sheets. Items move with the keyboard (Alt and the arrow keys) and with buttons. On a phone the move buttons go into each item's "…" menu so the row stays on one line.

**Regions.** A table of the active theme's regions with the cards each may hold (chips with a "+N" overflow, text only where none), then regions kept from themes not in use. On a phone the rows are cards.

**Inbox.** The results of background work, read and unread, kept 90 days. Each item has a status mark, its title and "Mark as read"; the header has "{n} unread" and "Mark all as read". Item buttons are 44px on touch.

## The decisions

1. **Restyle, do not redesign.** Each screen keeps its behaviour and its server code. Alternative: redraw them to match the demo exactly, which would add features (see "Not in"). We take the patterns and leave the rest.
2. **Lists use the shared table and card layout** from the content-lists part, so Types, Taxonomies, Regions and Navigation lists declare their card facts instead of drawing their own phone layout. If that part is late, they keep their current tables with the new tokens and move over afterwards.
3. **Forms use `FormPage`** with one save bar. Types and Taxonomies have the largest forms, so they keep their section anchors and the list of sections beside them on wide screens.
4. **Moves inside the item's menu on a phone.** Alternative: a smaller button row. The menu keeps targets at 44px and the row on one line.

## In, and not in

**In:** the six screens on their patterns; the touch-target and layout faults listed above; the phone cards and sheets; the Inbox's controls.

**Not in** (drawn in the demo, not done by the product yet):
- **Install a theme:** the demo draws a disabled button marked Soon. Themes are Composer packages, and the install flow is another part of the product.
- **A custom type's own list** (the demo's illustrative "Recipes" page): that is the content list from another part, with columns the type declares.
- **Anything the demo shows for plugins** that are not installed.
- Drag handles for the Navigation tree, if only the buttons and keyboard exist today. We do not add dragging.

## How it fits

- **Patterns used:** `ListPage`, `FormPage`, `InPageNav`, `StatusPill`, chips with "+N", `Sheet`, `Dialog`, `EmptyState`, `LoadingState`, `Notice`.
- **Files:** `pages/{themes,types,taxonomies,navigation,regions,inbox}/**` and their components.
- **Server work:** none expected. If a list needs a capped count, it uses the one the content-lists part adds.
- **Tests that change:** the themes, types, taxonomies, navigation, regions and inbox screen tests keep their hooks. Tests that read the old size of a button or chip change. Each screen gets one 375px case for sideways scrolling.
- **Depends on:** the foundation part. The lists depend on the content-lists part for the table and cards, and can start without it as described in decision 2.

## Settled

The questions this proposal raised, as decided:

1. The Types and Taxonomies forms use the row of in-page tabs, with no side list.
2. Themes is two pages: the list, and each theme's own settings.
3. If the content-lists part is late, these screens ship on today's tables first.
4. Navigation keeps buttons and keys; dragging may come later.
