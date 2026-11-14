# The media library and the one picker

| | |
|---|---|
| Status | accepted |
| Created | 2026-11-14 |
| Authors | TemeCMS maintainers |
| Issue | none yet |
| Plan | docs/plans/media.md |
| Release | — |
| Replaces | — |

## The problem

The Media screen and the picker still wear the admin's old frame. Tiles show a picture and a title, so nobody can see at a glance which files are unused or lack alt text. A file's details open in a sheet over the library, so looking through several files means opening and closing it each time, and they save with a Save button.

The picker's size changes with what it holds, and editing a file while choosing it opens a second panel over the dialog. Each screen that needs a file wires the picker up its own way.

And an image used as a person's picture can be moved to the trash with no warning; the picture disappears until it is restored.

The design system describes the library (sections 4.35 to 4.38, and the detail page pattern), adopted in I-3400. This part builds it.

## What we will build

**The library** is the first screen on the detail page pattern: the library on the left, the file's details in a 360px panel on the right that stays in view while the library scrolls.

- **Tiles** in three sizes, Small, Medium and Large, chosen with a segmented control and remembered in the browser. Each tile shows the picture, the file's name on one line, and its facts: "Used 3×" or "Unused", and "No alt" in the warning color when an image has no alt text. A file that is not an image shows its type ("PDF") over its picture.
- **The keyboard:** the arrow keys move between tiles, Home and End jump to the first and last, Enter opens the details, Esc closes them and returns focus to the tile.
- **The list view**, which exists today, keeps its columns (File, Type, Alt text, Credit, Used in, Uploaded) and takes the design system's look. Its "Used in" count opens the file's details, where the places are listed.
- **The All and Trash views** move onto the shared in-page navigation, and the filter row (search, type, date, mine, unused, missing alt text, uploaded for) is restyled with the same behavior.

**The detail panel** shows "2 of 24" with Previous and Next (also ← and →), and Close. Under it: the picture, the name, the facts (type, size, who uploaded it and when, what it was uploaded for), and the fields: title, alt text, caption, credit and license. **Each field saves when it is left**, and a toast confirms. Then where the file is used, and Download original, Replace, Try again (failed sizes) and Move to trash; in the Trash view, Restore and Delete permanently. Below 1024px the panel is a sheet.

**The focal point**, which exists today, stays in the panel with the design system's marker over rule-of-thirds lines. The picture can be focused; the arrow keys move the point 1%, or 10% with Shift.

**A person's picture.** The details say "2 people use it as their picture", and Move to trash and Delete permanently say so before they ask.

**The picker** is the large fixed dialog, one for every caller, opened with `openMediaPicker({ caller: 'featured', onInsert: (ids) => … })`. Its title and main button come from the caller: "Choose the featured image" and "Set featured image", "Add images to the gallery" and "Add 3 images", and so on. Its Library tab holds the filter row, a hint line and the grid; its Upload tab a drop zone with a progress bar per file, and finished uploads are chosen. The side shows the chosen file's fields, which save when left. The footer holds a tray, "Selected (2)", with a remove button on each, then Cancel and the main button. A gallery keeps the order chosen. On a phone the picker fills the screen.

## The decisions

1. **One picker, opened by a function** (I-3400). `openMediaPicker({ caller, multi, onInsert })` opens it from anywhere; the screen calling it holds no dialog state. It also takes `kinds` (images by default), `action` (for callers whose words differ, such as "Use as poster"), and `uploadedFor` (the item open in the editor, which uploads record: I-553).
2. **The existing `MediaModal` stays, as a thin wrapper over the same picker.** Ten screens outside this part open it today. They get the new picker without a change, and move to `openMediaPicker` in their own parts.
3. **No second panel over the picker.** Its side panel edits the file directly, so the details sheet that opened over the dialog is gone. A sheet never opens over a dialog (design system 4.20).
4. **Fields save as they are left** (design system 4.36). Saves run one at a time, and each sends every field as it stands, so a later save never undoes an earlier one. A field the server refuses keeps what was typed and shows why under it.
5. **The tile lives with the grid**, in `components/media/media-grid.tsx`, not `ui/media-tile.tsx`: feature parts add nothing to the shared components, and the listbox check allows that file alone.
6. **Nothing on the server changes but one read.** The file's details gain how many people use it as their picture. Saving, trashing and deleting use today's operations and routes, with their permission checks.

## In and not in

**In:** the library on the detail page pattern; tiles in three sizes with their facts and keys; the list view restyled; the detail panel with Previous and Next, saving as fields are left, and its sheet below 1024px; the focal point's new marker and keys; the person's-picture fact and warnings; `openMediaPicker` and the picker; every word in these files through the translation helper.

**Not in:**
- **Selecting several files in the library, and the bulk bar** (write alt text for several, download, move to trash). Doing these in one request needs server work, which I-3400 left as a separate decision.
- **The Edit image page** with live crop previews, and crop, rotate and flip.
- **Picker sources added by plugins** (I-201), and "Imported from" facts on tiles.
- **Moving the editor, settings, account, tools and content-list callers to `openMediaPicker`.** Each part that owns those screens does it; until then they open the same picker through `MediaModal`.
- **"Take a photo or a video"** on a phone's upload.
- Adding a file by its address: the picker has no add-by-URL (I-3400).

## How it fits

- **Components:** `components/media/**`, `components/media-drawer.tsx` (now the detail panel) and `pages/media/index.tsx`, plus one design-catalogue section to try the picker. No token or shared component changes.
- **Server:** the details gain `pictureOf`, a count. No route, permission, operation or migration changes.
- **Accessibility:** the grid is a listbox moved by the arrow keys; everything by keyboard alone; focus returns to the opener; targets at least 24px (44px on touch); axe clean in both schemes at desktop and phone widths.
- **Tests:** browser tests for the library and the picker, a feature test for the new fact. The design-system and words baselines shrink to no line for these files.
- **Dependencies:** none.

## Settled

1. The detail panel is always beside the library, with "Select a file to see its details." when nothing is open. (provisional: confirm)
2. Grid or List stays in the page's address, as today; the tile size is remembered in the browser. (provisional: confirm)
3. The focal point stays in the detail panel; the Edit image page with crop previews comes later. (provisional: confirm)
4. The picture warning gives a count, never names. (provisional: confirm)
5. The focal point saves when focus leaves the picture, and when the panel closes or moves to another file. (provisional: confirm)
6. `MediaModal` stays as a wrapper until its callers move to `openMediaPicker` in their own parts. (provisional: confirm)
