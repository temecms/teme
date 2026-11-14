# Interface wave 2: dialogs and menus on phones

**One part, one branch: `ui/phone-overlays`, from `origin/main`.** It adds three patterns that give overlays their phone rules below 640px: dialogs full screen, confirmations and menus as sheets from the foot, side sheets full screen. It moves the screens no other wave-2 part owns onto them. It runs beside the content lists, the editor, media, tools and plugin areas parts, and touches none of their files.

Steps use checkbox (`- [ ]`) syntax for tracking.

**Written 2026-11-14** for the repository `temecms/teme`. It starts from `origin/main`, which holds the design-system foundation and wave 1 (the Combobox, the sign-in pages, people and account). Everything this part needs is written out below, under "What this part relies on". Task 0 checks that it is there. This plan is lighter (I-569): it writes out the decisions, names, tests and the delicate code, and describes the rest.

**Goal.** Below 640px, every overlay the admin draws through these patterns fits a phone. Dialogs fill the screen with their head and actions in view. Confirmations and menus open from the foot. Side sheets fill the screen. From 640px up, nothing a person sees changes, except that a tall dialog now scrolls inside (P6).

**Tech stack** (Task 0 restates the exact versions from the locks): Laravel 13, PHP 8.4, PostgreSQL 18, Inertia 3 (`@inertiajs/react` 3.9), React 19.3, TypeScript, Tailwind 4.3, shadcn/ui "new-york" on Radix (`@radix-ui/react-dialog`, `-dropdown-menu`, `-slot`, all installed), Vite 8, Pest 5 with `pestphp/pest-plugin-browser` 5.1.

**Spec:** `docs/architecture/design-system.md` ("DS", by section number): 2.11 Layers, 2.12 Breakpoints, 4.17 Menu, 4.19 Dialog, 4.20 Sheet, section 7 (focus, keys, targets), section 8 (writing), section 9 (the check). The approved responsive design's phone rules are written out in P1 to P9, so nothing depends on a reference page.

**Bound by:** I-3439 (American English for new words), I-3373 (every word through `__()`), I-797 (a comment cites one I-number or one document path), I-569, I-3317, I-3369.

**New dependencies:** none. **Migrations, permissions, operations, routes, contract members (`packages/teme-contracts`), tokens:** none. **Drafts:** `B-?? (draft)` for plan faults.

---

## Where the documents and the code disagree

1. **DS 4.19** says that below 640px a dialog is `100vw − 32px` wide. The approved responsive design makes it full screen, with its head and actions fixed and the body scrolling. **The responsive design is followed.** DS 4.19 is updated at the merge.
2. **DS 4.17 and 4.18** give menus and popovers no phone rule. The responsive design makes both sheets from the foot. **Menus are done here. Popovers are not** (P7).
3. **DS 4.20** says a sheet is full width below 640. `ui/sheet.tsx` is `w-full max-w-(--sheet-w)`, so between 480 and 639px it is 480 wide. The side-sheet pattern makes it full screen below 640.
4. **The settings side list as one button below 1024px** (the responsive design) is already built on `ui/settings-pages`, opening the list in place. The account's list already opens from one button below 1024px on `main`. **This part adds nothing to either list**, apart from turning the account button's menu into a sheet on phones (Task 4).
5. **Ownership.** `components/ui/**`, the tokens, and the existing files in `components/patterns/**` belong to no wave-2 part. This part adds three new files to `patterns/` and edits only the callers named under "Owns".

## What this part relies on

Written out here so this plan stands alone.

- **The seams.** `resources/css/app.css`'s token block and the existing files in `components/ui/**` and `components/patterns/**` belong to the foundation. A part may **add** new files to `patterns/`. It never edits an existing one or a token. A change to them is asked of the maintainers, who make it once on the integration branch.
- **The values used here** (all tokens in `app.css`): `bg-surface`, `text-ink`, `text-ink-2`, `text-ink-3`, `bg-surface-3`, `border-line`, `outline-ring`, `bg-scrim`; heights `h-(--ctl-h)`, 36px and 44px on a coarse pointer or under 768px; type `text-caption`, `text-small`, `text-body`, `text-lead`; radii `rounded-md`, `rounded-lg`, `rounded-xl`; `shadow-modal`; the large dialog's height `--dialog-xl-h` (`min(740px, 100vh − 48px)`); layers `z-(--z-overlay)`; motion `duration-(--dur-3)`, `ease-(--ease)`. The stylesheet's phone query is `(max-width: 39.99rem)`, which is Tailwind's `max-sm:`.
- **What `ui/` gives, unchanged.** `DialogContent` takes `size` (`sm` default, `md`, `lg`, `xl`) and a `className` merged last with `cn()` (tailwind-merge). It draws its close button `absolute end-3 top-3` inside the content. `DialogHeader` writes `data-slot="dialog-header"`, and `DialogFooter` writes `data-slot="dialog-footer"` (`flex-col-reverse` below 640). `SheetContent` takes `side` and `className`. `DropdownMenuContent` portals into `<body>` inside Radix's positioned wrapper. `ScrollArea` (`ui/scroll-area.tsx`) is the only place an `overflow-*-auto` may be written.
- **The design check.** `tests/Architecture/DesignSystemTest.php` runs `tests/Fixtures/design-system-scan.mjs` over `resources/js` and `resources/css` and compares the result with `tests/Architecture/design-system-baseline.txt`, one line per offense: `rule<TAB>path<TAB>match`. The rules this part meets: **R1** arbitrary `[…]` values (variant brackets such as `data-[…]:` are allowed); **R2** text sizes other than the six tokens; **R3** spacing steps 1.5, 2.5, 3.5, 7, 9, 11, 14, 15; **R5** raw `<button>`, `<input>` and the like outside `ui/` and `patterns/`; **R6** bare `rounded`, `shadow`, numbered `z-`, `leading-`, `tracking-`; **R8** `overflow-auto` or `-scroll` anywhere but `ui/scroll-area.tsx`; **R10** `outline-none` without a `focus-visible:` outline; **R14** `transition`, `duration-N`, `ease-in/out` and `animate-*` outside `ui/` without `motion-safe:`. **Lowering the baseline:** fix the offense, run the test, remove exactly the lines its first case lists as gone, and lower those rules' numbers in `dsCounts()`. **Never add a line or raise a number.**
- **The admin's words.** `tests/Architecture/AdminWordsTest.php` lists, in `admin-words-baseline.txt`, every word a person reads that does not yet pass through `__()` or `__n()` from `@/lib/i18n`. New words always go through them. Moving a listed word into `__()` makes the test list it as gone. Remove that line and lower the count in the test's "never lets the baseline grow" case by as many.
- **Browser-test helpers** in `tests/Pest.php`. They are used, never edited:
  - `cmSignIn(User $person)` signs in through the real form and returns the page at Home.
  - `cmNavigate($page, string $url)` visits a page in one attempt. Never call `->navigate()` twice in a row.
  - `cmWaitUntil($page, string $jsCondition)` polls a condition in the page.
  - `shellSettled($page, string $selector)` waits until the elements matching `$selector` have no animation of their own running.
  - `cmSmallTargets($page)` returns `['small' => [...], 'checked' => n]`.
  - `$page->script(string $js)` returns a value from the page, as `AccountPagesTest` uses it.
- **Browser-test rules.** Run after `npm run build`, one file at a time, with output redirected to a file and read. Never pipe it through `grep` or `sed` (it hangs). Before reading or clicking inside an overlay, wait for its own animation with `shellSettled()` on its own selector, never `getAnimations({ subtree: true })`. Select by `role`, `aria-*`, `data-slot` and `data-test`. Run only the browser files named here. GitHub runs the full set after the merge.

## The decisions, written out

**P1. Patterns, not a `ui/` change.** Dialogs and side sheets need only classes, and `className` is merged last. So `ResponsiveDialogContent` and `ResponsiveSheetContent` are wrappers that add classes.

A menu cannot be moved to the foot with classes: Radix positions the dropdown's content inside a wrapper that carries an inline `transform`, and a `fixed` element inside a transformed parent is placed against that parent. So `ResponsiveMenu` draws a `Sheet` (`side="bottom"`) below 640px and the existing `DropdownMenu` from 640 up.

**No `ui/` change is needed.** If one turns out to be needed, that is a STOP.

**P2. Files and names.** All three are new:

- `resources/js/components/patterns/responsive-dialog.tsx` exports `ResponsiveDialogContent`.
- `resources/js/components/patterns/responsive-sheet.tsx` exports `ResponsiveSheetContent`.
- `resources/js/components/patterns/responsive-menu.tsx` exports `usePhoneWidth`, `ResponsiveMenu`, `ResponsiveMenuTrigger`, `ResponsiveMenuContent`, `ResponsiveMenuItem`, `ResponsiveMenuLabel`, `ResponsiveMenuGroup` and `ResponsiveMenuSeparator`.

Each class docblock follows the coding standard. Every word goes through `__()`.

**P3. The dialog, written out.** This is the delicate part. Callers keep their markup: the head and the footer stick, found by the `data-slot` values that `DialogHeader` and `DialogFooter` already write, at any depth, so a footer inside a `<form>` sticks too.

```tsx
type PhoneShape = "full" | "foot"

/** Below 640px: the whole screen, or a sheet from the foot for a confirmation (P4). */
const PHONE: Record<PhoneShape, string> = {
    full: "max-sm:inset-0 max-sm:max-h-none max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-none",
    foot: "max-sm:inset-x-0 max-sm:top-auto max-sm:bottom-0 max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-b-none",
}

/** The frame: one scrolling body between a head and a footer that stay in view (DS 4.19). */
const FRAME = "flex max-h-(--dialog-xl-h) flex-col gap-0 p-0"

const BODY = [
    "grid flex-1 content-start gap-4 px-6 pt-4 pb-6 max-sm:px-4",
    "**:data-[slot=dialog-header]:sticky **:data-[slot=dialog-header]:top-0 **:data-[slot=dialog-header]:-mt-4 **:data-[slot=dialog-header]:bg-surface **:data-[slot=dialog-header]:pt-4 **:data-[slot=dialog-header]:pb-2",
    "**:data-[slot=dialog-footer]:sticky **:data-[slot=dialog-footer]:bottom-0 **:data-[slot=dialog-footer]:-mb-6 **:data-[slot=dialog-footer]:bg-surface **:data-[slot=dialog-footer]:pt-3 **:data-[slot=dialog-footer]:pb-6",
].join(" ")

export function ResponsiveDialogContent({
    phone = "full",
    className,
    children,
    ...props
}: React.ComponentProps<typeof DialogContent> & { phone?: PhoneShape }) {
    return (
        <DialogContent data-phone={phone} className={cn(FRAME, PHONE[phone], className)} {...props}>
            <ScrollArea data-slot="responsive-dialog-body" className={BODY}>
                {children}
            </ScrollArea>
        </DialogContent>
    )
}
```

- `size="xl"` is not accepted. The large dialog is already full screen below 640. Type it out with `Exclude<…, "xl">`, or reject it in the type.
- Note on `max-h-(--dialog-xl-h)` and `max-sm:max-h-none`: from 640 up, a tall dialog stops at the large dialog's height and scrolls inside (P6). Below 640, `full` fills the screen, and `foot` keeps the cap, so it never covers the whole screen.
- The close button stays where `ui/` puts it, top end, outside the scrolling body, so it never scrolls away.
- `foot` slides in from the bottom on phones: `max-sm:motion-safe:data-[state=open]:slide-in-from-bottom`. R14 needs `motion-safe:` outside `ui/`.
- If the sticky head shows a positioned child of the body over it while scrolling, or the negative margins leave a gap, adjust within this file and record it as a B-?? (draft). Any class needed in `ui/dialog.tsx` is a STOP.

**P4. Which dialogs go to the foot.** `phone="foot"` is only for a confirmation: a question with no field, answered by its two buttons. A dialog with any field stays `full`, even at `size="sm"`, because the on-screen keyboard needs the room. A confirmation's focus rule is unchanged: Radix focuses the first focusable element, which is Cancel in `ConfirmAction`.

**P5. The side sheet.** `ResponsiveSheetContent` takes `SheetContent`'s props with `side` limited to `"left" | "right"`, and adds `max-sm:max-w-none max-sm:rounded-none`.

**P6. Tall dialogs scroll inside at every width.** DS 4.19 says the body "scrolls inside (ScrollArea)". `ui/` does not do it today, and a tall dialog runs off the screen. Opted-in dialogs get it (the proposal's Settled 4, provisional).

**P7. Popovers are not done here.** A popover is positioned the same way as a menu, so it can only become a sheet by drawing something else below 640px. Its users are `ui/combobox.tsx`, `ui/term-chips.tsx` and the content lists' `list/filter-button.tsx`. The first two are in `ui/`. Recorded for the maintainers: the one change, a phone branch in `ui/popover.tsx` that draws `PopoverContent` as a bottom `Sheet` below 640px, is theirs to make.

**P8. The menu, written out.**

```tsx
const phoneQuery = typeof window === "undefined" ? undefined : window.matchMedia("(max-width: 39.99rem)")

/** Whether the screen is a phone's: the stylesheet's own phone query (DS 2.12). */
export function usePhoneWidth(): boolean {
    return useSyncExternalStore(
        (onChange) => {
            phoneQuery?.addEventListener("change", onChange)
            return () => phoneQuery?.removeEventListener("change", onChange)
        },
        () => phoneQuery?.matches ?? false,
        () => false,
    )
}
```

- **`ResponsiveMenu`** takes `open?`, `onOpenChange?`, `modal?` (the dropdown's, from 640 up) and `children`. It holds `open` when the caller does not. It provides `{ phone, close }` through a context. It renders `Sheet` below 640 and `DropdownMenu` from 640 up.
- **Crossing 640 while open closes the menu.** This is an effect on `phone`, skipped on the first render, that calls `setOpen(false)`. The menu is never drawn two ways at once.
- **`ResponsiveMenuTrigger`** renders `SheetTrigger` or `DropdownMenuTrigger`, with `asChild` passed through.
- **`ResponsiveMenuContent`** takes `title: string` (required: the sheet's visible title and the menu's name), `align?`, `className?` (from 640 up only), `renderDesktop?: (children: ReactNode) => ReactNode` (P9) and `children`.
  - From 640 up, it renders `renderDesktop(children)` when that is given, otherwise `<DropdownMenuContent align={align} className={className}>`.
  - Below 640, it renders `SheetContent side="bottom"` with `max-h-(--dialog-xl-h)` and `aria-describedby={undefined}`, then `SheetHeader` with `SheetTitle` (`title`), then a `ScrollArea` holding `<div role="menu" aria-label={title} aria-orientation="vertical" onKeyDown={moveFocus} className="grid gap-0.5 px-2 pb-4">`.
  - `onOpenAutoFocus` prevents Radix's default and focuses the first enabled item. Radix returns focus to the trigger on close.
- **`ResponsiveMenuItem`** takes `onSelect?: () => void`, `asChild?`, `disabled?`, `variant?: "default" | "destructive"` and `children`.
  - From 640 up, it is `DropdownMenuItem` with the same props. `onSelect` is wrapped, so the event is not exposed.
  - Below 640, it renders a `<button type="button">`, or a `Slot` when `asChild`, with `role="menuitem"`, `tabIndex={-1}`, `aria-disabled` when disabled, and `data-slot="responsive-menu-item"`. It is drawn as DS 4.17's item: `flex h-(--ctl-h) items-center gap-3 rounded-md px-3 text-body text-ink`, focus `bg-surface-3`, a `focus-visible:` outline, icons 20px in `text-ink-2`, destructive in `text-danger-ink`.
  - On click it calls `onSelect?.()`, then `close()`. With `asChild` (an Inertia `Link`), the link's own click runs first.
- **`ResponsiveMenuLabel`**, **`ResponsiveMenuGroup`** and **`ResponsiveMenuSeparator`** are the dropdown's parts from 640 up. Below 640 they are `<div role="group">` (a label gives it `aria-labelledby`), a heading line in `text-caption` 600 uppercase `text-ink-3`, and `<div role="separator" className="my-1 border-t border-line">`.
- **The keys, written out:**

```ts
// ↑ ↓ Home End move between the enabled items, wrapping (the WAI-ARIA menu pattern).
function moveFocus(event: React.KeyboardEvent<HTMLDivElement>) {
    const items = [...event.currentTarget.querySelectorAll<HTMLElement>('[role="menuitem"]:not([aria-disabled="true"])')]
    const at = items.indexOf(document.activeElement as HTMLElement)
    const next = { ArrowDown: at + 1, ArrowUp: at - 1, Home: 0, End: items.length - 1 }[event.key]
    if (next === undefined || items.length === 0) return
    event.preventDefault()
    items[(next + items.length) % items.length]?.focus()
}
```

Esc and a tap on the scrim close the sheet; that is Radix's. Tab moves to the sheet's close button, because the sheet is modal.

**P9. The account menu keeps its desktop drawing.** `account-menu.tsx` draws its content in place, not portalled, so the open menu stays inside the top bar's banner landmark, and it is non-modal. From 640 up it passes its existing `DropdownMenuPrimitive.Content` element through `renderDesktop`, unchanged, and `modal={false}` to `ResponsiveMenu`. Below 640 it is a modal sheet titled "Account", like every phone sheet.

---

## Owns

**Create:** `resources/js/components/patterns/responsive-dialog.tsx`, `responsive-sheet.tsx`, `responsive-menu.tsx`; `tests/Browser/PhoneOverlaysTest.php`.

**Modify (the opt-ins; no other wave-2 part owns these files):**

| File | Change |
|---|---|
| `resources/js/components/confirm-action.tsx` | `ResponsiveDialogContent phone="foot"`; "Cancel" through `__()` |
| `resources/js/components/people/invite-dialog.tsx`, `role-dialog.tsx`, `delete-dialog.tsx` | `ResponsiveDialogContent` (full) |
| `resources/js/components/personalise-menu-dialog.tsx` | `ResponsiveDialogContent` (full); its own `max-h-[90vh] overflow-y-auto` removed (the pattern scrolls) |
| `resources/js/components/two-factor-setup-modal.tsx` | `ResponsiveDialogContent` (full) |
| `resources/js/components/header/status-sheet.tsx` | `ResponsiveSheetContent` |
| `resources/js/layouts/account/account-nav.tsx` | `AccountPagesButton` on `ResponsiveMenu` (title "Account pages") |
| `resources/js/components/account-menu.tsx`, `resources/js/components/user-menu-content.tsx` | on `ResponsiveMenu` (P9); the menu's words through `__()` |
| `resources/js/pages/dev/catalogue.tsx` | one section, `#phone-overlays` (additive) |
| `tests/Architecture/design-system-baseline.txt`, `DesignSystemTest.php`, `admin-words-baseline.txt`, `AdminWordsTest.php` | lines removed for the files above, counts lowered |
| `tests/Browser/DesignCatalogueTest.php` | only if a whole-page case counts sections |

**Through `ConfirmAction`, with no change to their files:** People's row actions, Backups, the import report, the editor's Restore, the media drawer, Navigation, Taxonomies, Types, Redirects and two-factor management all get the foot sheet on phones.

**Wait for their own part** (each is a one-import change there; listed for that part's plan):

| Part | Files |
|---|---|
| Content lists | `components/list/bulk-edit-dialog.tsx` (full), `confirm-items.tsx` and `select-all-matching.tsx` (foot), `row-actions.tsx` (menu), `filter-button.tsx` (sheet; its popover per P7); `pages/content/index.tsx` and `pages/terms/index.tsx` (Quick edit sheet); `components/comments/**` (delete and empty: foot; details and Quick edit: sheet), since the lists part rebuilds Comments |
| Editor | `components/editor/leave-dialog.tsx`, `stale-dialog.tsx`, `publish-confirmation.tsx`; `editor-header.tsx`, `toolbar.tsx`, `link-popover.tsx`, `rail/publish-panel.tsx`, `rewrite/rewrite-menu.tsx` (menus); `editor-shell.tsx` (the rail's sheet) |
| Media | `components/media/media-modal.tsx` (already full screen as `xl`), `components/media-drawer.tsx` |
| Tools | `components/backups/key-actions.tsx`, `components/tools/import-redirects.tsx`, `ai-activity-details.tsx`, `redirect-form.tsx`, `pages/tools/redirects.tsx` |
| Plugin areas | `components/plugins/consent-dialog.tsx`, `details-dialog.tsx`, `pages/plugins/index.tsx` |
| The maintainers, at the merge (existing patterns) | `patterns/crumbs.tsx` and `patterns/in-page-nav.tsx` (menus), `patterns/fresh-password.tsx` and `patterns/save-bar.tsx` (foot) |
| A follow-up once `ui/settings-pages` and `ui/remaining-screens` merge | `components/types/field-sheet.tsx`, `icon-picker.tsx`, `pages/types/fields.tsx`, `components/navigation/add-item-panel.tsx`, `pages/navigation/edit.tsx`, `pages/settings/site-code.tsx` |
| Later parts | `components/palette/command-palette.tsx` (the palette's own part); popovers (P7) |

## Global Constraints

- **Rule 1:** nothing here changes state. No operation is added or called differently, so no refused-writes-nothing test applies. The forms inside the opted-in dialogs post exactly as before.
- **Rule 3:** PostgreSQL only. **Rule 5:** `packages/teme-contracts` is not touched.
- **No dependency.** Never change `package.json`, `package-lock.json`, `composer.json` or `composer.lock`.
- **No file in `components/ui/**`, no existing file in `components/patterns/**`, and no token is edited.** If one has to be, STOP.
- **Every word a person reads goes through `__()` or `__n()`**, in American English (I-3439). The admin-words baseline only shrinks.
- **The design-system baseline only shrinks.** At the end, no line names the three new pattern files or `confirm-action.tsx`. In the other opted-in files, the lines the opt-in removes are removed. Fixing more lines in an owned file is allowed. Adding a line, raising a number, or widening an allowed place in the scanner is a STOP.
- **Other parts' files are not touched**, not even for an import (see "Wait for their own part").
- **Tests first:** each test below is run red, for the reason given, before the code.
- **The `.env`:** in a fresh checkout, `cp .env.example .env` and `php artisan key:generate`. Never copy, read or edit any other `.env`.
- **Never edit** `.ai/`, `CHANGELOG.md`, `docs/roadmap.md`, `docs/backlog.md`, `docs/architecture/*`, `docs/reference/*`, `tests/Pest.php`.
- **Conventional Commits**, staged by file (never `git add -A`), no closing keywords. They are written for the public: no private names or paths. A code comment cites at most one I-number or one document path (I-797).
- **Formatting:** every file follows `npm run check:fix`. Helpers used by one test file stay in it, with the prefix `po`.
- **The quick check** after each task: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, `npm run types:check`, `npm run check`, `php artisan test --testsuite=Architecture`, and the task's own test files.
- **Unattended work: decide small things yourself, and record them.** Where the plan is silent or a detail does not work as written (a class, a selector, a test's wording, the order of two steps), choose, and record a `B-?? (draft)` under Records with what was found, the options and the choice. Stop only for the STOP conditions.
- **When the work is interrupted:** each task ends committed and pushed, with one line in Records: "Done to Task N at `<hash>`, the quick check green". If Node is missing, run `export PATH=/opt/node24/bin:$PATH`; if it is still missing, stop, push and record it. If a test is red at the base and is not this part's, record it and never change it.

---

## Task 0: Preconditions, then branch

Run in a fresh checkout. If any line differs **by its text**, STOP and write why under Records.

- [ ] **Step 1:** `git fetch origin`. Then `git log --oneline --fixed-strings --grep="feat(admin): the design system and the admin's new frame" origin/main` and `git log --oneline --fixed-strings --grep="feat(admin): the combobox, one searchable list on Downshift" origin/main` → one commit each. Record `origin/main`'s head.
- [ ] **Step 2:**
  - `ls resources/js/components/patterns/responsive-dialog.tsx resources/js/components/patterns/responsive-sheet.tsx resources/js/components/patterns/responsive-menu.tsx tests/Browser/PhoneOverlaysTest.php 2>&1` → none exists.
  - `grep -n 'data-slot="dialog-header"\|data-slot="dialog-footer"\|data-slot="dialog-content"' resources/js/components/ui/dialog.tsx` → three lines.
  - `grep -n 'data-slot="sheet-content"' resources/js/components/ui/sheet.tsx` → one line.
  - `grep -n "\-\-dialog-xl-h\|max-width: 39.99rem" resources/css/app.css` → at least one line each.
  - `git grep -n "DialogContent" -- resources/js/components/confirm-action.tsx resources/js/components/people/invite-dialog.tsx resources/js/components/people/role-dialog.tsx resources/js/components/people/delete-dialog.tsx resources/js/components/personalise-menu-dialog.tsx resources/js/components/two-factor-setup-modal.tsx` → each file listed.
  - `git grep -n "SheetContent" -- resources/js/components/header/status-sheet.tsx` → listed.
  - `git grep -n "DropdownMenu" -- resources/js/layouts/account/account-nav.tsx resources/js/components/account-menu.tsx resources/js/components/user-menu-content.tsx` → each listed.
  - `git grep -l "UserMenuContent" -- resources/js` → exactly `components/account-menu.tsx` and `components/user-menu-content.tsx`.
  - `git grep -l "<ConfirmAction" -- resources/js` → record the list.
  - `git diff --stat origin/main...origin/ui/settings-pages -- <each Owns file>` and the same for `origin/ui/remaining-screens` → empty. If either branch changes an Owns file, STOP: the two would collide.
  - `grep -c -E "^function (cmSignIn|cmNavigate|cmWaitUntil|shellSettled|cmSmallTargets)\(" tests/Pest.php` → 5.
  - `php -v` shows 8.4; `node --version` is 22.18 or later; `pg_isready -h 127.0.0.1 -p 5433` answers.
  - `php artisan test tests/Architecture/DesignSystemTest.php` and `php artisan test tests/Architecture/AdminWordsTest.php` → green. Record the baseline's line count, the lines naming each Owns file, and `AdminWordsTest`'s count.
  - `git grep -l -E "invite-dialog|role-dialog|delete-dialog|account-menu-button|account-pages|status-sheet|two-factor|Personali[sz]e|Customi[sz]e" -- tests/Browser` → record the list. These are Task 3's and Task 4's "existing files".
- [ ] **Step 3:** restate the stack from `composer.lock` and `package-lock.json` in Records. `git checkout -b ui/phone-overlays origin/main`.

## Task 1: The dialog and side-sheet patterns

**Files:** `patterns/responsive-dialog.tsx`, `patterns/responsive-sheet.tsx`; `pages/dev/catalogue.tsx`, section `#phone-overlays` (dialog, confirmation and side-sheet parts); `tests/Browser/PhoneOverlaysTest.php`.

**The catalogue section** draws three buttons, each opening one overlay:

- `catalogue-overlay-dialog`: an `md` dialog titled "Edit the details", with 12 sample fields (enough to run past a 375 × 667 screen) and a footer with Cancel and Save.
- `catalogue-overlay-confirm`: a `phone="foot"` confirmation, "Delete this draft?", with Cancel and Delete.
- `catalogue-overlay-sheet`: a side sheet from the end, "Details", with a few lines.

Each overlay carries a `data-test` of the same name plus `-content`. Sample words go through `__()`.

**Tests, written first** (`tests/Browser/PhoneOverlaysTest.php`; they fail first on `Timeout … waiting for` the catalogue's buttons). Geometry is read with one helper in the file:

```php
/** The overlay's box and the window's size, rounded to whole pixels. */
function poBox($page, string $selector): array
{
    return $page->script(<<<JS
        (() => { const r = document.querySelector('{$selector}').getBoundingClientRect();
          return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left),
                   width: Math.round(r.width), vw: innerWidth, vh: innerHeight }; })()
        JS);
}
```

| Case | What it proves |
|---|---|
| "fills a phone's screen with a dialog, its title and Save in view as the body scrolls" | At 375 × 667: the dialog's box is `top 0, left 0, width = vw, bottom = vh`. The body (`[data-slot="responsive-dialog-body"]`) has `scrollHeight > clientHeight`. After `scrollTop` is set to its end, the title's top is still within 16px of the dialog's top, and the Save button's bottom is still at most `vh`. |
| "keeps the dialog centered at its width from 640 up" | At 1280 × 900: the width is 640, `left = (vw − 640) / 2` (±1), and the dialog does not touch the window's edges. At 1280 × 600, its height is at most `vh − 48` and the body scrolls. |
| "opens a confirmation from the foot of a phone's screen" | At 375: `bottom = vh`, `left 0`, `width = vw`, `top > 0`; the top corners are rounded and the bottom ones are not (`getComputedStyle(...).borderBottomLeftRadius === "0px"`). Focus is on Cancel. Esc closes it and focus returns to its button. |
| "fills a phone's screen with a side sheet at 600 pixels" | At 600: the sheet's `width = vw`. At 1280: its width is 480. |
| "passes axe with each overlay open, every target 24 pixels or more, at 375 and 1280 in both schemes" | `assertNoAccessibilityIssues()` with each of the three open in turn; `cmSmallTargets()` is empty. |
| "scrolls nothing sideways at 320 with the dialog open" | `document.documentElement.scrollWidth <= clientWidth` at 320. |

- [ ] **Step 1:** write the cases; run after `npm run build`; record the first failure.
- [ ] **Step 2:** build the two patterns as P3 to P6, and the section; run the file until green. Then plant each of these, see its case go red, and remove it, recording the first line: remove the footer's `sticky` (the scroll case goes red); remove `max-sm:inset-0` from `full`.
- [ ] **Step 3:** `DesignSystemTest`: the new files add no line. If they do, fix the file, never the baseline. `AdminWordsTest` green. Quick check; commit `feat(admin): dialogs fill a phone's screen and confirmations open from its foot`; push; the Records line.

## Task 2: The menu pattern

**Files:** `patterns/responsive-menu.tsx`; the catalogue section gains `catalogue-overlay-menu`; cases added to `PhoneOverlaysTest.php`.

**The catalogue menu** is a "…" button (`aria-label` "More actions for Sample post") with a menu titled "More actions". It holds a label ("Sample post"), Edit, Duplicate, a disabled Archive, a separator, and Move to trash (destructive). Choosing an item writes its name into a `data-test="catalogue-overlay-menu-chosen"` line.

**Tests, written first:**

| Case | What it proves |
|---|---|
| "opens a menu as a sheet from the foot of a phone's screen" | At 375: after the click, `[role="menu"]` is inside `[data-slot="sheet-content"]`, the sheet's `bottom = vh` and `width = vw`, the sheet's title reads "More actions", and focus is on the first item (Edit). |
| "moves through a phone menu with the arrows, Home and End, past the disabled item" | ↓ from Edit → Duplicate; ↓ → Move to trash (Archive is skipped); ↓ wraps to Edit; End → Move to trash; Home → Edit; ↑ from Edit → Move to trash. |
| "runs an item and closes on Enter, and closes on Esc with nothing run" | Enter on Duplicate: the chosen line reads "Duplicate", the sheet is gone, and focus is on the "…" button. Opening again and pressing Esc: closed, the line unchanged, and focus on the button. |
| "keeps the dropdown menu from 640 up" | At 1280: `[data-slot="dropdown-menu-content"]` holds `[role="menu"]`, and no `[data-slot="sheet-content"]` is present. |
| "closes an open menu when the window crosses 640" | Open at 375, resize to 1280: no menu is open. |
| axe and targets, extended | The menu open at 375 and at 1280, both schemes; `cmSmallTargets()` empty; every phone item is at least 44 tall. |

- [ ] **Step 1:** write the cases; run; record the first failure.
- [ ] **Step 2:** build the pattern as P8; run until green. Plant: remove `moveFocus`'s `aria-disabled` filter (the arrows case goes red); remove the crossing effect (that case goes red). Remove the plants.
- [ ] **Step 3:** `DesignSystemTest` adds no line; quick check; commit `feat(admin): menus open from the foot of a phone's screen`; push; the Records line.

## Task 3: Dialogs and the status sheet opt in

**Files:** `confirm-action.tsx`, `people/invite-dialog.tsx`, `people/role-dialog.tsx`, `people/delete-dialog.tsx`, `personalise-menu-dialog.tsx`, `two-factor-setup-modal.tsx`, `header/status-sheet.tsx`; cases added to `PhoneOverlaysTest.php`.

Each opt-in swaps the import and the tag: `DialogContent` → `ResponsiveDialogContent` (`phone="foot"` in `ConfirmAction` only), and `SheetContent` → `ResponsiveSheetContent`. Keep each `size`, `data-test` and `className`, except `personalise-menu-dialog.tsx`'s `max-h-[90vh] overflow-y-auto`, which the pattern replaces. Move these files' baseline-listed words into `__()` where the opt-in touches the line (at least `ConfirmAction`'s "Cancel").

**Tests, written first:**

| Case | What it proves |
|---|---|
| "fills a phone's screen with the invite dialog, Send invitation in view" | People at 375 × 667: open Invite. `[data-test="invite-dialog"]`'s box fills the window, and "Send invitation" has `bottom <= vh` with no scrolling. It fails first on the box (today it is `vw − 32` wide and centered). |
| "asks to revoke an invitation from the foot of a phone's screen" | People at 375, with a pending invitation from the factories: the row's Revoke opens a dialog whose `bottom = vh` and `top > 0`; Cancel closes it and the invitation stays. |
| "fills a phone's screen with the status sheet at 600 pixels" | The top bar's status button at 600: the sheet's `width = vw`. |

- [ ] **Step 1:** write the cases; run; record the failures.
- [ ] **Step 2:** make the seven opt-ins. `git diff --stat` shows only the files above and the test.
- [ ] **Step 3:** run `PhoneOverlaysTest` and every file recorded in Task 0's last grep that opens these dialogs or the status sheet, one at a time. All must be green with no change. If an existing case reads a dialog's geometry, or a selector the opt-in moved (the body now sits inside `[data-slot="responsive-dialog-body"]`), and goes red: change its selector only, keep what it proves, and record the change. If it needs more than that, STOP.
- [ ] **Step 4:** `DesignSystemTest` and `AdminWordsTest`: remove the lines listed as gone, lower the counts. Quick check; commit `feat(admin): confirmations, people's dialogs and the status sheet fit a phone`; push; the Records line.

## Task 4: The account menus opt in

**Files:** `layouts/account/account-nav.tsx` (`AccountPagesButton`), `account-menu.tsx`, `user-menu-content.tsx`; cases added to `PhoneOverlaysTest.php`.

- `AccountPagesButton`: `DropdownMenu*` → `ResponsiveMenu*`, with `title={__("Account pages")}`. The trigger's `data-test="account-pages"` and `aria-label` are unchanged.
- `UserMenuContent`: its `DropdownMenuLabel`, `Group`, `Item` and `Separator` → the `ResponsiveMenu*` parts. "Profile", "Security", "Preferences" and the sign-out label go through `__()`. Sign-out keeps its `cleanup()` and `router.flushAll()`.
- `AccountMenu`: `DropdownMenu modal={false}` → `ResponsiveMenu modal={false}`, and the trigger → `ResponsiveMenuTrigger`. The existing `DropdownMenuPrimitive.Content` element moves, unchanged, into `ResponsiveMenuContent`'s `renderDesktop`, with `title={__("Account")}` (P9).

**Tests, written first:**

| Case | What it proves |
|---|---|
| "opens the account menu as a sheet from the foot of a phone's screen, and signs out from it" | At 375: `[data-test="account-menu-button"]` opens `[role="menu"]` inside `[data-slot="sheet-content"]`, with `bottom = vh`. Its items include Profile and the sign-out item. Choosing sign-out lands on the sign-in page. |
| "opens the account pages as a sheet from the foot of a phone's screen" | At 375 on Account › Notifications: `[data-test="account-pages"]` opens a sheet titled "Account pages" holding `[role="menu"]` with 5 links, the current one `aria-current="page"`. |
| "keeps the account menu inside the top bar from 640 up" | At 1280: the open `[role="menu"]` is a descendant of `header` (the banner), not a direct child of `body`. |

- [ ] **Step 1:** write the cases; run; record the failures. The second fails on the sheet; today it is a dropdown.
- [ ] **Step 2:** make the three changes.
- [ ] **Step 3:** run `PhoneOverlaysTest`, `AccountPagesTest`, `ShellTest`, `ShellResponsiveTest`, `AdminShellTest` and `AccountNotificationsTest`, one at a time. `AccountPagesTest`'s case "opens the account pages from one button under 1024 pixels…" reads `[role="menu"] a` and should stay green unchanged. If a case reads `data-slot="dropdown-menu-content"` below 640, change that selector to `[role="menu"]`, keep what it proves, and record the change.
- [ ] **Step 4:** baselines as in Task 3; quick check; commit `feat(admin): the account menus open from the foot of a phone's screen`; push; the Records line.

## Task 5: Gate and records

- [ ] The full `composer check` once. Then, after `npm run build`, the browser files this part added or ran, one at a time: `PhoneOverlaysTest`, `DesignCatalogueTest`, `PeopleScreenTest`, `AccountPagesTest`, `AccountNotificationsTest`, `ShellTest`, `ShellResponsiveTest`, `AdminShellTest`, `HeaderActivityTest`, and those Task 0 recorded. GitHub runs the rest after the merge.
- [ ] `grep -c -E 'js/components/(patterns/responsive-(dialog|sheet|menu)|confirm-action)\.tsx' tests/Architecture/design-system-baseline.txt` → 0. `git diff --stat origin/main -- resources/js/components/ui resources/css` → nothing. `git diff --name-only origin/main` → only the Owns files.
- [ ] Records: the commits, each test seen red, the baseline counts per rule and the admin-words count before and after, every `B-?? (draft)`, and how to try it: on a phone-width window (375), People › Invite (full screen), a row's Revoke (from the foot), the account menu (from the foot); `/admin/_catalogue#phone-overlays` on a local site. Commit `docs(plans): phone overlays records`; push.

---

## Every word

**New**, through `__()`: "Account pages", "Account" (the sheets' titles, where not already a word), and the catalogue section's name, description, button labels, titles, sample fields and items ("Edit the details", "Delete this draft?", "Details", "More actions", "More actions for :name", "Sample post", "Edit", "Duplicate", "Archive", "Move to trash"). **Moved into `__()` unchanged:** every word in the Owns files that the admin-words baseline lists and the opt-in touches, at least "Cancel" in `confirm-action.tsx` and "Profile", "Security", "Preferences" in `user-menu-content.tsx`.

## What must not be done

- Edit any file in `components/ui/**`, an existing file in `components/patterns/**`, a token, the scanner or `tests/Pest.php`.
- Opt in a file listed under "Wait for their own part", even with one line.
- Change a dialog's or menu's behavior from 640 up, beyond P6's inside scroll.
- Make popovers into sheets (P7).
- Delete a test, or change what a test proves.

## STOP conditions

Stop, write why under Records, commit and push, then stop, when:

- a precondition differs by its text, or `ui/settings-pages` or `ui/remaining-screens` changes an Owns file;
- a pattern needs a change to a `ui/` file, an existing pattern, or a token. Name the one change needed;
- a dependency the plan does not name would be needed;
- a design-system or admin-words line would have to be added, a count raised, or the scanner's allowed places widened;
- an existing browser test would need more than a selector change, or would have to be deleted;
- a file another wave-2 part owns would have to change.

## Security

- No route, permission, query or operation changes. Every form inside an opted-in dialog posts as before.
- The patterns render their children as given, and no HTML from data.

## At the merge (for the maintainers)

- **DS 4.19:** below 640px, a dialog is full screen with its head and actions fixed and its body scrolling; a confirmation (`phone="foot"`) is a sheet from the foot; a tall dialog scrolls inside, capped at `--dialog-xl-h`. **DS 4.17:** below 640px, a menu is a sheet from the foot (`ResponsiveMenu`). **DS 4.20:** below 640px, a side sheet is full screen. **DS 6.2:** the three patterns join the kit's Overlays row.
- **The existing patterns' opt-ins**, one import each: `crumbs.tsx` and `in-page-nav.tsx` → `ResponsiveMenu*`; `fresh-password.tsx` and `save-bar.tsx` → `ResponsiveDialogContent phone="foot"` (the leave guard is a confirmation; the password prompt has a field, so `full`; decide).
- **Popovers (P7):** the one `ui/popover.tsx` change, when wanted.
- **The follow-up** for the files on `ui/settings-pages` and `ui/remaining-screens`, once both merge.
- **Baseline conflicts with other parts:** take both sides' removals, then run `DesignSystemTest` and `AdminWordsTest` and set each count to what the merged baseline holds. **The catalogue:** other parts add sections too; keep every section.
- **Changelog line, at the release:** "On a phone, dialogs fill the screen with their buttons in reach, and confirmations and menus open from the bottom of the screen."

## Open points

| # | Point | Recommendation |
|---|---|---|
| 1 | Popovers as sheets from the foot | One `ui/popover.tsx` change by the maintainers (P7); until then they float on phones |
| 2 | The existing patterns' overlays | The maintainers' one-import opt-ins at the merge |
| 3 | The account pages button: a menu, or the list in place as in Settings | Keep the menu, a sheet from the foot on phones |
| 4 | Tall dialogs scrolling inside from 640 up (P6) | Keep: DS 4.19 already asks for it |
| 5 | Safe-area padding and the keyboard's height for fixed bars and foot sheets | A later part with the tokens for them |

---

## Records

<!-- Filled in while building: setup; commits; each test seen red (task, file, case, the failure's first line); the design-system and admin-words counts before and after; existing tests whose selectors changed, and why; differences from the plan as B-?? (draft), each with what was found, the options and the choice; changelog lines; any STOP. -->

### Setup

- Before setup, `/tmp/teme-cloud-setup.log`: none; working directory `/home/user/teme`. PHP was 8.3 and neither PostgreSQL nor Valkey answered, so `bash scripts/cloud-setup.sh` ran once (20:55 to 21:07 UTC). After it: PHP 8.4.27, Node 24.22.1, `pg_isready` accepting on 5433, Valkey PONG on 6380.
- Stack from the locks: Laravel 13.39.0, Inertia Laravel 3.5.2, Pest 5.3.4, pest-plugin-browser 5.1.2; React 19.3.1, `@inertiajs/react` 3.9.1, Tailwind 4.3.3, Vite 8.3.5, TypeScript 7.0.2, `@radix-ui/react-dialog` 1.3.1, `-dropdown-menu` 2.2.1, `-slot` 1.5.0.

### Task 0

- `origin/main` at `4b8cc3b`. The design-system commit is `e7f2af7` (the grep also lists its merge, `bd0e715`, whose message quotes it); the Combobox commit is `772e0dd`.
- None of the four new files existed. `ui/dialog.tsx` writes the three slots, `ui/sheet.tsx` `sheet-content`; `app.css` holds `--dialog-xl-h` and `max-width: 39.99rem`. Every opt-in file uses the part named. `UserMenuContent` is used only in `account-menu.tsx` and `user-menu-content.tsx`. `tests/Pest.php` holds the five helpers.
- `ConfirmAction` users: `backups/key-actions.tsx`, `backups/places-list.tsx`, `backups/verdict.tsx`, `editor/restore-button.tsx`, `import/run-report.tsx`, `manage-two-factor.tsx`, `media-drawer.tsx`, `people/row-actions.tsx`, `pages/content/index.tsx`, `pages/navigation/edit.tsx`, `pages/navigation/index.tsx`, `pages/settings/site-code.tsx`, `pages/taxonomies/index.tsx`, `pages/taxonomies/show.tsx`, `pages/terms/index.tsx`, `pages/tools/redirects.tsx`, `pages/types/index.tsx`, `pages/types/show.tsx`.
- `ui/settings-pages` and `ui/remaining-screens` change none of the Owns files.
- `DesignSystemTest` and `AdminWordsTest` green. The design-system baseline: 1,228 lines (R1 114, R2 661, R3 149, R5 82, R6 41, R7 89, R8 34, R9 23, R10 14, R14 13, R15 8). Lines naming Owns files: `personalise-menu-dialog.tsx` 5 (R1 `max-h-[90vh]`, R2 `text-[11px]`, `text-[13px]`, `text-xs`, R8 `overflow-y-auto`), `user-menu-content.tsx` 2 (R2 `text-sm`, R3 `py-1.5`). The admin-words count: 2,350; Owns lines: `confirm-action.tsx` 1, `personalise-menu-dialog.tsx` 9, `user-menu-content.tsx` 4.
- Browser files naming these overlays: `AccountPagesTest`, `FirstRunTest`, `PeopleScreenTest`, `ShellResponsiveTest`, `ShellTest`, `SignInPagesTest`.


### Progress

- Done to Task 2, the quick check green.
