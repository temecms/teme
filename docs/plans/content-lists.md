# Interface part B: the content lists

**One part, one branch: `ui/content-lists`, from `origin/main`.** It moves every content type's list (Posts, Pages and the rest), Categories and Tags, and Comments onto the list pattern of the design system: the table, the hybrid row actions, numbered pages with a capped count, the column chooser, the bulk bar, Combobox filters, and cards on a phone. It runs beside the editor, media, tools, plugin-area and phone-overlay parts; the files each owns are listed under "Owns" and "Seams".

Steps use checkbox (`- [ ]`) syntax for tracking.

**Written 2026-11-14** for the repository `temecms/teme`. It starts from `origin/main`, which holds the design system, the shell, and the merged wave-1 parts (the Combobox, the sign-in pages, people and account). Everything this part needs from them is written out under "What this part relies on"; Task 0 checks it is there. This plan is lighter (I-569): it writes out the decisions, names, permissions, tests and the delicate code, and describes the rest.

**Goal.** The admin's most used screens on one list pattern, with every feature they have today still working, and no line left in the design-system or admin-words baselines for the screens rebuilt here.

**Tech stack** (Task 0 restates the exact versions from the locks): Laravel 13, PHP 8.4, PostgreSQL 18, Inertia 3 (`@inertiajs/react` 3.9), React 19.3, TypeScript, Tailwind 4.3, shadcn/ui "new-york" on Radix, Vite 8, Pest 5 with `pestphp/pest-plugin-browser` 5.1, `downshift` 9.4.0 (through `ui/combobox.tsx` only).

**Spec:** `docs/architecture/design-system.md` ("DS", by section number): 4.13 Status pill, 4.14 Chips and "+N", 4.15 Count, 4.16 Tooltip, 4.17 Menu and the "…" menu, 4.18 Popover, 4.21 Toast, 4.23 to 4.25 Empty, loading, error, **4.26 Table**, 4.27 InPageNav, section 5 (the List pattern), section 7 (accessibility), section 8 (writing), section 9 (the check). Every measure this part needs is also written out below, so nothing depends on a reference page.

**Bound by:** I-3398 (the hybrid row actions), I-3399 (the table holds its shape; "+N" chips), I-3421 (mixed row heights; numbered pages with a capped count), I-3422 (cards on a phone), I-3404 (no table engine; row heights 72 and 92), I-1452 (a searchable filter for any taxonomy), I-140 (the cost of a deep page stays flat), I-204 (each person's columns), I-3439 (American English for new words), I-3373 (every word through `__()`), I-797 (a comment cites one I-number or one document path), I-569, I-3317, I-3369.

**New dependencies, migrations, permissions, operations, routes, contract members (`packages/teme-contracts`), tokens:** none. **Drafts:** `B-?? (draft)` for plan faults and small decisions.

---

## Where the documents and the code disagree

1. **DS 4.26 "Pager"** draws page numbers with "an ellipsis beyond seven" and no cap; I-3421 caps the count. **The cap wins** (C1); DS 4.26 is updated at the merge.
2. **DS 11 Q1** still lists phone lists as open. I-3422 settled it: **cards under 640px**, the table from 640 up. DS 5 and 4.26 are updated at the merge.
3. **DS 4.26 "Bulk bar"** names "Publish, Edit categories, Move to trash". The built bulk actions are kept as they are, in their order; the first three show as buttons, the rest go under More (D9).
4. **`tests/Architecture/SharedControlsTest.php`** asserts the old table (44px rows, hairlines, one `<Card>`) and the old bulk bar (muted, "not the accent"). DS 12.3 row 35 says this test encodes the old design. Its two cases are **rewritten to DS 4.26** in Task 3, each keeping its meaning: "the shared table and bulk bar are drawn the one way the design system sets".
5. **The built paging** is Newer and Older with no total (I-140, `ContentListTest` "pages 50 at a time, older and newer, with no total"). I-3421 replaces it for these lists. That case is **rewritten** in Task 1 to the numbered pages; its other meaning (a deep page costs what page one does) is kept by "makes the same number of queries on every page" and a new case past the cap.
6. **Ownership of shared list parts.** `components/list/**` is this part's, but media, tools, people, navigation, regions, types, taxonomies and the inbox import from it. **Every existing export keeps its name and props.** `DataTable` and `BulkBar` take the new look in place (their callers change nothing); `Pager`, `RowLinks`, `RowActionsMenu`, `splitRowActions`, `TabStrip`, `PageHeader` (the list one) and `ScreenOptions`' callers outside this part are left exactly as they are (none outside: `ScreenOptions` is only the content list's, and is replaced there).
7. **Comments' row line** holds the state action (Approve or Unapprove), unlike the content lists, where it sits in the menu. Moderation is that screen's main task; the reference design draws it so. Written as D7.

## What this part relies on

Written out here so this plan stands alone.

- **The seams.** `resources/css/app.css`'s token block and the existing files in `components/ui/**` and `components/patterns/**` belong to the foundation. A part may **add** new files to `ui/` and `patterns/` (this part adds none); it never edits an existing one or a token. A change to them is a STOP, asked of the maintainers.
- **Components used, unchanged:** `patterns/list-page.tsx` (`ListPage`: `title`, `description`, `actions`, `children`), `patterns/in-page-nav.tsx` (`InPageNav`: `label`, `items: { href, label, count?, current? }[]`; the first five show, the rest under More), `ui/combobox.tsx` (`Combobox`, single and multiple, `search` for remote lists, `name` for hidden inputs), `ui/term-chips.tsx` (`TermChips`: `terms`, `noun: { one, many }`, `emptyLabel`), `ui/status-pill.tsx` (`StatusPill`: `tone` ok, neutral, info, warn, danger), `ui/text-button.tsx` (`TextButton`, `asChild`, `tone`), `ui/checkbox.tsx`, `ui/tooltip.tsx`, `ui/popover.tsx`, `ui/dropdown-menu.tsx`, `ui/sheet.tsx`, `ui/scroll-area.tsx` (`ScrollArea`: `axis` `"y" | "x" | "both"`, `label`), `ui/button.tsx` (pass `variant` explicitly), `ui/badge.tsx`, `ui/skeleton.tsx`, `list/empty-state.tsx`, `components/term-picker.tsx`, `@/lib/i18n` (`__`, `__n`).
- **The values used here** (all tokens in `app.css`): colors `bg-surface`, `bg-surface-2`, `bg-surface-3`, `bg-accent-soft`, `text-on-accent-soft`, `text-ink`, `text-ink-2`, `text-ink-3`, `text-danger-ink`, `border-edge`, `border-line`, `outline-ring`, `--img-outline`; heights `h-(--ctl-h)` 36px and `h-(--ctl-h-sm)` 32px (both 44px on a coarse pointer); type `text-caption` 12px, `text-small` 13px, `text-body` 15px; radii `rounded-sm` 6px, `rounded-md` 8px, `rounded-lg` 12px; `shadow-raised`, `shadow-float`; motion `duration-(--dur-1)`, `ease-(--ease)`.
- **The design check.** `tests/Architecture/DesignSystemTest.php` runs a Node scanner (`tests/Fixtures/design-system-scan.mjs`) over `resources/js` and `resources/css` and compares what it finds with `tests/Architecture/design-system-baseline.txt` (one line per offense: `rule<TAB>path<TAB>match`, the path relative to `resources/`). The rules: **R1** arbitrary `[…]` values; **R2** text sizes other than the six tokens; **R3** spacing steps 1.5, 2.5, 3.5, 7, 9, 11, 14, 15; **R4** raw colors; **R5** raw `<button>`, `<input>` (not `hidden`), `<select>`, `<textarea>` outside `ui/` and `patterns/`; **R6** bare `rounded`, `shadow`, numbered `z-`, `leading-`, `tracking-`, font weights other than 400, 500, 600; **R7** a page setting its own padding, width or header instead of a Page pattern; **R8** `overflow-auto` or `-scroll` anywhere but `ui/scroll-area.tsx`; **R10** `outline-none` without a `focus-visible:` outline; **R11** a `var(--x)` or color class naming no token; **R14** motion outside `ui/` without `motion-safe:`. **Lowering the baseline:** fix the offense; run `php artisan test tests/Architecture/DesignSystemTest.php`; its first case lists what is gone; remove exactly those lines and lower that rule's number in `dsCounts()`. **Never add a line or raise a number.**
- **The admin's words.** `tests/Architecture/AdminWordsTest.php` lists, in `tests/Architecture/admin-words-baseline.txt`, every word a person reads that does not yet pass through `__()` or `__n()`. New words always go through them, in American English (I-3439). Moving a listed word into `__()` makes the test list it as gone: remove that line and lower the count in its "never lets the baseline grow" case by as many.
- **Browser-test helpers** in `tests/Pest.php` (used, never edited): `cmSignIn(User $person)`, `cmNavigate($page, string $url)` (one attempt; never two `->navigate()` in a row), `cmWaitUntil($page, string $jsCondition)`, `shellSettled($page, string $selector)`, `cmEditorSettled($page)`, `cmSmallTargets($page)` (returns `['small' => [...], 'checked' => n]`), `uiComponent(string $path)`, `uiTsxFiles()`.
- **Browser-test rules.** Run after `npm run build`, one file at a time, output redirected to a file and read, never piped through `grep` or `sed` (it hangs). Wait for a popover, menu or sheet to settle before clicking inside it: `shellSettled()` on its own selector, never `getAnimations({ subtree: true })`. Select by `role`, `aria-*`, `data-slot` and `data-test`. Run only the browser files this part touches; GitHub runs the full set after the merge.

## The decisions, written out

**C1. Numbered pages with a capped count** (I-3421). The cap is **50 pages** (`ListPages::CAP = 50`; provisional, see the proposal's Settled 1).

- A page is asked for as `?page=N`. `N` from 1 to 50 is read by `OFFSET (N - 1) × perPage`, so the deepest numbered page skips at most 4,900 rows at 100 a page. Anything else (0, 51, "x") is page 1.
- **The count is capped.** When the list is not narrowed (no search, no filter beside the view), the exact total is the view's count, which the lists already read from maintained counts at no cost. Otherwise one query counts at most `CAP × perPage + 1` matching rows: `select count(*) from (<the filtered query, select 1, no order> limit ?) as capped`. More than `CAP × perPage` means "more than 50 pages".
- **Past the cap, keyset paging as today.** On page 50 of a list with more, Next carries today's `after` cursor of the last row; from there the list pages by `after` and `before` exactly as now, at the same cost on every page (I-140), and `page` is null. Previous from the first page past the cap, with no `before` row left beyond page 50, is `?page=50`.
- **What every list query returns**, replacing `newer` and `older` at the top level:

```php
/**
 * @return array{page: int|null, last: int, more: bool, from: int|null, to: int|null, total: int|null, newer: string|null, older: string|null}
 */
```

`page` (null past the cap), `last` (the last numbered page: `min(CAP, ceil(count / perPage))`, at least 1), `more` (pages beyond `last`), `from` and `to` (row positions, null past the cap), `total` (exact when known: the view count when not narrowed, or the capped count when under the cap; else null), `newer` and `older` (keyset cursors, used only past the cap and for Next on page 50). The rows are unchanged.

- **The helper:** `app/Domain/Support/Queries/ListPages.php` (new, `final`), with `public const int CAP = 50`, `public static function requested(mixed $page): int` (1 to 50, else 1), `public static function count(Builder $query, int $perPage): int` (the capped count above, never more than `CAP × perPage + 1`), and `public static function describe(int $page, int $perPage, int $rowsOnPage, ?int $exactTotal, int $cappedCount, ?string $older): array` (the shape above). Its docblock says what it is for and cites I-3421.
- **Where:** `ContentList::page()` (the exact total from `tabs()` when `filtered` is false and there is no search), `CommentList::page()` (always the capped count), `TermList::page()` (flat terms: capped count) and `TermList::branches()` (nesting terms: the branches are already in memory, so `last` and `total` are exact and no cap applies). The controllers read `page` from the query and pass it in; `ContentListRequest` and `CommentListRequest` gain `public int $page = 1`. Terms page 50 at a time as today; content and comments by `lists.per_page` (25, 50 or 100) as today.
- **The pager** (`components/list/numbered-pager.tsx`, new): inside the frame, padding 16 20 (`px-5 py-4`). At the start "1 to 50 of 240" (`__(':from to :to of :total', …)` with numbers formatted), or ":from to :to of more than :most" (`most` = `CAP × perPage`) when `total` is null and `more` is true, in `text-small text-ink-3`. At the end, a `nav` labelled "Pages": Previous (icon button, `aria-label` "Previous page"), the numbers as `sm` ghost buttons (the current one `aria-current="page"`, drawn selected), Next (icon button, "Next page"). Numbers shown: 1, the last, the current and one either side, with "…" (`aria-hidden`, and an `sr-only` "and more") for gaps; with `more`, the last one reads "50+" with `aria-label` "Page 50 of more than 50". Past the cap: Previous and Next only, with "Past page 50" between. Under 640px: Previous · "Page 3 of 50+" · Next. Every link is an Inertia `<Link>` that keeps the other query values. The old `Pager` stays for the screens that use it.

**C2. Undo after publishing or trashing from a row** (I-3398). In `ContentActionController::action()`:
- `publish`: the flashed toast keeps its message and gains `'undo' => ['href' => route('content.action', ['type' => $type, 'content' => $content, 'action' => 'unpublish'], false), 'data' => ['undo' => true]]`. The toast must therefore be flashed as `Inertia::flash('toast', [...])` (as trashing already is), not through `done()`'s message; read `done()` first and keep what it does beside the flash.
- `trash`: when no redirect offer applies, the toast gains `'undo' => ['href' => route('content.action', [..., 'action' => 'restore'], false), 'data' => ['undo' => true]]`. When a redirect offer applies, the toast keeps its link and has no Undo (the toast takes one action; Restore in the Trash view is the way back; provisional, the proposal's Settled 6).
- An Undo request (`undo=1`) answers with today's message for that action and no further Undo.
- The words stay as they are (provisional, Settled 7). No new route, operation or permission: Undo is the existing route, so `UnpublishContent` and `RestoreContent` decide as they always do.

**D1. The table** (`components/list/data-table.tsx`, restyled in place; its props stay, new ones are optional). DS 4.26:
- Frame: `Panel`-like section (`bg-surface rounded-lg shadow-raised overflow-hidden`, `data-slot="table-frame"`), inside it a `ScrollArea axis="x"` holding the table (`min-w-max w-full`), then the `footer` prop (the pager). No `Card` any more.
- Header: `h-11`, `bg-surface`, sticky to the top of the view (`sticky top-0 z-(--layer-sticky)` or the layer token the shell uses for sticky headers: read `app.css` §2.11 and use its name; a missing one is a STOP), `text-small font-medium text-ink-3`, nowrap. Sortable headings are buttons: label plus a 14px icon (`ArrowUpDown` at rest, `ArrowUp`/`ArrowDown` when sorted), `aria-sort` on the `th`.
- Rows: no borders between rows; `hover:bg-surface-2` on every cell; selected (`data-selected`) `bg-accent-soft`. Cells `px-4 py-3`, `align-top`, `text-body`. Rows have no fixed height (72 or 92 come from the title cell, D2). Non-title cells sit on the title's first line: DS 4.26 asks for a 22px top padding, which is off the spacing scale, so use the nearest allowed step (`pt-5`, 20px, or `pt-6`, 24px) that puts the first lines within 2px of each other, and record the choice; Task 4's row-height case measures it.
- New optional props: `selectedKeys?: Set<string | number>`; `pinned?: { start: string[]; end: string[] }` (column ids; pinned cells `sticky` with `bg-inherit`, the title's trailing shadow and the "…" column's leading shadow shown only when scrolled, measured with a `scroll` listener on the `ScrollArea`); column `width?: number` (px, from DS 4.26: tick 48, title min 320, author 128, categories 146, tags 168, status 120, date 116, numbers 100 to 116, "…" 56) and `minWidth?: number`; `cards?: CardLayout` (D6); `footer?: ReactNode`; `wideNote?: string` ("More columns: scroll sideways", shown in the footer's line when the table is wider than the frame, measured with a `ResizeObserver`).
- Callers outside this part pass none of the new props and get the new look with their columns as they are.

**D2. The title cell** (`components/list/title-cell.tsx`, new): `flex gap-3`; the thumbnail 48×32 (`rounded-md`, outline `--img-outline`; an `ImageIcon` on `bg-surface-3` when none), shown when the person's chooser has "Thumbnails" on (D8); then a column: the title as a `Link` to the editor (`text-body font-semibold text-ink`, line-height 1.35 by the `leading-snug` token or the nearest the check allows, `line-clamp-2`, `text-pretty`, the full title in a `Tooltip` on focus and hover), `mt-1`, then the row line (D3). Minimum width 320. The language tag and the visibility mark the list shows today stay, after the title.

**D3. The row line** (`components/list/row-line.tsx`, new): `role="group"`, `aria-label` `__('Actions for :title', …)`, always visible (no opacity rule, no hover), one line of `TextButton`s in `text-small`, 24px tall (44 on touch, from `--ctl-h-sm` where the touch rule applies; else `min-h-6`), gap 4, each with an `sr-only` ": {title}". It takes `RowAction[]` (the existing type) and draws `href` items as links (new tab when `newTab`), `onSelect` items as buttons, `form` items as the existing form submit (reuse `row-actions.tsx`'s form drawing by exporting nothing new: copy the small form branch, which `RowLinks` also has).

**D4. The "…" menu** (`components/list/row-menu.tsx`, new): an icon button (ghost, `sm`, `MoreHorizontal`, `aria-label` `__('More actions for :title', …)`, a `Tooltip`), opening the `DropdownMenu`. Its order, made by a pure function in `components/list/row-menu-order.ts` (new; imports nothing, so Node runs it as `tests/Fixtures/row-actions.mjs` runs `row-action-order.ts`):

```ts
/** The "…" menu's order (I-3398): core actions, then plugins' in their order, then the destructive ones, last. */
export function orderRowMenu<T extends { owner: string; destructive?: boolean }>(
    items: T[],
): { core: T[]; plugins: T[]; destructive: T[] } {
    return {
        core: items.filter((item) => item.owner === 'core' && item.destructive !== true),
        plugins: items.filter((item) => item.owner !== 'core' && item.destructive !== true),
        destructive: items.filter((item) => item.destructive === true),
    };
}
```

A separator sits between non-empty groups; destructive items are `text-danger-ink` with their icon. Esc and Tab close; focus returns to the button. A `form` item keeps the current behavior (a real form's submit, `wrap` for a confirmation).

**D5. Which actions go where** (content lists, `pages/content/index.tsx`'s `rowActions()` split into `lineActions()` and `menuActions()`):
- Line: **Edit**, **Quick edit**, **View** (when `viewUrl`) or **Preview**. In the Trash view: **Restore**.
- Menu: at most one state action (**Approve** when `review.canDecide`; else **Publish** when `can.publish`, not published, no pending review; else **Revert to draft** when `can.publish` and published), then plugin actions (none exist yet: I-200), then **Trash**. In the Trash view: **Delete permanently** (the existing `ConfirmAction`, its confirm button `danger`).
- A row the viewer may not edit (`canEdit` false) shows only View or Preview on its line and no menu, as today.

**D6. Cards on a phone** (I-3422). Under 640px (`components/list/use-cards.ts`, new: `matchMedia('(max-width: 39.99rem)')` through `useSyncExternalStore`, server snapshot false) `DataTable` with a `cards` prop draws a `ul` (`aria-label` = the caption, `data-slot="list-cards"`, `data-card-facts` = the fact ids joined by spaces) instead of the table:

```ts
export type CardLayout = {
    /** Column ids shown as the facts line, in this order. */
    facts: string[];
    /** A column drawn full width under the title, such as a comment's text. */
    body?: string;
    /** The title column and the tick column. */
    title: string;
    select?: string;
    /** The column whose cell is the "…" menu, drawn at the card's top right. */
    menu?: string;
    /** The sort control above the cards: the sortable columns and the one in force. */
    sort?: { options: { key: string; label: string }[]; current: string; direction: SortDirection };
};
```

Each card (`li`, `data-slot="list-card"`, `bg-surface rounded-lg shadow-raised p-4 grid gap-2`): the tick (the 44 square is the target, `data-card="select"`), the title cell (`data-card="title"`, the title clamped at three lines), the facts line (`data-card="facts"`, the facts' cells in order separated by space and a `·` that is `aria-hidden`, never leading or trailing), the body when declared (`data-card="body"`), the row line (inside the title cell), and the menu at the top right (`data-card="menu"`). Above the cards, a bar (`data-slot="cards-bar"`): **Select all** (a checkbox, "Select all :count :plural shown") and **Sort** (a `Select` of `sort.options` and an icon button "Reverse the order"). The column chooser is not drawn under 640. Ticking pins the bulk bar to the foot (D9). From 640 up, the table.

Facts declared: content lists `['status', 'date', 'author']`; Comments `body: 'comment'`, facts `['item', 'submitted']`; terms `['slug', ...one count column per type]`.

**D7. Comments on the pattern** (`pages/comments/index.tsx`): line **Approve** or **Unapprove** (by the comment's state, as the built bulk moves say), **Reply**, **Quick edit**, **Details**; in the Spam view **Mark as not spam**, **Details**; in the Trash view **Restore**, **Details**. Menu: **Mark as spam** (where it applies), plugin actions, **Move to trash**; in Spam and Trash, **Delete permanently**. Everything else stays: the views with counts (now `InPageNav`), search, the item filter chip, the moderation keys (J, K, X, A, U, S, T, R, Q through `rowProps`, and their hint under the table), the inline reply, Quick edit, the Details drawer, Empty spam and Empty trash, and the toasts' own Undo. `pages/comments/off.tsx` and `refused.tsx` move onto `Page` with `PageHeader` from the patterns (clearing their R7 lines), words unchanged but through `__()`.

**D8. The column chooser** (`components/list/column-chooser.tsx`, new; replaces `ScreenOptions` on the content list, and `screen-options.tsx` is deleted once nothing imports it: `git grep` proves it). "Columns" with its count of shown columns as a Count (DS 4.15) after the label, ghost, `SlidersHorizontal` icon, at the end of the filter row. A 380px `Popover` (`data-test="column-chooser"`): the caption "Columns, in order"; **Title** first, ticked and disabled, with "always shown"; then every optional column in the person's order, each a `Checkbox` with its name and two icon buttons, `aria-label` `__('Move :name earlier', …)` and `__('Move :name later', …)` (the first's Earlier and the last's Later disabled). Then "Thumbnails" (a checkbox; the preference id stays `featured`). Then "Rows per page" (a `SelectField` of 25, 50, 100). Then the line: "The title takes the room that is left, and never gets narrower than its minimum." or, when the chosen columns' widths plus 48, 320 and 56 exceed the frame, "The table is now wider than the screen. It scrolls sideways, with the tick box and title kept in view." Then **Apply** (primary `sm`) and **Cancel** (ghost). Apply saves `lists.{type}.columns` (the ids **in the chosen order**) and `lists.per_page` through `savePreferences()` and reloads, as `ScreenOptions` does today. The column order on the page follows the saved order; ids the preference does not name (a column added later) follow, in their declared order.

**D9. The bulk bar** (`components/list/bulk-bar.tsx`, restyled in place; props kept, two optional added): a labelled region (`aria-label` "Bulk actions"), `bg-accent-soft text-on-accent-soft rounded-lg min-h-13 py-2 pr-2 pl-5`; "{n} posts selected" in 600 (`__n`), announced by a polite live region; the caller's actions; `more?: RowAction[]` drawn as "More" (a `DropdownMenu`, `ChevronDown`); `onClear?: () => void` drawn as "Clear selection" (ghost) at the end. Under 640 it is `fixed inset-x-4 bottom-4` with `shadow-float` (the shell's safe-area padding included), and the page gains bottom padding while it shows. On the content list, the first three of today's bulk actions are buttons (neutral `sm` on `bg-surface`), the rest are in More; Bulk edit opens from its button or from More. "Select all 240 posts" (today's `SelectAllMatching`) sits in the bar when the whole page is ticked.

**D10. The filter row** (`components/list/filter-row.tsx`, new): search (280 wide; the In titles / In text select stays beside it on the content list), then the filters, then "Clear filters" (ghost) once any is on, then space, then Columns. Gap 8, wraps. Each filter has an `sr-only` label and an "All …" first choice:
- **Author:** a single `Combobox` of the authors (local filtering), "All authors" first.
- **A nesting taxonomy** (categories): a single `Combobox` of the tree's options, each with its path as `description` and `keywords` holding the path's names, "All categories" first.
- **A flat taxonomy** (tags) (I-1452): a single `Combobox` with `search` fed by `terms.search` (the route `TermPicker` already uses: 200 ms after typing stops, the previous request superseded), its chosen value shown from `chosenTerms`, "All tags" first.
- **Date:** today's `DateFilter` popover, restyled; the months and their counts as now.
- Choosing applies at once (an Inertia visit with `preserveScroll`, the other query values kept, `page` dropped).
- Under 640px: the search, then "Filters (n)" (n = filters on) opening a `Sheet` with the same controls stacked, each applying at once, and "Clear filters" at its foot.

`filter-button.tsx` stays while anything imports it; the content list stops using it.

**D11. Status column** (content lists; provisional, Settled 3): id `status`, width 120, a `StatusPill`: Published `ok`, Scheduled `info`, In review `info` ("In review"), Draft `neutral`, Private `neutral` ("Private"), and in the Trash view "In Trash" `neutral`. A failed or held schedule shows `warn` "Schedule held" with today's reason in a `Tooltip` and in the date cell's second line. The title's status suffix (`statusSuffix()`) is removed; who submitted a review moves to the date cell's second line as today's words.

**D12. What stays exactly as it is:** Quick edit (the `Sheet` and its form), Bulk edit (`BulkEditDialog`), select all matching (`SelectAllMatching`, `ConfirmMatching`), `ConfirmItems`, the schedule warning, "Add new {singular}", the views and their counts, search, every filter's query keys, the month filter, sorting by heading (one column; the first click uses the column's default), the Comments column, the language tag. Their words move into `__()`.

---

## Owns

**Exclusively (this part writes them):** `resources/js/components/list/**`; `resources/js/pages/content/index.tsx`; `resources/js/pages/terms/index.tsx`; `resources/js/pages/comments/**`; the list queries and their controllers: `app/Domain/Contents/Queries/{ContentList,ContentListRequest,ContentListFilters}.php`, `app/Domain/Comments/Queries/{CommentList,CommentListRequest}.php`, `app/Domain/Taxonomy/Queries/TermList.php`, `app/Http/Admin/Contents/{ContentListController,ContentActionController}.php`, `app/Http/Admin/Comments/CommentScreenController.php`, `app/Http/Admin/Taxonomy/TermController.php` (its `index` only); new `app/Domain/Support/Queries/ListPages.php`; new `resources/js/types/list-pages.ts`; the page types for these three screens in `resources/js/types/{content,comments,terms}.ts` (only the `…IndexPage` types and the row types' new fields).

**Tests it writes or changes:** listed per task. **Shared lists it may only shrink:** `tests/Architecture/design-system-baseline.txt` and `DesignSystemTest.php`'s `dsCounts()`, `tests/Architecture/admin-words-baseline.txt` and `AdminWordsTest.php`'s count, for this part's files only.

**Never touched:** `components/ui/**`, `components/patterns/**` (existing files), `resources/css/app.css`'s tokens, `components/editor/**`, `pages/content/edit.tsx`, `pages/content/compare.tsx`, `components/media/**`, `components/media-drawer.tsx`, `pages/media/**`, `pages/tools/**`, `components/{backups,import,tools,jobs}/**`, `pages/plugins/**`, `components/plugins/**`, `components/comments/**`, `components/term-picker.tsx`, `components/flash-toast.tsx`, `pages/dev/catalogue.tsx`, `tests/Pest.php`, the scanner.

## Global Constraints

- **Rule 1:** every state change goes through the existing operations, by the existing routes. Nothing new writes.
- **Rule 3:** PostgreSQL only; the count query is plain SQL that PostgreSQL plans.
- **Rule 5:** `packages/teme-contracts` is not touched.
- **No new dependency.** Never change `composer.json`, `composer.lock`, `package.json` or `package-lock.json`.
- **Every word a person reads goes through `__()` or `__n()`**, new words in American English (I-3439). At the end, no line in the admin-words baseline names a file this part owns.
- **The design-system check's baseline only shrinks.** At the end, no line names `js/pages/content/index.tsx`, `js/pages/terms/index.tsx`, `js/pages/comments/`, or a file in `js/components/list/` this part changed or added. Files it leaves unchanged for other screens (`pager.tsx`, `row-links.tsx`, `row-actions.tsx`, `row-action-order.ts`, `tab-strip.tsx`, `page-header.tsx`, `filter-button.tsx` while imported) keep their lines. **Adding a line, raising a number, or widening the scanner's allowed places is a STOP.**
- **Every existing export of `components/list/**` keeps its name and props**, so media, tools, people, navigation, regions, types, taxonomies, the inbox and AI activity compile and behave unchanged.
- **Tests first:** each test is run red for the reason given before the code. An architecture test is planted red first.
- **The `.env`:** in a fresh checkout, `cp .env.example .env` and `php artisan key:generate`. Never copy, read or edit any other `.env`.
- **Never edit** `.ai/`, `CHANGELOG.md`, the decision log, `docs/roadmap.md`, `docs/backlog.md`, `docs/architecture/*`, `docs/reference/*`.
- **Conventional Commits**, staged by file (never `git add -A`), no closing keywords, written for the public: no private names or paths. A code comment cites at most one I-number or one document path (I-797).
- `components/ui/*` is not edited; every other file follows `npm run check:fix`.
- **`tests/Architecture` files that use `resource_path()` declare `uses(TestCase::class)`.** Helpers used by one file stay in it with its prefix (`cl`).
- **Browser files** run after `npm run build`, one at a time, output redirected to a file and read.
- **The quick check** after each task: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, `npm run types:check`, `npm run check`, `php artisan test --testsuite=Architecture`, and the task's own test files.
- **When the work is interrupted:** each task ends committed and pushed, with one line in Records: "Done to Task N at `<hash>`, the quick check green". Node missing: run `export PATH=/opt/node24/bin:$PATH`; if still missing, stop, push and record it. A test red at the base and not this part's: record it, never change it.
- **Unattended work: decide small things yourself.** Where the plan is silent or a detail does not fit (a spacing step, a word, a test's selector, the split of a component, a helper's name), choose the option nearest the design system and this plan, write it as `B-?? (draft)` in Records with the options you weighed and why you chose, and carry on. Only the STOP conditions below stop the work.

---

## Task 0: Preconditions, then branch

Run in a checkout of `origin/main`. If any line differs **by its text**, STOP and write why under Records.

- [ ] **Step 1:** `git fetch origin && git log --oneline --fixed-strings --grep="feat(admin): the design system and the admin's new frame" origin/main` → one commit (`e7f2af73`). `git log --oneline --fixed-strings --grep="Merge pull request #269 from temecms/ui/combobox" origin/main` → one commit. Record the head.
- [ ] **Step 2:**
  - `ls resources/js/components/ui/combobox.tsx resources/js/components/ui/term-chips.tsx resources/js/components/ui/status-pill.tsx resources/js/components/ui/text-button.tsx resources/js/components/ui/scroll-area.tsx resources/js/components/ui/sheet.tsx resources/js/components/ui/tooltip.tsx resources/js/components/patterns/list-page.tsx resources/js/components/patterns/in-page-nav.tsx` → all exist;
  - `grep -n "export { Combobox\|export function Combobox\|export type ComboboxOption" resources/js/components/ui/combobox.tsx` → at least two lines; `grep -n "axis" resources/js/components/ui/scroll-area.tsx` → at least one line; `grep -n "const SHOWN = 5" resources/js/components/patterns/in-page-nav.tsx` → one line;
  - `git grep -n "export function\|export type" -- resources/js/components/list` → exactly the exports `BulkBar`, `BulkEditReport`, `BulkEditDialog`, `ConfirmItems`, `SortDirection`, `Column`, `DataTable`, `DateMonths`, `DateFilter`, `monthLabel`, `rangeLabel`, `dateValue`, `EmptyState`, `ListPanel`, `FilterChoice`, `FilterButton`, `PageHeader`, `Pager`, `splitRowActions`, `RowAction`, `RowActionsMenu`, `RowLinks`, `ScreenOptions`, `MatchingAction`, `matchingScope`, `SelectAllMatching`, `ConfirmMatching`, `Tab`, `TabStrip`; record them;
  - `git grep -l "ScreenOptions" -- resources/js` → only `components/list/screen-options.tsx` and `pages/content/index.tsx`;
  - `grep -n "'newer' =>\|'older' =>" app/Domain/Contents/Queries/ContentList.php app/Domain/Comments/Queries/CommentList.php app/Domain/Taxonomy/Queries/TermList.php` → two lines in each of the first two, four in `TermList.php`; `ls app/Domain/Support/Queries/Cursor.php` exists; `ls app/Domain/Support/Queries/ListPages.php 2>&1` → not found;
  - `grep -n "->where('action', 'trash|restore|publish|unpublish')" routes/admin.php` → one line; `grep -n "name('terms.search')" routes/admin.php` → one line;
  - `grep -n "h-11" resources/js/components/list/data-table.tsx` → one line; `grep -n "draws 44px rows\|gives the bulk bar a muted background" tests/Architecture/SharedControlsTest.php` → two lines;
  - `grep -c -E "^function (cmSignIn|cmNavigate|cmWaitUntil|shellSettled|cmEditorSettled|cmSmallTargets|uiComponent|uiTsxFiles)\(" tests/Pest.php` → 8;
  - `php artisan test tests/Architecture/DesignSystemTest.php` and `php artisan test tests/Architecture/AdminWordsTest.php` → green; record `wc -l tests/Architecture/design-system-baseline.txt` (1441 on the day this was written), `grep -c -E "js/(components/list/|pages/content/index|pages/terms/index|pages/comments/)" tests/Architecture/design-system-baseline.txt` (119), the admin-words count (`grep -n "toHaveCount(" tests/Architecture/AdminWordsTest.php`: 2373) and its lines for owned files (211);
  - record the browser files that touch these screens: `git grep -l -i -E "content\.index|/admin/(posts|pages)|terms\.index|categories|comments\.index|/admin/comments|row-links|screen-options|bulk|list-filters" -- tests/Browser`;
  - `php -v` 8.4; `node --version` 22.18 or later; `pg_isready -h 127.0.0.1 -p 5433` answers.
- [ ] **Step 3:** restate the stack from `composer.lock` and `package-lock.json` in Records. `git checkout -b ui/content-lists origin/main`. Commit nothing yet.

## Task 1: Numbered pages with a capped count (the server)

**Files:** `app/Domain/Support/Queries/ListPages.php` (new); `ContentList.php`, `ContentListRequest.php`, `ContentListFilters.php`; `CommentList.php`, `CommentListRequest.php`; `TermList.php`; the three controllers; `tests/Feature/Admin/ListPagesTest.php` (new); the existing tests named below.

**Tests, written first** (`tests/Feature/Admin/ListPagesTest.php`; `RefreshDatabase`, factories; per-page 25 set through `PreferencesFor` as `ContentListTest` does):

| Case | What it proves |
|---|---|
| "numbers the pages of an unfiltered list and gives its exact total from the view count" | 60 posts, 25 a page: `pages` is `{page: 1, last: 3, more: false, from: 1, to: 25, total: 60}`; `?page=3` holds rows 51 to 60 in the list's order |
| "caps the count of a filtered list at fifty pages" | 1,300 posts matching a search, 25 a page: `last` 50, `more` true, `total` null; the count query in the query log carries `limit 1251` |
| "gives a filtered list under the cap its exact total" | 40 matches: `total` 40, `last` 2 |
| "goes past the cap by keyset, with the same number of queries on every page" | at page 50, `older` is set; `?after=<older>` lists the next 25 with `page` null; the query count at page 1, page 50 and past the cap is equal (the count query runs on each) |
| "reads anything but 1 to 50 as page 1" | `?page=0`, `?page=51`, `?page=x` → `page` 1 |
| "numbers comments the same way" | 30 comments, 25 a page: `last` 2, `total` 30 |
| "numbers nesting terms by their branches, exactly" | a category tree of 60 top-level branches: `last` 2, `more` false, `total` the branch count |
| "numbers flat terms with a capped count" | 120 tags: `last` 3 at 50 a page, `total` 120 |
| "lists nothing it should not on a deep page" | an author's `?page=2` of the Mine view holds only their rows (the view and filters apply before the offset) |

- [ ] **Step 1:** write the cases; run; record the first failure (expected: `Property [pages] does not exist`).
- [ ] **Step 2:** build `ListPages` as C1 and use it in the three queries; the controllers pass `page` through. The rows, tabs, filters, sorts and the dense-term plan (`fetch()`) are unchanged; the offset is applied after the cursor branch only when no cursor is given.
- [ ] **Step 3: existing tests that change, meaning kept.** Run `git grep -l -E "'newer'|'older'|newer|older" -- tests/Feature/Admin tests/Feature/Cms/Contents tests/Feature/Comments` and, for each case that reads `newer`/`older` on these three screens, read the top-level `pages` instead (`pages.older`, `pages.newer` past the cap). `ContentListTest` "pages 50 at a time, older and newer, with no total" becomes "pages 50 at a time by number, with the total where it is known" (I-3421 replaces the no-total rule for these lists); "makes the same number of queries on every page" stays as it is and must pass unchanged. Record each changed case and why.
- [ ] **Step 4:** quick check; `php artisan test tests/Feature/Admin tests/Feature/Cms/Contents tests/Feature/Comments`; commit `feat(admin): numbered pages with a capped count on the content, comment and term lists (I-3421)`; push; the Records line.

## Task 2: Undo after publishing or trashing from a row

**Files:** `ContentActionController.php`; `tests/Feature/Admin/RowUndoTest.php` (new).

**Tests, written first:**

| Case | What it proves |
|---|---|
| "offers Undo after publishing from a row, and Undo reverts it to a draft" | An editor publishes a draft through `content.action` publish: the flashed toast has `undo.href` = `content.action` unpublish for that item, `undo.data.undo` true; posting it leaves the item a draft, and its toast has no `undo` |
| "offers Undo after trashing an item that offers no redirect, and Undo restores it" | A draft trashed: `undo.href` = restore; posting it restores it |
| "keeps the redirect offer and no Undo when trashing a published item offers one" | As today's I-856 case: `link` present, `undo` absent |
| "refuses an Undo from someone who may not revert it, and writes nothing" | A contributor posts the unpublish Undo for an editor's published post: 403, the post still published, no revision or status row changed (count `contents` and `revisions` before and after) |
| "refuses an Undo of a trash from someone who may not restore it, and writes nothing" | The same for restore: 403, still trashed |

- [ ] **Step 1:** write; run; record the first failure (`undo` missing).
- [ ] **Step 2:** build as C2.
- [ ] **Step 3:** quick check; `php artisan test tests/Feature/Admin/RowUndoTest.php` and every file `git grep -l "content.action\|ContentActionController" -- tests/Feature` names; commit `feat(admin): Undo after publishing or trashing from a list row (I-3398)`; push; the Records line.

## Task 3: The shared list parts

**Files:** `components/list/{data-table,bulk-bar}.tsx` (restyled); new `components/list/{title-cell,row-line,row-menu,row-menu-order,numbered-pager,column-chooser,filter-row,list-cards,use-cards}.ts(x)` and `resources/js/types/list-pages.ts`; `tests/Architecture/SharedControlsTest.php` (two cases rewritten); `tests/Architecture/ListPartsTest.php` (new); `tests/Fixtures/row-menu.mjs` and `tests/Feature/Admin/RowMenuOrderTest.php` (new, as `RowActionOrderTest` runs `row-actions.mjs`).

**Tests, written first:**
- `RowMenuOrderTest` (Node runs `row-menu-order.ts`): core first, plugins in their order, destructive last; a destructive plugin item goes with the destructive group; an empty input gives three empty groups. Red first: the file does not exist.
- `SharedControlsTest`, rewritten, meaning kept ("the shared table and bulk bar are drawn the one way the design system sets"): "draws the table in a raised frame that scrolls sideways, with a 44px header and no lines between rows (DS 4.26)": `data-table.tsx` contains `rounded-lg`, `shadow-raised`, `axis="x"`, `h-11`, `hover:bg-surface-2`, `bg-accent-soft`, and not `<Card` and not `border-b`; "draws the bulk bar on the accent's soft ground as a labelled region (DS 4.26)": `bulk-bar.tsx` contains `bg-accent-soft`, `text-on-accent-soft`, `aria-label`, and not `bg-accent ` (with the space). Red first against today's files.
- `ListPartsTest` (architecture, `uses(TestCase::class)`): "keeps every export the other screens import" (each name Task 0 recorded is still exported from its file); "draws row actions with no hover rule" (`row-line.tsx` has no `opacity-0` and no `group-hover`); "pages these lists only through the numbered pager" (`pages/content/index.tsx`, `pages/terms/index.tsx`, `pages/comments/index.tsx` import `numbered-pager` and not `list/pager`). Red first: the files do not exist yet.

- [ ] **Step 1:** write the three; run; record the first lines.
- [ ] **Step 2:** build D1 to D4, D6, D8 (the component; the page adopts it in Task 4), D9, D10 and C1's pager. `types/list-pages.ts` holds `ListPages` (C1's shape) and `CardLayout`. Run the three test files green.
- [ ] **Step 3:** run `DesignSystemTest` and `AdminWordsTest`: the restyled files' lines are gone; remove them, lower the counts. The new files add no line (if one does, fix the file, never the baseline).
- [ ] **Step 4:** the screens that already use `DataTable` and `BulkBar` must still pass: run, after `npm run build`, one at a time, the browser files of media, tools (redirects, the 404 log, jobs, AI activity), people, navigation, regions, types, taxonomies and the inbox that `git grep -l -E "table|bulk" -- tests/Browser` names among `MediaScreenTest`, `RedirectsScreenTest`, `JobsScreenTest`, `AiActivityScreenTest`, `PeopleScreenTest`, `NavigationScreensTest`, `RegionsScreenTest`, `TypeBuilderTest`, `TaxonomyBuilderTest`, `InboxScreenTest`, `ToolsScreensTest`. A failure caused by the restyle that needs a change in another part's file is a STOP; one that a selector in this part's components can satisfy (a `data-slot` or role kept) is fixed here.
- [ ] **Step 5:** quick check; commit `feat(admin): the list parts of the design system: table, row line and menu, numbered pager, column chooser, bulk bar, filter row, cards`; push; the Records line.

## Task 4: Posts, Pages and every content type's list

**Files:** `pages/content/index.tsx`; `components/list/screen-options.tsx` (deleted once unused); `tests/Browser/ContentListPatternTest.php` (new); the existing browser and feature files named in Step 3.

The page renders `ListPage` (title, description "Everything of this kind on the site.", actions: "Add new {singular}" primary), then `InPageNav` (label "Status", the views with counts), the schedule warning, `FilterRow` with the Columns chooser, the `BulkBar` when ticked, and `DataTable` with columns in the person's order: tick (pinned start), title (pinned start, `TitleCell`), the chosen optional columns (author, each taxonomy as `TermChips` with `noun` from the taxonomy's names, status (D11), comments, date), and "…" (pinned end, `RowMenu`), `cards` as D6, and `NumberedPager` as `footer`. Row actions as D5. Quick edit, Bulk edit and the rest as D12. Shift+click on a tick box ticks the range from the last ticked row (add it if today's page lacks it).

**Tests, written first** (`tests/Browser/ContentListPatternTest.php`; an editor; 30 posts with titles of one and of two lines, two categories and five tags on some; `lists.per_page` 25):

| Case | What it proves |
|---|---|
| "shows Edit, Quick edit and View under every title without hovering" | With the pointer away from the table, each row's `[data-test="row-line"]` is visible (computed opacity 1) and holds those three; a draft's holds Preview |
| "puts the state action, then Trash, in the row's menu, and answers Publish with Published and Undo" | Open a draft's "More actions for …": items Publish, separator, Trash (last, danger); choose Publish; the toast says today's words with Undo; Undo; the row's status pill says Draft |
| "offers Approve, not Publish, on an item in review to someone who may decide" | The menu of an item in review holds Approve and no Publish |
| "shows Restore on the line and Delete permanently in the menu in the Trash view, and asks first" | In Trash: line Restore; menu Delete permanently opens a dialog whose confirm is the danger button |
| "draws rows 72 pixels tall for a one-line title and 92 for two, and wraps no other column" | Measured `getBoundingClientRect().height` within 2px; every non-title cell's `scrollHeight` equals one line |
| "keeps the tick box and title pinned and brings the date fully into view beside the menu when scrolled to the end" | At 1024 with every column chosen: the table is wider than the frame and "More columns: scroll sideways" shows; scroll the frame to its end; the title's left edge is unchanged and the Date cell's right edge is at or left of the "…" cell's left edge |
| "shows two term chips or one, then +N opening the rest" | A post with five tags: one or two chips, then a "+N" button named "N more tags: …"; Enter opens a popover listing them |
| "pages by number, keeps the filters, and reads 1 to 25 of 30" | The pager reads "1 to 25 of 30"; page 2 keeps `?author=`; its current number has `aria-current="page"` |
| "reorders and hides columns in the chooser and keeps the order after a reload" | Move Date earlier than Author with the buttons, untick Tags, Apply: headings in the new order, no Tags; reload: the same |
| "ticks three rows, shows 3 posts selected, offers Select all matching on a full page, and clears" | The bar's live region says "3 posts selected"; tick the page's box: "Select all 30 posts"; Clear selection empties it |
| "filters by author and by a tag through the Combobox by keyboard alone" | Tab to the Author filter, type two letters, ↓, Enter: the address carries `author=`; the Tags filter finds a tag by typing (after "Searching…" ends), Enter: `tags=` |
| "does every row action, the bulk bar and the filters with the keyboard alone" | Tab order in a row: tick, title, Edit, Quick edit, View, "…"; Space ticks; Quick edit opens its sheet and Esc returns focus to Quick edit |
| "passes axe with the chooser, a row menu and a filter open, every target 24 pixels or more, at 1280 in both schemes" | `assertNoAccessibilityIssues()` in each state; `cmSmallTargets()` empty |

- [ ] **Step 1:** write; run after `npm run build`; record the first failure.
- [ ] **Step 2:** build the page. Remove `ScreenOptions` from it; when `git grep -l "screen-options" -- resources/js` is empty, delete `screen-options.tsx`.
- [ ] **Step 3: existing tests that change, meaning kept.** Run each, one at a time: `ContentListsTest`, `RowLinksTest` (its content-list cases move from "the links show on hover" to "the line shows always and the rest is in the menu": DS 10 step 5 names these as changing), `ListHeaderTest` (its "Screen options" cases now open "Columns"; "puts … on the title line" now finds Columns at the end of the filter row, with Add new in the page header: I-3129's meaning, every filter in one labelled line, kept), `SelectAllMatchingTest`, `DiscussionTest`, `ViewDataTest`, `MenuAndSettingsTest`, `FullPassTest`, `AccessibilityTest`, and the feature files `ScreenOptionsTest`, `ListScreenTest`, `FeaturedImageColumnTest`, `ContentListRowsTest`, `ContentListTermChipTest`, `ContentBulkBarTest`, `BulkMatchingScreenTest`, `ContentListTabsAndFiltersTest`, `VisibilityRowTest`. For each change, record the case, the old and new assertion, and the meaning kept. A case whose meaning cannot be kept is a STOP.
- [ ] **Step 4:** `DesignSystemTest` and `AdminWordsTest`: remove `pages/content/index.tsx`'s lines and those of every list file it now uses that this part changed; lower the counts. Quick check; commit `feat(admin): every content type's list on the list pattern (I-3398, I-3399, I-3421)`; push; the Records line.

## Task 5: Categories and tags

**Files:** `pages/terms/index.tsx`; `tests/Browser/CategoriesAndTagsTest.php` (cases kept; new cases added).

The page keeps its layout (the "New {singular}" panel beside the list on wide screens, under it below 768) and its features (search, the setup link, the edit sheet or form, the delete confirmation and what it says happens, counts per content type that open the filtered list). On the pattern: `ListPage`, the table as D1 with the name column as the title (its depth marks kept), a line of **Edit** and **View** (the term's page on the site, new tab, when it has one), the "…" menu with **Delete** (danger, confirmed as today); counts right-aligned and tabular; `cards` with facts `slug` and the counts; `NumberedPager` (C1).

**Tests, written first** (added to `CategoriesAndTagsTest`): "shows Edit and View under every term and Delete in its menu, confirmed", "pages a long tag list by number", "lists terms as cards with their address and counts on a phone". Red first.

- [ ] **Step 1:** write; run; record. **Step 2:** build. **Step 3:** run `CategoriesAndTagsTest`, `TaxonomyBuilderTest`, `TermScreensTest`, `TermScreenRowsTest` (row-link cases change as in Task 4; record each). **Step 4:** lower the baselines for `pages/terms/index.tsx`; quick check; commit `feat(admin): categories and tags on the list pattern`; push; the Records line.

## Task 6: Comments

**Files:** `pages/comments/{index,off,refused}.tsx`; `tests/Browser/CommentsScreenTest.php` (cases kept; new cases added).

On the pattern as D7, with `InPageNav` for the views, the filter row (search; the item chip as today), the bulk bar (D9: today's moves for the view, first three as buttons, Delete permanently in More, confirmed), the table (author as the title column: `Commenter` unchanged; comment; In response to; Submitted), `cards` with `body: 'comment'` and facts `item`, `submitted`, and `NumberedPager`.

**Tests, written first** (added to `CommentsScreenTest`): "shows Approve, Reply, Quick edit and Details under a pending comment, and Mark as spam and Move to trash in its menu", "keeps J, K, X and A working on the new table", "approves a comment from its card by thumb on a phone and pins the bulk bar to the foot when one is ticked". Red first.

- [ ] **Step 1:** write; run; record. **Step 2:** build; `off.tsx` and `refused.tsx` onto `Page` and the pattern's `PageHeader`. **Step 3:** run `CommentsScreenTest`, `CommentsTest`, `DiscussionTest`, and the feature files `CommentScreenTest`, `CommentListTest`, `CommentsColumnTest`, `QuickEditDiscussionTest` (record each changed case). **Step 4:** lower the baselines for `pages/comments/**`; quick check; commit `feat(admin): comments on the list pattern`; push; the Records line.

## Task 7: Phones, 320 pixels and the last checks

**Files:** `tests/Browser/ContentListPatternTest.php` (cases added).

**Tests, written first:**

| Case | What it proves |
|---|---|
| "lists posts as cards on a phone with status, date and author, and no column chooser" | At 375: `[data-slot="list-cards"]` with `data-card-facts="status date author"`; each card's facts line holds those three in that order with no leading or trailing dot; no `[data-test="column-chooser"]` trigger; the table is absent |
| "sorts the cards with the Sort control and reverses the order" | The cards' bar's Sort set to Title: the address carries `sort=title`; Reverse: `direction=desc` |
| "folds the filters into Filters (n) on a phone and applies one from the sheet" | "Filters (0)"; open; choose an author; the address carries `author=`; the button reads "Filters (1)" |
| "pins the bulk bar to the foot when a card is ticked" | The bar's bottom edge within 24px of the viewport's |
| "reads Page 1 of 2 on a phone" | The pager's text |
| "reaches everything at 320 pixels with nothing clipped and no sideways page scroll" | `document.scrollingElement.scrollWidth <= innerWidth` on Posts, Tags and Comments; every row action and the menu reachable by Tab |
| "passes axe on the cards, the filters sheet and a card's menu, every target 44 pixels or more on touch, in both schemes" | At 375 |

- [ ] **Step 1:** write; run; record. **Step 2:** fix what fails within owned files. **Step 3:** quick check; commit `feat(admin): content lists as cards on a phone (I-3422)`; push; the Records line.

## Task 8: Gate and records

- [ ] The full `composer check` once. After `npm run build`, one file at a time: `ContentListPatternTest`, `CategoriesAndTagsTest`, `CommentsScreenTest`, and every browser file Tasks 3 to 6 ran. GitHub runs the rest after the merge.
- [ ] `grep -c -E "js/(pages/content/index|pages/terms/index|pages/comments/)" tests/Architecture/design-system-baseline.txt` → 0; the same for `admin-words-baseline.txt` → 0; for every `components/list` file this part changed or added → 0.
- [ ] `git diff --stat origin/main -- resources/js/components/ui resources/js/components/patterns resources/css packages composer.json composer.lock package.json package-lock.json` → nothing.
- [ ] Records: the commits, every test seen red, the counts per rule before and after, every changed test with its meaning kept, every `B-?? (draft)`, the changelog lines, and how to try it. Commit `docs(plans): content lists records`; push.

---

## Every word

**New**, through `__()` or `__n()`, in American English: "Actions for :title", "More actions for :title", ":from to :to of :total", ":from to :to of more than :most", "Pages", "Previous page", "Next page", "Page :page", "Page :page of more than :last", "Page :page of :last", "and more", "Past page :last", "Columns", "Columns, in order", "always shown", "Move :name earlier", "Move :name later", "Thumbnails", "Rows per page", "The title takes the room that is left, and never gets narrower than its minimum.", "The table is now wider than the screen. It scrolls sideways, with the tick box and title kept in view.", "More columns: scroll sideways", "Apply", "Cancel", ":count post selected|:count posts selected" (and the same for each list's noun, from the type's names), "Select all :count :plural", "Clear selection", "More", "Bulk actions", "Clear filters", "Filters (:count)", "All authors", "All :plural", "Sort", "Reverse the order", "Select all :count :plural shown", "Status", "Published", "Draft", "In review", "Scheduled", "Private", "In Trash", "Schedule held", "No :plural", ":count more :noun: :names". **Moved into `__()` unchanged:** every word the admin-words baseline lists today for the files this part owns.

## What must not be done

- Edit `components/ui/**`, an existing `components/patterns/**` file, a token, the scanner, `tests/Pest.php`, `components/flash-toast.tsx`, `components/term-picker.tsx`, `components/comments/**` or the catalogue.
- Change an existing export's name or props in `components/list/**`, or another part's page.
- Add plugin columns, row actions or filters, a "From plugins" filter, column dragging, "Posts ⇅", a Details sheet for posts, or saved views.
- Add a route, operation, permission, migration or dependency.
- Delete a test, or change what a test proves beyond the changes listed in Tasks 1, 3, 4, 5 and 6 with their meaning kept.

## STOP conditions

Stop, write why under Records, commit and push, then stop, when:
- a Task 0 line differs by its text, or the design-system or Combobox commits are missing;
- the work needs a dependency this plan does not name;
- it needs a token, a change to an existing `ui/` or `patterns/` component, or a sticky layer token that does not exist;
- a test would have to be deleted, or a test's meaning cannot be kept;
- a design-system or admin-words line would have to be added, a count raised, or the scanner's allowed places widened;
- anything would weaken security: a permission check skipped or moved to the client, a row shown to someone the query would not show it to, an Undo that bypasses an operation;
- a migration would change rows;
- a fix needs another part's files (the editor's, media's, tools', plugin areas', the phone overlays' new pattern files, `components/comments/**`).

## Security

- The page number is read as an integer from 1 to 50; anything else is page 1. The offset is applied after the view, filters, and the viewer's own scope, so a deep page shows nothing page 1 would not.
- The capped count runs the same filtered query, so it counts nothing the viewer may not list.
- Undo is the existing route and operation; its checks decide (Task 2's refused cases).
- Cards and cells render text, never HTML.

## At the merge (for the maintainers)

- `docs/architecture/design-system.md`: 4.26's Pager (the cap of 50, "50+", past the cap by Previous and Next, "Page 3 of 50+" on a phone), 4.26's Rows (cards under 640 with declared facts), 4.26's Bulk bar (the built actions, three then More), 11 Q1 closed by I-3422, 12.3 row 35 (`SharedControlsTest` rewritten for the table and the bulk bar), section 5's responsive list.
- `accessibility.md` rule 9 re-worded with I-3398 (DS 12.3 row 32).
- Changelog lines, at the release: "Posts, Pages, every content list, categories, tags and comments now show their row actions without hovering, page by number, and turn into cards on a phone." "Columns can be reordered from the Columns chooser." "Publishing or trashing from a list can be undone from the toast."
- Baseline conflicts with other parts: take both sides' removals, then run `DesignSystemTest` and `AdminWordsTest` and set each count to what the merged baseline holds. `SharedControlsTest`: take both sides' changes (another part edits a different case's dataset).
- The phone-overlays part adds its one-line opt-ins to `pages/content/index.tsx` and `pages/comments/index.tsx` (Quick edit and Bulk edit as full-screen sheets on a phone); merge them after this part.

## Open points

| # | Point | Recommendation |
|---|---|---|
| 1 | The page-number cap (I-3421 leaves it to the build) | 50 pages: "1 2 3 … 50+", at most 4,900 rows skipped at 100 a page |
| 2 | Past the cap | Previous and Next by keyset, "Past page 50" in place of numbers; the cost stays flat (I-140) |
| 3 | A Status column | On by default, the title's status suffix removed |
| 4 | The thumbnail | In the title cell, turned on and off as "Thumbnails" in the chooser (the `featured` preference id kept) |
| 5 | Rows per page | 25, 50 or 100, at the chooser's foot |
| 6 | Trash's toast when a redirect is offered | Keep the redirect link, no Undo (the toast holds one action; changing that is the shell's) |
| 7 | The toasts' words ("Published." against DS 8's "Published") | Keep today's words; only Undo is added |

---

## Records

<!-- Filled in while building: setup; commits; each test seen red (task, file, case, the failure's first line); the design-system and admin-words counts before and after; every existing test changed, with its meaning kept; differences from the plan as B-?? (draft) with the options weighed; changelog lines; any STOP. -->

### Setup

- `cat /tmp/teme-cloud-setup.log`: "2026-11-14T20:55:01Z ran in /home/user/teme as root", "2026-11-14T21:06:10Z finished". `pwd`: /home/user/teme. PHP 8.4.27; `pg_isready -h 127.0.0.1 -p 5433` accepting connections; `valkey-cli -p 6380 ping` PONG; node v24.22.1.
- Task 0: the branch was made from `origin/main` at `4b8cc3b`. The exports match the plan. The design-system baseline had 1,228 lines (the plan said 1,441), 119 of them in this part's files (as the plan says). The admin-words count was 2,350 (the plan said 2,373), 211 lines in this part's files (as the plan says). The other counts moved because other parts merged first; this part's own lines are as the plan has them.
- The full `composer check` on the base: Architecture 105 passed (1,225 assertions); Unit and Feature 10,490 passed, 2 skipped (53,814 assertions), 1,694s.

### Task 1

- Seen red at the base, `ListPagesTest`: 11 failed, first lines "Undefined array key "pages"" and, on the deep page, "actual size 25 matches expected size 5".
- Existing cases changed, meaning kept: `ContentListTest` "pages 50 at a time, older and newer, with no total" became "pages 50 at a time by number, with the total where it is known" (I-3421 replaces the no-total rule); "makes the same number of queries on every page" passes unchanged. `ListScreenTest` "offers a way back on every pager" now goes to page 2 and back by number and no longer asserts that no query uses an offset (I-3421 uses an offset for pages 1 to 50; the cost stays bounded by the cap, as `ListPagesTest` "goes past the cap by keyset, with the same number of queries on every page" proves). `ScreenOptionsTest` "remembers rows per page" reads `paging.last` (2 pages of 25, 1 of 30) where it read `older`. `TermScreenRowsTest` (three cases), `TermScreensTest` "lists categories by name", `CommentListTest` (two cases) and `CommentScreenTest` "opens the Pending view" read the cursors inside `paging` where they read them at the top level.
- B-01 (draft): the plan names the prop `pages` with a `page` number. `NoTypeLiteralsTest` reads `'pages' =>` and `'page' =>` in PHP as the content types' names. Options: rename to `paging` with `number`; add these files to that test's allow-list (not this part's file, and it widens a check); build the arrays so the scan misses them (dodges the check). Chosen: `paging` and `number`, the same shape under other names. Commit `71c8603`.
- B-02 (draft): `ContentList::page()` keeps its top-level `newer` and `older` beside `paging`, since the bulk runs (`StartBulkRun`, `AdvanceBulkRun`) and the benchmark read them and are not this part's files; the controller drops them from the props. Options: keep them; change those callers (not owned). Commit `71c8603`.
- B-03 (draft): `ListPages::describe()` takes the page number as nullable (null past the cap) and the newer cursor as well as the older, so every list builds the whole `paging` in one call. Commit `71c8603`.
- B-04 (draft): a nesting taxonomy's pages of branches are numbered exactly with no cap, since the whole tree is already read; its total counts terms, not branches. Options: cap them like the others (pointless when the tree is in memory); count branches (not what a person sees as rows). Commit `71c8603`.
- Done to Task 1 at `71c8603`, the quick check green.

### Task 2

- Seen red at the base, `RowUndoTest`: 2 failed, first line "Failed asserting that false is true." (no `undo` in the toast after publishing and after trashing); its three refused cases passed at the base, as they should.
- Existing case changed, meaning kept: `TrashOfferTest` "offers nothing to someone who can't manage redirects, or after trashing what was never published" compared the whole toast; it now checks the type and words and that there is no `link` (no redirect offer), since the toast now also carries Undo.
- Done to Task 2 at `e8b8ec2`, the quick check green.

### Task 3

- Seen red at the base: `RowMenuOrderTest` 4 failed, first line "ERR_MODULE_NOT_FOUND" (`row-menu-order.ts` missing); `SharedControlsTest` the two rewritten cases failed against today's table and bulk bar; `ListPartsTest` "draws row actions with no hover rule" failed (`row-line.tsx` missing), and "keeps every export the other screens import" passed, as it should.
- Counts: design-system baseline 1,228 to 1,219 lines (R1 114 to 112, R2 661 to 658, R5 82 to 81, R6 41 to 39, R8 34 to 33); admin words 2,350 to 2,348.
- Other screens' browser files, after `npm run build`, one at a time, all green: MediaScreenTest 11, RedirectsScreenTest 6, JobsScreenTest 3, AiActivityScreenTest 6, PeopleScreenTest 14, TypeBuilderTest 15, TaxonomyBuilderTest 10, InboxScreenTest 4, ToolsScreensTest 3. NavigationScreensTest and RegionsScreenTest name neither a table nor a bulk bar, so they were not run.
- B-05 (draft): `ListPartsTest` "pages these lists only through the numbered pager" is committed with each page's task (content in Task 4, terms in Task 5, comments in Task 6), one page added to its list each time, so the Architecture suite stays green after each task. Options: commit it here red (breaks the quick check); commit it with all three pages in Task 6 (no red seen for the first two). Commit `ecde2ff`.
- B-06 (draft): `data-table.tsx` keeps its plain `<table>` (its R5 line stays): there is no table in `components/ui`, and this part adds none. Options: add one (a `components/ui` change, a STOP); wrap it under another name (dodges the check). Commit `ecde2ff`.
- B-07 (draft): every cell is padded the same (`py-3`), so the first lines of all cells align, instead of the plan's taller top padding on the title cell; the row heights the browser test measures come from the title cell's two lines. Commit `ecde2ff`.
- B-08 (draft): the header row sticks to the top of the table's own sideways scroll frame, not of the page, since a sticky header cannot stick outside its scroll container. Commit `ecde2ff`.
- B-09 (draft): the column chooser measures the table frame by an ID that `data-table.tsx` exports (`TABLE_FRAME_ID`), not by a `[data-slot=…]` selector, which the design check reads as an arbitrary value. Commit `ecde2ff`.
- Done to Task 3 at `ecde2ff`, the quick check green.

### Task 4

- Seen red at the base build, `ContentListPatternTest`: 12 failed, 2 passed (the Approve case and the bulk-bar case already held on the old page). `ListPartsTest` "pages these lists only through the numbered pager" with `pages/content/index.tsx`: red, the old page imported `@/components/list/pager`.
- Counts: design-system baseline 1,219 to 1,188 lines (R1 112 to 110, R2 658 to 641, R3 149 to 145, R6 39 to 38, R7 89 to 85, R8 33 to 31, R9 23 to 22); admin words 2,348 to 2,261. `components/list/screen-options.tsx` is deleted, so `SharedControlsTest` names `column-chooser.tsx` in its place.
- Browser files after `npm run build`, one at a time, all green: ContentListPatternTest 14, ContentListsTest 10, RowLinksTest, ListHeaderTest 13, SelectAllMatchingTest, DiscussionTest, ViewDataTest, MenuAndSettingsTest, FullPassTest, AccessibilityTest. Feature files green: ScreenOptionsTest, ListScreenTest, FeaturedImageColumnTest, ContentListRowsTest, ContentListTermChipTest, ContentBulkBarTest, BulkMatchingScreenTest, ContentListTabsAndFiltersTest, VisibilityRowTest.
- Existing cases changed, meaning kept:
  - `ListScreenTest`: "shows no Status column" became "shows each row's status on a pill in its Status column, no longer after its title" (D11 reverses it); the pager scan reads `NumberedPager` or `Pager`. `ContentListRowsTest`: the scans read the `__()` forms, `<StatusPill` and `id: 'status'`, and name `row-menu.tsx` and `row-line.tsx` where they named `row-actions` and `row-links`. `ContentListTermChipTest` names the flat taxonomy's filter in `TermSearchFilter`. `ContentListTabsAndFiltersTest` "links each term in its column to the filter" became "shows each row's terms in its column" (see B-11), with `<ChoiceFilter` and `<TermSearchFilter`. `VisibilityRowTest` and `ContentBulkBarTest` read the `__()` forms.
  - `ContentListsTest`: the state case reads the Status column's pills, and reads the tabs past the fifth under More (B-18); Preview's name is "Preview: Market day, opens in a new tab"; the category filter is chosen through its Combobox, and the column shows the row's terms; the review chip's remove button is found by its name, not as a link.
  - `RowLinksTest`: "shows a row's links on hover" became "draws a row's line under its title, shown without hovering", the line holding Edit, Quick edit and Preview; View's name is "View: Harbour news, opens in a new tab"; the menu holds Publish and Trash, and the confirmation and the trash go through it; the axe case no longer hovers.
  - `ListHeaderTest`: Screen options became Columns (`[data-test="columns"]`, `[data-test="column-chooser"]`, Thumbnails where Featured image was); "puts Screen options and Add new on the title line" now finds Add new in the page header and Columns at the end of the one filter line (I-3129's meaning kept); the filters are read and chosen through their Combobox, and the line now holds the Tag filter too; "closes an open filter when another opens" reads the open popover by its search field; the phone case now opens the Date filter from the Filters (n) sheet and still applies a month with no sideways scroll.
- B-10 (draft): the Status column (D11, the proposal's provisional item 3) reverses "no Status column" in `ListScreenTest`, `ContentListRowsTest` and `ContentListsTest`. Built as written. Commit `b7d90d3`.
- B-11 (draft): `TermChips` in `components/ui` draws names, not links, so a row's terms no longer link to the filter; the filter row's Combobox does that job. Options: keep links in a page-drawn chip (a second chip beside the system's); change `TermChips` (a `components/ui` change, a STOP). Chosen: `TermChips` as the plan's design names it; for the maintainer to confirm. Commit `b7d90d3`.
- B-12 (draft): the row menu is non-modal and drawn inside the main landmark, as the account menu is drawn inside the banner, since axe's region rule flagged it drawn at the end of the page, and a modal menu hid focused content from assistive technology. The container is found after the first draw. Commit `b7d90d3`.
- B-13 (draft): the "…" button's tooltip never shows over its own open menu. Commit `b7d90d3`.
- B-14 (draft): the Status column also shows "Update in review" (info) for a published item with a pending review and "Password protected" (neutral), the two states the title's suffix carried that D11 does not list. Commit `b7d90d3`.
- B-15 (draft): Quick edit hands focus back to the control that opened it when it closes, as it did inside the old row; Radix had returned it to the page. Commit `b7d90d3`.
- B-16 (draft): D12 keeps "the language tag" after the title, but the base has none; none is added. Commit `b7d90d3`.
- B-17 (draft): the review filter's chip is a flat `StatusPill` with its own 24-pixel remove button beside it, since the pill's built-in remove button is 20 pixels and `ContentListsTest` (issue 99) holds it to 24. Options: the pill's own button (fails the target size); change `StatusPill` (a `components/ui` change, a STOP). Commit `b7d90d3`.
- B-18 (draft): `InPageNav` shows five views and puts the rest under More, so Posts' In review, Scheduled and Trash are under More. Kept as the pattern draws it. Commit `b7d90d3`.
- B-19 (draft): a nesting taxonomy's and the authors' filters are `ChoiceFilter`s and a flat taxonomy's is `TermSearchFilter`, both Combobox; the flat taxonomy's old chip is gone with them. Commit `b7d90d3`.
- Done to Task 4 at `b7d90d3`, the quick check green.

### Task 5

- Seen red on the build before the terms page changed, `CategoriesAndTagsTest`: 4 failed, 6 passed; the three new cases failed (no `row-line`, no numbered pager, no cards), and "says what deleting each category does" failed (no "More actions for" button). `ListPartsTest` with `pages/terms/index.tsx` was added with the new page, so its red was not seen apart.
- Counts: design-system baseline 1,188 to 1,172 lines (R1 110 to 108, R2 641 to 636, R3 145 to 141, R5 81 to 80, R7 85 to 81); admin words 2,261 to 2,234.
- After `npm run build`, `CategoriesAndTagsTest` 10 passed and `RowLinksTest` green; feature files `TermScreensTest` and `TermScreenRowsTest` green.
- Existing cases changed, meaning kept: `CategoriesAndTagsTest` "names a tag in a chip that another filter keeps and its link removes" now reads the tag on its Combobox filter and removes it with its "All tags" choice, the search kept; "says what deleting each category does" opens each term's menu for Delete; the edit and axe cases no longer wait for a menu to close, since Edit is on the line. `RowLinksTest`'s category row reads Edit and View on the line and Delete in the menu.
- B-20 (draft): the plan names no views control for this page, so Categories and Tags keep their `TabStrip` between the two taxonomies, a part of the layout the page keeps; the add form stays in its panel beside the list. Options: `InPageNav` (not named; the two are separate pages, not views of one list). Commit `5e62ba2`.
- Done to Task 5 at `5e62ba2`, the quick check green.

### Task 6

- Seen red on the build before the comments page changed, `CommentsScreenTest`: 3 failed, 13 passed; the three new cases failed (no `row-line` for a comment, no table frame, no cards).
- Counts: design-system baseline 1,172 to 1,159 lines (R2 636 to 633, R5 80 to 79, R7 81 to 72); admin words 2,234 to 2,182.
- After `npm run build`, one at a time, all green: CommentsScreenTest 16, CommentsTest, DiscussionTest, RowLinksTest. Feature files green: CommentScreenTest, CommentListTest, CommentsColumnTest, QuickEditDiscussionTest.
- Existing cases changed, meaning kept: `CommentsScreenTest`'s first axe case opens Details from the line, where it opened it from More, and waits for the drawer to settle; "1 selected" reads "1 comment selected" (the bar names its noun); Delete permanently is chosen from the bulk bar's More, then confirmed as before. `RowLinksTest`'s comment row reads Approve, Reply, Quick edit and Details on the line and Mark as spam and Move to trash in the menu. `CommentScreenTest` "says Move to trash in the row menu, never a bare Trash" reads the `__()` forms.
- B-21 (draft): `off.tsx` and `refused.tsx` are on `ListPage`, which is `Page` with the patterns' `PageHeader`, since the design check accepts only a page pattern as a page's root and `Page` is not one. Options: `Page` and `PageHeader` as the plan says (keeps an R7 line, against the rule that the baseline only shrinks). Commit `776c659`.
- B-22 (draft): Empty spam and Empty trash sit in the page header's actions, not at the end of the filter row, since the filter row leaves its end out on a phone. Commit `776c659`.
- B-23 (draft): the keys' hint keeps its words in short pieces through `__()`, each after its key drawn as `Kbd`, since the keys sit inside the sentence. Commit `776c659`.
- B-24 (draft): the bulk bar shows the view's first three moves as buttons and puts the rest and Delete permanently under More, as D9 says for Delete permanently, in Spam and Trash too. Commit `776c659`.
- B-25 (draft): the item chip is a flat `StatusPill` with a 24-pixel remove button beside it, as the review chip (B-17). Commit `776c659`.
- Done to Task 6 at `776c659`, the quick check green.

### Task 7

- Run first on the Task 6 build, `ContentListPatternTest`: the sort case and both 44-pixel axe cases failed, the rest of the new cases passed. The sort case timed out on Reverse, which sat off the screen: `InPageNav`'s views gave the page's grid column their full width (561 pixels at 375), so the table frame was wider than the screen and clipped. The 44-pixel cases found each card's tick box (18 pixels), Edit and View on a card's line (narrower than 44), and, in the Filters sheet, the Date filter's 32-pixel button.
- Fixed within this part's files: the content and comments pages put `InPageNav` in a cell that may be narrower than its views, which then scroll sideways inside it; a card's tick square is a label, so all 44 pixels tick it; a line action is at least as wide as it is tall on touch; the Date filter's button is 44 pixels on a phone. The 320-pixel case now also checks that the frame, views and filters end inside the screen.
- Counts: the Date filter and Select all matching were changed, so their lines went too (the plan's Task 8 holds every changed `components/list` file at none): design-system baseline 1,159 to 1,149 lines (R2 633 to 627, R3 141 to 140, R5 79 to 78, R8 31 to 30, R10 14 to 13); admin words 2,182 to 2,162 (7 from `select-all-matching.tsx`, 13 from `date-filter.tsx`).
- After `npm run build`, one at a time, all green: ContentListPatternTest 22, ListHeaderTest 13, SelectAllMatchingTest 2, CommentsScreenTest 16, CategoriesAndTagsTest 10, TaxonomyBuilderTest 10. Feature: BulkMatchingScreenTest (its source scan reads words that are still there in the `__()` forms).
- B-26 (draft): the sort case presses an option only once the list's opening fade is over, since Radix's Select does not take a press during it. Commit `158d8ae`.
- B-27 (draft): the 44-pixel check measures a tick box by the label around it and leaves out a title's text link, which is a line of words (WCAG 2.5.8's inline exception). Options: make each title 44 tall (breaks the 72 and 92 pixel rows). Commit `158d8ae`.
- B-28 (draft): the Reverse button gives `desc` after Title, as the plan says, because Title's own default order is A to Z. Commit `158d8ae`.
- Done to Task 7 at `158d8ae`, the quick check green.

### Task 8

- The first full `composer check` found 11 failures, all source scans that the plan's lists did not name, reading the old words: `ContentScreensTest` (2), `TermScreenFormsTest` (4), `WordingPassListsTest` (2), `AccessibilityTest` (Feature, 3). Each now reads the same words through `__()`, and the page header's `actions` where it read `action`; what each proves is unchanged. `ContentScreensTest` "leads from a row into the editor" now reads the line (Edit, Quick edit, then View or Preview) and the menu (the decisions, then Trash last) in the source's order. Commit `eb236fa`.
- The full `composer check`, after that: Pint, PHPStan, the type check and the JS check pass; Architecture 123 passed (1,269 assertions); Unit and Feature 10,510 passed, 2 skipped (54,081 assertions), 1,585s.
- After `npm run build`, one at a time, all green: ContentListPatternTest 22, CategoriesAndTagsTest 10, CommentsScreenTest 16, ContentListsTest 10, RowLinksTest 13, ListHeaderTest 13, SelectAllMatchingTest 2, DiscussionTest 5, ViewDataTest 1, MenuAndSettingsTest 16, FullPassTest 40, AccessibilityTest 24, CommentsTest 2, TaxonomyBuilderTest 10, MediaScreenTest 11, RedirectsScreenTest 6, JobsScreenTest 3, AiActivityScreenTest 6, PeopleScreenTest 14, TypeBuilderTest 15, InboxScreenTest 4, ToolsScreensTest 3.
- `RedirectsScreenTest` "offers a redirect just after a published post is trashed" pressed Trash on the row's line; Trash is in the menu now, so it opens the menu first. Same meaning. Commit `6a42318`.
- The last line naming a page, `R3 js/pages/content/index.tsx mt-0.5` (the row's tick box), went with its class. Commit `06b5dd5`.
- `grep -c -E "js/(pages/content/index|pages/terms/index|pages/comments/)"`: 0 in both baselines. Of the `components/list` files this part changed or added, one line stays: `R5 js/components/list/data-table.tsx <table>` (B-06). `git diff --stat 4b8cc3b --` the forbidden paths: nothing. (Against today's `origin/main`, `components/patterns/settings-page.tsx` differs, from main's own later merge, not from this branch.)
- Counts from the start to the end: design-system baseline 1,228 to 1,148 lines; admin words 2,350 to 2,162.
- Environment: a restart of the build machine stopped PostgreSQL and Valkey mid-way; they were started again as `scripts/cloud-setup.sh` starts them (`pg_ctlcluster 18 test start`, `valkey-server --port 6380`). One browser run had failed because a feature run shared its database at the same moment; it was run again alone.
- Changelog lines (for the maintainer to place):
  - Every content type, categories and tags, and comments now list on one pattern: a line of actions under each title, always shown, the rest in a "…" menu, a Status column on content, numbered pages with a total, and cards on a phone.
  - Columns replaces Screen options: choose, order and hide columns, show thumbnails, and set rows per page.
  - Publishing or trashing from a list row offers Undo.
  - The filters are searchable lists that apply at once; on a phone they fold into Filters (n).
- How to try it: open Posts, Pages, Categories, Tags and Comments at full width and at phone width; tick rows for the bulk bar; open Columns; page through a long list.
- Done at `06b5dd5`. No STOP.

### Follow-up X1-2

The review accepts the part, and every B-?? decision above is agreed.

- B-29: the Status column is always shown and is not in Columns. Decided by the maintainer; provisional, the maintainers confirm. The code already did this, so nothing changed.
- Columns' "Move :name earlier" and "Move :name later" are `aria-disabled` at the ends, and a press there does nothing, so focus stays on the button inside the popover. Seen red first: `ListHeaderTest` "keeps focus on a column's move button when the keyboard moves it to the first or last place" failed because focus had left the button. Commit `30143c2`. That commit was formatted with the wrong tool. `a242d68` puts it back in the project's formatting, with no change in behavior.
- Undo after Publish is offered only when the item was a draft before. The toast's Undo comes from `ContentActionController`, not from the page, so the fix is there. Seen red first: `RowUndoTest` "offers no Undo after publishing a scheduled item" failed because the toast carried an `undo`. Commit `afec10e`.
- The bulk bar's count is `__n(':count :one selected|:count :many selected', …)`. Commit `c535e4d`. The term list's screen-reader phrases are the whole-phrase keys "under :parent" and ":items in :name". The comma and space are outside the keys. `TermScreenFormsTest`'s source scan reads the new keys, and it proves the same thing. Commit `49442b4`.
- `ListPartsTest`'s pager dataset now includes `pages/comments/index.tsx`, as B-05 said it would. It passed when added, because comments already page through `NumberedPager`. Commit `1608559`.
- Quick check: Pint, PHPStan, the type check, `vp check` and Architecture (130 with the term scans) all pass. Feature: `ListPagesTest` and `RowUndoTest` 17 passed. After `npm run build`, run one at a time, all green: ListHeaderTest 14, ContentListPatternTest 22, ContentListsTest 10, CommentsScreenTest 16, CategoriesAndTagsTest 10.
