# Dialogs and menus on phones

| | |
|---|---|
| Status | accepted |
| Created | 2026-11-14 |
| Authors | TemeCMS maintainers |
| Issue | none yet |
| Plan | docs/plans/phone-overlays.md |
| Release | — |
| Replaces | — |

## The problem

On a phone, the admin's overlays still behave as they do on a laptop. A dialog floats mid-screen and can grow taller than the screen, so its Save button scrolls out of reach. A menu opens as a small floating list, often far from the thumb. A side sheet stops at 480px, leaving a strip of page beside it.

The approved responsive design sets one rule for each below 640px wide. Dialogs become full-screen sheets, with their head and actions fixed and the body scrolling between them. A confirmation and a menu open as a sheet from the foot of the screen. A side sheet takes the whole screen. The design system's first part built the phone's menu drawer, the touch sizes and the phone top bar, but left these overlay rules for a part of their own.

The shared components in `components/ui/` are the foundation's, and a part never edits them. So the phone rules arrive as patterns that screens opt into, one screen at a time.

## What we will build

**A responsive dialog** (`ResponsiveDialogContent`). It is used in place of `DialogContent`, with the same props, plus a `phone` prop:

- At 640px and wider, nothing changes, except that a tall dialog scrolls inside. It stops at the large dialog's height, `min(740px, 100vh − 48px)`, with its head and actions kept in view while the body scrolls.
- Below 640px, `phone="full"` (the default) fills the screen: no rounded corners, the head fixed at the top, the actions fixed at the foot, the body scrolling between them.
- Below 640px, `phone="foot"` is a sheet from the foot of the screen, as wide as the screen, rounded at its top corners only, and no taller than the large dialog. This is what a confirmation uses.

**A responsive side sheet** (`ResponsiveSheetContent`). It is used in place of `SheetContent` for sheets from the start or the end. Below 640px it fills the screen.

**A responsive menu** (`ResponsiveMenu` and its parts: trigger, content, item, label, group, separator). Its parts are named like the dropdown menu's parts, so moving a menu over is mostly renaming.

- At 640px and wider it is the dropdown menu, unchanged.
- Below 640px it is a sheet from the foot of the screen. The sheet is headed by the menu's title and holds the same items as a `role="menu"` list, each item 44px tall. ↑ and ↓, Home and End move between the items. Enter or a tap runs an item and closes the sheet. Esc or a tap on the scrim closes it. Focus returns to the button that opened it.

**The screens that move over in this part:**

- The shared confirmation, `ConfirmAction`, becomes a sheet from the foot. Every screen that uses it gets the new behavior with no change of its own.
- The dialogs for inviting a person, changing a role and removing a person, the dialog for customizing the menu, and the two-factor setup dialog become full screen.
- The top bar's status sheet fills the screen.
- The account menu in the top bar and the account pages button open as sheets from the foot.

**The design catalogue** gains a "Phone overlays" section that draws a dialog with a long body, a confirmation, a side sheet and a menu.

## The decisions

1. **Patterns, not a change to `ui/`.** Dialogs and side sheets need only layout classes, and `DialogContent` and `SheetContent` already accept a `className`. So the dialog and side-sheet patterns are thin wrappers that add the phone classes.

   A menu cannot be moved to the foot with classes: Radix places it inside a wrapper moved by an inline transform. So below 640px the menu pattern draws a sheet instead. Both are Radix primitives already installed.
2. **The width is read once, in one place.** The menu pattern switches at the same point the stylesheet uses for phones, `(max-width: 39.99rem)`. If the width crosses that point while a menu is open, the menu closes, so it is never drawn both ways.
3. **A confirmation is chosen by its caller.** `phone="foot"` is for a question answered by its two buttons, with no field. A dialog with fields stays full screen even when it is small, because the keyboard needs the room.
4. **Head and actions stay in view by sticking, not by restructuring.** The body of the dialog becomes one scrolling area. The dialog's own head and footer, found by the `data-slot` values the dialog parts already write, stick to its top and bottom. Callers keep their markup.
5. **Every overlay keeps its role and focus rules.** Dialogs stay modal dialogs, and a phone menu is a modal sheet holding a `role="menu"`. On a phone, the account menu becomes modal like every other sheet. At 640px and wider it stays the non-modal menu inside the top bar.
6. **Callers opt in only where no other part is working.** Screens that the content lists, the editor, media, tools and plugin parts are rebuilding move over in those parts. Each of them needs only the new import.
7. **Words.** Every new word goes through the translation helper, in American English (I-3439).

## In and not in

**In:** the three patterns; the catalogue section; `ConfirmAction`; the invite, role, remove-person, customize-menu and two-factor setup dialogs; the status sheet; the account menu and the account pages button; every word in these files through the translation helper.

**Not in:**

- **Popovers as sheets from the foot.** The approved responsive design asks for it. The only popovers are inside `ui/` (the Combobox and the "+N" chips) or in the content lists' filters. Doing it needs one change to `ui/popover.tsx`, which the maintainers make once.
- **The overlays inside existing patterns:** Find a setting's crumb switcher, the in-page navigation's More menu, the fresh-password prompt and the save bar's leave guard. Each needs only the new import, and the maintainers make it at the merge.
- **Screens other parts are rebuilding:** the content lists, the editor, media, tools and plugins each opt in within their own part.
- **Screens on branches that have not merged yet:** the field sheet, the icon picker, the menu editor's add panel and site code. They move over in a short follow-up once those branches merge.
- The command palette as a full-screen sheet, which comes with its own later part.
- The settings side list as one button below 1024px. The settings part already builds it, opening the list in place.
- Safe-area padding for notched phones and the keyboard's height, which need tokens of their own.

## How it fits

- **Components:** three new files in `components/patterns/`. No token, no file in `ui/`, and no existing pattern changes.
- **Server:** unchanged. No route, permission, operation or migration.
- **Accessibility:** focus moves into each overlay and returns to its opener. Menu keys follow the WAI-ARIA menu pattern. Targets are 44px on touch. Axe passes at 375 and 1280 in light and dark, and nothing scrolls sideways at 320.
- **Tests:** browser tests for each pattern in the catalogue at 375 and 1280, for the People screen's invite dialog and a confirmation at 375, and for the account menus at 375. Existing browser tests for these screens keep passing unchanged. The design-system and admin-words baselines only shrink.
- **Dependencies:** none.

## Settled

1. Popovers stay floating on phones until the maintainers change `ui/popover.tsx` once (provisional: confirm).
2. The four existing patterns that hold an overlay opt in at the merge, done by the maintainers (provisional: confirm).
3. The account pages button stays a menu, a sheet from the foot on phones, rather than opening its list in place as Settings does (provisional: confirm).
4. A dialog that has moved over scrolls inside at every width, capped at the large dialog's height, as the design system's dialog body already asks (provisional: confirm).
