# One searchable list: the Combobox

| | |
|---|---|
| Status | accepted |
| Created | 2026-11-14 |
| Authors | TemeCMS maintainers |
| Issue | none yet |
| Plan | docs/plans/combobox.md |
| Release | — |
| Replaces | — |

## The problem

In many places a person types to find one thing in a long list: a tag, an author, a parent page, a post to link to. Each is built by hand today. The tag picker is a text field over a plain list of buttons, so the arrow keys do nothing and a screen reader hears no list. The link search, the home page chooser and the redirect form each repeat the pattern their own way.

The design system names one component for all of them, the Combobox (section 4.7). The content lists, the editor and the people screens all need it, so it comes first.

## What we will build

**The Combobox** looks like a Select: a button showing the current choice or a quiet placeholder. Clicking it, or pressing Enter, Space or ↓ on it, opens a popover with a focused search field above the list. Typing a letter on the button opens it with that letter typed.

- Typing filters: an item matches when every typed word appears in its name or keywords.
- ↑ and ↓ move, Home and End jump to the first and last item, Page Up and Page Down move ten. Enter chooses. Esc closes and changes nothing. Focus returns to the button.
- Items may sit in headed groups, with an icon, a second line and an end note.
- **One choice:** choosing closes the popover.
- **Several choices:** the popover stays open and Enter ticks or unticks. On reopening, chosen items come first, ticked. Chosen items also show beside the button as removable tags, which the arrow keys move between.
- Screen readers hear how many results there are ("3 results", "Nothing matches.") and what was added or removed.
- "Searching…" while the server is asked.
- Given a field name, it writes hidden inputs, so a plain form post carries the choice.

**The tag picker** (flat taxonomies, in the editor, Quick edit and Bulk edit) is its first user. A tag can now be found and chosen by keyboard alone.

**The design catalogue** gains a Combobox section with every state.

## The decisions

1. **Built on Downshift with the existing Radix popover** (I-3436). Downshift is actively maintained, MIT-licensed, and implements the WAI-ARIA combobox pattern: the roles, the active item, the keys, and the live announcements. It draws nothing itself, so the Combobox takes its whole look from the design system's tokens. Radix stays the only family of primitives: the popover's placement, focus handling and dismissal are the same Radix code every other popover uses.
2. **`cmdk` was considered and not taken.** It was approved earlier (I-3404) and powers many command palettes. It has had no release since March 2025, and its filtering and scoring would sit beside our own. Downshift does less and leaves the filtering to us, which is what we want.
3. **One file holds every use of Downshift:** `components/ui/combobox.tsx`. An architecture test keeps it so, which makes a later change of library one file.
4. **The button opens a popover; the search field inside is the combobox.** Each control has one combobox, and the button is announced with its label, its value and that it opens a popup. Radix moves focus into the search on open and back to the button on close. Downshift handles everything inside.
5. **Items are known by their ID.** Two posts with the same title stay two items, and a chosen item stays chosen when a new search returns it again.
6. **Matching by words, in the list's own order.** No fuzzy scoring that reorders lists people expect in their order. This is the rule the command palette already uses.
7. **Chosen means ticked.** Downshift marks the highlighted item as selected, as the ARIA pattern asks. In a list with several choices, chosen items carry a tick and are announced as checked, so highlighted and chosen are never confused.
8. **Leaving changes nothing.** Moving focus away or pressing Esc never chooses the highlighted item and never clears a choice. Only Enter or a click chooses.
9. **The Combobox does not fetch.** The screen using it searches and passes results in, keeping permissions and addresses with the screen that owns them.

## In, and not in

**In:** the `downshift` dependency; the Combobox; the tag picker on it; the catalogue section; every word in these files through the translation helper.

**Not in:**
- **The command palette.** It keeps its current code, already restyled to the design system, and moves onto the Combobox's list parts in a later part.
- Screens that later parts rebuild adopt the Combobox then: the list's category and author filters (I-1452), a term's parent (I-1490), the author in Quick and Bulk edit, the editor's link search and button target, the Tools launcher, the home page chooser, the menu editor's search, the redirect form, the importer's person, and who receives a removed person's content.
- Creating a tag by typing its name (it needs its own permission check and operation).
- Popovers as sheets on phones, a change to every popover with its own part.
- The category tree, which stays a tree of tick boxes.

## How it fits

- **Components:** `ui/combobox.tsx` (new); the tag picker (rebuilt, same props, so its three callers do not change); one catalogue section. It uses the existing Popover, Field, ScrollArea, Spinner and the removable status pill; no token or existing component changes.
- **Server:** unchanged. The term search keeps its route, permission check and rate limit.
- **Accessibility:** the WAI-ARIA combobox pattern; every path by keyboard alone; focus back to the opener; result counts announced; items at least 24px tall (44px on touch); axe clean in light and dark, desktop and phone.
- **Tests:** browser tests for the Combobox in the catalogue (keys, several choices, Esc and leaving, two items with one name, announcements, axe) and for choosing a tag by keyboard in the editor; an architecture test keeping Downshift in one file. The design-system baseline only shrinks, to no line for these files.
- **Dependencies:** `downshift` 9.4.0. It brings a few small dependencies of its own; nothing already installed changes.

## Settled

The questions this proposal raised, as decided:

1. Typing a new tag creates it, for people allowed to create tags.
2. With several choices, the button shows their names, then "and N more" when they don't fit.
3. When the command palette later moves onto the Combobox's parts, it keeps its built groups (Recent, the search groups, Go to, Do, AI), and the design system is updated to match.
