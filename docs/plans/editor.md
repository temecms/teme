# Interface part: the editor

**One part, one branch.** It rebuilds the editor on the design system: the writing surface, toolbar and bubble, block handles on Tiptap's drag handle, the one block picker, the card frame, the link editor on the Combobox, the rail and card inspector, the Tools launcher with pins, panes with "Open larger", the Search and social box, and the compare page. Every current behavior and its tests keep working: autosave, revisions, focus mode, the failed-saves guard, block IDs, cards, presence, scheduling, review.

> **For agentic workers:** work task by task, in order. Steps use checkbox (`- [ ]`) syntax for tracking.

**Written 2026-11-14** for the repository `temecms/teme`. This plan is lighter (I-569): it writes out the decisions, names, permissions, tests and the delicate code, and describes the rest.

**Goal.** The editor drawn to DS 4.39, with its parts on one set of sockets a plugin will fill later, and no line in either baseline naming a file this part owns.

**Proposal:** `docs/proposals/editor.md` (its "Settled" section is binding). **Design document:** `docs/architecture/design-system.md` ("DS", by section number): 2.9 sizes, 2.10 motion, 2.11 layers, 2.12 breakpoints, 4.7 Combobox, 4.17 Menu, 4.18 Popover, 4.19 Dialog (`xl`), 4.20 Sheet, 4.27 Tabs, 4.39 the editor's parts, 4.40 smaller parts (Disclosure, Kbd, plug mark), 6.1 (the Tool, Block, Selection button and "Tab in a core box" surfaces), 6.3, section 7, section 8, section 9. Every measure this plan needs is written out below, so nothing depends on a reference page.

**Bound by:** I-3404 (Tiptap's drag handle approved), I-3436 (the Combobox on Downshift), I-3400 (the one picker, the Tools launcher), I-3415 (the Search and social box), I-3418 (a plugin's options inside a core box), I-3362 (block IDs), I-3121 (the writing area's width), I-3124 (the box's fold and the jump from Checks), I-234 (panes), I-1316 (the editor's bar), I-3439 (American English), I-3373 (every word through `__()`), I-797 (a code comment cites one I-number or one document path), I-569, I-3317, I-3369.

**Branch:** `ui/editor`, from `origin/main`. Push only this branch.

**New dependencies:** `@tiptap/extension-drag-handle-react` and `@tiptap/extension-drag-handle`, both **3.32.0** (the installed Tiptap; every `@tiptap/*` stays on one exact version, `EditorControlsTest`). **Migrations:** none. **Permissions:** none. **Operations:** none new (pins save through the existing `UpdatePreferences`). **Routes:** none. **Contract members (`packages/teme-contracts`):** none. **Tokens and `components/ui/**`:** none. **Drafts:** `B-?? (draft)` for plan faults.

---

## Where the documents and the code disagree, and what this plan does

1. **DS 4.39's sheet (864 wide, figures Column / Wide / Full) against I-3121** (the writing area fills the room between the menu and the rail; paragraphs at a reading measure; tables, images and embeds at the full width), which `EditorWritingAreaTest` proves. **I-3121 holds** (Settled 1): the page takes DS 4.39's look (surface, padding, type, order) but spans the writing column; paragraphs and headings keep `--measure` (704) centered; tables, images, galleries, embeds and video take the column's width. `EditorWritingAreaTest` is not changed.
2. **DS 4.39 draws the box open; today it starts closed (I-3124)** and `cmOpenSearch()` in `tests/Pest.php` (not editable) clicks `[data-test="search-toggle"]` and waits for `aria-expanded="true"` and `#editor-search-fields` not hidden. **The box starts folded** (Settled 2); its header strip's fold button carries `data-test="search-toggle"`, `aria-expanded` and `aria-controls="editor-search-fields"`, and the box's body is `#editor-search-fields`.
3. **DS 4.39 says the launcher pins "plugin tools"**; there are none until plugins can ship admin code. **Assistant and Share (Teme's) may be pinned too** (Settled 3); Outline and History are always on the bar and never pinnable.
4. **DS 4.39's Tools launcher search is "a combobox".** The Combobox's items cannot hold a second button (the pin), and `ui/combobox.tsx` exports no list parts. **The launcher is a search field over a list of rows** (D9), filtered with `matchesEveryWord` from `ui/combobox.tsx`; no `role="listbox"`.
5. **The one block picker needs a listbox; `ComboboxSourceTest` allows `role="listbox"` only in `components/editor/slash-menu.tsx`** (and two other parts' files), a shrink-only list. **The picker is built in `slash-menu.tsx`**, its export renamed `BlockPicker`; the file is renamed when the picker moves onto the Combobox's list parts. The allowed list is not touched.
6. **DS 4.39's Schema tab: "the type and its summary"; the reference design lets one item change its type.** No per-item field exists. **The Schema tab reads, never writes** (Settled 5).
7. **Workspace frame, live preview, the phone top bar with More, the toolbar docked above the keyboard, the review banner:** in DS 4.39 and the reference design; **not built here** (the proposal's "not in").
8. **The media picker.** The editor's six callers open `MediaModal` today. The media part builds `openMediaPicker` beside this part. **Every editor caller goes through one adapter, `components/editor/pick-media.tsx`**, which uses `MediaModal` until `openMediaPicker` is on `origin/main` and switches in Task 12 (D2).
9. **Dialogs and menus as sheets on phones** belong to the phone-overlays part, whose files are not on `origin/main` yet. **The editor opts in at Task 12 if they have merged; otherwise it is recorded** for a follow-up.

## What this part relies on

Written out so this plan stands alone.

- **The seams.** `resources/css/app.css`'s token block and every existing file in `components/ui/**` belong to the foundation; a part never edits them (STOP). This part **adds one file to `components/patterns/`**, `editor-page.tsx` (`EditorPage`, a name the design check's R7 already accepts). The media part's `components/media/media-modal.tsx` imports `useUploadTarget` from `components/editor/cards/card-environment.ts`: **that export keeps its name, path and answer.**
- **Values used here** (tokens in `app.css`): `--measure` 704px; `--rail-w` 344px (resizable 320 to 480, as built); `--ctl-h` 36, `--ctl-h-sm` 32, `--ctl-h-xs` 28 (all 44 on a coarse pointer); `--top-h` 60; `--dialog-xl-w` / `-h`; layers `z-(--z-sticky)` 10, `z-(--z-toolbar)` 20, `z-(--z-overlay)` 200, `z-(--z-float)` 300 (menus, popovers, the bubble, the block picker, the drag handle); motion `duration-(--dur-1)` 120ms, `duration-(--dur-2)` 180ms, `ease-(--ease)`; type `text-caption` 12, `text-small` 13, `text-body` 15, `text-lead` 18, `text-title`, `text-display`; colors `bg-page`, `bg-surface`, `bg-surface-2`, `bg-surface-3`, `bg-chrome`, `text-ink`, `text-ink-2`, `text-ink-3`, `border-line`, `border-edge`, `bg-accent`, `text-accent-ink`, `bg-accent-soft`, `text-warn-ink`, `text-danger-ink`; radii `rounded-sm` 6, `rounded-md` 8, `rounded-lg` 12; `shadow-raised`, `shadow-float`.
- **Components used, unchanged:** `Button`, `TextButton`, `Input`, `Textarea`, `Select`, `Combobox` (and `matchesEveryWord`), `Checkbox`, `Switch`, `Segmented`, `Field`, `Tabs`, `Popover`, `DropdownMenu`, `Dialog` (size `xl`), `Sheet`, `ScrollArea`, `Tooltip`, `Kbd`, `Notice`, `StatusPill`, `PluginMark`, `Progress`, `Spinner`, `Separator`, `Collapsible`, `Icon`.
- **The design check.** `tests/Architecture/DesignSystemTest.php` runs `tests/Fixtures/design-system-scan.mjs` over `resources/js` and `resources/css` and compares with `tests/Architecture/design-system-baseline.txt` (`rule<TAB>path<TAB>match`, the path relative to `resources/`). Rules: R1 arbitrary `[…]` values; R2 text sizes other than the six tokens; R3 spacing steps 1.5, 2.5, 3.5, 7, 9, 11, 14, 15; R4 raw colors; R5 raw `<button>`, `<select>`, `<textarea>`, `<table>`, `<details>`, `<input>` (not `hidden` or `file`), `<dialog>` outside `ui/` and `patterns/`; R6 off-token radius, shadow, weight, z-index, leading, tracking; R7 a page in `pages/**` whose root is not a page pattern, or padding, `max-w-*`, `mx-auto` on its top two levels; R8 `overflow-*-auto|scroll` outside `ui/scroll-area.tsx`; R9 `role="note"` or a status soft ground with a border or padding outside `ui/` and `patterns/`; R10 `outline-none` without a focus outline; R11 a variable or color class naming no token; R14 `transition`, `duration-N`, `ease-in/out`, `animate-*` outside `ui/` without `motion-safe:`; R15 a style object setting anything but a custom property or a position. A width the tokens do not name is set as `w-(--x)` with `style={{ '--x': '…' }}`. **Lowering:** fix the offense; run `php artisan test tests/Architecture/DesignSystemTest.php`; its first case lists what is gone; remove exactly those lines and lower that rule's number in `dsCounts()`; a rule at zero leaves `dsCounts()`. **Adding a line, raising a number or widening an allowed place is a STOP.**
- **The admin's words.** `tests/Architecture/AdminWordsTest.php` lists in `tests/Architecture/admin-words-baseline.txt` (`path<TAB>words`) every word a person reads that is not yet passed through `__()` or `__n()` from `@/lib/i18n`. New words always go through them, in American English (I-3439). Moving a listed word into `__()` makes the test list it as gone: remove that line and lower the count in its "never lets the baseline grow" case by as many. **Never raise it.**
- **Tests that read the editor's source.** About twenty feature and architecture files read editor sources with `uiComponent()`, `file_get_contents(resource_path(…))` or `guardCall()` (among them `EditorControlsTest`, `EditorSweepSourceTest`, `EditorRailTest`, `SearchSectionWiringTest`, `LinkChoicesTest`, `LinkEditorTest`, `SaveFailureTest`, `BlockIdsEditorTest`, `PasteTest`, `VisibilityRowTest`, `MetadataEditorTest`, `RevisionHistoryTest`, `PreviewLinkTest`). **Keep the strings they pin in the files they read.** A word wrapped as `__('Saved')` still contains `'Saved'`. When code a test reads must move to another file, change only the path the test reads, record it under "Tests changed", and keep the assertion.
- **Helpers in `tests/Pest.php`** (used, never edited): `cmSignIn($user)`; `cmNavigate($page, $url)` (one attempt; never two `->navigate()` in a row); `cmWaitUntil($page, $js)`; `shellSettled($page, $selector)` (a popover's or dialog's own animations; never `getAnimations({ subtree: true })`); `cmEditorSettled($page)` (needs `.teme-prose` and the folded sidebar); `cmOpenSearch($page)` (D-2 above); `cmSmallTargets($page)`; `cmAxeViolations($page)`; `cmScriptArray($page, $js)`; `cmNoJavaScriptErrors($page)`; `lscrPrefer($user, [...])`; `guardCall($module, $function, $argumentLists)` (runs an exported function of a module under `resources/js` in Node and returns its answers); `uiComponent($path)`; `uiTsxFiles()`; `pageArticleWithCards(...)`, `pageImage(...)`, `docWith(...)`, `docText(...)`, `docSave(...)`. A helper used by one new file stays in it, with that file's prefix (`esf`, `ebk`, `elk`, `etl`, `sbx`, `pins`).
- **Browser-test rules.** Run after `npm run build`, one file at a time, output redirected to a file and read, never piped through `grep` or `sed`. Select by `role`, `aria-*`, `data-slot`, `data-test`. Each new file's `beforeEach` follows `SearchSectionTest`'s.

## The decisions, written out

**D1. Owns.** Everything under `resources/js/components/editor/**`; `resources/js/pages/content/edit.tsx`, `resources/js/pages/content/compare.tsx`; the `.teme-prose` rules in `resources/css/app.css` (outside the token block); new `resources/js/components/patterns/editor-page.tsx`; `resources/js/types/editor.ts` and the `editor.pinned_tools` line of `resources/js/types/preferences.ts`; one registration in `app/Domain/Preferences/BuiltInPreferences.php`; `package.json` and `package-lock.json` for the two dependencies only; the new test files below, and the existing tests listed under "Tests that change". Nothing else.

**D2. The media picker, one contract with the media part.** The media part builds it, in `resources/js/components/media/media-picker.tsx`; this part only calls it. Written out exactly, in both parts' plans:

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

| `caller` | Title | Main button |
|---|---|---|
| `insert` | "Insert an image" | "Insert image" |
| `replace` | "Replace the image" | "Replace" |
| `featured` | "Choose the featured image" | "Set featured image" |
| `gallery` | "Add images to the gallery" | "Add :count images" (`__n`, "Add 1 image") |
| `choose` | "Choose a file" ("Choose an image" when `kinds` is images only) | `action` |

The editor's callers: the image card (`insert`, `replace`), the gallery (`gallery`, `multi: true`), the video card (`choose` with `kinds: ['video']` and "Use video", `choose` with `['image']` and "Use as poster", `choose` with `['document']` and "Add track"), the audio card (`choose`, `['audio']`, "Use audio"), the featured image (`featured`), a media field (`choose`, `action: __('Use as :name', …)`), the social image (`choose`, "Use as social image"). Each passes `uploadedFor: useUploadTarget()`.

**The adapter.** Until `openMediaPicker` is on `origin/main`, `components/editor/pick-media.tsx` gives the editor the same call over today's `MediaModal`:

```tsx
/** The editor's one way to open the media picker (D2): openMediaPicker's options, drawn with MediaModal until it merges. */
export function useMediaPicker(): { open: (options: MediaPickerOptions) => void; picker: ReactNode } {
    const [options, setOptions] = useState<MediaPickerOptions | null>(null);
    const picker = options && (
        <MediaModal
            open
            onOpenChange={(open) => {
                if (!open) {
                    setOptions(null);
                    options.onClose?.();
                }
            }}
            kinds={options.kinds ?? ['image']}
            multiple={options.multi}
            action={options.action ?? CALLER_ACTION[options.caller]}
            onChoose={(ids, rows) => {
                setOptions(null);
                options.onInsert(ids, rows);
            }}
        />
    );

    return { open: setOptions, picker };
}
```

`MediaPickerOptions` and `MediaPickerCaller` are declared in `pick-media.tsx` with the text above until Task 12 imports them from the media part. An architecture case (Task 2) keeps every other editor file off `MediaModal`.

**D3. The writing surface** (DS 4.39, with I-3121). `EditorPage` (new pattern) is the page's root: a header slot, the writing column (`bg-page`, its own scroll) and the rail slot; it carries today's `EditorShell` behavior (rail 320 to 480 by drag, `editor.rail_width`, a sheet below 1024px, `html.editor-workspace`). Inside the column, in order: Title (auto-growing, Plex Serif `text-display` 600, placeholder "Title", the label "Title" shown only while focused, `text-caption` 600 uppercase `text-ink-3`, focus as a 2px `bg-accent` bar 14px before its start), Excerpt (`text-lead`, `text-ink-2`, placeholder "Add an excerpt: one or two sentences that sell the story", the same label rule), the featured image row (`bg-surface-2`, `rounded-lg`, padding 12, gap 16: a 96×64 thumbnail, the name 500 over "Featured image · 2400 × 1600 · 412 KB · alt text set" in `text-small` `text-ink-3`, then Replace, Edit, Remove; empty: one full-width "Add a featured image"), the toolbar, the body, the counts line (Words, Characters, Reading time; `text-small` `text-ink-3`, values `text-ink-2` 500, `tabular-nums`, 32 above), the Details section, the Search and social box. The column's padding is 24 80 120 (64 inline below 1360px). The body's `.teme-prose`: Plex Serif `text-lead` at line-height 1.7; paragraphs and headings at `max-w-(--measure)` centered; H2 sans `text-title` 600 line-height 1.3; H3 sans `text-lead` 600; quote with a 3px `bg-accent` start border, italic, `text-ink-2`; code in Plex Mono `text-small` on `bg-surface-2`, `rounded-md`; links `text-accent-ink` underlined 1px; the divider 96×2 `bg-surface-4` centered; figures, tables, embeds, galleries and video at the column's width (I-3121). Empty-line placeholder: "Write, or type / for a block". The editor's ids, `data-slot="editor-body"` and `.teme-prose` stay.

**D4. Toolbar and bubble.** The toolbar keeps its rules and its source-test strings (`EditorControlsTest`: one row, never wraps, `FOLD_ORDER`, Bold, Italic, Link and Insert never fold). Look: sticky in the column, `z-(--z-toolbar)`, gap 2, padding 4 8, `rounded-lg`, `--page` at 86% with an 8px backdrop blur (a `bg-page/86` class), buttons `--ctl-h-sm` ghost `text-ink-2`, full strength at all times; the block type "Paragraph ▾" 132 wide, `text-small`; separators 1×20 `bg-line`; "+ Insert" opens the block picker (D7); Undo and Redo at the end. The bubble: `bg-surface`, `rounded-lg`, `shadow-float`, padding 4, gap 2, `z-(--z-float)`: Bold, Italic, Strikethrough, Inline code | Link | More (Underline, Superscript, Subscript, Clear formatting; then a "From plugins" group, empty today, which a selection button entry fills). Rewrite stays where it is when the editor offers it. **On touch the bubble does not open** (the toolbar has the formats; the system's own text menu is left alone).

**D5. Block handles on Tiptap's drag handle.** `components/editor/block-handle.tsx` renders `DragHandle` from `@tiptap/extension-drag-handle-react` with `nested={false}` (top-level blocks and cards). Inside it, two 28px buttons (`--ctl-h-xs`; 44 on touch), `text-ink-3`, hover `bg-surface-3`: "+" (`aria-label` "Add a block below") and the grip (`aria-label` "Block menu: :name", the block's type name; `aria-haspopup="menu"`). The gutter is 64px before every block. Visible on hover, on the current block, on a selected card and while focus is inside; on touch always for the current block. Dragging the grip: the block at 40% opacity, a 3px `bg-accent` drop line. The card frame's own `data-drag-handle` grip goes: one handle for every block. **Keyboard:** outside a list, Tab from the writing surface reaches the current block's grip and Shift Tab returns to the text; in a list, Tab keeps indenting as today. In the text, Alt Shift ↑ and ↓ move the current block, Ctrl D (⌘ D) duplicates it. Following the selection: pass `getReferencedVirtualElement` the current top-level block's rectangle while the last input was a key or a touch, and let the handle follow the pointer otherwise. If `DragHandle` cannot follow the selection this way, draw the keyboard and touch handle as a second element in the same gutter with the same buttons and record the choice.

**The block menu** (a `DropdownMenu` from the grip): "Turn into" (Paragraph, Heading, Subheading, Quote, Bulleted list, Numbered list) for text blocks; Duplicate (`Kbd` Ctrl D), Move up (Alt Shift ↑), Move down (Alt Shift ↓); "Collapse to one line" for cards; a "From plugins" group (empty today); separator; Delete (`Kbd` Del, `text-danger-ink`). Move up is disabled on the first block, Move down on the last. After Turn into, Duplicate, Move up and Move down, focus returns to the block's text (the copy's, after Duplicate); after Delete it goes to the next block, or the previous one when the last was deleted.

**Moves keep IDs, copies get new ones (I-3362).** In `components/editor/block-actions.ts`, plain functions over the editor's state:

```ts
/** The top-level block holding the selection: its position and node, or null at the document's start. */
export function currentBlock(state: EditorState): { pos: number; node: ProseMirrorNode; index: number } | null {
    const { selection } = state;

    if (selection instanceof NodeSelection && selection.$from.depth === 0) {
        return { pos: selection.from, node: selection.node, index: selection.$from.index(0) };
    }

    if (selection.$from.depth === 0) {
        return null;
    }

    const pos = selection.$from.before(1);

    return { pos, node: state.doc.nodeAt(pos)!, index: selection.$from.index(0) };
}

/** Moves the current block one place up or down. A move: the block keeps its ID (I-3362). */
export function moveBlock(state: EditorState, direction: 'up' | 'down'): Transaction | null {
    const block = currentBlock(state);
    const target = block === null ? -1 : block.index + (direction === 'up' ? -1 : 1);

    if (block === null || target < 0 || target >= state.doc.childCount) {
        return null;
    }

    const neighbour = state.doc.child(target);
    const at = direction === 'up' ? block.pos - neighbour.nodeSize : block.pos + neighbour.nodeSize;
    const offset = state.selection.from - block.pos;
    const tr = state.tr.delete(block.pos, block.pos + block.node.nodeSize).insert(at, block.node);

    return tr.setSelection(
        block.node.isAtom ? NodeSelection.create(tr.doc, at) : TextSelection.near(tr.doc.resolve(at + offset)),
    ).scrollIntoView();
}

/** Puts a copy of the current block after it. A copy: every ID in it is new (freshPastedIds, I-3362). */
export function duplicateBlock(state: EditorState, cards: readonly string[]): Transaction | null {
    const block = currentBlock(state);

    if (block === null) {
        return null;
    }

    const [copy] = freshPastedIds([block.node.toJSON() as JSONContent], blockIdsIn(state.doc.toJSON() as JSONContent, cards), cards);
    const at = block.pos + block.node.nodeSize;

    return state.tr.insert(at, state.schema.nodeFromJSON(copy)).scrollIntoView();
}
```

Dragging is a drop with `view.dragging.move === true`, which `blockIds()`' `transformPasted` already keeps (`block-ids.ts`). **Do not change `block-ids.ts`'s rule**; Task 4 proves both paths.

**D6. The card frame** (DS 4.39). `cards/card-frame.tsx` keeps its export and props. Each card: 8px above and below, `rounded-lg`. Header 40 tall: the card's type name (`text-caption` 600 uppercase `text-ink-3`), then, when selected, its tools (the image: Replace | a Width segmented Column / Wide / Full where the card has widths | Focal point), "Collapse" and "…" (28px). The header shows on hover, when selected, when collapsed and while focus is inside. Hover: a 1px `border-line` ring; selected: a 2px `bg-accent` ring (`outline-accent`). Collapsed: `bg-surface-2`, one line with the name and a preview of its text; collapsing is kept in the editor's view state only, never saved (Settled 6). The image card: caption typed in place under the picture (`text-small` `text-ink-2`, placeholder "Add a caption"); an alt-text row under it (label "Alt text" and a 32px `Input`), shown when the card is selected or when alt text is missing ("Alt text missing" in `text-warn-ink` with a triangle). The card's attributes and stored JSON do not change.

**D7. The one block picker** (DS 4.39), in `slash-menu.tsx` (see "disagree" 5), export `BlockPicker`; `SlashMenu` and the toolbar's Insert menu go. Opened from the handle's "+" (head "Insert after: :name"), from the "+" between blocks (head "Insert here"), from "/" on an empty line (head "Turn this empty line into…") and from the toolbar's Insert (head "Insert at the cursor"). A 400px floating panel, max height 480, `bg-surface`, `rounded-lg`, `shadow-float`, `z-(--z-float)`. A search field (`role="combobox"`, `aria-controls` the list, `aria-activedescendant`) from "+" and Insert; from "/" the typed text filters and the head shows "Filtering by “:text”: keep typing to narrow". The list (`role="listbox"`) in groups Text, Media, Cards, From plugins (each a `role="group"` with a heading); an item (`role="option"`) is 44 tall: a 32px icon square on `bg-surface-2`, the name (500) over its description (`text-small` `text-ink-3`) and its shortcut in `Kbd`. Filtering is `matchesEveryWord` over the name, the description and keywords, in the list's order. ↑ ↓ Home End move, Enter inserts, Esc closes and returns focus to what opened it. Footer (`bg-surface-2`, `text-caption`): "↑ ↓ move · Enter insert · Esc close". Nothing matches: "No block matches. Esc closes this." Everything the slash menu and Insert offered is offered (`EditorControlsTest`: the horizontal rule stays in `slash-menu.tsx`; every mark and block stays on the toolbar's source). **Between blocks:** a 12px gap; on hover or focus a 24px round `bg-accent` "+" over a 2px `bg-accent` line at 35% (`aria-label` "Add a block here"); not shown on touch. The Grid view is not built.

**D8. The link editor on the Combobox.** `link-popover.tsx` keeps its export and `use-link-choices.ts` its request (`LinkChoicesTest` pins `LinkChoicesController({ query: { q: query } })`). In the bubble, Link replaces the buttons with a 360px panel holding one `Combobox` ("Link to", `searchPlaceholder` "Search by title or name, or paste an address", `search` fed by `use-link-choices`, 200ms after typing stops). Each result is `{ value: kind + ':' + id, label: title, description: kind and address, icon }`; choosing one sets the link mark by ID as today (rule 9). When the typed text is an address (`normaliseHref` from `link-address.ts` gives one), the first option is `{ value: 'href:' + href, label: __('Link to “:address”', …), group: __('Address') }`, which sets an outside `href` as today; this site's own address is turned into the item's ID as `link-address.ts` does now. Below: "Open in a new tab" (`Checkbox`), Remove link (`TextButton`), Apply. Esc closes and returns focus to the text.

**D9. The Tools launcher** (DS 4.39). In the top bar after the save state: Outline and History (icon buttons), a divider, the pinned tools (icon buttons, a 12px plug badge on a plugin's), then "Tools :count" (ghost `sm`, plug icon, `aria-haspopup="dialog"`). It opens a 448px `Popover`: a search `Input` (`type="search"`, label "Find a tool", focused on open), then one list in groups "Pinned to the bar", "Teme", "Plugins" (A to Z), nothing listed twice. A row is 40 tall: the tool's icon, its name (500) as a button that opens it, its plugin (`text-caption` `text-ink-3`, right-aligned; empty for Teme's), its size as a small tag ("Pane", "Window", "Full screen"), and a 28px pin toggle (`aria-pressed`, `aria-label` "Pin :name to the bar" / "Unpin :name"; filled `text-accent-ink` when pinned). ↑ ↓ move between the rows' name buttons, Tab reaches the pin, Enter opens. Pinning a fourth says in a live line (`role="status"`): "Up to three tools can be pinned to the bar. Unpin one to pin this one." Footer (`bg-surface-2`): "{n} tools from {m} plugins" (`__n` for each count; "Teme's own tools" when `m` is 0) and "Manage plugins" (a link to the Plugins screen for those who may open it). Below 1360px wide the pinned icons give way first (they stay in Tools). Every tool is also a palette command (today's `usePaletteCommands`).

```ts
/** One tool in the launcher (DS 6.1's Tool surface). Core's today; a plugin's once plugins ship admin code. */
export type EditorTool = {
    id: string;
    owner: string;
    label: string;
    icon: LucideIcon;
    size: 'pane' | 'dialog' | 'workspace';
    openLarger?: boolean;
    /** Outline and History: always on the bar, never pinnable. */
    fixed?: boolean;
    /** False hides it for this item (the Assistant without AI, Share on a theme region). */
    available: boolean;
    render: () => ReactNode;
};
```

Core's tools: `overview` (label "Outline", fixed, pane), `history` (fixed, pane, `openLarger: true`), `assistant` (pane), `share` (pane). Their ids, the panes they open and the `onTogglePane` paths in `edit.tsx` stay. A `workspace` size is refused with a console warning until the workspace frame is built (no core tool has it).

**The pin rule**, in `components/editor/tools/pins.ts` (no imports, so `guardCall` runs it):

```ts
export const MAX_PINS = 3;

/** The pins after pinning or unpinning one tool, and whether a pin was refused for being a fourth. */
export function togglePin(pins: string[], id: string, pinnable: string[]): { pins: string[]; refused: boolean } {
    if (pins.includes(id)) {
        return { pins: pins.filter((pin) => pin !== id), refused: false };
    }

    if (!pinnable.includes(id)) {
        return { pins, refused: false };
    }

    if (pins.length >= MAX_PINS) {
        return { pins, refused: true };
    }

    return { pins: [...pins, id], refused: false };
}
```

**D10. Pins are the person's preference.** In `BuiltInPreferences::register()`, beside the editor's others:

```php
// Pinned to the editor's bar (DS 4.39); plugin tools join the allowed list once plugins ship admin code.
$registry->register(new PreferenceDefinition('editor.pinned_tools', 'list', ['assistant', 'share'], 'Pinned editor tools', allowed: fn (): array => ['assistant', 'share'], maxItems: 3));
```

A `list` preference is not drawn on the Preferences page (`PreferencesController` leaves lists out). The client reads `usePage().props.preferences['editor.pinned_tools']` and saves with `savePreferences({ 'editor.pinned_tools': next })` from `@/lib/preferences`, optimistically, putting the old pins back with an error toast if the save is refused. `types/preferences.ts` gains `'editor.pinned_tools': string[]`. The old `localStorage` key `teme.editor.unpinnedPanels` is no longer read or written.

**D11. Panes, and "Open larger".** The pane head (DS 4.39): 56 tall, sticky (`z-(--z-sticky)`), `bg-chrome`: Back (icon, `aria-label` "Back to the :noun settings"), the title (`text-body` 600, `tabIndex={-1}`, focused when the pane opens), the plug mark for a plugin's, "Open larger" (ghost `sm`) when the tool offers it, Close. Body padding 0 16 24, gap 16. The card inspector uses the same head. `RailPane` (in `types/editor.ts`) gains `openLarger?: boolean`.

"Open larger" shows the same pane, **with its state kept**, in `Dialog` size `xl`: head with the title, the plug mark, "Back to the pane" (ghost `sm`) and Close. Closing it returns the pane to the rail and puts focus on "Open larger". The state is kept by rendering the pane once into a node that moves, never by rendering it twice:

```tsx
/** The pane's body, rendered once into a node that moves between the rail and the larger dialog, so its state survives (DS 4.39). */
function PaneBody({ pane, large, railSlot, dialogSlot }: { pane: RailPane; large: boolean; railSlot: HTMLElement | null; dialogSlot: HTMLElement | null }) {
    const [host] = useState(() => document.createElement('div'));

    useLayoutEffect(() => {
        const slot = large ? dialogSlot : railSlot;

        slot?.appendChild(host);

        return () => {
            host.remove();
        };
    }, [host, large, railSlot, dialogSlot]);

    return createPortal(pane.render(), host);
}
```

A `dialog`-size tool opens straight into the same dialog, with no rail pane behind it.

**D12. The Search and social box** (I-3415, DS 4.39). `components/editor/search-social/` holds it; `rail/search-panel.tsx` keeps `SearchPanel`'s export and props (`SearchSectionWiringTest`, `edit.tsx`) and draws the box. Under the article, the column's width, at most 760 (`max-w-(--form-max)`) centered, `bg-surface`, `rounded-lg`, `shadow-raised`, `data-test="search-section"` kept.

| Part | Rule |
|---|---|
| Header strip | `bg-chrome`, min height 52, padding 0 16 0 24: the name "Search and social" (an `h2` with today's id, focusable, I-3124's jump target), a summary (the result's title and the check line, `data-test="search-check-line"` kept), and the fold button (`data-test="search-toggle"`, `aria-expanded`, `aria-controls="editor-search-fields"`, `aria-label` "Open Search and social" / "Fold Search and social"). Starts folded (Settled 2) |
| Body | `id="editor-search-fields"`, `hidden` when folded; nothing in it is reachable by Tab while hidden |
| Tabs | `Tabs`, one panel on screen: Search, Social, Schema, Advanced (`orderTabs`, below). Values typed in one tab are kept when another is shown and when the box folds |
| Rows | A two-column grid from 640px of box width: the label and its length guidance in a 300px left column on `bg-surface-2`, the field at full width on the right. Narrower, they stack (label and guidance above the field); the tabs scroll |
| Search | The result preview at true size (600 wide on Desktop, 400 on Mobile, from `search-previews.tsx`), with a `Segmented` Desktop / Mobile ("Result size"; Mobile by default under 640px); the SEO title (placeholder: the site's template applied, until typed); the meta description (`Textarea`). Guidance: today's counts in characters and about the pixels (I-882). The address is shown in the preview only; the rail's Publish › Address is the one place it changes |
| Social | Social title, social description: empty means "Same as search", shown as the placeholder "Same as search: :value"; the social image (`SocialImage`, through `pick-media.tsx`); one card preview. A line says what is inherited: ":count of 3 set for sharing; the rest same as search." or "Same as search, with the featured image." |
| Schema | Read only (Settled 5): the type the item is published as (from `page.seo`), a plain line of what the site leaves out of the page (`useStructuredData`, today's words), and a link to Settings › Search and social for those who may open it |
| Advanced | Today's Hide from search engines and the canonical address (`CanonicalChoice`), with their words and behavior (I-292, I-693, I-880) |
| Checks | Core's checks (`coreChecks()`), after the fields of every tab, never above them; each check's Fix opens the tab and focuses the field |

Every value is the built-in field it is today, saved with the item's revision; nothing about saving changes. **Tab placement**, in `components/editor/search-social/tab-order.ts` (no imports):

```ts
export type BoxTab = { id: string; owner: string; position?: 'after-search' | 'after-social' | 'last'; order?: number };

const CORE = ['search', 'social', 'schema', 'advanced'];
/** Core's tabs sit at 0 to 3; an added tab sits between them. */
const SLOTS = { 'after-search': 0.5, 'after-social': 1.5, last: 3.5 } as const;

/** Core's tabs in their order, and each added tab where its author declared it; Search is always first (I-3418). */
export function orderTabs(added: BoxTab[]): string[] {
    const core = CORE.map((id, index) => ({ id, slot: index, order: 0 }));
    const placed = added
        .filter((tab) => tab.owner !== 'core' && !CORE.includes(tab.id))
        .map((tab) => ({ id: tab.id, slot: SLOTS[tab.position ?? 'last'], order: tab.order ?? 0 }));

    return [...core, ...placed]
        .sort((a, b) => a.slot - b.slot || a.order - b.order || a.id.localeCompare(b.id))
        .map((tab) => tab.id);
}
```

The box draws core's four tabs from this order today; a plugin's tab entry joins `added` once plugins ship admin code. `EditorSeamsTest` proves the rule.

**D13. The rail and the card inspector** (DS 4.39). The action bar pinned at the top (padding 16, gap 8): Preview (ghost `sm`, eye), Save draft (neutral `sm`; "Save" when published), then the split primary (Publish, Schedule, Update, Submit for review, Approve, as today's rules give). The Publish section never collapses and holds the Publish button only (I-3404), then its summary rows (96px key column): Visibility, Discussion, Publish, At, Address, Template, Author, History. Sections, each collapsible: a 44px header button (title `text-body` 600, a plug mark for a plugin's, a summary at the end in `text-small` `text-ink-3`, a chevron), body padding 4 12 16, gap 12: Categories and tags (the term picker as built), Checks, then plugin sections. Move to trash at the foot. Every rule the rail has today stays, with its `data-test` values and the words its source tests pin.

**D14. The compare page.** `pages/content/compare.tsx` renders `EditorPage` with no rail; `diff-view.tsx` keeps its export, drawn with tokens (added `bg-ok-soft`, removed `bg-danger-soft` with a strike, both with a text label for those who do not see color). Behavior unchanged.

**D15. Tablet and touch** (DS 2.12). From 768 to 1023 the rail is a sheet opened from the top bar, as today; selecting a card no longer opens it. On touch: the current block's handle and "+" always show (44 each), the "+" between blocks does not, the bubble does not open, card heads always show. The phone's top bar with More and the docked toolbar are not built.

**D16. Words.** Every word in a file this part changes goes through `__()` or `__n()` in American English. The names here are the words; new ones are listed under "Every word".

---

## Global Constraints

- **Rule 1:** pins save through `UpdatePreferences` (via the existing preferences route); no controller or component touches a model. No other state changes are added.
- **Rule 3:** PostgreSQL only. **Rule 5:** `packages/teme-contracts` is not touched. **Rule 7:** content stays Tiptap JSON; no node, mark or attribute is added or removed (`EditorControlsTest`, `BlockIdsEditorTest`, `PasteTest`). **Rule 9:** internal links stay by ID.
- **Dependencies:** `@tiptap/extension-drag-handle-react` and `@tiptap/extension-drag-handle` at exactly `3.32.0` in `package.json`. npm adds their peers to the lock: expected `@tiptap/extension-node-range` 3.32.0, `@tiptap/extension-collaboration` 3.32.0, `@tiptap/y-tiptap` 3.x, `yjs` 13.x, `y-protocols` 1.x, `lib0` 0.2.x (Settled 4). **No existing lock entry's version changes.** Never change `composer.json` or `composer.lock`.
- **No token, and no change to an existing `ui/` or `patterns/` file.** One new pattern file, `patterns/editor-page.tsx`.
- **Shared lists only shrink:** `design-system-baseline.txt`, `admin-words-baseline.txt` (and their counts), `ComboboxSourceTest`'s allowed list. At the end no line in either baseline names a file in D1.
- **Every word through `__()` or `__n()`**, American English (I-3439).
- **Tests first:** each test below is run red for the reason given before the code.
- **The `.env`:** in a fresh checkout, `cp .env.example .env` and `php artisan key:generate`. Never copy, read or edit any other `.env`.
- **Never edit** `.ai/`, `CHANGELOG.md`, `docs/roadmap.md`, `docs/architecture/*`, `tests/Pest.php`, the scanner.
- **Conventional Commits**, staged by file (never `git add -A`), no closing keywords, written for a public reader. A code comment cites at most one I-number or one document path (I-797).
- `components/ui/*` keeps shadcn's formatting; every other file follows `npm run check:fix`; PHP files `vendor/bin/pint --dirty --format agent`.
- **The quick check** after each task: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, `npm run types:check`, `npm run check`, `php artisan test --testsuite=Architecture`, the feature files that read editor sources (`php artisan test tests/Feature/Admin tests/Feature/Cms/Cards tests/Feature/Cms/Contents tests/Feature/Ai`), and the task's own test files.

## Unattended work

This part is built without anyone to ask. **Decide small things yourself and record them**: under Records › Decisions, one entry per choice, as `B-?? (draft) N: <the choice>. Found: <what made it a choice>. Options: <each one>. Chose <which>, because <why>. Commit <hash>.` A small thing is anything that does not hit a STOP condition: a name, a measure the DS leaves open, the order of two rows, which of two equivalent APIs to use, a test helper's shape. When the plan and the code disagree on something that is not a STOP, follow the proposal's meaning, record the disagreement, and go on.

## When the work is interrupted

- Each task ends committed and pushed, with one line in Records: "Done to Task N at `<hash>`, the quick check green".
- **Node missing:** `export PATH=/opt/node24/bin:$PATH`; still missing: the design and words checks skip as "a known gap, not a pass"; never commit a baseline or count no run produced; stop, push, record "waiting for Node".
- **`npm install` cannot reach the registry:** stop before Task 1's install, push, record "waiting for the network".
- **PostgreSQL stops:** `docker compose up -d`, then run the check again before believing a failure.
- **A test red at the base and not this part's:** record it; never change it.
- **Out of room:** stop at the last green checkpoint, push, record where it is and what comes next.

---

## Task 0: Preconditions, then branch

Run against `origin/main`. A line that differs **by its text** is recorded; stop only if a decision above no longer holds.

- [ ] **Step 1:** `git fetch origin`. Then:
  - `git log --oneline --fixed-strings --grep="feat(admin): the design system and the admin's new frame" origin/main` → one commit; `git log --oneline origin/main | grep -c "ui/combobox"` → at least `1`.
  - `git show origin/main:package.json | grep -c '"@tiptap/'` → `14`, each `"3.32.0"`; `grep -c "drag-handle" package.json` → `0`.
  - `npm view @tiptap/extension-drag-handle-react@3.32.0 license peerDependencies` → MIT, peers `@tiptap/extension-drag-handle`, `@tiptap/pm`, `@tiptap/react` at `3.32.0`, React 19 allowed; `npm view @tiptap/extension-drag-handle@3.32.0 license peerDependencies` → MIT, peers `@tiptap/core`, `@tiptap/pm`, `@tiptap/extension-node-range`, `@tiptap/extension-collaboration` at `3.32.0`, `@tiptap/y-tiptap` `^3.0.7`.
  - `grep -n "^export" resources/js/components/ui/combobox.tsx` → `ComboboxOption`, `ComboboxProps`, `matchesEveryWord`, `Combobox`.
  - `grep -n "export function useUploadTarget" resources/js/components/editor/cards/card-environment.ts` → one line.
  - `git grep -l "MediaModal" -- resources/js/components/editor resources/js/pages/content` → `cards/audio-card.tsx`, `cards/gallery-card.tsx`, `cards/image-card.tsx`, `cards/video-card.tsx`, `fields/media-control.tsx`, `rail/social-image.tsx`.
  - `ls resources/js/components/media/media-picker.tsx 2>&1` → record whether it exists (Task 12 depends on it).
  - `grep -n "search-toggle\|editor-search-fields\|search-section\|search-check-line" resources/js/components/editor/rail/search-panel.tsx` → four or more lines.
  - `grep -n "teme.editor.unpinnedPanels" resources/js/components/editor/editor-header.tsx` → one line; `grep -n "editor.focus_mode_default" app/Domain/Preferences/BuiltInPreferences.php` → one line; `grep -n "maxItems" app/Domain/Preferences/PreferenceDefinition.php` → at least one line; `grep -n "export async function savePreferences" resources/js/lib/preferences.ts` → one line.
  - `grep -n "transformPasted\|view.dragging?.move" resources/js/components/editor/block-ids.ts` → two or more lines.
  - `grep -n "slash-menu.tsx" tests/Architecture/ComboboxSourceTest.php` → one line.
  - `ls resources/js/components/patterns/editor-page.tsx 2>&1` → does not exist; `grep -n "EditorPage" tests/Fixtures/design-system-scan.mjs` → one line (R7 accepts it).
  - Record: `grep -c -E "js/(components/editor/|pages/content/(edit|compare)\.tsx)" tests/Architecture/design-system-baseline.txt` (expected about 396), the same against `admin-words-baseline.txt` with `components/editor/|pages/content/(edit|compare)` (expected about 503), `dsCounts()` and AdminWordsTest's count.
  - `php -v` 8.4; `node --version` 22.18 or later; `pg_isready -h 127.0.0.1 -p 5433` answers.
- [ ] **Step 2:** `git switch -c ui/editor origin/main`. `npm ci`, `npm run build`, `composer check` once; record failures that are not this part's.

## Task 1: The drag handle's packages

- [ ] **Step 1:** Record `npm run build`'s size for the editor's chunk (the asset holding `prose-editor`), raw and gzip.
- [ ] **Step 2:** `npm install --save-exact @tiptap/extension-drag-handle-react@3.32.0 @tiptap/extension-drag-handle@3.32.0`. Check: `package.json` gains exactly those two lines; the lock gains the two and their peers (Global Constraints) and **no existing entry's version changes** (`git diff package-lock.json` read whole); licenses MIT. If `npm install` rewrites the root's `"name"` or drops a platform-only entry, put it back by hand and record it.
- [ ] **Step 3:** `php artisan test tests/Architecture/EditorControlsTest.php` green ("pins every tiptap package to one exact version"). Commit `build(deps): add Tiptap's drag handle 3.32.0 for block handles (I-3404)`. The chunk's size after is recorded in Task 4, once the handle is imported.

## Task 2: The seams: one way to the media picker, and the page pattern

**Test, written first** (`tests/Architecture/EditorSeamsTest.php`, `uses(TestCase::class)`):

| Case | What it proves |
|---|---|
| "opens the media picker from the editor only through pick-media" | No file under `components/editor/` or `pages/content/` but `components/editor/pick-media.tsx` contains `MediaModal` or `openMediaPicker(` |
| "keeps useUploadTarget where the media picker reads it" | `components/editor/cards/card-environment.ts` contains `export function useUploadTarget(` |
| "pins tools up to three, refusing a fourth and anything not pinnable" | `guardCall('components/editor/tools/pins.ts', 'togglePin', …)`: `[[], 'share', ['assistant','share']]` → pins `['share']`; three pinned plus a fourth pinnable → `refused: true`, pins unchanged; unpinning one → removed, order kept; an id not pinnable → unchanged, not refused |
| "places a plugin's tab where its author declared it, Search always first" | `guardCall('components/editor/search-social/tab-order.ts', 'orderTabs', …)`: `[]` → `search, social, schema, advanced`; `after-search` → second; `after-social` → third; `last` → after Advanced; two in one place by `order`, then by id; an added tab named `search` or owned by `core` is ignored |

- [ ] **Step 1:** write the cases; run; record the first failures (the first: six files listed; the last two: the module cannot be found).
- [ ] **Step 2:** write `pick-media.tsx` (D2), `tools/pins.ts` (D9), `search-social/tab-order.ts` (D12; make "last" sort after Advanced) and `patterns/editor-page.tsx` (D3's frame, moved from `editor-shell.tsx`, which keeps its export as a thin wrapper until Task 3 removes its callers). Move the six callers onto `useMediaPicker()`, each with the caller and words in D2's list; `{picker}` rendered where the `MediaModal` was.
- [ ] **Step 3:** run `EditorSeamsTest` green; run `MediaScreenTest`, `UploadedForTest`, `AltTextSuggestionTest` and `FieldControlsTest` (browser) after `npm run build`; all green. Quick check; commit `refactor(editor): one way to the media picker, the tool pins and the box's tab order`.

## Task 3: The writing surface, toolbar and bubble

**Files:** `patterns/editor-page.tsx`, `editor-shell.tsx`, `editor-header.tsx` (look only; the launcher is Task 9), `pages/content/edit.tsx`, `prose-editor.tsx`, `toolbar.tsx`, `bubble-menu.tsx`, `featured-image.tsx`, `fields/media-control.tsx`, `.teme-prose` in `app.css`; `tests/Browser/EditorSurfaceTest.php`.

**Tests, written first** (`EditorSurfaceTest`, helpers prefixed `esf`):

| Case | What it proves |
|---|---|
| "shows Title's and Excerpt's labels only while they are focused, and keeps their ids" | `#editor-title`, `#editor-excerpt` exist; their visible label is hidden until focus, shown with it; each has an accessible name either way |
| "counts words, characters and reading time under the body as it is typed" | Typing ten words updates the line under `[data-slot="editor-body"]` to "10" words |
| "keeps paragraphs at the reading measure and figures at the column's width" | A paragraph's width ≤ 704; an image card's width equals the column's content width (I-3121; `EditorWritingAreaTest` keeps the full proof) |
| "does not open the selection bubble on touch" | With a coarse pointer (`data-touch`), selecting a word shows no `[data-slot="bubble-menu"]`; with a mouse it shows |
| "offers Underline, Superscript, Subscript and Clear formatting under the bubble's More" | Select text, open More, the four items are there; Underline applies |
| "passes axe in light and dark with every target 24 pixels or more" | `cmAxeViolations()` empty and `cmSmallTargets()` empty, both schemes, at 1280 |

- [ ] **Step 1:** write the cases; run after `npm run build`; record the first failures.
- [ ] **Step 2:** build D3 and D4. `edit.tsx`'s default export returns `EditorPage`. Keep every id, `data-slot`, `data-test`, the focus-mode class, `html.editor-workspace`, the rail width preference and the sheet below 1024px.
- [ ] **Step 3:** run `EditorSurfaceTest`, `EditorWorkspaceTest`, `EditorWritingAreaTest`, `EditorBarTest`, `EditorLeavingTest`, `TwoEditorsTest`, `DetailsSectionTest` one at a time; all green. `DesignSystemTest` and `AdminWordsTest`: remove the lines gone for these files and lower the counts. Quick check; commit `feat(editor): the writing surface, toolbar and bubble on the design system`.

## Task 4: Block handles and the block menu

**Files:** `block-handle.tsx`, `block-actions.ts`, `prose-editor.tsx`, `use-teme-editor.ts` (the keymap: Alt Shift ↑ ↓, Mod D), `cards/card-frame.tsx` (its own grip goes); `tests/Browser/EditorBlocksTest.php`.

**Tests, written first** (`EditorBlocksTest`, helpers prefixed `ebk`; an article of three paragraphs "One", "Two", "Three" and an image card, saved with known block ids through `docSave()`):

| Case | What it proves |
|---|---|
| "moves a paragraph down with Alt Shift ↓ and back up, keeping its id, and saves the new order" | Caret in "One"; Alt Shift ↓: order Two, One, Three; the moved block's `data-id` is unchanged; after the save the stored document has the same ids in the new order |
| "moves a block from the block menu by keyboard alone: Tab to the grip, Enter, Move down" | Focus reaches the grip from the text with Tab; Enter opens `role="menu"`; "Move down" moves it; focus returns to the moved block's text; Move up is disabled on the first block |
| "drags a block by its grip, and it keeps its id" | A pointer drag of "Three"'s grip above "One": order Three, One, Two; ids unchanged (the `blockIds()` drop rule) |
| "duplicates a block with Ctrl D, and the copy gets a new id" | The copy has the same text, a different id of the node-id shape; the original keeps its own |
| "duplicates an image card from the block menu, the copy with a new id and the same picture" | The card's media id is the same; its block id is new |
| "turns a paragraph into a heading from the block menu" | "Turn into › Heading" makes it an `h2` with the same id |
| "deletes a block from the block menu and puts focus on the next block" | The block is gone; `document.activeElement` is inside the next block |
| "shows the current block's handle on touch, at 44 pixels, with no + between blocks" | With `data-touch`, the current block's grip and "+" are ≥ 44 tall; no between-blocks "+" exists |
| "passes axe with the block menu open, light and dark" | `cmAxeViolations()` empty |

- [ ] **Step 1:** write the cases; run; record the first failures.
- [ ] **Step 2:** build D5. Then plant each of these, see its case red, remove it, and record the first line: `moveBlock` given `duplicateBlock`'s copy (the id case fails); `block-ids.ts`'s `if (moved) return slice;` bypassed (the drag case fails).
- [ ] **Step 3:** run `EditorBlocksTest`, `BlockIdsTest`, `ImportImagesTest`, `FullPassTest` one at a time; all green. `php artisan test tests/Feature/Cms/Cards` green. Record the editor chunk's size now, against Task 1's (Settled 4). Baselines lowered. Quick check; commit `feat(editor): block handles on Tiptap's drag handle, and the block menu`.

## Task 5: The one block picker

**Files:** `slash-menu.tsx` (`BlockPicker`), `toolbar.tsx` (Insert opens it), `block-handle.tsx` ("+"), `prose-editor.tsx` (the between-blocks "+"), `region-entry.ts` and `insert-entries.ts` as needed; cases added to `EditorBlocksTest`.

| Case | What it proves |
|---|---|
| "inserts an image card from + with the keyboard: type two words, ↓, Enter" | The head says "Insert after: Paragraph"; the search filters by every word; Enter inserts after the block; the picker is gone; focus is in the new card |
| "turns an empty line into a heading by typing /hea and Enter" | The head says "Filtering by “/hea”: keep typing to narrow"; Enter turns the line into a heading; the typed "/hea" is gone |
| "offers in Insert everything the slash offers, in the same picker" | The option names from Insert equal those from "/" (one picker) |
| "says No block matches. and closes with Esc, focus back where it opened" | A search with no match shows the words; Esc closes; focus returns to the toolbar's Insert |
| "exposes the picker as a listbox with one highlighted option" | `role="listbox"`, groups with headings, exactly one `[role="option"][aria-selected="true"]` whose id is the search's `aria-activedescendant` |

- [ ] **Step 1:** write; run; record. **Step 2:** build D7. **Step 3:** `EditorBlocksTest`, `RewriteMenuTest`, `AddNewDraftTest`; `php artisan test tests/Architecture/EditorControlsTest.php tests/Architecture/ComboboxSourceTest.php` green. Baselines lowered. Quick check; commit `feat(editor): one block picker from +, / and Insert`.

## Task 6: The card frame

**Files:** `cards/card-frame.tsx`, `cards/card-views.tsx`, each card's view (`image`, `gallery`, `video`, `audio`, `embed`, `button`, `callout`, `code`, `imported-content`), `pasted-image-view.tsx`; cases added to `EditorSurfaceTest`.

| Case | What it proves |
|---|---|
| "shows a card's header on hover, when selected and while focus is inside" | The header (`data-slot="card-head"`) is hidden at rest and shown in each of the three states |
| "collapses a card to one line and opens it again, saving nothing" | Collapse shows one line with the name; no autosave request is sent for it; Expand restores it |
| "types an image's caption in place and shows the alt-text row when alt text is missing" | The caption typed under the picture is saved in the card's caption; with no alt text, "Alt text missing" shows and the row's input sets it |
| "passes axe with a card selected, light and dark" | `cmAxeViolations()` empty |

- [ ] **Step 1:** write; run; record. **Step 2:** build D6. **Step 3:** `EditorSurfaceTest`, `AltTextSuggestionTest`, `ImportImagesTest`; `php artisan test tests/Feature/Admin/CardMediaTest.php tests/Feature/Cms/Cards` green. Baselines lowered. Quick check; commit `feat(editor): the card frame`.

## Task 7: The link editor on the Combobox

**Files:** `link-popover.tsx`, `use-link-choices.ts`, `use-link-targets.ts`, `bubble-menu.tsx`; `tests/Browser/EditorLinkSearchTest.php` (helpers `elk`).

| Case | What it proves |
|---|---|
| "links a word to an article found by keyboard alone, stored by its id" | Select a word, Ctrl K (or the bubble's Link), type part of a title, ↓, Enter, Apply: the saved document's link mark holds the article's id and no `href` of this site |
| "links to a pasted outside address from the first option" | Typing `example.org/tide` offers "Link to “https://example.org/tide”" first; choosing it stores that `href` |
| "turns this site's own address into the item's id" | Pasting the address of a published article stores its id (rule 9) |
| "removes a link, and Esc leaves the text as it was" | Remove link unlinks; Esc closes with nothing changed and focus back in the text |

- [ ] **Step 1:** write; run; record. **Step 2:** build D8. **Step 3:** `EditorLinkSearchTest`; `php artisan test tests/Feature/Admin/LinkChoicesTest.php tests/Feature/Cms/Cards/LinkEditorTest.php` green. Baselines lowered. Quick check; commit `feat(editor): the link editor on the Combobox`.

## Task 8: The rail, the card inspector, pane heads and Open larger

**Files:** `editor-rail.tsx`, `rail/**` (but `search-panel.tsx`, Task 10), `types/editor.ts`, `history-panel.tsx`, `share-panel.tsx`, `rail/card-inspector.tsx`, `rail/document-panel.tsx`; `tests/Browser/EditorToolsTest.php` (helpers `etl`).

| Case | What it proves |
|---|---|
| "opens History as a pane with Back, its title focused, and Close" | The head's title has focus; Back returns to the sections and focus to the History control that opened it |
| "opens History larger and back, keeping where it was" | Scroll History's list (or open one revision's detail), Open larger: the `xl` dialog shows the same state; Back to the pane: the rail shows it, focus on Open larger |
| "replaces the sections with a selected card's settings, and gives them back when it is deselected" | Selecting the image card shows its pane; clicking a paragraph returns the sections |
| "keeps the Publish section open and the action bar in view while the rail scrolls" | The Publish section has no fold control; the action bar stays at the top after scrolling the rail |

- [ ] **Step 1:** write; run; record. **Step 2:** build D11, D13. **Step 3:** `EditorToolsTest`, `EditorBarTest`, `EditorPublishingTest`, `AssistantPaneTest`, `VisibilityTest`, `DiscussionTest`, `MetadataSuggestionsTest`; `php artisan test tests/Feature/Admin/EditorRailTest.php tests/Feature/Admin/EditorSweepSourceTest.php tests/Feature/Admin/VisibilityRowTest.php` green. Baselines lowered. Quick check; commit `feat(editor): the rail, pane heads and Open larger`.

## Task 9: The Tools launcher and pins

**Server test, written first** (`tests/Feature/Admin/EditorToolPinsTest.php`, helpers `pins`), through `UpdatePreferences` as `PreferencesTest` calls it:

| Case | What it proves |
|---|---|
| "pins Assistant and Share for a person who has chosen nothing" | `PreferencesFor` gives `editor.pinned_tools` = `['assistant', 'share']` |
| "keeps the pins in the order given" | `['share', 'assistant']` is stored and read back in that order |
| "refuses a fourth pin and writes nothing" | Four ids (with a duplicate-free fourth): refused; the stored row is unchanged |
| "refuses a tool that cannot be pinned, and writes nothing" | `['history']` and `['made-up']`: refused; nothing written |
| "writes nothing when authorization fails" | The same call for another person, as `PreferencesTest`'s refusal case does: refused, no row for either |

**Browser cases** (added to `EditorToolsTest`):

| Case | What it proves |
|---|---|
| "shows Outline and History, then Assistant and Share pinned, then Tools" | The bar's order, for a person with no choice yet, on an item where AI is offered |
| "unpins Share from Tools and it stays unpinned on the next visit" | The pin's `aria-pressed` goes false; the bar loses Share; a reload keeps it gone; the preferences row holds `['assistant']` |
| "pins Share again from Tools and it returns to the bar, after Assistant" | From `['assistant']`, pinning Share puts it on the bar in second place; the row holds `['assistant', 'share']`. (Only two tools are pinnable today, so the cap of three is proved by `EditorSeamsTest` and `EditorToolPinsTest`; the live line is drawn but cannot be reached until a third pinnable tool exists) |
| "finds a tool by typing in Tools, and opens it with Enter" | Type "hist": one row; Enter opens History's pane; the popover closes; focus is on the pane's title |
| "opens every tool from the command palette too" | The palette lists Outline, History, Share (and Assistant where offered) |
| "passes axe with Tools open, light and dark" | `cmAxeViolations()` empty, `cmSmallTargets()` empty |

- [ ] **Step 1:** write the feature and browser cases; run; record the first failures (the feature cases: the preference is not declared).
- [ ] **Step 2:** register `editor.pinned_tools` (D10); build D9 in `components/editor/tools/` and `editor-header.tsx`; remove the `localStorage` pins.
- [ ] **Step 3:** `EditorToolPinsTest`, `PreferencesTest`, `PreferencesPageTest` (the list is not drawn there) green; `EditorToolsTest`, `EditorBarTest`, `AssistantPaneTest` green. Baselines lowered. Quick check; commit `feat(editor): the Tools launcher, with up to three tools pinned per person`.

## Task 10: The Search and social box

**Files:** `rail/search-panel.tsx`, `components/editor/search-social/**`, `rail/search-previews.tsx`, `rail/social-image.tsx`, `rail/canonical-choice.tsx`, `rail/advanced-fold.tsx`, `rail/checks.ts` (words only; its entries and `owner: 'core'` stay), `rail/checks-panel.tsx`, `edit.tsx` (`goToSearch`, `goToCheck`); `tests/Browser/SearchAndSocialBoxTest.php` (helpers `sbx`); `tests/Browser/SearchSectionTest.php` (see "Tests that change").

| Case | What it proves |
|---|---|
| "shows Search, Social, Schema and Advanced as tabs, one panel at a time, Search first" | Four `role="tab"`; one visible `role="tabpanel"`; ← → move between tabs |
| "shows the result at true size, 600 wide on Desktop and 400 on Mobile, with the address only in the preview" | The preview's width changes with the switch; no field in the box holds the address |
| "keeps Social the same as search until something is typed there" | Social's title placeholder reads "Same as search: " and the SEO title; typing one sets only it; the line says "1 of 3 set for sharing; the rest same as search." |
| "lays the label and guidance in a left column, stacking them on a narrow box" | At 1280 the label's left edge is left of the field's; at 375 the label is above the field |
| "puts the checks after the fields, and a check's Fix opens its tab and focuses its field" | The checks list follows the fields in DOM order; Fix on the description's check focuses the meta description |
| "keeps what was typed across tabs and a fold" | Type in Search, switch to Social, fold, unfold: the text is there; it saves with the item |
| "passes axe open on each tab, light and dark, at 1280 and 375" | `cmAxeViolations()` empty, `cmSmallTargets()` empty |

- [ ] **Step 1:** write; run; record. **Step 2:** build D12. **Step 3:** `SearchAndSocialBoxTest`, `SearchSectionTest` (changed as listed), `SearchAndSocialSettingsTest`, `MetadataSuggestionsTest`, `FieldControlsTest`, `EditorPublishingTest`; `php artisan test tests/Feature/Admin/SearchSectionWiringTest.php tests/Feature/Ai/Metadata` green. Baselines lowered. Quick check; commit `feat(editor): Search and social as a box with tabs under the article (I-3415)`.

## Task 11: The compare page, tablet and touch, and the remaining words

**Files:** `pages/content/compare.tsx`, `diff-view.tsx`, `restore-button.tsx`, and any owned file that still has a baseline line; cases added to `EditorSurfaceTest`.

| Case | What it proves |
|---|---|
| "compares two revisions on the editor's page, with added and removed marked in words as well as color" | The page's root is `EditorPage`; each change has a text label |
| "opens the rail over the writing from the top bar on a tablet, and selecting a card does not open it" | At 900 wide the rail is a sheet; selecting a card leaves it closed |

- [ ] **Step 1:** write; run; record. **Step 2:** build D14, D15; then work through every remaining baseline line for D1's files until `grep -c -E "js/(components/editor/|pages/content/(edit|compare)\.tsx)" tests/Architecture/design-system-baseline.txt` → `0` and the same for the words baseline → `0`. **Step 3:** `php artisan test tests/Feature/Cms/Contents/RevisionComparisonTest.php tests/Feature/Cms/Contents/RevisionHistoryTest.php` green; `EditorSurfaceTest`. Quick check; commit `feat(editor): the compare page, and every word in the editor through the translation helper`.

## Task 12: Joining: the media picker and the phone overlays

- [ ] **Step 1:** `git fetch origin`. If `origin/main` has `resources/js/components/media/media-picker.tsx` exporting `openMediaPicker` with D2's types (compare them line by line): merge `origin/main` into `ui/editor`; in `pick-media.tsx`, import `MediaPickerOptions` and `MediaPickerCaller` from it, and make `open` call `openMediaPicker(options)`; `picker` becomes `null`, and the callers' `{picker}` lines go. `EditorSeamsTest`'s first case then allows `openMediaPicker(` in `pick-media.tsx` only (unchanged). Run Task 2's browser files. Commit `refactor(editor): open the media picker through openMediaPicker`. If it is not there, or its types differ from D2: record it; the switch is a follow-up.
- [ ] **Step 2:** If `origin/main` has the phone-overlays patterns: opt the editor's dialogs and menus in, one import each, as that part's plan lists for callers; run the files touched. Otherwise record it.

## Task 13: Gate and records

- [ ] The full `composer check` once. After `npm run build`, one at a time, the browser files: `EditorSurfaceTest`, `EditorBlocksTest`, `EditorLinkSearchTest`, `EditorToolsTest`, `SearchAndSocialBoxTest`, `SearchSectionTest`, `EditorBarTest`, `EditorPublishingTest`, `EditorWorkspaceTest`, `EditorWritingAreaTest`, `EditorLeavingTest`, `BlockIdsTest`, `TwoEditorsTest`, `AssistantPaneTest`, `AltTextSuggestionTest`, `DetailsSectionTest`, `DiscussionTest`, `FieldControlsTest`, `ImportImagesTest`, `MetadataSuggestionsTest`, `RewriteMenuTest`, `UploadedForTest`, `VisibilityTest`, `AddNewDraftTest`, `ComboboxTest`, `FullPassTest`. GitHub runs the rest after the merge.
- [ ] `grep -c -E "js/(components/editor/|pages/content/(edit|compare)\.tsx|components/patterns/editor-page\.tsx)" tests/Architecture/design-system-baseline.txt` → `0`; the same in the words baseline → `0`.
- [ ] Records: commits, tests seen red, the lock entries added, the chunk sizes, the baseline counts per rule and the words count before and after, every `B-?? (draft)`, the changelog lines, how to try it. Commit `docs(plans): editor records`; push.

---

## Tests that change (meaning kept)

| File › case | Change | Why the meaning holds |
|---|---|---|
| `SearchSectionTest` › "starts closed with the search result at Google's size, a small social card and one line for the search checks, opens in place with Edit and closes with Done (I-3124)" | "Edit" and "Done" become the header strip's fold button (`data-test="search-toggle"`); "a small social card" is checked on the Social tab | Still: starts folded, shows the result and the check line folded, opens in place and folds again |
| `SearchSectionTest` › "shows the search result and the social card as the SEO fields are typed…" | The social card is read after switching to the Social tab | Same preview updates, same counts |
| `SearchSectionTest` › "hides the item and names another item as its original, both in Advanced…" and "keeps saving while the canonical's address is half typed…" | Open the Advanced tab instead of the Advanced fold | Same fields, same saving |
| `SearchSectionTest` › "chooses the social image from the library…" | Opens from the Social tab | Same choice, same card |
| `SearchSectionTest` › "keeps everything typed when Done closes it and Edit opens it again…" | Done/Edit become fold/unfold; the canonical is on Advanced | Same: nothing typed is lost across a fold |
| `SearchSectionTest` › "opens and closes with the keyboard, keeps focus on its button, and leaves its hidden fields out of the tab order" | The button is the strip's fold button | Same |
| `EditorSweepSourceTest` and other source tests | Only where code they read moved to another file: the path read changes, the assertion stays | Same strings pinned |

Every other existing test is unchanged. Changing what a test proves, or deleting one, is a STOP.

## Every word

**New**, through `__()` or `__n()`: "Title", "Excerpt", "Add an excerpt: one or two sentences that sell the story", "Featured image", "Add a featured image", "Write, or type / for a block", "Words", "Characters", "Reading time", ":count min", "Add a block below", "Add a block here", "Block menu: :name", "Turn into", "Paragraph", "Heading", "Subheading", "Quote", "Bulleted list", "Numbered list", "Duplicate", "Move up", "Move down", "Collapse to one line", "Expand", "Delete", "From plugins", "Insert after: :name", "Insert here", "Insert at the cursor", "Turn this empty line into…", "Filtering by “:text”: keep typing to narrow", "No block matches. Esc closes this.", "↑ ↓ move · Enter insert · Esc close", "Text", "Media", "Cards", "Add a caption", "Alt text", "Alt text missing", "Collapse", "Link to", "Search by title or name, or paste an address", "Link to “:address”", "Address", "Open in a new tab", "Remove link", "Apply", "Outline", "Tools :count", "Find a tool", "Pinned to the bar", "Teme", "Plugins", "Pane", "Window", "Full screen", "Pin :name to the bar", "Unpin :name", "Up to three tools can be pinned to the bar. Unpin one to pin this one.", ":count tool|:count tools", "from :count plugin|from :count plugins", "Teme's own tools", "Manage plugins", "Couldn't save your pinned tools.", "Back to the :noun settings", "Open larger", "Back to the pane", "Search and social", "Open Search and social", "Fold Search and social", "Search", "Social", "Schema", "Advanced", "Result size", "Desktop", "Mobile", "Same as search: :value", ":count of 3 set for sharing; the rest same as search.", "Same as search, with the featured image.", "Published as :type", "Change this for every item in Settings › Search and social", "Added", "Removed", "Insert an image", "Insert image", "Replace the image", "Replace", "Choose the featured image", "Set featured image", "Add images to the gallery", "Add :count image|Add :count images", "Choose a file", "Choose an image", "Use video", "Use audio", "Use as poster", "Add track", "Use as :name", "Use as social image". **Moved into `__()` unchanged:** every word in D1's files that the words baseline lists today.

## What must not be done

- Change `block-ids.ts`'s rule, the Tiptap schema, a node, mark or attribute, or what is stored.
- Touch `components/media/**`, `components/media-drawer.tsx`, `pages/media/**` (the media part's), `components/list/**`, `pages/content/index.tsx` (the lists part's), `components/plugins/**`, `pages/plugins/**`, `components/palette/**`, `term-picker.tsx`, or any existing `components/ui/**` or `components/patterns/**` file.
- Build live preview, the workspace frame, the phone top bar with More, the docked toolbar, the review banner, the picker's Grid view, a per-item schema type, or plugin delivery of tools, tabs, picker items or bubble buttons.
- Add `role="listbox"`, `role="option"` or `role="combobox"` in any editor file but `slash-menu.tsx`; import `downshift`.
- Keep pins in `localStorage`.
- Delete a test, change what it proves, or edit `tests/Pest.php`.

## STOP conditions

Stop, write why under Records, commit and push, then stop, when:
- a dependency is needed that this plan does not name, or installing the two named changes an existing lock entry's version;
- a token or an existing `components/ui/**` or `components/patterns/**` file would have to change;
- a design-system or words baseline line would have to be added, a count raised, or `ComboboxSourceTest`'s allowed list widened;
- a test would have to be deleted, or changed beyond "Tests that change";
- a change would weaken a permission check, store an internal link by address, or let the preferences route accept another person's pins;
- a migration, or any change to stored rows, would be needed;
- another part's files (D1's "must not") would have to change.

## Security

- No route, permission or query is added. The link search keeps its route, its check and its rate limit; the preference keeps `UpdatePreferences`' check (a person changes only their own).
- `editor.pinned_tools` holds ids from an allowed list, at most three; the server refuses anything else.
- Every new text is rendered as text, never HTML.

## At the merge (for the maintainers)

- `docs/architecture/design-system.md` 4.39: the writing area keeps I-3121 (Settled 1); the box starts folded (Settled 2); Teme's Assistant and Share may be pinned (Settled 3); the Schema tab reads only (Settled 5); the block picker lives in `slash-menu.tsx` until it moves onto the Combobox's list parts.
- The media part's plan and this plan carry D2's contract; whichever merges second runs Task 12.
- Baseline conflicts with other parts: take both sides' removals, then run `DesignSystemTest` and `AdminWordsTest` and set each count to what the merged baseline holds.
- Changelog lines, at the release: "Blocks can be moved, duplicated and turned into another kind from a handle beside each one, by keyboard as well as by dragging." "One picker adds every kind of block, from +, / or Insert." "Search and social is a box under the article, with tabs and a result preview at true size." "Up to three editor tools can be pinned to the bar, and the pins follow you to any computer."

## Open points

| # | Point | Recommendation |
|---|---|---|
| 1 | I-3121 against DS 4.39's 864 sheet | I-3121 holds (Settled 1); DS updated at the merge |
| 2 | The box open or folded at first | Folded, as built and as `cmOpenSearch()` expects (Settled 2) |
| 3 | What may be pinned before plugin tools exist | Assistant and Share (Settled 3) |
| 4 | The drag handle's collaboration peers in the bundle | Accepted and measured (Settled 4); a lighter handle of our own is the alternative if the size is a problem |
| 5 | The workspace frame | With the first tool that needs one |

---

## Records

<!-- Filled in while building: commits; each test seen red (task, file, case, the failure's first line); the lock entries added and the chunk sizes; the design-system and words counts before and after; the tests changed; differences from the plan as B-?? (draft); Task 12's outcome; changelog lines; any STOP. -->
