# Interface part A: the Combobox

**One part, one branch.** It builds the shared Combobox on Downshift with the existing Radix popover, and moves the tag picker onto it. The content lists (part B) and the editor (part C) start after it merges, since both adopt the Combobox. The command palette is not rebuilt here.

Steps use checkbox (`- [ ]`) syntax for tracking.

**Written 2026-11-14** for the repository `temecms/teme`. It builds on the design-system foundation, which is on `feat/interface-foundation` (and on `main` once that branch has merged). Everything this part needs from the foundation is written out below, under "What this part relies on"; Task 0 checks it is there. This plan is lighter (I-569): it writes out the decisions, names, tests and the delicate code, and describes the rest.

**Goal.** One searchable list for the whole admin. `components/ui/combobox.tsx` is the only file that imports `downshift`, and the tag picker is its first user.

**Tech stack** (Task 0 restates the exact versions from the locks): Laravel 13, PHP 8.4, PostgreSQL 18, Inertia 3 (`@inertiajs/react` 3.9), React 19.3, TypeScript, Tailwind 4.3, shadcn/ui "new-york" on Radix, Vite 8, Pest 5 with `pestphp/pest-plugin-browser` 5.1, plus **`downshift` 9.4.0** (released 2026-06-30; MIT; peer `react >=16.12.0`). The newest release, 9.5.0 (2026-11-09), and 9.4.1 (2026-11-07) are less than two weeks old on the day this plan was written, so 9.4.0 is pinned; Task 0 checks again.

**Spec:** `docs/architecture/design-system.md` ("DS", by section number): 4.6 Select (the trigger's look), 4.7 Combobox, 4.13 Status pill (the removable tag), 4.17 Menu (the item's look), 4.18 Popover, 4.40 ScrollArea and Spinner, section 7 (keyboard paths, targets, announcements), section 8 (writing), section 9 (the check). Every measure this part needs is also written out in A1, A3 and "What this part relies on", so nothing depends on a reference page.

**Bound by:** I-3436 (Downshift with the Radix popover; `cmdk` not added), I-1721 (one shared Combobox on Downshift and Radix), I-3404 (`cmdk`'s approval, now not used), I-1452 and I-1490 (a searchable filter and a searchable parent: later parts), I-3373 (every word through `__()`), I-797 (a comment cites one I-number or one document path), I-569, I-3317, I-3369.

**New dependencies:** `downshift` 9.4.0, nothing else. **Migrations, permissions, operations, routes, contract members (`packages/teme-contracts`), tokens:** none. **Drafts:** `B-?? (draft)` for plan faults.

---

## Where the documents and the code disagree

1. **DS 4.7 names `cmdk`** ("Built on Popover plus a filterable listbox (`cmdk`, approved: I-3404)"). I-3436 replaces it with Downshift. **Downshift is used**; DS 4.7 is updated at the merge.
2. **DS 4.7 says the palette uses the same listbox.** Not in this part: the palette keeps its current code and moves onto the Combobox's parts later.
3. **Ownership.** This part owns `ui/combobox.tsx`. It also changes `components/term-picker.tsx` (no other part owns it; its three callers do not change) and adds one section to `pages/dev/catalogue.tsx` (additive). It does not touch the palette.
4. **The phone.** The approved responsive design turns popovers into sheets from the foot of the screen under 640px. That is a change to the shared Popover, left to its own part. **The Combobox stays a popover on phones here**, with touch-sized items.

## What this part relies on

Written out here so this plan stands alone.

- **The seams.** `resources/css/app.css`'s token block and the existing files in `components/ui/**` and `components/patterns/**` belong to the foundation. A part may **add** new files to `ui/` and `patterns/`; it never edits an existing one or a token. A change to them is asked of the maintainers, who make it once on the integration branch.
- **The values used here** (all tokens in `app.css`): colors `bg-surface`, `bg-surface-2`, `bg-surface-3`, `text-ink`, `text-ink-2`, `text-ink-3`, `border-edge`, `border-line`, `outline-ring`; heights `h-(--ctl-h)` 36px and `h-(--ctl-h-sm)` 32px, both 44px on a coarse pointer, so items drawn at `--ctl-h` are touch-sized with no extra rule; type `text-caption` 12px, `text-small` 13px, `text-body` 15px; radii `rounded-sm` 6px, `rounded-md` 8px, `rounded-lg` 12px; `shadow-float`; motion `duration-(--dur-1)`, `ease-(--ease)`. The Select's trigger (`ui/select.tsx`) is the look the Combobox's button copies.
- **The design check.** `tests/Architecture/DesignSystemTest.php` runs a Node scanner (`tests/Fixtures/design-system-scan.mjs`) over `resources/js` and `resources/css` and compares what it finds with `tests/Architecture/design-system-baseline.txt` (one line per offense: `rule<TAB>path<TAB>match`, the path relative to `resources/`). The rules a new component is most likely to meet: **R1** arbitrary `[…]` values; **R2** text sizes other than the six tokens (`text-xs`, `text-[13px]`, …); **R3** spacing steps 1.5, 2.5, 3.5, 7, 9, 11, 14, 15; **R4** raw colors (palette classes, hex, `white`, `black`); **R5** raw `<button>`, `<input>` (not `hidden`), `<select>`, `<textarea>` outside `ui/` and `patterns/`; **R6** bare `rounded`, `shadow`, numbered `z-`, `leading-`, `tracking-`, font weights other than 400, 500, 600; **R8** `overflow-auto` or `-scroll` anywhere but `ui/scroll-area.tsx`; **R10** `outline-none` without a `focus-visible:` outline in the same list; **R11** a `var(--x)` or color class naming no token; **R14** `transition`, `duration-N`, `ease-in/out`, and `animate-*` outside `ui/` without `motion-safe:`. **Lowering the baseline:** fix the offense; run `php artisan test tests/Architecture/DesignSystemTest.php`; its first case lists what is gone; remove exactly those lines and lower that rule's number in `dsCounts()` in the same file. **Never add a line or raise a number.**
- **The admin's words.** `tests/Architecture/AdminWordsTest.php` lists, in `tests/Architecture/admin-words-baseline.txt`, every word a person reads that does not yet pass through `__()` or `__n()` from `@/lib/i18n`. New words always go through them. Moving a listed word into `__()` makes the test list it as gone: remove that line and lower the count in its "never lets the baseline grow" case by as many. New words are in American English (I-3439).
- **Browser-test helpers** in `tests/Pest.php` (used, never edited): `cmSignIn(User $person)` signs in through the real form and returns the page at Home; `cmNavigate($page, string $url)` visits in one attempt (never two `->navigate()` calls in a row); `cmWaitUntil($page, string $jsCondition)` polls a condition in the page (the plugin's `assertSee` and `assertNotPresent` look once and race the render); `shellSettled($page, string $selector)` waits until the elements matching `$selector` have no animation of their own running; `cmEditorSettled($page)` waits for the editor's writing surface and the sidebar's fold; `cmSmallTargets($page)` returns `['small' => [...], 'checked' => n]` for targets under 24px; `uiComponent(string $path)` and `uiTsxFiles()` read sources under `resources/js` for architecture tests.
- **Browser-test rules.** Run after `npm run build`, one file at a time, output redirected to a file and read, never piped through `grep` or `sed` (it hangs). Wait for a popover or dialog to settle before clicking inside it: `shellSettled()` on its own selector, never `getAnimations({ subtree: true })` (a spinner inside animates forever). Select by the attributes the components write (`role`, `aria-*`, `data-slot`, `data-test`). Run only the browser files this part touches; GitHub runs the full set after the merge.

## The decisions, written out

**A1. One file.** `resources/js/components/ui/combobox.tsx` exports `Combobox`, `ComboboxOption` (type), `ComboboxProps` (type) and `matchesEveryWord`. The list's parts (search row, list, group heading, item, empty and searching rows) are functions inside the file, drawn to DS 4.17's menu item: 36 tall (44 on touch), radius `md`, padding 0 12, gap 12, a 20px icon in `text-ink-2`, an optional second line (`text-small` `text-ink-3`), an optional end note; highlighted `bg-surface-3`; a 16px tick at the end when chosen; group headings `text-caption` 600 uppercase `text-ink-3`. They are exported later, when the palette moves onto them. Every word goes through `__()`. The file keeps shadcn's own formatting.

**A2. `Combobox`'s props.** Written out; add nothing without recording a `B-?? (draft)`.

```ts
export type ComboboxOption = {
    value: string
    label: string
    description?: string
    icon?: LucideIcon
    keywords?: string[]
    group?: string
    disabled?: boolean
}

type ComboboxBase = {
    options: ComboboxOption[]
    id?: string
    placeholder: string
    searchPlaceholder: string
    /** Shown on the button in place of the chosen labels (the tag picker shows its tags beside it). */
    triggerLabel?: string
    emptyText?: string
    /** Remote search: the caller fetches and passes `options`; local filtering is then off. */
    search?: { value: string; onChange: (value: string) => void; loading: boolean }
    /** Writes one hidden input per chosen value under this name, as given (`terms[]`). */
    name?: string
    disabled?: boolean
    size?: "md" | "sm"
    "aria-describedby"?: string
    "aria-invalid"?: boolean
}

export type ComboboxProps = ComboboxBase &
    (
        | { multiple?: false; value: ComboboxOption | null; onValueChange: (value: ComboboxOption | null) => void }
        | {
              multiple: true
              value: ComboboxOption[]
              onValueChange: (value: ComboboxOption[]) => void
              /** The removable tags beside the button; their remove button's name. */
              removeLabel: (option: ComboboxOption) => string
          }
    )
```

The value is the option, not its key, so a remote search never needs a second lookup to show what is chosen.

**A3. Who does what.**
- **Radix `Popover`** owns open and closed, placement, dismissal and focus. The button is `PopoverTrigger` (a `button` with Radix's own `aria-haspopup="dialog"`, `aria-expanded`, `aria-controls`); it is **not** given `role="combobox"`. It looks like the Select's trigger (DS 4.6) with a 16px `ChevronsUpDown` at the end, and shows the chosen label, or the chosen labels joined by ", " on one truncated line, or `triggerLabel`, or the placeholder in `text-ink-3`. Its name comes from the `Field`'s label through `id`. `PopoverContent` takes `p-1`, `w-(--radix-popover-trigger-width)`, `min-w-60`, `align="start"`; `onOpenAutoFocus` prevents Radix's default and focuses the search; on close Radix returns focus to the button. The list is a `ScrollArea` of at most `max-h-80`.
- **Downshift `useCombobox`** owns the search field (`getInputProps`: `role="combobox"`, `aria-activedescendant`, `aria-controls`, the keys), the list (`getMenuProps`: `role="listbox"`) and each item (`getItemProps`: `role="option"`, highlight). `isOpen` is controlled and equals the popover's state; `onIsOpenChange` closing it closes the popover.
- **Downshift `useMultipleSelection`** (multiple only) owns the chosen list (`selectedItems` controlled by `value`, `onSelectedItemsChange` calls `onValueChange`). Its `getSelectedItemProps` go on the removable tags (the flat `StatusPill` with `onRemove`), so ← and → move between them and Delete or Backspace removes the focused one; `getDropdownProps({ preventKeyAction: isOpen })` goes on the button.
- **Identity:** both hooks get `itemToKey: (option) => option?.value` and `itemToString: (option) => option?.label ?? ""`. Two options with one label stay two items; a chosen option returned again by a new search is recognized as chosen.

**A4. The state reducer, written out.** Downshift's defaults suit a free-text combobox; this one is a chooser inside a popover. The changes:

```ts
// The popover's search is never the value: leaving or Esc chooses nothing and clears nothing.
function comboboxReducer(multiple: boolean) {
    return (state: UseComboboxState<ComboboxOption>, { type, changes }: UseComboboxStateChangeOptions<ComboboxOption>) => {
        switch (type) {
            case useCombobox.stateChangeTypes.InputBlur:
                // Downshift chooses the highlighted item on blur; here only Enter or a click chooses.
                return { ...changes, selectedItem: state.selectedItem, inputValue: state.inputValue }
            case useCombobox.stateChangeTypes.InputKeyDownEscape:
                // Downshift clears the choice on a second Esc; here Esc only closes.
                return { ...changes, selectedItem: state.selectedItem, inputValue: state.inputValue }
            case useCombobox.stateChangeTypes.InputKeyDownEnter:
            case useCombobox.stateChangeTypes.ItemClick:
                return multiple
                    ? { ...changes, isOpen: true, highlightedIndex: state.highlightedIndex, inputValue: state.inputValue }
                    : { ...changes, inputValue: "" }
            default:
                return changes
        }
    }
}
```

In multiple mode `selectedItem` stays `null`; `onSelectedItemChange` toggles the option in `value` (add when absent, remove when present) and the reducer above keeps the popover open, the highlight and the search where they were.

**A5. Keys.** On the search, from Downshift: ↑ ↓, Home, End, Page Up, Page Down (ten), Enter, Esc. On the button, written out (Downshift's typeahead belongs to `useSelect`, which is not used):

```ts
onKeyDown={(event) => {
    if (event.key === "ArrowDown") {
        event.preventDefault()
        setOpen(true)
    } else if (event.key.length === 1 && event.key !== " " && !event.ctrlKey && !event.metaKey && !event.altKey) {
        // A letter typed on the button opens it with that letter searched.
        event.preventDefault()
        changeSearch(event.key)
        setOpen(true)
    }
}}
```

**A6. Chosen is `aria-checked`, never `aria-selected`.** Downshift sets `aria-selected` on the highlighted item. Each item of a multiple Combobox carries `aria-checked={chosen}`; styles read `aria-selected:bg-surface-3` (highlighted) and `aria-checked:` (the tick). A single Combobox shows its tick on the chosen item too, without `aria-checked`.

**A7. Chosen first, in an order fixed as the popover opens** (DS 4.7), with an empty search, so ticking never moves an item under the pointer:

```ts
// Fixed as it opens: ticking an item never moves it under the pointer.
const changeOpen = (next: boolean) => {
    if (next && multiple) {
        const chosen = new Set(value.map((option) => option.value))
        setOpening([...value, ...options.filter((option) => !chosen.has(option.value))])
    }
    setOpen(next)
}
```

With a search typed, the options come in their given order, chosen ones ticked where they appear.

**A8. The filter.** Local lists (no `search` prop) are filtered by this function; remote lists are shown as given.

```ts
/** Matches when every word typed is among the option's words; the list keeps its own order. */
export function matchesEveryWord(option: ComboboxOption, search: string): boolean {
    const words = search.toLocaleLowerCase().split(/\s+/).filter((word) => word !== "")
    const haystack = [option.label, ...(option.keywords ?? [])].join(" ").toLocaleLowerCase()

    return words.every((word) => haystack.includes(word))
}
```

**A9. Announcements.** Downshift 9 announces nothing by default. `useCombobox` gets `getA11yStatusMessage` returning, when open: with `search.loading`, "Searching…"; with no item, "Nothing matches."; otherwise `__n(":count result|:count results", count)`. `useMultipleSelection` gets `getA11yStatusMessage` returning `__(":name removed", …)` when the chosen list shrinks and `__(":name added", …)` when it grows.

**A10. Hidden inputs.** One `<input type="hidden" name={name} value={option.value} />` per chosen option; one empty one for a single Combobox with nothing chosen.

**A11. The tag picker.** `components/term-picker.tsx` keeps its export and props (`taxonomy`, `singular`, `label`, `labelHidden`, `value: TermChip[]`, `onChange`), so `editor/rail/taxonomy-panel.tsx`, `list/bulk-edit-dialog.tsx` and `pages/content/index.tsx` do not change. Inside: a multiple `Combobox` with `triggerLabel={__("Find a :name", { name: singular.toLowerCase() })}`, `removeLabel={(option) => __("Remove :name", { name: option.label })}`, `name="terms[]"`, and `search` fed by today's request (`search as searchTerms` from `@/routes/terms`, 200 ms after typing stops, the previous request superseded). Each result becomes `{ value: id, label: name, description: path }`, the description only when the path differs from the name. Chosen terms are no longer filtered out of the results: they show ticked, and unticking removes them.

---

## Files

**Create:** `resources/js/components/ui/combobox.tsx`; `tests/Architecture/ComboboxSourceTest.php`; `tests/Browser/ComboboxTest.php`.
**Modify:** `package.json`, `package-lock.json` (A12); `resources/js/components/term-picker.tsx`; `resources/js/pages/dev/catalogue.tsx` (one section); `tests/Browser/DesignCatalogueTest.php` (only if a whole-page case counts sections); `tests/Architecture/design-system-baseline.txt`, `DesignSystemTest.php` (counts lowered); `tests/Architecture/admin-words-baseline.txt`, `AdminWordsTest.php` (lines removed, count lowered).

**A12. The lock.** One direct dependency, `downshift` 9.4.0. The lock gains `downshift` and only the packages it needs that are not already met: expected `prop-types`, `loose-envify`, `object-assign`, `compute-scroll-into-view`, and nested copies of `react-is` (16 for `prop-types`, 18 for `downshift`; the root's 17.0.2 stays) and `js-tokens` (4, for `loose-envify`; the root's 10.0.0 stays). **No existing entry changes.** If `npm install` rewrites the root's `"name"` or drops a platform-only entry, put it back by hand and record it.

## Global Constraints

- **Rule 1:** nothing here changes state through a new path. The term search is called as today; no operation is added, so no authorization-failure test applies.
- **Rule 3:** PostgreSQL only.
- **Rule 5:** `packages/teme-contracts` is not touched.
- **No dependency but `downshift` 9.4.0** (A12). Never change `composer.json` or `composer.lock`.
- **Every word a person reads goes through `__()` or `__n()`** from `@/lib/i18n` (`AdminWordsTest`). The words of `term-picker.tsx` move into `__()`, which lowers that baseline. Never raise its count.
- **The design-system check's baseline only shrinks.** At the end, no line in `tests/Architecture/design-system-baseline.txt` names `js/components/term-picker.tsx` or `js/components/ui/combobox.tsx`. Removing lines: run `php artisan test tests/Architecture/DesignSystemTest.php`, remove exactly the lines it lists as gone, lower those rules' numbers in `dsCounts()`. **Adding a line, raising a number, or widening an allowed place in the scanner is a STOP.**
- **No token, and no change to an existing `ui/` component.** If the Combobox needs one, STOP: tokens and existing components belong to the foundation (the seams, above).
- **The palette is not touched:** nothing in `components/palette/**` changes.
- **Tests first:** each test below is run red for the reason given before the code. An architecture test is planted red first.
- **The `.env`:** in a fresh checkout, `cp .env.example .env` and `php artisan key:generate`. Never copy, read or edit any other `.env`.
- **Never edit** `.ai/`, `CHANGELOG.md`, the decision log, `docs/roadmap.md`, `docs/backlog.md`, `docs/architecture/*`, `docs/reference/*`.
- **Conventional Commits**, staged by file (never `git add -A`), no closing keywords, written for the public: no private names or paths. A code comment cites at most one I-number or one document path (I-797).
- `components/ui/*` keeps shadcn's formatting; every other file follows `npm run check:fix`.
- **`tests/Architecture` files that use `resource_path()` declare `uses(TestCase::class)`.** Helpers used by one file stay in it with its prefix (`cb`); `tests/Pest.php` is not edited.
- **Browser files** run after `npm run build`, one file at a time, output redirected to a file and read, never piped through `grep` or `sed`. Before clicking inside a popover, wait for its own animations to end (`shellSettled()`), never `getAnimations({ subtree: true })`.
- **The quick check** after each task: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, `npm run types:check`, `npm run check`, `php artisan test --testsuite=Architecture`, and the task's own test files.
- **When the work is interrupted:** each task ends committed and pushed, with one line in Records: "Done to Task N at `<hash>`, the quick check green". Node missing: run `export PATH=/opt/node24/bin:$PATH`; if it is still missing, stop, push and record it. `npm install` cannot reach the registry: stop before Task 1's install, push, record "waiting for the network". A test red at the base and not this part's: record it, never change it.

---

## Task 0: Preconditions, then branch

Run in a checkout of the integration branch. If any line differs **by its text**, STOP and write why under Records.

The integration branch is `origin/feat/interface-foundation`, or `origin/main` once the foundation has merged there; `<base>` below means that one.

- [ ] **Step 1:** `git fetch origin && git log --oneline --fixed-strings --grep="feat(admin): the design system and the admin's new frame" <base>` → one commit. If it is missing, STOP. Record the base's head.
- [ ] **Step 2:**
  - `grep -n '"downshift"\|"cmdk"' package.json` → nothing; `ls resources/js/components/ui/combobox.tsx tests/Architecture/ComboboxSourceTest.php tests/Browser/ComboboxTest.php 2>&1` → none exists;
  - `php -v` shows 8.4; `node --version` 22.18 or later; `pg_isready -h 127.0.0.1 -p 5433` answers;
  - `ls tests/Architecture/DesignSystemTest.php tests/Architecture/AdminWordsTest.php tests/Fixtures/design-system-scan.mjs resources/js/pages/dev/catalogue.tsx` → all exist; `php artisan test tests/Architecture/DesignSystemTest.php` and `php artisan test tests/Architecture/AdminWordsTest.php` → green; `grep -c -E "^function (cmSignIn|cmNavigate|cmWaitUntil|shellSettled|cmEditorSettled|cmSmallTargets|uiComponent|uiTsxFiles)\(" tests/Pest.php` → 8;
  - `ls resources/js/components/ui/popover.tsx resources/js/components/ui/scroll-area.tsx resources/js/components/ui/spinner.tsx resources/js/components/ui/status-pill.tsx resources/js/components/ui/field.tsx` → all exist; `grep -n "onRemove" resources/js/components/ui/status-pill.tsx` → at least one line;
  - `grep -n "export function TermPicker" resources/js/components/term-picker.tsx` → one line; `git grep -n "from '@/components/term-picker'" -- resources/js` → exactly `editor/rail/taxonomy-panel.tsx`, `list/bulk-edit-dialog.tsx`, `pages/content/index.tsx`;
  - `git grep -l -E 'role="(listbox|option|combobox)"' -- resources/js` → record the list (expected: `components/editor/slash-menu.tsx`, `components/media/media-grid.tsx`, `components/palette/command-palette.tsx`, and files in `components/ui/`);
  - record `grep -c . tests/Architecture/design-system-baseline.txt`, the lines naming `term-picker`, and `AdminWordsTest`'s count (`grep -n "toHaveCount(" tests/Architecture/AdminWordsTest.php`);
  - `npm view downshift time --json`: record the newest release and its date. Use 9.4.0 unless a newer 9.x is at least two weeks old on the day, then that one (record it); a new major is a STOP. `npm view downshift@<chosen> license peerDependencies` → MIT, `react >=16.12.0`.
- [ ] **Step 3:** restate the stack from `composer.lock` and `package-lock.json` in Records. `git checkout -b ui/combobox <base>`.

## Task 1: `downshift`, and the test that keeps it in one file

**Test, written first** (`tests/Architecture/ComboboxSourceTest.php`):

```php
<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

it('imports downshift in one file only, the combobox', function (): void {
    $importers = array_values(array_filter(
        uiTsxFiles(),
        fn (string $file): bool => preg_match("/from ['\"]downshift['\"]/", uiComponent($file)) === 1,
    ));

    expect($importers)->toBe(['components/ui/combobox.tsx']);
});

it('draws a list to search only through the combobox', function (): void {
    // Shrinks only: the media grid is a grid of tiles; the slash menu and the palette move to the combobox in later parts.
    $allowed = ['components/editor/slash-menu.tsx', 'components/media/media-grid.tsx', 'components/palette/command-palette.tsx'];

    $found = array_values(array_filter(
        uiTsxFiles(),
        fn (string $file): bool => ! str_starts_with($file, 'components/ui/')
            && ! in_array($file, $allowed, true)
            && preg_match('/role="(listbox|option|combobox)"/', uiComponent($file)) === 1,
    ));

    expect($found)->toBe([]);
});
```

- [ ] **Step 1:** run it: the first case fails (`[]` is not `['components/ui/combobox.tsx']`). The second passes today: plant `role="listbox"` on the term picker's `<ul>`, see it listed, remove it. Record both first lines. If Task 0's list held a file outside `ui/` and the allowed list, STOP (the allowed list is not widened without the maintainers).
- [ ] **Step 2:** `npm install downshift@9.4.0` (or Task 0's choice); check the lock against A12 and the license; record the version resolved and the entries added. Commit `build(deps): add downshift 9.4.0 for the combobox (I-3436)`.

## Task 2: The Combobox

**Files:** `ui/combobox.tsx`; `pages/dev/catalogue.tsx` (a `#combobox` section); `tests/Browser/ComboboxTest.php`.

**The catalogue section** draws, each with a `Field` and an id: `catalogue-combobox-single` (Author, 30 sample names, one chosen, two of them with the same name and different second lines), `catalogue-combobox-multi` (Tags, 20 sample names, two chosen), `catalogue-combobox-empty-list` (no options), `catalogue-combobox-searching` (`search.loading` true), `catalogue-combobox-disabled`, `catalogue-combobox-error` (`aria-invalid` with the Field's error). Sample words through `__()`.

**Tests, written first** (`tests/Browser/ComboboxTest.php`; they fail first on `Timeout … waiting for` the catalogue's ids):

| Case | What it proves |
|---|---|
| "chooses an author with the keyboard alone: Tab, Enter, two words typed, the arrow and Enter" | Tab reaches the button; Enter opens with focus in the search (`document.activeElement` has `role="combobox"`); typing two words leaves only the options holding both, in their given order; ArrowDown then Enter chooses; the popover is gone, focus is on the button, its text is the chosen name, the hidden input holds its value |
| "moves with Home, End, Page Down and the arrows, one highlighted item at a time" | After each key, exactly one `[role="option"][aria-selected="true"]`, its id equal to the search's `aria-activedescendant`: Home the first, End the last, Page Down ten on |
| "opens with the letter typed on the button already searched" | Focus on the button, type `m`: the popover is open and the search's value is `m` |
| "closes on Esc, twice, and changes nothing" | Open, ArrowDown, Esc: closed, focus on the button, choice and hidden input unchanged; Esc again on the button: still unchanged |
| "chooses nothing when focus leaves with an item highlighted" | Open, ArrowDown twice, Tab away: closed, choice unchanged (the reducer's `InputBlur`) |
| "keeps two authors with one name apart" | Choosing the second of the two same-named options marks that one chosen and writes its value, not the first's (`itemToKey`) |
| "ticks several tags, stays open, and lists the chosen first when opened again" | Open the multi, Enter on two options: still open; those options `aria-checked="true"`; two more hidden inputs; close and open: the first four options are the four chosen, in order |
| "moves between the chosen tags with the arrows and removes one with Delete" | Focus a tag, ← and → move between tags, Delete removes the focused one and its hidden input |
| "announces the count, Nothing matches. and Searching…" | The live region Downshift writes holds "1 result" after a search with one match (read at N = 1), "Nothing matches." on the empty case, "Searching…" on the searching case; after a tag is removed, ":name removed" |
| "passes axe with each one open, every target 24 pixels or more, at 1280 and 375 pixels in both schemes" | `assertNoAccessibilityIssues()` with the single and the multi open in turn; `cmSmallTargets()` empty |

- [ ] **Step 1:** write the cases; run after `npm run build`; record the first failure.
- [ ] **Step 2:** build `ui/combobox.tsx` as A1 to A10 and the catalogue section; run the file until green. Then plant each of these, see its case red, and remove it, recording the first line: the reducer's `InputBlur` branch removed; `itemToKey` removed. Then `ComboboxSourceTest` (now green in full), `DesignSystemTest` (the new file adds no line: if it does, fix the file, never the baseline) and `AdminWordsTest`.
- [ ] **Step 3:** quick check; commit `feat(admin): the combobox, one searchable list on Downshift (I-3436)`; push; the Records line.

## Task 3: The tag picker on the Combobox

**Files:** `components/term-picker.tsx`; one case added to `tests/Browser/ComboboxTest.php`.

**Test, written first:** "finds and chooses a tag in the editor with the keyboard alone, and keeps it on save". An article whose type has a flat taxonomy and an existing tag "Harbour"; open the editor; Tab to the Tags button; type "harb": the search holds "harb", and once "Searching…" is gone the option "Harbour" is present; Enter ticks it; Esc closes; the rail shows a removable "Harbour" tag; save as the editor's existing tests do; the article's terms hold Harbour's id. It fails first on the Tags button (no popover today).

- [ ] **Step 1:** write the case; run; record the failure.
- [ ] **Step 2:** rebuild the picker as A11. Its three callers do not change: `git diff --stat` shows none of them.
- [ ] **Step 3:** run `ComboboxTest`, and every browser file that opens Quick edit, Bulk edit or the editor rail's taxonomies (`git grep -l -i -E "quick edit|bulk edit|bulk-edit|taxonom" -- tests/Browser`), one at a time; all green. `DesignSystemTest` and `AdminWordsTest`: remove the picker's lines, lower the counts.
- [ ] **Step 4:** quick check; commit `feat(admin): the tag picker on the combobox`; push; the Records line.

## Task 4: Gate and records

- [ ] The full `composer check` once. The browser files this part added or changed, after `npm run build`, one at a time: `ComboboxTest`, `DesignCatalogueTest`, and those Task 3 ran; GitHub runs the rest after the merge.
- [ ] `grep -c -E 'js/components/(term-picker|ui/combobox)' tests/Architecture/design-system-baseline.txt` → 0; `git diff --stat <base> -- resources/js/components/palette` → nothing.
- [ ] Records: the commits, the totals, the baseline counts per rule and the admin-words count before and after, `downshift`'s resolved version, license and the lock entries added, every `B-?? (draft)`, and how to try it: on a post with tags, Tab to Tags, type part of a name, Enter, Esc; `/admin/_catalogue#combobox` on a local site. Commit `docs(plans): interface part A records`; push.

---

## Every word

**New**, through `__()` or `__n()`: "Nothing matches." (the Combobox's default), "Searching…", ":count result|:count results" (read at N = 1: "1 result"), ":name added", ":name removed", "Find a :name", "Remove :name", and the catalogue's section name, description and sample words. **Moved into `__()` unchanged:** every word in `term-picker.tsx` the admin-words baseline lists today.

## What must not be done

- Touch the command palette (`components/palette/**`) or its tests.
- Adopt the Combobox anywhere else: the list filters, the term parent, Quick and Bulk edit's author, the link search, the button card, the Tools launcher, the home page chooser, the menu editor, the redirect form, the importer and people's reassignment belong to the parts that rebuild those screens.
- Let a tag be created from the picker other than as Follow-up W1-2 builds it: on the existing `CreateTerm` operation and `terms.store` route, offered only where the page's `canCreate` says the server allows it.
- Use `useSelect` or the `Downshift` render-prop component; import `downshift` outside `ui/combobox.tsx`.
- Change any existing `ui/` component, a token, the scanner, `tests/Pest.php`, or the category tree (`term-tree-picker.tsx`).
- Add a dependency other than `downshift`; change an existing lock entry.
- Delete a test, or change a test's meaning.

## STOP conditions

Stop, write why under Records, commit and push, then stop, when:
- the foundation commit is missing from the base, or a Task 0 line differs by its text;
- `downshift` has a new major, its license is not MIT, or installing it changes an existing lock entry;
- the Combobox needs a token or a change to an existing `ui/` component;
- a design-system or admin-words line would have to be added or a count raised, or the scanner's allowed places widened;
- a browser test not named here would have to change;
- Downshift cannot do what A3 to A9 ask (controlled `isOpen` with Radix's focus, the reducer's cases, `itemToKey`, the status messages) without patching it.

## Security

- No route, permission or query changes. The term search keeps its checks and rate limit.
- The Combobox renders text, never HTML.

## At the merge (for the maintainers)

- `docs/architecture/design-system.md` 4.7: built on Downshift with the Radix popover (I-3436); the button opens a popover and the search is the combobox; matching by words; chosen is `aria-checked`; leaving and Esc choose nothing. The palette's line ("same listbox inside a dialog") stays as the target for the later part.
- I-3404's `cmdk` approval: marked not used (I-3436).
- Changelog line, at the release: "Searchable lists now work the same way everywhere, starting with the tag picker, and can be used by keyboard alone."
- Baseline conflicts with other parts: take both sides' removals, then run `DesignSystemTest` and `AdminWordsTest` and set each count to what the merged baseline holds.

## Open points

| # | Point | Recommendation |
|---|---|---|
| 1 | Creating a tag from the picker, as the demo draws | Built here (Follow-up W1-2, the proposal's Settled 1): on the existing `CreateTerm` and `terms.store`, with no new operation, permission or route |
| 2 | A multiple Combobox's button: chosen names or a count ("3 tags") | Names on one truncated line, as built; the tag picker shows removable tags beside it |
| 3 | Popovers as sheets from the foot of a phone screen (the approved responsive design) | The phone-overlays part changes the shared Popover once; the Combobox inherits it |
| 4 | The palette on the Combobox's list parts, and its groups (DS 4.34 vs the built ones) | A later part exports the list parts from `ui/combobox.tsx` and moves the palette; decide the groups then |

---

## Records

<!-- Filled in while building: commits; each test seen red (task, file, case, the failure's first line); downshift's resolved version, license and the lock entries added; the design-system and admin-words counts before and after; differences from the plan as B-?? (draft); changelog lines; any STOP. -->
