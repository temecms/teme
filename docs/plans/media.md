# Interface part D: the media library and the one picker

**One part, one branch: `ui/media`, from `origin/main`.** It moves the Media screen onto the detail page pattern (tiles in three sizes, the list view, the detail panel, the focal point) and builds the one picker, opened by `openMediaPicker()`. It runs beside the other wave-2 parts and touches none of their files.

Steps use checkbox (`- [ ]`) syntax for tracking.

**Written 2026-11-14** for the repository `temecms/teme`. `origin/main` holds the design-system foundation, the shell, and wave 1 (the Combobox, the sign-in pages, people and account). Everything this part needs from them is written out below, under "What this part relies on"; Task 0 checks it is there. This plan is lighter (I-569): it writes out the decisions, names, permissions, tests and the delicate code, and describes the rest.

**Goal.** The library a person can look through quickly, and one picker every screen opens the same way. At the end no line of the design-system or admin-words baseline names a file this part owns.

**Tech stack** (Task 0 restates the exact versions from the locks): Laravel 13, PHP 8.4, PostgreSQL 18, Inertia 3 (`@inertiajs/react` 3.9), React 19.3, TypeScript, Tailwind 4.3, shadcn/ui "new-york" on Radix, Vite 8, Pest 5 with `pestphp/pest-plugin-browser` 5.1.

**Spec:** `docs/architecture/design-system.md` ("DS", by section number): 2.12 (breakpoints), 4.19 Dialog (`xl`), 4.20 Sheet, 4.21 Toast, 4.27 Tabs and in-page navigation, 4.35 Media tile and the list view, 4.36 Media detail panel, 4.37 Focal-point tool (the marker and keys only), 4.38 The picker, 4.40 Segmented control and Progress bar, section 5 (Detail with side panel), section 7, section 8, section 9. Every measure this part needs is also written out below.

**Bound by:** I-3400 (one picker; library tiles with facts; a detail panel with previous and next; bulk actions in one request are a separate decision), I-553 (Uploaded for), I-166 (destructive actions name what they touch), I-236 (one grid for the library and the picker), I-201 (plugin sources: later), I-3410 (people's pictures), I-3373 (every word through `__()`), I-3439 (American English), I-797 (a comment cites one I-number or one document path), I-569, I-3317.

**New dependencies, migrations, permissions, operations, routes, contract members (`packages/teme-contracts`), tokens:** none. **Server change:** one read, `pictureOf` in `MediaDetails`. **Drafts:** `B-?? (draft)` for plan faults.

---

## Where the documents and the code disagree

1. **DS 4.35 names `ui/media-tile.tsx`.** `components/ui/**` belongs to no part, and `tests/Architecture/ComboboxSourceTest.php` allows `role="listbox"` and `role="option"` outside `ui/` only in `components/media/media-grid.tsx` (its list only shrinks). **`MediaTile` is exported from `components/media/media-grid.tsx`.** The DS line is updated at the merge.
2. **DS 4.36 says the panel becomes a sheet below 1024px; `patterns/detail-page.tsx` puts the panel under the content.** The pattern is not changed. The page passes the panel only at 1024px and wider; below it, the same panel body opens in a `Sheet` from `ui/sheet.tsx` (D6).
3. **DS 4.35 remembers Grid / List in browser storage.** The product keeps it in the address (`view=list`), which the server already reads and the tests use. It stays there (proposal, Settled 2); the tile size is the one thing kept in browser storage.
4. **DS 4.36's actions include "Edit image"** (the page of 4.37 with crop previews). Not built: the focal point stays in the panel (Settled 3). No "Edit image" button is drawn.
5. **DS 4.38's side panel lists Alt text, Caption and Credit.** The product's picker also shows Title and License today, and a test proves License is there. The side keeps all five fields (one component with the panel's).
6. **DS 4.38's tray remove button is 20px.** Targets are at least 24px (section 7): the button is 24px with a 20px circle drawn inside.
7. **Ownership.** This part owns the files under "Owns". `pages/media/index.tsx` uses `components/list/{data-table,pager,empty-state}.tsx` (the lists part's) **as they are on `origin/main`**, never editing them. If that part changes their props first, the part merging second fixes the call site at the merge.

## What this part relies on

Written out here so this plan stands alone.

- **The seams.** `resources/css/app.css`'s token block and the existing files in `components/ui/**` and `components/patterns/**` belong to the foundation. A part may **add** new files to `ui/` and `patterns/`; it never edits an existing one or a token. This part adds none. A change to them is asked of the maintainers, who make it once on the integration branch.
- **The parts used, as they are:** `patterns/detail-page.tsx` (`DetailPage`: `title`, `description`, `actions`, `panel`, `panelLabel`, `children`; grid `minmax(0, 1fr) var(--detail-w)`, gap 24, the panel sticky at `lg`), `patterns/in-page-nav.tsx` (`InPageNav`: `label`, `items: { href, label, count?, current? }[]`), `ui/dialog.tsx` (`DialogContent size="xl"`: `min(1120px, 100vw − 80px)` × `min(740px, 100vh − 48px)`, full screen under 640px), `ui/sheet.tsx`, `ui/tabs.tsx`, `ui/segmented.tsx` (`Segmented`, `SegmentedItem`), `ui/progress.tsx`, `ui/scroll-area.tsx`, `ui/panel.tsx`, `ui/field.tsx`, `ui/button.tsx` (variants include `danger-quiet`), `ui/text-button.tsx`, `ui/tooltip.tsx`.
- **The values used here** (all tokens in `app.css`): `bg-surface`, `bg-surface-2`, `bg-surface-3`, `bg-surface-4`, `bg-accent`, `bg-accent-soft`, `text-on-accent`, `text-ink`, `text-ink-2`, `text-ink-3`, `text-warn-ink`, `border-edge`, `border-line`, `outline-ring`, `outline-accent`, `bg-photo-scrim`, `text-on-photo`, `border-on-photo`, `outline-img-outline`; `--detail-w` (360px, 320 below 1360px); radii `rounded-sm` 6, `rounded-md` 8, `rounded-lg` 12; `shadow-raised`, `shadow-modal`; type `text-caption` 12, `text-small` 13, `text-body` 15; heights `h-(--ctl-h)` 36 and `h-(--ctl-h-sm)` 32 (44 on a coarse pointer); motion `duration-(--dur-1)`, `ease-(--ease)`.
- **The design check.** `tests/Architecture/DesignSystemTest.php` runs `tests/Fixtures/design-system-scan.mjs` over `resources/js` and `resources/css` and compares with `tests/Architecture/design-system-baseline.txt` (one line per offense: `rule<TAB>path<TAB>match`, the path relative to `resources/`). The rules this part meets: **R1** arbitrary `[…]` values; **R2** text sizes other than the six tokens (`text-xs`, `text-sm`, `text-[13px]`); **R3** spacing steps 1.5, 2.5, 3.5, 7, 9, 11, 14, 15; **R4** raw colors; **R5** raw `<button>`, `<input>` (but `type="hidden"` and `type="file"`), `<select>`, `<textarea>`, `<table>` outside `ui/` and `patterns/`; **R6** bare `rounded`, `shadow`, numbered `z-`, `leading-`, `tracking-`, weights other than 400, 500, 600; **R7** a page's root that is not a page pattern (`pages/media/index.tsx` has four lines today: `root:MediaDrawer` and three frames); **R8** `overflow-auto` or `-scroll` anywhere but `ui/scroll-area.tsx`; **R9** `role="note"` outside the system; **R10** `outline-none` without a `focus-visible:` outline; **R11** a `var(--x)` naming no token (a custom property set in the same file's `style` counts as defined); **R14** motion without `motion-safe:` outside `ui/`; **R15** a `style` setting anything but a custom property or `left`, `top`, `right`, `bottom`, `transform`, `translate`. **Lowering the baseline:** fix the offense; run `php artisan test tests/Architecture/DesignSystemTest.php`; its first case lists what is gone; remove exactly those lines and lower that rule's number in `dsCounts()`. **Never add a line or raise a number.**
- **The admin's words.** `tests/Architecture/AdminWordsTest.php` lists in `tests/Architecture/admin-words-baseline.txt` every word a person reads that does not pass through `__()` or `__n()` from `@/lib/i18n`. New words always go through them. Moving a listed word into `__()` makes the test list it as gone: remove that line and lower the count in its "never lets the baseline grow" case (`toHaveCount(…)`) by as many. Words are American English (I-3439): a moved word keeps its text, except British spellings, which become American ("Licence" → "License"; the field's name `licence` and the API key stay).
- **Browser-test helpers** in `tests/Pest.php` (used, never edited): `cmSignIn(User $person)`; `cmNavigate($page, string $url)` (one attempt; never two `->navigate()` in a row); `cmWaitUntil($page, string $jsCondition)` (the plugin's `assertSee` and `assertNotPresent` look once and race the render); `shellSettled($page, string $selector)` (waits until those elements have no animation of their own); `cmEditorSettled($page)`; `cmSmallTargets($page)` (`['small' => [...], 'checked' => n]` for targets under 24px); `uiComponent(string $path)`, `uiTsxFiles()`; and from the media tests, `mediaJpeg(int $width, int $height)`.
- **Browser-test rules.** Run after `npm run build`, one file at a time, output redirected to a file and read, never piped through `grep` or `sed` (it hangs). Wait for a dialog or sheet to settle before clicking inside it (`shellSettled()` on its own selector, never `getAnimations({ subtree: true })`). Select by `role`, `aria-*`, `data-slot`, `data-test`. Run only the browser files named here; GitHub runs the full set after the merge.

## The decisions, written out

**D1. `openMediaPicker`, the signature.** In `resources/js/components/media/media-picker.tsx`. Written out; add nothing without recording a `B-?? (draft)`.

```ts
import type { MediaKind, MediaRow, UploadTarget } from '@/types';

/** What the picker is opened for: it sets the title and the main button's words (DS 4.38). */
export type MediaPickerCaller = 'insert' | 'replace' | 'featured' | 'gallery' | 'choose';

export type MediaPickerOptions = {
    caller: MediaPickerCaller;
    /** Several may be chosen; they reach onInsert in the order chosen. */
    multi: boolean;
    /** Called once, with at least one file, when the main button is pressed. */
    onInsert: (ids: string[], rows: MediaRow[]) => void;
    /** The kinds the grid offers; images when left out. */
    kinds?: MediaKind[];
    /** The main button's words where the caller's differ ("Use as poster"); required for 'choose'. */
    action?: string;
    /** The item open in the editor, which an upload made here records (I-553). */
    uploadedFor?: UploadTarget | null;
    /** Called when it closes without a choice. */
    onClose?: () => void;
};

/** Opens the one media picker over the current screen. The caller mounts nothing; focus returns to what had it. */
export function openMediaPicker(options: MediaPickerOptions): void;
```

This is the editor part's D2, word for word: the two plans hold the same contract. Do not change a name, a type or a word of it; a difference found while building is a STOP for this point only (record it, build the rest).

The words by caller (all through `__()`; `action`, when given, replaces the main button's words):

| `caller` | Title | Main button |
|---|---|---|
| `insert` | Insert an image | Insert image |
| `replace` | Replace the image | Replace |
| `featured` | Choose the featured image | Set featured image |
| `gallery` | Add images to the gallery | `__n('Add image\|Add :count images', n)` |
| `choose` | Choose a file (Choose an image when `kinds` is images only) | `action` |

**Behavior.** Opening while a picker is open replaces its options (one picker at a time). The picker opens on the Library tab with nothing chosen and the filters cleared. Single: a click or Space chooses a file (replacing the choice); double-click or Enter on a tile chooses it and inserts at once. Multi: a click or Space ticks or unticks; Enter inserts what is ticked; the tray keeps the order ticked. The main button is disabled until something is chosen. Insert calls `onInsert(ids, rows)` and then closes; Cancel, Esc and the close button close without calling it, and only then call `onClose`. Closing returns focus to the element focused before it opened (Radix's own focus return). An Inertia visit starting closes it.

**D2. How it is hosted, written out.** The picker mounts itself, so no layout or shell file changes:

```tsx
let show: ((options: MediaPickerOptions) => void) | null = null
let waiting: MediaPickerOptions | null = null

export function openMediaPicker(options: MediaPickerOptions): void {
    if (show !== null) {
        show(options)

        return
    }

    waiting = options

    // The first call mounts the one host; it lives as long as the page.
    if (document.querySelector('[data-media-picker-host]') === null) {
        const holder = document.createElement('div')
        holder.dataset.mediaPickerHost = ''
        document.body.append(holder)
        createRoot(holder).render(<MediaPickerHost />)
    }
}

function MediaPickerHost() {
    const [opened, setOpened] = useState<{ options: MediaPickerOptions; key: number } | null>(() => {
        const first = waiting
        waiting = null

        return first === null ? null : { options: first, key: 1 }
    })

    useEffect(() => {
        show = (options) => setOpened((current) => ({ options, key: (current?.key ?? 0) + 1 }))

        return () => {
            show = null
        }
    }, [])

    useEffect(() => router.on('start', () => setOpened(null)), [])

    if (opened === null) {
        return null
    }

    const close = () => {
        setOpened(null)
        opened.options.onClose?.()
    }

    return <MediaPickerDialog key={opened.key} open onOpenChange={(open) => !open && close()} {...pickerProps(opened.options)} />
}
```

`@/lib/i18n`'s words are module state and Wayfinder's routes are plain functions, so the separate root needs no provider for them. If anything the picker draws needs a provider the page tree gives (read `resources/js/app.tsx`), wrap the host in the same one and record it. If a separate root cannot work (a provider that cannot be repeated), STOP.

**D3. `MediaModal` stays, as a wrapper.** `components/media/media-modal.tsx` keeps its export and props (`open`, `onOpenChange`, `kinds`, `multiple`, `action`, `onChoose(ids, rows)`), so its ten callers do not change. Inside, it renders the same `MediaPickerDialog`, controlled, with `action` as its words and `uploadedFor` from `useUploadTarget()` as today. Its callers move to `openMediaPicker` in their own parts.

**D4. The picker's parts** (`MediaPickerDialog`, DS 4.38). `DialogContent size="xl"`. **Head:** the title (`text-lead` 600), then `Tabs` with Library and Upload (no plugin tabs: I-201), then close. **Body**, a grid `minmax(0, 1fr) 300px`: main (padding 8 24 24) and side (`bg-surface-2`, padding 20, gap 16, its own `ScrollArea`); under 768px the side goes under the grid. **Library tab:** `MediaFilters` (ids prefixed `media-modal-filters`), a hint line (`text-small` `text-ink-3`, images only: "Only images can be used here. Double-click to insert at once." or, multi, "Only images can be used here. Choose several; they go in the order you choose them."), the grid (`MediaGrid` at 160), "Show more" while there is an `older` cursor. **Upload tab:** a drop zone (`bg-surface-2`, a 2px inset `bg-surface-4` ring, min height 320, centered: an icon, "Drop files here", "They go into the library and are chosen here.", "Choose files" primary); dragging over it `bg-accent-soft` with a 2px inset `accent` ring; under it `UploadList`. Dropping anywhere on the dialog uploads, on either tab, as today ("Drop files to upload"). **The file input** (`data-test="media-modal-upload-input"`, `type="file"`, `hidden`) is mounted always, outside the tabs, so tests and "Choose files" reach it. **Side:** nothing chosen: "Select a file to see its details."; otherwise the focused file's picture, name (600, breaks anywhere), dimensions and size, and `MediaFields` (D8). **Footer:** the tray (`aria-label` "Selected"): "Selected (:count)" or "Nothing selected yet", then each chosen file as a 48px thumbnail with a 24px remove button ("Remove :name from the selection") drawing a 20px `bg-ink` circle with a `text-page` x; then the status line (`data-test="media-modal-status"`, words kept: "Select a file. Space selects; Enter confirms." / "Select one or more files. Space selects; Enter confirms." / "1 file selected" / "Uploaded 1 file. Add its alt text."), Clear (`media-modal-clear`), Cancel (`media-modal-cancel`), the main button (`media-modal-use`). The side keeps `data-test="media-modal-details"`. Uploads finished in the picker are chosen (the last alone in single), as today; "Use the existing one" chooses the library's file, as today.

**D5. Tiles and the grid** (`components/media/media-grid.tsx`, DS 4.35). Exports `MediaGrid`, `MediaTile`, `Thumb`, `TILE_SIZES`. The grid is a `ul role="listbox"` (`aria-label` "Files"; `aria-multiselectable` only in a multi picker); each tile an `li role="option"` with a roving `tabIndex` (0 on the focused tile, −1 on the rest), `aria-selected` for chosen (picker) or open (library). Tile: padding 8, radius `lg`, gap 8; hover `bg-surface-3`; open in the panel `bg-surface-3`; chosen `bg-accent-soft` and a 2px inset `accent` outline on the picture; focus `focus-visible:outline-2 outline-ring`. Picture square, radius `md`, a 1px `img-outline` outline. Name `text-small` 500, one line. Facts `text-caption` `text-ink-3`: `__n('Used :count×|Used :count×', uses)` or "Unused", then, for an image with no alt text, "No alt" in `text-warn-ink` after a 14px triangle. A file that is not an image: its extension in capitals at the picture's top end (`bg-photo-scrim`, `text-on-photo`, `text-caption` 600). Picker tiles show the tick (DS 4.35: a 24px square at the top start, radius `sm`; shown on hover, focus, when chosen and while anything is chosen; always on touch; chosen `bg-accent` with an `text-on-accent` tick). Library tiles show no tick (selection is not in).

```tsx
/** The tile widths (DS 4.35); on a phone M is two across, S three, L one. */
export const TILE_SIZES = { s: { wide: 136, phone: 96 }, m: { wide: 172, phone: 148 }, l: { wide: 228, phone: 0 } } as const

// A custom property set here, so the scanner counts it as defined (R11); 0 means one column.
const columns = (px: number) => (px === 0 ? '1fr' : `repeat(auto-fill, minmax(min(${px}px, 100%), 1fr))`)

<ul
    role="listbox"
    className="grid grid-cols-(--media-cols) gap-4 max-sm:grid-cols-(--media-cols-phone)"
    style={{ '--media-cols': columns(size.wide), '--media-cols-phone': columns(size.phone) } as CSSProperties}
>
```

The picker passes `{ wide: 160, phone: 148 }`.

**Keys, written out** (the library and the picker):

```ts
/** Where an arrow, Home or End moves focus in a grid drawn `columns` across; null for any other key. */
export function nextTile(key: string, index: number, count: number, columns: number): number | null {
    switch (key) {
        case 'ArrowRight':
            return Math.min(index + 1, count - 1)
        case 'ArrowLeft':
            return Math.max(index - 1, 0)
        case 'ArrowDown':
            return Math.min(index + columns, count - 1)
        case 'ArrowUp':
            return Math.max(index - columns, 0)
        case 'Home':
            return 0
        case 'End':
            return count - 1
        default:
            return null
    }
}

// The columns as drawn: the tiles on the first tile's row.
const drawnColumns = (list: HTMLElement) => {
    const tiles = [...list.children] as HTMLElement[]
    const top = tiles[0]?.offsetTop

    return Math.max(1, tiles.filter((tile) => tile.offsetTop === top).length)
}
```

Library: Enter or a click opens the panel; Esc in the grid with the panel open closes it. Picker: as D1.

**D6. The Media screen** (`pages/media/index.tsx`). The root is `DetailPage` (`title` "Media", `description` "Every file the site uses.", `actions` the "Upload files" primary with its hidden file input (`media-upload`, `media-upload-input` kept), `panelLabel` "File details"). Children, in order: `UploadList`; `InPageNav` (`label` "Views": All, Trash, with counts, the address as today); the filter row (`MediaFilters`, Clear filters, the Order select in All, then the tile size `Segmented` (S, M, L; `aria-label` "Tile size"; each item's name "Small tiles", "Medium tiles", "Large tiles") shown in the grid only, then a Grid / List `Segmented` replacing today's two buttons, keeping `data-test="media-layout-grid"` and `media-layout-list` and `aria-pressed` semantics as Radix's `aria-checked` gives them; the address keeps `view=list`); the grid or the list; the pager. `Head` keeps "Media". The tile size is read and written in `localStorage` key `teme.media.tile`, each access in `try`/`catch`, default `m`.

The panel: at 1024px and wider (a `useMediaQuery('(min-width: 1024px)')` in `components/media/use-wide.ts`, `true` where `window` is missing), `panel` is the panel body (D7) or, with nothing open, a `Panel` holding "Select a file to see its details." (`text-small` `text-ink-3`). Below 1024px, `panel` is `null` and the open file's panel body is inside a `Sheet` (`side="right"`, full width under 640px). Crossing 1024px with a file open moves it between the two without closing it. The list view: the same `DataTable` columns as today, words through `__()`, the "Used in" cell a `TextButton` ("Used in :count places: show them") opening the panel, the alt text "Missing" flag as the tile's "No alt" look.

**D7. The detail panel** (`components/media-drawer.tsx`, which keeps its name, so the source-reading tests keep their paths; it exports `MediaDetailPanel`, `trashSentence`, `deleteSentence`; `MediaDrawer` goes). `data-test="media-detail"`. A `Panel` (`shadow-raised`, radius `lg`) with its own `ScrollArea` up to the view's height (`lg:max-h-(--media-panel-h)` with `'--media-panel-h': 'calc(100dvh - 92px)'` set in its `style`). **Head:** `:current of :total` (the position in the rows on this page; `text-small` `text-ink-3`), Previous file and Next file (icon buttons with tooltips "Previous (←)", "Next (→)"; disabled at the ends), Close the details (tooltip "Close (Esc)"). ← and → step while focus is in the panel and not in a field; Esc closes; closing returns focus to the file's tile (or its name in the list). **Body** (padding 0 20 20, gap 16): the trash notice when trashed (`ui/notice` look: "In the trash since :date." and "Items that use it show nothing in its place until you restore it."); the picture with the focal point (D9) or, for other kinds, "Open the file"; the file name (600, breaks anywhere); facts as a two-column `dl` (`text-small`): Type, Size (`:width × :height · :size` for images), Uploaded (`:name, :date`), Uploaded for (as today, with its link), and, when `pictureOf > 0`, Picture of: `__n(':count person|:count people', pictureOf)`; `MediaFields` (D8); Used in (each item's title through `shownTitle`, then "live" or its status, as today); actions: Download original (neutral sm, images), Replace… (neutral sm, `can.edit`), Try again (failed sizes), Move to trash (`danger-quiet` sm, `data-test="media-trash"`, `can.delete`); in the Trash view Restore (`media-restore`) and Delete permanently (`media-delete`). Both destructive ones stay inside `ConfirmAction` with `confirmLabel={__('Move to trash')}` and `confirmLabel={__('Delete permanently')}`, their sentences as today plus D10, the Undo toast as today. There is no Save button.

**D8. Saving as fields are left** (`components/media/attachment-fields.tsx`, used by the panel and the picker's side). Fields: Title, Alt text (images; its "missing" flag; help "Used wherever an article does not give its own."; the alt-text suggestion as today), Caption, Credit, License; ids as today. A field saves on `blur` when its value differs from the last saved details. The delicate part, written out:

```ts
// One save at a time, each sending every field as it stands, so a later save never undoes an earlier one.
const queue = useRef<Promise<void>>(Promise.resolve())

const saveNow = () => {
    queue.current = queue.current.then(async () => {
        const values = read() // every field and the focal point, read when this save runs, not when it was asked for
        if (same(values, saved.current)) {
            return
        }
        const fresh = await send(values) // PATCH media.update; undefined on a field's error (422)
        if (fresh !== undefined) {
            saved.current = fromDetails(fresh)
            onSaved(fresh, values.focalMoved)
        }
    })
}
```

A refused field keeps what was typed and shows the server's message under it; any other failure: the toast "That didn’t work, so nothing changed. Try again." A success: the toast "Saved." (or "Saved. Its sizes are being made again around the new focal point." when the point moved). Stepping to another file or closing the panel first runs `saveNow()` for a field that has focus (its `blur` fires before the click; nothing more is needed, and a test proves it).

**D9. The focal point** (`components/media/focal-point.tsx`, DS 4.37's marker and keys). The picture at its own ratio, radius `md`, `img-outline`, crosshair cursor, two rule-of-thirds lines each way (`border-on-photo` at 35%). The stage is focusable: `role="application"`, `aria-label` "Focal point of :name. Click, or use the arrow keys, to move it.", `aria-describedby` the hint "Click to set the focal point, or focus the picture and use the arrow keys (Shift for bigger steps)."; `aria-valuetext`-like text in an `sr-only` live span: ":across% across, :down% down". The marker: a 36px circle, a 2px `border-on-photo` ring, a 2px `photo-scrim` outer ring, a 6px `bg-on-photo` center dot, placed by `left`/`top` percentages (R15 allows them). A click places it; arrows move 1, Shift+arrows 10, kept within 0 to 100. Disabled (not focusable, no crosshair) without `can.edit`. **Saved when focus leaves the stage** after a move, through D8's queue; a click followed by focus staying saves when focus leaves.

**D10. A person's picture.** `MediaDetails::find()` adds:

```php
// Whose picture it is, counted only: names are People's to show (I-3410).
'pictureOf' => DB::table('users')->where('picture_media_id', $media->id)->count(),
```

`MediaDetailsData` gains `pictureOf: number`. `trashSentence` appends, when `pictureOf > 0`, `__n('1 person uses it as their picture and shows the site’s default picture until you restore it.|:count people use it as their picture and show the site’s default picture until you restore it.', pictureOf)`; `deleteSentence` appends `__n('1 person loses their picture.|:count people lose their picture.', pictureOf)`. The trash dialog asks even when nothing live uses the file and it is somebody's picture (today it asks always; it keeps asking always).

---

## Owns

**Create:** `resources/js/components/media/media-picker.tsx`; `resources/js/components/media/focal-point.tsx`; `resources/js/components/media/use-wide.ts`; `tests/Browser/MediaLibraryTest.php`; `tests/Browser/MediaPickerDialogTest.php`.
**Modify:** everything else in `resources/js/components/media/**`; `resources/js/components/media-drawer.tsx`; `resources/js/pages/media/index.tsx`; `resources/js/types/media.ts` (`pictureOf`); `app/Domain/Media/Queries/MediaDetails.php`; `tests/Feature/Media/MediaDetailsTest.php` (one case added); `tests/Browser/MediaScreenTest.php`; `tests/Browser/FullPassTest.php` (the `media-drawer` opener only); `tests/Feature/Admin/AccessibilityTest.php` (its two media lines only); `resources/js/pages/dev/catalogue.tsx` (one added section, `#media-picker`); `tests/Architecture/design-system-baseline.txt`, `DesignSystemTest.php`, `admin-words-baseline.txt`, `AdminWordsTest.php` (lines removed, counts lowered).
**Never:** `components/ui/**`, `components/patterns/**`, `resources/css/**`, `components/list/**`, `components/editor/**`, `pages/content/**`, `pages/tools/**`, `components/tools/**`, `components/account/**`, `components/fields/**`, any caller of `MediaModal`.

## Existing tests that change (meaning kept)

| File, case | Change | Meaning kept |
|---|---|---|
| `MediaScreenTest`, "uploads from the page header…" | `progress[aria-label^="Uploading "]` becomes `[role="progressbar"][aria-label^="Uploading "]` if `ui/progress` is not a native `progress` | One bar per file while uploading |
| `MediaScreenTest`, "uploads files dropped anywhere…, and nothing dropped while the drawer is open" | Opening by tile now shows the inline panel; the "nothing dropped" half runs at 800px wide, where the details are a sheet (`[data-slot="sheet-content"]`) | Drops upload; nothing is dropped through an open sheet |
| `MediaScreenTest`, "narrows the library…, switches layout and tabs" | The layout buttons are `Segmented` items: `aria-pressed` reads become `aria-checked` (`data-state="on"`); `nav[aria-label="Views"]` kept | Filters, layout and views work |
| `MediaScreenTest`, "moves a file to the trash…" and "deletes a file permanently…" | `shellSettled(…'[data-slot="sheet-content"]')` becomes `cmWaitUntil` on `[data-test="media-detail"]` holding the name; the `media-drawer-footer` Save assertion becomes `[data-test="media-trash"]` present | The live page is named, Undo restores; the delete dialog counts references |
| `MediaScreenTest`, "saves the details and the licence from the drawer’s footer, closes it, and says so" | Becomes "saves the details and the license as each field is left, and says so": type, Tab away, the toast "Saved.", the row holds both values | Details and license are saved, and the save is announced |
| `MediaScreenTest`, "reloads the modal’s details after a save in the drawer opened over it…" | Becomes "keeps a caption saved in the picker’s side when the title is saved after it": caption typed and left, title typed and left, both saved | An edit made while choosing is never lost to a later save |
| `MediaScreenTest`, the axe case | The drawer becomes the panel (desktop) and the sheet (at 800px) | Each state passes axe in both schemes |
| `FullPassTest`, `media-drawer` | The wait reads `[data-test="media-detail"]` instead of the sheet | The screen with a file's details passes the full pass |
| `AccessibilityTest`, two media needles | `confirmLabel="Move to trash"` → `confirmLabel={__('Move to trash')}`, same for Delete permanently | Both ask before acting |

Any other existing test that would have to change is a STOP.

## Global Constraints

- **Rule 1:** no new state change. Saving, replacing, trashing, restoring and deleting call today's routes and operations (`UpdateMediaDetails`, `ReplaceMediaFile`, `TrashMedia`, `RestoreMedia`, `DeleteMediaPermanently`, `UploadMedia`), whose refused-writes-nothing tests exist already (`tests/Feature/Media/*`); Task 0 records them. No operation is added, so no new authorization-failure test applies.
- **Rule 3:** PostgreSQL only. **Rule 5:** `packages/teme-contracts` is not touched.
- **No dependency.** Never change `composer.json`, `composer.lock`, `package.json` or `package-lock.json`.
- **Every word a person reads goes through `__()` or `__n()`** (`AdminWordsTest`). Never raise its count.
- **The design-system baseline only shrinks.** At the end, no line names `js/components/media/`, `js/components/media-drawer.tsx` or `js/pages/media/`. Adding a line, raising a number, or widening an allowed place in the scanner is a STOP.
- **No token, no change to an existing `ui/` or `patterns/` file, no new file there.**
- **The allowed list in `ComboboxSourceTest` is not widened:** `role="listbox"` and `role="option"` appear only in `components/media/media-grid.tsx` among this part's files.
- **Tests first:** each test below is run red for the reason given before the code.
- **The `.env`:** in a fresh checkout, `cp .env.example .env` and `php artisan key:generate`. Never copy, read or edit any other `.env`.
- **Never edit** `.ai/`, `CHANGELOG.md`, the decision log, `docs/roadmap.md`, `docs/backlog.md`, `docs/architecture/*`, `docs/reference/*`, `tests/Pest.php`, the scanner.
- **Conventional Commits**, staged by file (never `git add -A`), no closing keywords, written for the public. A comment cites at most one I-number or one document path (I-797).
- `tests/Architecture` files using `resource_path()` declare `uses(TestCase::class)`. Helpers used by one test file stay in it with a prefix (`ml` for `MediaLibraryTest`, `mp` for `MediaPickerDialogTest`).
- **The quick check** after each task: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, `npm run types:check`, `npm run check`, `php artisan test --testsuite=Architecture`, and the task's own test files.
- **Unattended work: decide small things yourself.** When the plan is silent or a small detail does not fit (a class, a word's order, a wait in a test), choose, and record it under Records as a `B-?? (draft)`: what was found, the options, the one taken, the commit. Only the STOP conditions stop the work.
- **When the work is interrupted:** each task ends committed and pushed, with one line in Records: "Done to Task N at `<hash>`, the quick check green". Node missing: `export PATH=/opt/node24/bin:$PATH`; if still missing, stop, push and record it. A test red at the base and not this part's: record it, never change it.

---

## Task 0: Preconditions, then branch

Run in a checkout of `origin/main`. If any line differs **by its text**, STOP and write why under Records.

- [ ] **Step 1:** `git fetch origin && git log --oneline --fixed-strings --grep="feat(admin): the design system and the admin's new frame" origin/main` → one commit; `git log --oneline --fixed-strings --grep="the combobox, one searchable list on Downshift" origin/main` → one commit. Record `origin/main`'s head.
- [ ] **Step 2:**
  - `grep -n "export function DetailPage" resources/js/components/patterns/detail-page.tsx` → one line; `grep -n "export function InPageNav" resources/js/components/patterns/in-page-nav.tsx` → one line;
  - `grep -n '^  xl: "h-(--dialog-xl-h)' resources/js/components/ui/dialog.tsx` → one line; `ls resources/js/components/ui/{segmented,tabs,progress,sheet,scroll-area,panel,text-button,tooltip}.tsx` → all exist; `ls resources/js/components/ui/media-tile.tsx 2>&1` → no such file;
  - `grep -n "'components/media/media-grid.tsx'" tests/Architecture/ComboboxSourceTest.php` → one line;
  - `git grep -n "openMediaPicker" -- resources/js` → nothing; `git grep -l "from '@/components/media/media-modal'" -- resources/js` → exactly `components/account/picture-panel.tsx`, `components/editor/cards/{audio,gallery,image,video}-card.tsx`, `components/editor/fields/media-control.tsx`, `components/editor/rail/social-image.tsx`, `components/fields/media-setting.tsx`, `components/tools/redirect-form.tsx`, `pages/content/index.tsx`;
  - `git grep -n "from '@/components/media-drawer'" -- resources/js` → `pages/media/index.tsx` and `components/media/media-modal.tsx` only;
  - `grep -n "focal_x" app/Http/Admin/Media/MediaItemController.php` → one line (the focal point exists); `grep -n "picture_media_id" app/Domain/Media/Queries/MediaDetails.php` → nothing; `grep -n "whereNull('media.deleted_at')" app/Domain/Users/Queries/PersonPictures.php` → one line (a trashed picture is not shown);
  - `grep -n 'confirmLabel="Move to trash"\|confirmLabel="Delete permanently"' tests/Feature/Admin/AccessibilityTest.php` → the two `components/media-drawer.tsx` lines and the `pages/content/index.tsx` ones (not this part's);
  - `ls tests/Browser/MediaLibraryTest.php tests/Browser/MediaPickerDialogTest.php 2>&1` → none exists;
  - `grep -c -E "js/(components/media/|components/media-drawer.tsx|pages/media/)" tests/Architecture/design-system-baseline.txt` → 63; `grep -c -E "^(components/media/|components/media-drawer.tsx|pages/media/)" tests/Architecture/admin-words-baseline.txt` → 105 (record both, and each rule's number in `dsCounts()` and `AdminWordsTest`'s count);
  - `grep -c -E "^function (cmSignIn|cmNavigate|cmWaitUntil|shellSettled|cmEditorSettled|cmSmallTargets|uiComponent|uiTsxFiles)\(" tests/Pest.php` → 8; `git grep -n "function mediaJpeg" -- tests` → one line;
  - `git grep -l -E "refuse|unauthori|AuthorizationException" -- tests/Feature/Media` → record the files that prove the five operations write nothing when refused;
  - `php -v` 8.4; `node --version` 22.18 or later; `pg_isready -h 127.0.0.1 -p 5433` answers; `php artisan test tests/Architecture/DesignSystemTest.php tests/Architecture/AdminWordsTest.php` → green.
- [ ] **Step 3:** restate the stack from `composer.lock` and `package-lock.json` in Records. `git checkout -b ui/media origin/main`.

## Task 1: Whose picture a file is

**Test, written first** (`tests/Feature/Media/MediaDetailsTest.php`, one case): "counts the people who use an image as their picture, and says nothing of who they are". Two people with `picture_media_id` set to one image, a third with another; `GET media.show` as an editor: `pictureOf` is 2; the response's JSON contains neither person's name nor email; another image answers 0. Red first on `pictureOf` missing.

- [ ] **Step 1:** write; run; record the first line.
- [ ] **Step 2:** D10's line in `MediaDetails`; `pictureOf` in `MediaDetailsData`. Green; the existing `MediaDetailsTest` cases green.
- [ ] **Step 3:** quick check; commit `feat(media): the details count whose picture a file is (I-3410)`; push; Records line.

## Task 2: Tiles, sizes and the keys

**Files:** `components/media/media-grid.tsx`; `pages/media/index.tsx` (the size control only); `tests/Browser/MediaLibraryTest.php`.

**Tests, written first** (fail first on `Timeout … waiting for` `[data-slot="segmented"][aria-label="Tile size"]` or on the facts):

| Case | What it proves |
|---|---|
| "draws tiles at three sizes, keeps the size after a reload, and is two across at M on a phone" | S, M, L set `--media-cols` to 136, 172, 228 minimums (read from the `ul`'s style); reload: L kept; at 375px wide with M, the first two tiles share a row and the third starts the next |
| "shows each tile’s facts: Used 2×, Unused, No alt, and PDF over a document" | Seeded: an image used twice with alt, an unused image without alt, a PDF |
| "moves between tiles with the arrows, Home and End, one tile in the tab order" | After each key exactly one `[role="option"][tabindex="0"]`, and it is `document.activeElement`; Down moves by the drawn columns |

- [ ] **Step 1:** write; run after `npm run build`; record.
- [ ] **Step 2:** build D5 (and the size control of D6). The library's grid has no tick. Plant `columns` returning 1 for every width: the first case red; remove. Green; `ComboboxSourceTest` green.
- [ ] **Step 3:** quick check; lower the baselines for `media-grid.tsx`; commit `feat(media): tiles in three sizes with their facts, moved by the arrow keys`; push; Records line.

## Task 3: The Media screen on the detail page pattern, and the detail panel

**Files:** `pages/media/index.tsx`; `components/media-drawer.tsx`; `components/media/{attachment-fields,attachment-details,alt-text-suggestion,media-filters,upload-list,page-drop,focal-point,use-wide}.tsx/.ts`; `tests/Browser/MediaLibraryTest.php`; `MediaScreenTest`, `FullPassTest`, `AccessibilityTest` as the table above.

**Tests, written first** (in `MediaLibraryTest`; they fail first on `[data-test="media-detail"]`):

| Case | What it proves |
|---|---|
| "opens a file’s details beside the library with Enter, says 2 of 3, steps with Next and ←, and Esc returns focus to the tile" | Panel present beside the grid at 1280 (no `[data-slot="sheet-content"]`); the position text; Next shows the third file; ← (focus on a head button) the second; Esc: no panel content, focus on the second file's tile |
| "shows the details in a sheet below 1024 pixels, and the sheet becomes the panel when the window widens" | At 900: the sheet holds the name; resize to 1280: the sheet is gone and the panel holds the name |
| "saves a field when it is left, says so, and a second field’s save keeps the first" | Type a caption, Tab: the toast "Saved."; type a credit, click Next: both saved in the database (read through `Media::find`) |
| "keeps what was typed under the field when the server refuses it" | A title over the length limit: the server's message under the Title field, the typed text still in it, nothing saved |
| "moves the focal point by keyboard, 1 and 10 at a time, and saves it when the picture is left" | Focus the stage; → twice, Shift+↓ once: the live text "52% across, 60% down"; Tab away: the toast about sizes; `focal_x` 52, `focal_y` 60 |
| "names the people whose picture it is before moving it to the trash, and before deleting it" | A person's picture: the Picture of fact "1 person"; Move to trash's dialog holds "1 person uses it as their picture"; Undo; trash, then the Trash view: Delete permanently's dialog holds "1 person loses their picture." |
| "lists files in the list view, and Used in opens the details" | `view=list`; the "Used in 2 places: show them" button opens the panel with Used in listing both |
| "passes axe with the panel open and empty, the list, and the sheet, every target 24 pixels or more, at 1280 and 375 pixels in both schemes" | `assertNoAccessibilityIssues()` in each; `cmSmallTargets()` empty |

- [ ] **Step 1:** write; run; record.
- [ ] **Step 2:** build D6, D7, D8, D9, D10's sentences. Plant D8's `read()` at request time removed (values captured when asked): the "second field's save keeps the first" case red; remove. Plant the focal stage's `onBlur` save removed: its case red; remove.
- [ ] **Step 3:** change the existing tests as the table says, then run `MediaScreenTest`, `MediaLibraryTest`, `UploadedForTest`, `AltTextSuggestionTest`, `ImportImagesTest`, and `FullPassTest --filter=media`, one at a time; all green. `AccessibilityTest` and `ContentScreensTest` green.
- [ ] **Step 4:** quick check; lower the baselines for these files; commit `feat(media): the library on the detail page, with a panel that saves as you go (I-3400)`; push; Records line.

## Task 4: The one picker

**Files:** `components/media/media-picker.tsx`; `components/media/media-modal.tsx` (the wrapper); `pages/dev/catalogue.tsx` (a `#media-picker` section: one button per caller, `data-test="catalogue-picker-<caller>"`, and one with `action` "Use as poster"; the chosen IDs written into an `output` with `data-test="catalogue-picker-chosen"`); `tests/Browser/MediaPickerDialogTest.php`; `MediaScreenTest`'s modal cases as the table says.

**Tests, written first** (they fail first on `[data-test="catalogue-picker-featured"]`):

| Case | What it proves |
|---|---|
| "opens with each caller’s title and main button" | For each of the four callers and the `action` one: the dialog's title and `media-modal-use`'s text match D1's table; gallery reads "Add 3 images" with three chosen |
| "chooses a file by keyboard alone and inserts it, then returns focus to the opener" | Tab to the featured button, Enter; Tab into the grid; → ; Space: "1 file selected"; Enter: the dialog is gone, `catalogue-picker-chosen` holds that ID, focus is on the button |
| "keeps a gallery in the order chosen, and the tray removes one" | Tick the third, first and second tiles: the tray's order; remove the first from the tray; insert: two IDs in the order third, second |
| "inserts at once on a double-click in a single choice, and Cancel and Esc call nothing" | Double-click: closed, ID written; open again, choose, Esc: nothing new written |
| "uploads in the Upload tab and chooses the finished file" | Attach to `media-modal-upload-input` on the Upload tab: a progress bar, then the tray holds the new file and the status reads "Uploaded 1 file. Add its alt text." |
| "edits alt text in the side, saved when left, and the library shows it" | Choose a file; type alt text; Tab: "Saved."; close; `/admin/media`: the tile no longer says "No alt" |
| "replaces its options when opened again from another caller" | Open featured, then (by script) `gallery`: one dialog, gallery's title |
| "passes axe on both tabs, every target 24 pixels or more, and fills the screen at 375 pixels, in both schemes" | `assertNoAccessibilityIssues()`; `cmSmallTargets()` empty; at 375 the dialog's box equals the viewport |

- [ ] **Step 1:** write; run; record.
- [ ] **Step 2:** build D1 to D4, then the wrapper (D3). Plant `key: opened.key` removed: "replaces its options…" or the filters-cleared state red (record which); remove. Plant the tray order as `rows` order: the gallery case red; remove.
- [ ] **Step 3:** change `MediaScreenTest`'s modal cases as the table says; run `MediaPickerDialogTest`, `MediaScreenTest`, `UploadedForTest`, `DetailsSectionTest`, `SearchSectionTest`, `SettingControlsTest`, `AudioVideoCardsTest`, `AccountPagesTest`, `DesignCatalogueTest`, one at a time; all green. A caller's test that fails because the picker's markup changed beyond the kept `data-test`s and ids is a STOP (those files are other parts').
- [ ] **Step 4:** quick check; lower the baselines; commit `feat(media): one picker for every screen, opened by openMediaPicker (I-3400)`; push; Records line.

## Task 5: Gate and records

- [ ] `grep -c -E "js/(components/media/|components/media-drawer.tsx|pages/media/)" tests/Architecture/design-system-baseline.txt` → 0; `grep -c -E "^(components/media/|components/media-drawer.tsx|pages/media/)" tests/Architecture/admin-words-baseline.txt` → 0. If a line cannot go without a `ui/` change, it stays, recorded with why (never a new line).
- [ ] `git diff --stat origin/main -- resources/js/components/ui resources/js/components/patterns resources/css resources/js/components/list resources/js/components/editor package.json composer.json` → nothing.
- [ ] The full `composer check` once. The browser files this part added or changed, after `npm run build`, one at a time: those Tasks 2 to 4 ran; GitHub runs the rest after the merge.
- [ ] Records filled; commit `docs(plans): interface part D records`; push.

---

## Every word

**New**, through `__()` or `__n()`: "Tile size", "Small tiles", "Medium tiles", "Large tiles", "S", "M", "L", "Grid", "List", "File details", "Select a file to see its details.", ":current of :total", "Previous file", "Next file", "Close the details", "Previous (←)", "Next (→)", "Close (Esc)", "Used :count×", "Unused", "No alt", "Type", "Size", "Uploaded", ":name, :date", "Picture of", ":count person|:count people", "Used in :count place: show them|Used in :count places: show them", D9's three, D10's two, D1's table, "Library", "Upload", "Drop files here", "They go into the library and are chosen here.", "Choose files", "Selected (:count)", "Nothing selected yet", "Remove :name from the selection", D4's two hints, "That didn’t work, so nothing changed. Try again." (moved), "Media picker" (the catalogue section, with its description and button labels). **Moved into `__()`:** every word of the owned files the admin-words baseline lists, unchanged but for British spellings (I-3439).

## What must not be done

- Edit a caller of `MediaModal`, or move one to `openMediaPicker`: each belongs to the part that owns its screen.
- Build selection or the bulk bar in the library, the Edit image page, crop, rotate or flip, plugin picker tabs, add-by-URL, or the phone's camera choice.
- Open a sheet or a second dialog over the picker.
- Show the names of the people whose picture a file is.
- Change an existing `ui/` or `patterns/` component, a token, the scanner, `tests/Pest.php`, or `ComboboxSourceTest`'s allowed list.
- Add a dependency; delete a test, or change a test's meaning.

## STOP conditions

Stop, write why under Records, commit and push, then stop, when:
- a Task 0 line differs by its text;
- the work needs a new dependency, a token, or a change to or new file in `components/ui/**` or `components/patterns/**`;
- a design-system or admin-words line would have to be added, a count raised, or `ComboboxSourceTest`'s list widened;
- a test would have to be deleted or prove something else, or a test not in "Existing tests that change" would have to change;
- another part's file would have to change (`components/list/**`, `components/editor/**`, a `MediaModal` caller, `pages/content/**`, the tools, the account, the settings fields);
- a permission check would be weakened, or a route, operation or migration added;
- D2's separate root cannot carry what the picker needs.

## Security

- No route, permission, operation or migration changes. The library keeps `can:media.upload`; the picker keeps its own check; each save, trash and delete keeps its operation's `authorize()`.
- `pictureOf` is a count; no name, email or account ID leaves the server through it.
- Private items' titles in Used in and Uploaded for stay as `MediaDetails` and `MediaUsageList` give them (I-1700).
- Text is rendered as text, never HTML.

## At the merge (for the maintainers)

- `docs/architecture/design-system.md`: 4.35, the tile is `MediaTile` in `components/media/media-grid.tsx`; the Grid / List choice is in the address; 4.36, no Edit image action until 4.37's page is built; 4.38, `openMediaPicker`'s signature (D1), the side's five fields, the tray's 24px remove target; 6, the extension surface's `openMediaPicker()` row points at D1.
- Changelog lines, at the release: "The Media library shows each file's facts on its tile, opens details beside the library, and saves each field as you leave it." "Every screen now chooses files in the same picker, with a tray of what is chosen." "Moving an image to the trash now warns when it is someone's picture."
- Baseline conflicts with other parts: take both sides' removals, then run `DesignSystemTest` and `AdminWordsTest` and set each count to what the merged baseline holds.
- `AccessibilityTest`: the lists part changes the `pages/content/index.tsx` needles beside this part's two; take both.

## Open points

| # | Point | Recommendation |
|---|---|---|
| 1 | The panel when nothing is open (the reference design hides it) | Always shown, with "Select a file to see its details." (Settled 1) |
| 2 | Grid / List in the address or the browser | The address (Settled 2) |
| 3 | The Edit image page with crop previews | Later; the focal point stays in the panel (Settled 3) |
| 4 | Names of the people whose picture it is | Count only (Settled 4) |
| 5 | When the focal point saves | When focus leaves the picture (Settled 5) |
| 6 | `MediaModal`'s callers | Stay on the wrapper until their parts move them (Settled 6) |
| 7 | Selection and the bulk bar | A separate decision with the server work it needs (I-3400) |

---

## Records

<!-- Filled in while building: Task 0's findings and the stack; commits with "Done to Task N" lines; each test seen red (task, file, case, the failure's first line); each plant and its red line; the design-system and admin-words counts before and after; every B-?? (draft) with its options and choice; any STOP; how to try it (on a local site: /admin/media, arrows and Enter, a field left; /admin/_catalogue#media-picker for each caller). -->
