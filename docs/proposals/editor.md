# The editor on the new design system

| | |
|---|---|
| Status | accepted |
| Created | 2026-11-14 |
| Authors | TemeCMS maintainers |
| Issue | none yet |
| Plan | docs/plans/editor.md |
| Release | — |
| Replaces | — |

## The problem

The editor is where people spend most of their time in Teme, and the new design system has reached it least. It took the new colors and type when the foundation merged, but its layout, menus and most of its words are older.

- Blocks can only be moved by cut and paste. Cards have their own drag handle, text blocks have none, and nothing moves a block from the keyboard.
- Blocks are added two ways, the slash menu and the toolbar's Insert, which look different and list different things.
- The link editor's search list is built by hand: no arrow keys, nothing announced to a screen reader.
- The top bar's panels are unpinned in one browser and the choice is lost on the next computer. A plugin's tool has nowhere to go.
- Search and social is a closed panel with Edit and Done buttons and one long column of fields. The approved design (I-3415) gives it tabs, a preview at true size, and a place for a plugin's tab.
- Most of the editor's words cannot be translated.

## What we will build

**The writing surface** reads as a page: title, excerpt, featured image, toolbar, body, then words, characters and reading time. Paragraphs keep a 704-pixel reading measure.

**Toolbar and bubble** keep their rules: one row that never wraps, core formats only, Bold, Italic, Link and Insert never folded. The bubble gains a More menu (Underline, Superscript, Subscript, Clear formatting).

**Block handles.** Before every block, text or card, a "+" and a grip, shown on hover, on the current block and while focus is inside; on touch, always for the current block. Dragging the grip moves the block. Clicking it opens the block menu: Turn into, Duplicate (Ctrl D), Move up (Alt Shift ↑), Move down (Alt Shift ↓), Collapse (cards) and Delete. A moved block keeps its ID; a duplicate gets a new one.

**One block picker** for "+", "/" on an empty line and Insert: a search, groups (Text, Media, Cards), and each item's description and typing shortcut.

**The card frame.** A header with the card's name and tools, shown on hover, when selected and while focus is inside. Cards collapse to one line while writing. An image takes its caption in place and shows an alt-text row when selected or when alt text is missing.

**The link editor** searches the site with the Combobox and stores the result by ID. A typed outside address is the first choice.

**The rail** keeps its action bar, Publish section and sections; a selected card replaces the sections with its settings.

**The Tools launcher.** Outline and History, up to three pinned tools, then "Tools", a searchable list of every tool, each pinnable. Pins are saved with the person's preferences and follow them to any computer. Every tool is also a command.

**Panes** open in the rail with Back, the title and Close. A pane that offers it opens larger in a large dialog, keeping what was typed, and returns to the rail. History is the first.

**Search and social** becomes a full-width box under the article. A header strip names, summarizes and folds it. Tabs, one at a time: Search, Social, Schema, Advanced. Each row has its label and length guidance in a left column and the field at full width. Search shows the result preview at true size with a Desktop and Mobile switch, the SEO title and the meta description; the address appears only in the preview and is changed in the rail's Publish › Address. Social is "Same as search" until changed, with one card preview. Schema says what the item is published as. Advanced holds Hide from search engines and the canonical address. Checks come after the fields.

## The decisions

1. **Built in place, on the editor's route.** One part rebuilds it section by section, its existing tests green after each step: autosave, revisions, focus mode, the failed-saves guard, block IDs, cards.
2. **Tiptap's drag handle** (I-3404), at the installed Tiptap version, so every Tiptap package keeps one exact version. Its collaboration peers are installed and bundled but unused.
3. **A move keeps a block's ID; a copy gets a new one** (I-3362). Dragging and Move up or down are moves; Duplicate and paste are copies.
4. **The link search uses the Combobox** (I-3436); internal links stay by ID (rule 9). The block picker keeps its own list until the Combobox's list parts are shared.
5. **Pins are a preference,** `editor.pinned_tools`: at most three tool IDs, saved through the existing UpdatePreferences operation. Assistant and Share are pinned by default, as the bar shows them today.
6. **A tool declares its size** (pane, dialog or workspace) and whether it opens larger. "Open larger" moves the same pane into the dialog without restarting it.
7. **A plugin's tab in a core box is placed by its author:** after Search, after Social, or last, with an order. Search is always first; core's tabs never move.
8. **One media picker.** The editor opens the library only through `openMediaPicker({ caller, multi, onInsert })`, which the media part builds; until it merges, through one adapter file.
9. **Plugin sockets are shaped now and filled by core.** Tools, tabs, picker items and bubble buttons carry an owner and a size or position; plugins fill them once they can ship admin code.

## In and not in

**In:** the writing surface, toolbar, bubble, block handles and menu, the block picker, the card frame, the link editor on the Combobox, the rail and card inspector, the Tools launcher and pins, pane heads and Open larger, the Search and social box, the compare page, the editor on a tablet and on touch, and every word in these files through the translation helper.

**Not in:**
- Live preview, and the workspace frame (no tool needs one yet).
- The phone's top bar with More, and the toolbar docked above the keyboard.
- The review banner on the writing page, and Update with Undo.
- The block picker's Grid view.
- A structured-data type chosen per item; "no snippet" and "no cached copy".
- The canonical choice on the Combobox.
- Plugins filling the sockets from their own code.
- Dialogs and menus as sheets on phones: the editor opts in once the phone-overlays part has merged.

## How it fits

- **Data:** no migration; stored documents and the Tiptap schema do not change.
- **Server:** one preference added to the built-in list. No route, permission or operation.
- **Dependencies:** `@tiptap/extension-drag-handle-react` and `@tiptap/extension-drag-handle` 3.32.0, with their peers.
- **Accessibility:** every block action by keyboard, Move up and Move down as the alternative to dragging (WCAG 2.5.7), focus returned to what opened a menu, pane or dialog, axe clean in light and dark, targets at least 24 pixels (44 on touch).
- **Tests:** browser tests for each part; a feature test for the pins; Node tests for tab placement and pinning. Existing editor tests keep what they prove. The design-system and words baselines only shrink.

## Settled

1. The writing area keeps I-3121: it fills the room between the menu and the rail, paragraphs keep the 704-pixel measure, and tables, images and embeds use the full width. (provisional: confirm)
2. Search and social starts folded on every visit, as today; the header strip shows the summary. (provisional: confirm)
3. Teme's Assistant and Share may be pinned, so pins are useful before plugins bring tools; Outline and History are always on the bar. (provisional: confirm)
4. The drag handle's collaboration peers are accepted in the bundle, and their size is measured. (provisional: confirm)
5. The Schema tab shows what is published and changes nothing. (provisional: confirm)
6. Collapsing a card is a view state for the writer, not saved. (provisional: confirm)
