# The content lists on the new design system

| | |
|---|---|
| Status | accepted |
| Created | 2026-11-14 |
| Authors | TemeCMS maintainers |
| Issue | none yet |
| Plan | docs/plans/content-lists.md |
| Release | — |
| Replaces | — |

## The problem

The content lists, categories, tags and comments are the most used screens, and they still wear the old interface. Row actions appear only on hover, so touch and keyboard users find them late. Extra columns squeeze a long title. Paging is Newer and Older, with no idea how far the list goes. Filters are plain lists of buttons, so finding one author among two hundred means scrolling. On a phone the table is a narrow strip.

The design system describes the list these screens need (section 4.26), and the decisions behind it are made. This part builds it.

## What we will build

**One table for every content list** (section 4.26): a panel frame the table scrolls sideways in, a header that stays in view, rows as tall as their title needs (72px for one line, 92px for two), no lines between rows.

- **The title holds its shape.** At least 320px wide, it takes the room that is left and wraps to two lines before an ellipsis, with a thumbnail beside it. No other column wraps.
- **Categories and tags as chips:** the first one or two that fit, then a "+N" button listing the rest.
- **A status column** with the status pill, and a date column with a second line saying which date it is.
- **Wide tables scroll sideways.** The tick box and title stay pinned at the left and the "…" column at the right, as a real last column, so scrolling to the end always shows the date in full.

**Row actions, always visible** (I-3398). Under each title: **Edit, Quick edit, View** (Preview when the item is not live). The "…" menu at the end of the row holds the one state action that applies (**Publish**, **Approve**, or **Revert to draft**), then actions added by plugins, then **Trash**, last and in red. Publishing from the menu answers "Published" with **Undo**, and trashing answers with Undo too. In the Trash view the line holds **Restore** and the menu **Delete permanently**, which asks first.

**Numbered pages with a capped count** (I-3421): "1 2 3 … 50+", and "1 to 50 of 240" where the count is cheap. A site with a million posts never waits for a count.

**The column chooser** replaces Screen options: "Columns" lists every column in order, each with a tick box and Move earlier and Move later buttons. The choice and the order are each person's own.

**The bulk bar** appears above the table when a row is ticked: "3 posts selected", up to three actions, "More" for the rest, and "Clear selection". A ticked page offers "Select all 240 posts".

**Filters that scale.** Author and each category or tag filter is a Combobox you can type into (I-1452), so a long list is found by name. The month filter keeps its popover.

**On a phone, each row is a card** (I-3422): the tick box, thumbnail, title, one line of facts (for posts: status, date and author) and the same actions. A bar above offers Select all and Sort. Ticking pins the bulk bar to the foot; the filters fold into "Filters (n)".

**The same pattern on Categories, Tags and Comments.** Comments keep their moderation keys, inline reply, Quick edit and Details, with Approve or Unapprove on the row's line, because moderating is that screen's main task.

## The decisions

1. **One table, restyled, no table engine** (I-3404). The existing table gains pinned columns, selection and cards as options; screens already using it take the new look with the same props.
2. **The hybrid row actions** (I-3398) replace the hover-only links on these screens. Other screens keep their current links until their own part moves them.
3. **Mixed row heights** (I-3421): a row is as tall as its title needs, and nothing but the title adds lines (I-3399).
4. **Numbered pages, capped** (I-3421). The count stops at the cap, so it costs the same on any site. Past the cap, Next works as today, at the same cost on every page.
5. **Cards under 640px** (I-3422). Each list declares its card's facts.
6. **Filters that pick from many use the Combobox**; short fixed choices stay Selects.
7. **Every current feature stays:** Quick edit, Bulk edit, the views with their counts, search in titles or text, every filter, the month filter, select all matching, rows per page, and the comment keys.
8. **Every new word is in American English** (I-3439) and goes through the translation helper.

## In, and not in

**In:** the table, row actions, pager, column chooser, bulk bar, filters and phone cards for every content type's list, Categories, Tags and Comments; numbered pages on their server queries; Undo after publishing or trashing from a row; every word in these screens through the translation helper.

**Not in:**
- Plugins adding columns, row actions or filters, and the "From plugins" filter. The menu keeps their place in its order, ready for when plugins can add to lists (I-200).
- Dragging columns to reorder them (the buttons do it).
- "Posts ⇅" in the breadcrumb listing recent posts.
- The other lists (People, Media's list view, Redirects, the 404 log, Jobs, Types, Taxonomies, Navigation, Regions, AI activity). They get the restyled table with the props they have today, and move onto the rest of the pattern in their own parts.
- Dialogs, menus and popovers as sheets from the foot on phones: that is its own part, which these screens opt into.
- A Details sheet for posts, and saved views.

## How it fits

- **Components:** the shared list parts are restyled or added in `components/list/`: the table, the row line, the row's "…" menu, the numbered pager, the column chooser, the bulk bar, the filters row, the card layout. The old pager and hover links stay for the screens still using them. No design token and no existing `components/ui/` component changes; the page uses the List page pattern and InPageNav.
- **Server:** the content, comment and term list queries answer by page number with a capped count, keeping today's keyset paging past the cap. Publishing and trashing from a row offer an Undo through the existing routes and operations. No new permission, operation, route or migration.
- **Accessibility:** no hover-only actions; focus never hidden under pinned columns; targets at least 24px (44px on touch); selection counts announced; axe clean in both schemes; nothing clipped at 320px.
- **Tests:** feature tests for page numbers, the cap and the cost of a deep page; browser tests for the row actions, Undo, the pinned columns, the chooser, the bulk bar, the Combobox filters, the phone cards and keyboard-only use. The design-system and admin-words baselines only shrink, to no line for the rebuilt screens.
- **Dependencies:** none new.

## Settled

The questions this proposal raised, as decided:

1. The page-number cap is **50 pages**, so the pager reads "1 2 3 … 50+" (provisional: confirm).
2. Past page 50, the pager shows Previous and Next with "Past page 50" in place of the numbers (provisional: confirm).
3. A **Status column** joins the content lists, on by default, and the status no longer trails the title (provisional: confirm).
4. The **thumbnail sits in the title cell**; the chooser's "Thumbnails" entry turns it on and off (provisional: confirm).
5. **Rows per page** stays a choice of 25, 50 or 100, at the foot of the column chooser (provisional: confirm).
6. When trashing a published item offers a redirect, the toast keeps that offer, and Restore in the Trash view is the way back; otherwise it offers Undo (provisional: confirm).
7. The toasts keep today's words; only Undo is added (provisional: confirm).
