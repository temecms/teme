# Interface part H: Home

**One part, one branch: `ui/home`, from `origin/main` once wave 2 is merged.** It rebuilds Home on the design system: the panel grid in four sizes, the figures as tiles, Quick draft as a panel, the From plugins section, and Customize, where each person moves, resizes, hides, adds and drags panels, saved as their preferences. Nothing else runs beside it on these files, so it is one part: the server's arrangement, the page and the drag are one piece of work that only makes sense together.

Steps use checkbox (`- [ ]`) syntax for tracking.

**Written 2026-11-15** for the repository `temecms/teme`. It starts from `origin/main` with the six wave-2 parts merged (content lists, editor, media, tools screens, plugin areas, phone overlays). Task 0 checks they are. This plan is lighter (I-569): it writes out the decisions, names, preferences, tests and the delicate code (the arrangement rules and the drag), and describes the rest.

**Goal.** Home as v10 draws it, arranged by each person, every word through `__()`, and no line left in the design-system or admin-words baselines for Home's files.

**Tech stack** (Task 0 restates the exact versions from the locks): Laravel 13, PHP 8.4, PostgreSQL 18, Inertia 3, React 19, TypeScript, Tailwind 4, shadcn/ui on Radix, Vite 8 on vite-plus, Pest 5 with the browser plugin. **New:** `@atlaskit/pragmatic-drag-and-drop` 4.0.0 and `@atlaskit/pragmatic-drag-and-drop-hitbox` 3.0.0.

**Spec:** `docs/proposals/home.md` (its Settled section is binding; every item in it is confirmed). The picture: `docs/demos/v10/index.html` with `index.js` (Home) and `teme.css` §§ `.figures`, `.dash`, `.cust-bar`, `.panel-ctl`; `RESPONSIVE-NOTES.md`'s Dashboard row. `docs/architecture/design-system.md` ("DS"): 4.x Panel, Segmented, Menu, Toast, Notice, 4.40 Plugin mark, section 5 (Page patterns), 7 (accessibility), 8 (writing), 9 (the check).

**Bound by:** I-161 (your own work first; empty panels hidden), I-210 (option B: per person, with a site default), I-200 (plugins' panels in their own labelled section), I-3401 (Home as D2 drew it), I-3441 (Pragmatic drag and drop, with keyboard moves as an equal way), I-3397 (the site status lives in the top bar), I-3384 (the plugin panel socket stays parked), I-3439 (American English), I-3373 (every word through `__()`), I-797, I-569, I-3317, I-3369.

**New dependencies:** the two packages above, exact versions (no `^`), with what they bring: `raf-schd` 4.0.3 and `bind-event-listener` 3.0.0 (MIT); `@babel/runtime` is already in the lock. **Migrations, permissions, operations, routes, contract members (`packages/teme-contracts`), tokens:** none. **Drafts:** `B-?? (draft)` for plan faults and small decisions.

---

## Where the documents and the code disagree

1. **v10 draws a Site status panel and six plugin panels.** The proposal leaves them out (decision 6; plugins' panels are pictures). The From plugins section is built, drawn only when a registered panel's `owner` is not `core`; tests register one.
2. **v10 draws "+ Page".** Not built (Settled 7). The main type's New button stays.
3. **v10 writes "Customise".** The admin's source is American English (I-3439): **Customize**, "Customizing your Home".
4. **v10's Move buttons are `disabled` at the ends.** A disabled focused button drops focus to the page (the content-lists review, follow-up X1-2). Here they are `aria-disabled="true"` and ignore the click.
5. **`DashboardPageTest` and `DashboardPanelsTest` pin `panels`** (its keys, "leaves out a panel with nothing in it", "greets a person with nothing waiting once"). Those meanings stay: `panels` keeps sending only panels with rows, now in the person's order and without hidden ones. Customize reads the new `catalogue` and `layout` props instead (D2). No existing assertion changes.
6. **`PreferencesTest`'s "declared defaults"** lists every preference: it gains the three new ones at its end (Task 1). Its meaning is kept.
7. **`WordingPassSettingsTest`'s "offers Open Search and social as a button beside Home's search engines notice"** reads `pages/dashboard.tsx`: `asChild` must still come before `{searchEngines.actionLabel}`, after `{searchEngines.detail}`. Keep that order in the notice; if the notice moves to its own component, point the case at that file, same meaning, and record it.
8. **`ContentScreensTest`** reads `components/dashboard/dashboard-panel.tsx` for `shownTitle(` and `pages/dashboard.tsx` for no `new: '1'`. Keep `shownTitle` in the file that draws a row's title; if that file's name changes, point the dataset at it and record it.

## What this part relies on

- **The seams.** `resources/css/app.css`'s tokens and the existing files in `components/ui/**` and `components/patterns/**` belong to the foundation. A part may **add** files there; it never edits an existing one or a token. A change to them is a STOP.
- **Components used, unchanged:** `patterns/page.tsx` and `patterns/page-header.tsx` (the Page pattern: title, description, actions), `ui/panel.tsx` (`Panel`, `PanelHeader`, `PanelBody`), `ui/segmented.tsx` (`Segmented`, `SegmentedItem`, a Radix toggle group), `ui/dropdown-menu.tsx`, `ui/plugin-mark.tsx` (`PluginMark`: `name`, `iconOnly`), `ui/notice.tsx`, `ui/tooltip.tsx`, `ui/button.tsx` (pass `variant`), `ui/skeleton.tsx`, `ui/error-state.tsx`, `@/lib/i18n` (`__`, `__n`), `@/lib/preferences` (`savePreferences(values): Promise<boolean>`), `@/lib/titles` (`shownTitle`), the toast (`flash-toast` as the shell gives it, with an action for Undo; read how wave 2's parts raise a toast with Undo from the client and do the same).
- **The preferences.** `app/Domain/Preferences/BuiltInPreferences.php` registers `PreferenceDefinition`s; kind `list` takes `allowed` (a closure listing allowed strings, read when a value is checked) and `maxItems`; a list with duplicates or an item not allowed is refused; a stored list is read back without items no longer allowed (`keepAllowedItems`). The client saves through `savePreferences()`, which posts to `PreferencesController` and `UpdatePreferences` (its refused-writes-nothing test exists).
- **The design check and the admin's words:** as every wave-2 plan states them (`DesignSystemTest` with `design-system-baseline.txt` and `dsCounts()`; `AdminWordsTest` with `admin-words-baseline.txt` and its count). **Lowering:** fix, run the test, remove exactly the lines it lists as gone, lower the counts. **Never add a line or raise a number.**
- **Browser-test helpers** in `tests/Pest.php` (used, never edited): `cmSignIn`, `cmNavigate`, `cmWaitUntil`, `shellSettled`, `cmSmallTargets`, `lscrPrefer($person, $preferences)`, `guardNode($fixture, $input)` (runs a Node fixture in `tests/Fixtures/` with a JSON input and returns its JSON output, as `RowActionOrderTest` does). `$page->drag($from, $to)` exists; for a drop on one side of a target, `$page->page()->locator($from)->dragTo($page->page()->locator($to), ['targetPosition' => ['x' => …, 'y' => …]])`.

## The decisions, written out

**D1. The registry's panel** (`app/Domain/Dashboard/DashboardPanel.php`). Two constructor parameters are added at the end, named, with defaults, so every existing registration compiles unchanged:

```php
/**
 * @param  'small'|'medium'|'large'|'full'  $size  how wide it is drawn until the person changes it
 * @param  'rows'|'quick-draft'  $kind  what it draws: up to five rows from its query, or the quick draft form
 */
public string $size = 'medium',
public string $kind = 'rows',
```

`$query` becomes `?string` (a `rows` panel must name one; a `quick-draft` panel names none). `DashboardRegistry::register()` refuses a size or kind outside these lists, and a `rows` panel whose query does not implement `DashboardPanelQuery` (today's check), each with an `InvalidArgumentException` naming the panel. `DashboardPanel::SIZES = ['small', 'medium', 'large', 'full']` is the one list of sizes. The provider sets the sizes of Settled 2 and registers `new DashboardPanel('quick_draft', 'Quick draft', 600, null, ['create'], size: 'small', kind: 'quick-draft')`. Labels stay English in PHP and reach the page through `__()` on the server, as the other panel labels do.

**D2. What Home is sent** (`Dashboard::forUser()`). Today's keys stay, with these changes and additions:

```php
/**
 * @return array{
 *   panels: list<array{key: string, label: string, rows: list<array{id: string, title: string, subtitle: string, url: string, at: string}>, seeAll: string|null, failed: bool}>,
 *   catalogue: list<array{key: string, label: string, size: 'small'|'medium'|'large'|'full', kind: 'rows'|'quick-draft', owner: string, ownerName: string|null}>,
 *   layout: array{order: list<string>, hidden: list<string>, sizes: array<string, 'small'|'medium'|'large'|'full'>},
 *   figures: …, mainType: …, gettingStarted: …, canQuickDraft: bool, canUpload: bool, searchEngines: …
 * }
 */
```

- **`catalogue`:** every panel the viewer may see (the types grant its actions, as today; `quick_draft` when `canQuickDraft`), in registry order, empty or not, hidden or not. `ownerName` is the plugin's name for a panel whose `owner` is not `core` (read it the way wave 2's plugin areas name a plugin on its pages; null for core).
- **`layout`:** the person's arrangement read from `PreferencesFor`, cleaned: `order` keeps only catalogue keys, then the catalogue keys it does not name, in registry order (a panel registered later appears at the end of its section); `hidden` keeps only catalogue keys; `sizes` maps every catalogue key to the person's size or the declared one.
- **`panels`:** as today (only `rows` panels with rows), **in `layout.order`, without hidden ones**, each with `failed: false`. **A hidden panel's query is not run.** A query that throws is reported (`report($e)`) and the panel is sent with `rows: []` and `failed: true`; the page goes on. A failed panel is sent even though it has no rows.
- **`canUpload`:** `$viewer->can('media.upload')`.
- The query count stays the same whatever the site holds (the existing "makes the same number of queries" case), and hiding panels only lowers it.

**D3. The preferences** (`BuiltInPreferences::register()`, after the `nav.*` entries). Allowed values are worked out when a value is checked, as `nav.*` do, so a panel registered later is allowed:

```php
// Home (I-210): each person's order, hidden panels and sizes, over the
// registry's declared order and sizes, which are the site default.
$panelKeys = fn (): array => array_map(fn (DashboardPanel $panel): string => $panel->key, app(DashboardRegistry::class)->all());

$registry->register(new PreferenceDefinition('dashboard.order', 'list', [], 'Home panel order', allowed: $panelKeys));
$registry->register(new PreferenceDefinition('dashboard.hidden', 'list', [], 'Hidden Home panels', allowed: $panelKeys));
$registry->register(new PreferenceDefinition('dashboard.sizes', 'list', [], 'Home panel sizes', allowed: fn (): array => array_merge(...array_map(
    fn (string $key): array => array_map(fn (string $size): string => "{$key}:{$size}", DashboardPanel::SIZES),
    $panelKeys(),
))));
```

`dashboard.sizes` holds `"{key}:{size}"` entries; at most one per panel is enforced in `Dashboard` when it is read (the first entry for a key wins), and the client never writes two. **Reset** saves all three as `[]`.

**D4. The arrangement rules** (`resources/js/components/dashboard/home-layout.ts`, new; imports nothing, so `tests/Fixtures/home-layout.mjs` runs it in Node). Written out, since every control and the drag go through it:

```ts
/** A panel's place on Home: its key, its section, and its size. */
export type PanelSize = 'small' | 'medium' | 'large' | 'full';
export type Layout = { order: string[]; hidden: string[]; sizes: Record<string, PanelSize> };
export type Section = 'core' | 'plugins';

/** The section a panel sits in: core's own, or From plugins (I-200). */
export function sectionOf(owner: string): Section {
    return owner === 'core' ? 'core' : 'plugins';
}

/** The shown keys of one section, in the person's order. */
export function shownIn(layout: Layout, owners: Record<string, string>, section: Section): string[] {
    return layout.order.filter((key) => key in owners && sectionOf(owners[key]) === section && !layout.hidden.includes(key));
}

/**
 * Moves a panel to `index` among the shown panels of its own section, and
 * returns the new order. Hidden panels and the other section keep their
 * places in the order, so showing a panel again puts it back where it was.
 */
export function moveTo(layout: Layout, owners: Record<string, string>, key: string, index: number): string[] {
    const section = sectionOf(owners[key]);
    const shown = shownIn(layout, owners, section);
    const from = shown.indexOf(key);
    const to = Math.max(0, Math.min(index, shown.length - 1));

    if (from === -1 || from === to) {
        return layout.order;
    }

    const next = shown.filter((k) => k !== key);
    next.splice(to, 0, key);

    // Write the section's new sequence back into the slots its shown panels held.
    const slots = layout.order.map((k, i) => (shown.includes(k) ? i : -1)).filter((i) => i !== -1);
    const order = [...layout.order];
    slots.forEach((slot, i) => {
        order[slot] = next[i];
    });

    return order;
}

/** One step earlier (-1) or later (+1); the same order at either end. */
export function moveBy(layout: Layout, owners: Record<string, string>, key: string, step: -1 | 1): string[] {
    const shown = shownIn(layout, owners, sectionOf(owners[key]));

    return moveTo(layout, owners, key, shown.indexOf(key) + step);
}

/** The preferences to save for a layout: sizes only where they differ from the declared one. */
export function toPreferences(layout: Layout, declared: Record<string, PanelSize>): { 'dashboard.order': string[]; 'dashboard.hidden': string[]; 'dashboard.sizes': string[] } {
    return {
        'dashboard.order': layout.order,
        'dashboard.hidden': layout.hidden,
        'dashboard.sizes': Object.entries(layout.sizes)
            .filter(([key, size]) => declared[key] !== size)
            .map(([key, size]) => `${key}:${size}`),
    };
}
```

**D5. Saving.** Every change (move, size, hide, show, add, reset) updates the page's layout at once and calls `savePreferences(toPreferences(...))`. If it resolves `false`, the layout goes back to what it was and a toast says "Couldn't save your Home. Try again." Changes are not batched; a second change while one save runs waits for it (one promise chain), so the last saved state is the last change.

**D6. The page** (`resources/js/pages/dashboard.tsx`, on the Page pattern; the old `PageHeader` from `components/list/` is no longer used here).
- **Header:** the title is the greeting (`components/dashboard/greeting.ts`, new, pure: the hour by the browser's clock, 5 to 11 "Good morning, :name", 12 to 17 "Good afternoon, :name", else "Good evening, :name"; `:name` is the person's first name as the shell's account menu shows it, or the whole name). Description: today's date in the admin's locale (`Intl.DateTimeFormat`, weekday, day and month), then " Your work first, then the site's figures and what plugins report." The tab's title stays "Home". Actions: **New :singular** (primary, today's `Form`, when `mainType.canCreate`), **Upload media** (a link to the media library, `canUpload`), **Customize** (ghost, `LayoutDashboard` icon; `aria-pressed` while customizing). While customizing, the actions are not drawn.
- **Notices:** the search engines notice on `ui/notice` (kind warning, the action button after the text, as `WordingPassSettingsTest` reads it), then Getting started (restyled in place, its behavior unchanged).
- **Figures** (`components/dashboard/site-figures.tsx`, restyled): a `nav` labelled "Site figures" of tiles, 5 across from 1024, 3 across from 640, 2 on a phone (v10 `.figures`); each tile the value (tabular numbers, the page's figure size token) and its label with its group, a link when `url` is set, else plain text.
- **Sections:** "Your work" (an `h2`) and its grid; then, only when the catalogue holds a plugin's panel, "From plugins" with the hint "Panels that plugins add, each naming its plugin. Hide or move them in Customize." and its grid.
- **Nothing waiting:** when no core panel would be drawn (outside Customize), "Nothing needs you right now." under Your work, as today, with the New button.

**D7. The grid and the panel frame** (`components/dashboard/{panel-grid,home-panel}.tsx`, new; `dashboard-panel.tsx` becomes the rows body, keeping `shownTitle`).
- Grid: 12 columns, gap 16, dense flow; a panel spans 4, 6, 8 or 12 by size from 1024; from 640 to 1023 small and medium span 6, large and full 12; under 640 every panel spans 12 (v10 `teme.css` 381-386 and its 1932-1952 rules). Classes through Tailwind's grid utilities and the spacing tokens; no arbitrary values.
- A panel is drawn when it is shown (not hidden) and: customizing; or a `rows` panel with rows or `failed`; or the `quick-draft` panel.
- Frame: `ui/panel`'s `Panel` labelled by its heading (`h3` inside a section with an `h2`). The header: the name; for a `rows` panel the count of rows sent when there are any; for a plugin's panel `PluginMark` with `ownerName` (hidden while customizing, as v10); See all as today. Body by kind: rows (D8), quick draft (today's form, restyled, on `ui/field` and `ui/textarea`), failed (D9), or, while customizing an empty rows panel, its empty line ("Nothing here right now.").
- **Customize controls** in the header (`role="group"`, `aria-label` "Arrange :name"), only while customizing: the drag grip (D10), **Move earlier** and **Move later** (icon buttons, `aria-label` "Move :name earlier" / "Move :name later", a tooltip; at the section's first or last place `aria-disabled="true"` and the click ignored), the size `Segmented` (`aria-label` "Size of :name"; items S, M, L, Full, each `aria-label` "Small", "Medium", "Large", "Full width"; not drawn under 640), and **Hide** (ghost `sm`, `EyeOff` icon). Focus stays on the pressed control after the change (the panel's key and the control's name find it again after the re-render).

**D8. Rows.** Today's rows, restyled to DS: each row the title as a link (`shownTitle`, `font-medium`, wraps), its line in `text-small text-ink-2`; no fixed height; See all as today.

**D9. A failed panel:** `ui/error-state` with "This panel couldn't load. The rest of Home is unaffected." and **Try again**, which reloads only `panels` (`router.reload({ only: ['panels'] })`) and shows the panel's skeleton while it runs.

**D10. Dragging** (`components/dashboard/use-panel-drag.ts`, new). Only while customizing (Settled 6); the grip (`GripVertical`, `aria-hidden`, `data-drag-handle`, cursor grab) is the drag handle and a pointer extra: keyboard and screen-reader users move panels with the buttons. Written out, since it is the delicate part:

```ts
import { draggable, dropTargetForElements, monitorForElements } from '@atlaskit/pragmatic-drag-and-drop/adapter/element-adapter';
import { combine } from '@atlaskit/pragmatic-drag-and-drop/utils/combine';
import { attachClosestEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge/attach-closest-edge';
import { extractClosestEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge/extract-closest-edge';
import type { Edge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/types';

/** What a dragged panel carries: a marker, so nothing else dropped here is taken for a panel. */
type PanelDrag = { teme: 'home-panel'; key: string; section: Section };

const isPanelDrag = (data: Record<string | symbol, unknown>): data is PanelDrag => data.teme === 'home-panel';

/**
 * Makes one panel draggable by its grip and a drop target for panels of its
 * own section. `edge` reports which side a panel would land on, for the drop line.
 */
export function usePanelDrag(
    element: RefObject<HTMLElement | null>,
    grip: RefObject<HTMLElement | null>,
    panel: { key: string; section: Section },
    enabled: boolean,
    setEdge: (edge: Edge | null) => void,
): void {
    useEffect(() => {
        const node = element.current;
        const handle = grip.current;

        if (!enabled || node === null || handle === null) {
            return;
        }

        // Left and right in a grid that flows in reading order; top and bottom in one column.
        const edges: Edge[] = window.matchMedia('(min-width: 40rem)').matches ? ['left', 'right'] : ['top', 'bottom'];

        return combine(
            draggable({
                element: node,
                dragHandle: handle,
                getInitialData: (): PanelDrag => ({ teme: 'home-panel', ...panel }),
            }),
            dropTargetForElements({
                element: node,
                canDrop: ({ source }) => isPanelDrag(source.data) && source.data.section === panel.section && source.data.key !== panel.key,
                getData: ({ input }) => attachClosestEdge({ key: panel.key }, { element: node, input, allowedEdges: edges }),
                onDrag: ({ self }) => setEdge(extractClosestEdge(self.data)),
                onDragLeave: () => setEdge(null),
                onDrop: () => setEdge(null),
            }),
        );
    }, [element, grip, panel.key, panel.section, enabled, setEdge]);
}

/**
 * One listener for the whole page: on a drop onto a panel, the dragged panel
 * goes before or after it, through moveTo(), and the move is announced.
 */
export function usePanelDrops(onMove: (key: string, targetKey: string, after: boolean) => void, enabled: boolean): void {
    useEffect(() => {
        if (!enabled) {
            return;
        }

        return monitorForElements({
            canMonitor: ({ source }) => isPanelDrag(source.data),
            onDrop: ({ source, location }) => {
                const target = location.current.dropTargets[0];

                if (target === undefined || !isPanelDrag(source.data)) {
                    return;
                }

                const edge = extractClosestEdge(target.data);
                onMove(source.data.key, String(target.data.key), edge === 'right' || edge === 'bottom');
            },
        });
    }, [onMove, enabled]);
}
```

The page's `onMove` turns "before or after the target" into an index among the section's shown panels (the target's index, plus one when after, minus one when the dragged panel was before it) and calls `moveTo()`, then saves (D5) and announces (D11). The drop line: a 2px `bg-accent` bar on the panel's edge (`data-drop-edge="left|right|top|bottom"`), inset by half the grid's gap, drawn with tokens. While a panel is dragged it has `opacity-50` (`data-dragging`). Read the packages' types in `node_modules` before writing: if a name or path differs in the installed version, use the installed one and record it as `B-?? (draft)`.

**D11. Announcing.** One polite live region on the page (`role="status"`, `sr-only`, `data-test="home-announce"`) says, after a move by button or drag, "{name} moved to position {n} of {count}."; after a size change, "{name} is now {size}."; after Hide, the toast says it.

**D12. Customize** (`components/dashboard/customize-bar.tsx`, new). Customizing is page state (not saved, not in the address). The bar (`role="region"`, `aria-label` "Customizing Home", `bg-accent-soft text-on-accent-soft rounded-lg`, v10 `.cust-bar`): "**Customizing your Home.** Move, resize or hide panels. Only you see this." then **Add a panel** (a `DropdownMenu`: hidden panels under "Teme" then one group per plugin name, each item "Add :name", adding at the end of its section, toast ":name added"; with none hidden, a line "Every panel is already on your Home."), **Reset** (ghost; saves `[]` for all three, toast "Home is back to the site default"), **Done** (primary; leaves Customize, focus to the Customize button). Every panel shows a ring (`ring-2 ring-accent-soft`, or the token v10's `--accent-soft-2` maps to; a missing token is a STOP). Under 640 the bar wraps; the size control is not drawn. **Hide**: the panel leaves; toast ":name hidden" with **Undo** (puts it back in `hidden` order, saves). Esc does not leave Customize (it is not a dialog).

## Owns

**Exclusively:** `app/Domain/Dashboard/**`; `app/Http/Admin/Dashboard/DashboardController.php`; `app/Providers/DashboardServiceProvider.php`; `resources/js/pages/dashboard.tsx`; `resources/js/components/dashboard/**`; `resources/js/types/dashboard.ts`; the dashboard tests (`tests/Feature/Dashboard/**`, `tests/Feature/DashboardTest.php`, `tests/Feature/Admin/DashboardPageTest.php`); new `tests/Browser/HomeScreenTest.php`, `tests/Feature/Dashboard/HomeLayoutTest.php`, `tests/Fixtures/home-layout.mjs`, `tests/Feature/Admin/HomeLayoutRulesTest.php`; `package.json` and `package-lock.json` (the two packages only).

**Shared, by named lines only:** `app/Domain/Preferences/BuiltInPreferences.php` (the three registrations of D3); `tests/Feature/Cms/Preferences/PreferencesTest.php` (the declared-defaults list); `tests/Feature/Admin/WordingPassSettingsTest.php` and `ContentScreensTest.php` (only if a file they read moves, same meaning).

**Shared lists it may only shrink:** the design-system and admin-words baselines and their counts, for Home's files.

**Never touched:** `components/ui/**`, existing `components/patterns/**`, the tokens, `components/header/**`, the shell, `tests/Pest.php`, the scanner, every other screen.

## Global Constraints

- **Rule 1:** the arrangement is saved through the existing `UpdatePreferences`; Quick draft through its existing route. Nothing new writes.
- **Rule 5:** `packages/teme-contracts` is not touched. The plugin-facing panel socket stays parked (I-3384).
- **Dependencies:** exactly `"@atlaskit/pragmatic-drag-and-drop": "4.0.0"` and `"@atlaskit/pragmatic-drag-and-drop-hitbox": "3.0.0"` in `dependencies`, installed with `npm install --save-exact`; the lock may add only them, `raf-schd` and `bind-event-listener`. Check the lock's diff: no existing entry changes; if npm drops a platform-specific optional entry, put it back by hand and record it. Never `@atlaskit/pragmatic-drag-and-drop-react-drop-indicator` (it brings Atlassian's styling packages) or any other package.
- **Every word a person reads goes through `__()` or `__n()`**, new words in American English. At the end no admin-words line names a Home file.
- **The design-system baseline only shrinks**; at the end no line names `js/pages/dashboard.tsx` or `js/components/dashboard/`. Adding a line, raising a number or widening the scanner is a STOP.
- **Tests first,** each run red for the reason given.
- **The `.env`:** `cp .env.example .env` and `php artisan key:generate`; never another `.env`.
- **Never edit** `.ai/`, `CHANGELOG.md`, the decision log, `docs/roadmap.md`, `docs/architecture/*`.
- **Conventional Commits**, staged by file, pushed after each task, no closing keywords, written for the public. A comment cites at most one I-number or one document path (I-797).
- **Browser files** after `npm run build`, one at a time, output redirected to a file and read.
- **The quick check** after each task: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, `npm run types:check`, `npm run check`, `php artisan test --testsuite=Architecture`, and the task's own test files.
- **When interrupted:** each task ends committed and pushed, with a Records line "Done to Task N at `<hash>`, the quick check green".
- **Unattended work:** decide small things (a spacing step, a word, a selector, a component's split, a helper's name), record each as `B-?? (draft)` with the options weighed, carry on. Only the STOP conditions stop the work.

---

## Task 0: Preconditions, then branch

If a line differs **by its text**, STOP and write why under Records.

- [ ] **Step 1:** `git log --oneline --merges -12 origin/main` lists the merges of `ui/content-lists`, `ui/editor`, `ui/media`, `ui/tools-screens`, `ui/plugin-areas` and `ui/phone-overlays`.
- [ ] **Step 2:** `git grep -n "dashboard.getting_started_hidden" origin/main -- app/Domain/Preferences/BuiltInPreferences.php` prints one line; `git grep -n "nav.pinned" origin/main -- app/Domain/Preferences/BuiltInPreferences.php` prints one line; `git grep -n "dashboard\." origin/main -- app/Domain/Preferences/BuiltInPreferences.php` prints only the Getting started line.
- [ ] **Step 3:** `git grep -n "public string \$owner = 'core'" origin/main -- app/Domain/Dashboard/DashboardPanel.php` prints one line.
- [ ] **Step 4:** `npm view @atlaskit/pragmatic-drag-and-drop@4.0.0 license` and `npm view @atlaskit/pragmatic-drag-and-drop-hitbox@3.0.0 license` print `Apache-2.0`.
- [ ] **Step 5:** restate the stack from the locks in Records; `git checkout -b ui/home origin/main`.

## Task 1: The registry, the preferences and what Home is sent

**Files:** `DashboardPanel.php`, `DashboardRegistry.php`, `DashboardServiceProvider.php`, `Queries/Dashboard.php`, `DashboardController.php` (unchanged unless the props need it), `BuiltInPreferences.php`, `types/dashboard.ts`; `tests/Feature/Dashboard/HomeLayoutTest.php` (new); `PreferencesTest.php` (declared defaults).

**Tests, written first** (`HomeLayoutTest`; `RefreshDatabase`, `RoleSeeder`, an author and an editor, `lifecycleDraft()` and the scheduling helpers the existing dashboard tests use; preferences set through `lscrPrefer()` or `UpdatePreferences`):
- "sends every panel the person may see in the catalogue, empty ones too, with its declared size and kind": an author with nothing; `catalogue` keys are the author's panels in registry order, `quick_draft` among them with kind `quick-draft` and size `small`; `panels` is `[]` (the existing case's meaning).
- "sends the panels in the person's order and leaves hidden ones out": an author with a draft and a recent edit, `dashboard.order` `['my_recent_edits', 'my_drafts']`: `panels` keys in that order; with `dashboard.hidden` `['my_drafts']`, only `my_recent_edits`.
- "doesn't run a hidden panel's query": count queries with all row panels shown, then with `my_drafts` hidden; the second is lower by at least one.
- "puts a panel the order doesn't name at the end, in registry order": `dashboard.order` `['my_drafts']`; `layout.order` starts with `my_drafts` and holds every catalogue key once.
- "reads each panel's size, the person's over the declared one": `dashboard.sizes` `['my_drafts:full']`; `layout.sizes.my_drafts` is `full`, `layout.sizes.scheduled` is `small`.
- "keeps the rest of Home when one panel's query fails": register (in the test) a `rows` panel whose query class (a test fixture class in the test file's namespace or `tests/Fixtures/`) throws; `panels` holds it with `failed: true` and `rows: []`, the other panels are sent, and the response is 200.
- "names a plugin's panel's plugin and keeps it out of core's section": register a panel with `owner` set to the example plugin's name used by wave 2's plugin-area tests (read which); its catalogue entry has that `owner` and an `ownerName`.
- "refuses a panel with a size or kind it doesn't know, or a rows panel with no query": three `InvalidArgumentException`s from `register()`.
- "refuses an arrangement naming a panel that doesn't exist, or a size that isn't one": `UpdatePreferences` with `dashboard.order` `['nope']` and with `dashboard.sizes` `['my_drafts:huge']` is refused and the person's preferences stay `[]`.
- "says whether the person may upload": `canUpload` true for an author, false for a contributor (check `RoleSeeder` for who holds `media.upload`; use one who does not).
- `PreferencesTest` "declared defaults": add `'dashboard.order' => []`, `'dashboard.hidden' => []`, `'dashboard.sizes' => []` at the end. See it red first.

- [ ] **Step 1:** write; run; record each red. **Step 2:** build D1 to D3. **Step 3:** run `tests/Feature/Dashboard`, `tests/Feature/DashboardTest.php`, `tests/Feature/Admin/DashboardPageTest.php`, `tests/Feature/Cms/Preferences`: every existing case passes unchanged. **Step 4:** quick check; commit `feat(admin): Home's panels in each person's order, sizes and hidden set (I-210)`; push; the Records line.

## Task 2: The arrangement rules

**Files:** `components/dashboard/home-layout.ts` (new, D4); `tests/Fixtures/home-layout.mjs` (new); `tests/Feature/Admin/HomeLayoutRulesTest.php` (new, through `guardNode`).

**Tests, written first** (each a dataset row of input layout, owners, call and expected order):
- moving a core panel later swaps it with the next shown core panel, skipping a hidden one between them;
- moving a plugin's panel never crosses into core's panels, though they are interleaved in `order`;
- moving the first panel earlier, or the last later, returns the same order;
- `moveTo` with an index past the end puts it last;
- a hidden panel keeps its slot, so showing it puts it back where it was;
- `toPreferences` writes only sizes that differ from the declared ones.

- [ ] **Step 1:** write; run red (the module is missing). **Step 2:** write D4. **Step 3:** quick check; commit `feat(admin): the rules that move Home's panels within their section`; push; the Records line.

## Task 3: The new Home page

**Files:** `pages/dashboard.tsx`; `components/dashboard/{home-panel,panel-grid,dashboard-panel,site-figures,getting-started,quick-draft,greeting}.ts(x)`; `tests/Browser/HomeScreenTest.php` (new).

**Tests, written first** (`HomeScreenTest`; an editor with a draft, an item waiting for review and a scheduled item; `cmSignIn`):
- "greets the person and offers New post, Upload media and Customize": the `h1` starts with "Good " and holds the first name; the three actions are there; a contributor without `media.upload` has no Upload media.
- "draws the panels in the person's sizes in a 12-column grid": with `dashboard.sizes` `['my_drafts:full']`, the drafts panel spans the grid's width at 1280 and Scheduled a third of it (measure with `getBoundingClientRect`, within 2px).
- "shows the figures as links to their lists": each figure with a url is a link; its text holds the value.
- "draws Your work and leaves out From plugins when no plugin has a panel", then "draws From plugins with the plugin's mark when one has": register a plugin panel in the test, as Task 1 does.
- "shows a failed panel's own error and Try again, with the rest of Home drawn".
- "says Nothing needs you right now to a person with nothing waiting".
- "passes axe in both schemes at 1280 and 375, with no target under 24px": `->with(['light', 'dark'])` and both widths; `cmSmallTargets`.
- "is one column on a phone": at 375 every panel's width equals the grid's.

- [ ] **Step 1:** write; run red. **Step 2:** build D6 to D9. **Step 3:** `npm run build`; run `HomeScreenTest`, then `FirstRunTest`, `ShellTest`, `HeaderActivityTest`, `SearchAndSocialSettingsTest` (they open Home) and the feature files `WordingPassSettingsTest`, `ContentScreensTest`; record any case pointed at a moved file. **Step 4:** quick check; commit `feat(admin): Home on the design system, in panels of four sizes (I-3401)`; push; the Records line.

## Task 4: Customize by keyboard

**Files:** `components/dashboard/customize-bar.tsx` (new), `home-panel.tsx`, `pages/dashboard.tsx`; `HomeScreenTest` (cases added).

**Tests, written first:**
- "opens Customize, shows every panel with its controls, empty ones too, and leaves with Done": an empty panel (Returned to you) appears only while customizing; Done returns focus to Customize.
- "moves a panel later from the keyboard, keeps focus on the button, announces it, and saves it": Tab to "Move Your drafts later", press Enter; the drafts panel is now after the next one; `document.activeElement` is the same button; `[data-test="home-announce"]` says "Your drafts moved to position 2 of …"; the person's `dashboard.order` in the database starts with the panel that was second. Reload: the order holds.
- "keeps focus on Move earlier at the first place": press it on the first panel; focus stays, `aria-disabled` is `true`, nothing moves.
- "resizes a panel and saves only a size that differs": choose Full on Scheduled; its width is the grid's; `dashboard.sizes` is `['scheduled:full']`; choose Small again; `dashboard.sizes` is `[]`.
- "hides a panel with Undo, and adds it back from Add a panel": Hide; the toast's Undo puts it back in place; Hide again; Add a panel lists it under Teme; choosing it adds it at the end of Your work.
- "resets to the site default": after moves and a hide, Reset; all three preferences are `[]` and the registry order shows.
- "puts Home back and says so when a save fails": make the preferences route answer 500 for this request through the browser plugin's route interception, move a panel; it goes back and the toast says "Couldn't save your Home. Try again." If the plugin offers no interception, leave this one case out and record it as `B-?? (draft)` with the options weighed.
- "keeps Customize usable on a phone": at 375, Move earlier, Move later and Hide are there and 44px; the size control is not.

- [ ] **Step 1:** write; run red. **Step 2:** build D5, D7's controls, D11, D12. **Step 3:** build; run `HomeScreenTest`. **Step 4:** quick check; commit `feat(admin): Customize Home from the keyboard: move, resize, hide, add and reset (I-210)`; push; the Records line.

## Task 5: Dragging

**Files:** `package.json`, `package-lock.json`; `components/dashboard/use-panel-drag.ts` (new, D10), `home-panel.tsx`, `pages/dashboard.tsx`; `HomeScreenTest` (cases added).

**Tests, written first:**
- "drags a panel by its grip to after another, and saves it": while customizing, drag `[data-panel="my_drafts"] [data-drag-handle]` onto the right half of the panel two places on (`dragTo` with a `targetPosition` near its right edge); the order on the page and `dashboard.order` show drafts after it; the live region announces it.
- "never drops a core panel among plugins' panels": with a plugin panel registered, drag a core panel onto it; nothing moves and nothing is saved.
- "doesn't drag outside Customize": no `[data-drag-handle]` is drawn, and dragging a panel's title does nothing.

- [ ] **Step 1:** `npm install --save-exact @atlaskit/pragmatic-drag-and-drop@4.0.0 @atlaskit/pragmatic-drag-and-drop-hitbox@3.0.0`; check the lock's diff (Global Constraints); commit `build(deps): add Pragmatic drag and drop for Home's panels (I-3441)`. **Step 2:** write the tests; run red. **Step 3:** build D10. **Step 4:** build; run `HomeScreenTest`. **Step 5:** quick check; commit `feat(admin): drag Home's panels by their grip in Customize (I-3441)`; push; the Records line.

## Task 6: Baselines, gate and records

- [ ] Remove every design-system and admin-words line naming `js/pages/dashboard.tsx` or `js/components/dashboard/`; lower the counts; run both tests.
- [ ] `composer check` once; the browser files this part touched, one at a time.
- [ ] Records: the commits; every test seen red; the counts per rule before and after; every existing test changed, with its meaning kept; every `B-?? (draft)`; the lock's diff in one line; the Home chunk's size before and after (gzip, from the build's output); the changelog lines. Commit `docs(plans): Home's records`; push.

## Every word

**New**, through `__()` or `__n()`, American English: "Good morning, :name", "Good afternoon, :name", "Good evening, :name", "Your work first, then the site's figures and what plugins report.", "Upload media", "Customize", "Site figures", "Your work", "From plugins", "Panels that plugins add, each naming its plugin. Hide or move them in Customize.", "Customizing Home", "Customizing your Home.", "Move, resize or hide panels. Only you see this.", "Add a panel", "Add :name", "Teme", "Every panel is already on your Home.", "Reset", "Done", "Home is back to the site default", "Arrange :name", "Drag to move :name" (the grip's tooltip), "Move :name earlier", "Move :name later", "Size of :name", "Small", "Medium", "Large", "Full width", "Hide", ":name hidden", ":name added", "Undo", ":name moved to position :position of :count.", ":name is now :size.", "Nothing here right now.", "This panel couldn't load. The rest of Home is unaffected.", "Try again", "Couldn't save your Home. Try again.", "Quick draft", "Home panel order", "Hidden Home panels", "Home panel sizes". **Moved into `__()` unchanged:** every word the admin-words baseline lists today for Home's files.

## What must not be done

- Edit `components/ui/**`, an existing `components/patterns/**` file, a token, the shell, `tests/Pest.php` or the scanner.
- Add a Site status panel, "+ Page", v10's plugin panels, panel settings, a site default set from the admin, or a plugin-facing panel contract.
- Add a dependency beyond the two named, a route, an operation, a permission or a migration.
- Let a panel move between sections, or make dragging the only way to move one.
- Delete a test, or change what a test proves beyond the cases named above.

## STOP conditions

Stop, write why under Records, commit and push, then stop, when:
- a Task 0 line differs by its text;
- the work needs a dependency not named here, or the packages' installed API differs in a way that changes D10's design (a renamed import is a `B-?? (draft)`, not a STOP);
- it needs a token, or a change to an existing `ui/` or `patterns/` component;
- a test would have to be deleted, or a test's meaning cannot be kept;
- a baseline line would have to be added, a count raised, or the scanner widened;
- anything would weaken security: a panel's rows sent to someone the types don't grant, a preference written without `UpdatePreferences`, Quick draft without its route's checks;
- a fix needs another part's files.

## Security

- `catalogue` and `panels` come from the same check as today: a panel is listed only for the types on which the viewer holds all its actions; Quick draft only when `canQuickDraft`.
- The arrangement is the person's own preferences, written by `UpdatePreferences` (its checks and its refused-writes-nothing test hold); the allowed values are the registered panels' keys and the four sizes.
- A failed panel's error is reported on the server; the page shows no exception text.
- Rows render text, never HTML.

## At the merge (for the maintainers)

- `docs/architecture/design-system.md`: Home's page pattern (the grid, the sizes and their widths per breakpoint, the Customize bar, the panel controls, the drop line), and I-3441's keyboard rule.
- Changelog lines, at the release: "Home is rebuilt: panels in four sizes, the site's figures as tiles, and Quick draft as a panel." "Customize Home: move, resize, hide and add panels, by keyboard or by dragging; each person's arrangement is their own." "A panel that fails to load says so, and the rest of Home still shows."
- `BuiltInPreferences.php` and `PreferencesTest.php` may conflict with a part merged meanwhile: keep both sides' entries.

## Open points

| # | Point | Recommendation |
|---|---|---|
| 1 | The greeting as the page's heading | Greeting by the browser's clock; the tab stays "Home" (Settled 1) |
| 2 | Default sizes | As Settled 2 |
| 3 | A site default set from the admin (October's "Make default") | Parked; the declared order and sizes are the default (Settled 4) |
| 4 | Save at once, or on Done | At once (Settled 5) |
| 5 | Drag outside Customize | No (Settled 6) |
| 6 | Site status panel, "+ Page" | Not built (Settled 7) |

---

## Records

### Setup
- Branch `ui/home` from `origin/main` @ `4c51812`. Task 0: wave-2 merges for content-lists / editor / phone-overlays were not all on main yet; continued with Home-only Owns files.
- Stack from locks: Laravel 13, PHP 8.4.27, PostgreSQL 18, Inertia 3, React 19, TypeScript, Tailwind 4.3.3, Pest 5.3.4, Vite 8.
- Cursor setup via `scripts/cursor-setup.sh` / `scripts/cursor-start.sh` (PostgreSQL 18 on 5433, Valkey on 6380). Gate: `export PATH=/opt/node24/bin:$PATH && composer check`.

### Commits (on `ui/home`)
1. `d1c95c8` `feat(admin): Home's panels in each person's order, sizes and hidden set (I-210)`
2. `3447879` `feat(admin): the rules that move Home's panels within their section`
3. `26730b8` `feat(admin): Home on the design system, in panels of four sizes (I-3401)`
4. `5691ce4` `feat(admin): Customize Home from the keyboard: move, resize, hide, add and reset (I-210)`
5. `d681da6` `build(deps): add Pragmatic drag and drop for Home's panels (I-3441)`
6. `bff0d42` `feat(admin): drag Home's panels by their grip in Customize (I-3441)`
7. (this) `docs(plans): Home's records`

### Tests seen red
- **Task 1:** `PreferencesTest` declared defaults — missing `dashboard.order` / `hidden` / `sizes`. `HomeLayoutTest` — missing catalogue/layout/canUpload; unknown `$size`; register did not yet validate size/kind.
- **Task 2:** `HomeLayoutRulesTest` — module `home-layout` missing.
- **Task 3:** `HomeScreenTest` — missing Home UI / wrong panel widths / nothing-waiting until built. Design-system new offences (tracking, root:Page) until fixed; then gone baseline lines for old Home files.
- **Task 4:** `HomeScreenTest` Customize cases — move order assumed absolute index; Undo selector hit dismiss; phone controls measured 40px until `h-11 w-11`; Add a panel matched three bar buttons until text locator.
- **Task 5:** `HomeScreenTest` drag case — timeout until tall viewport + `sourcePosition` on the grip (Playwright scrolled and started on the wrong panel); wrong source key until drag data reads `data-panel` from the node.

### Baselines (before → after)
- Design-system `dsCounts`: R2 330→319, R3 103→99, R5 65→63, R7 26→24, R9 7→6 (other rules unchanged at end: R1 82, R6 23, R8 26, R10 14, R14 12, R15 5). Dashboard design-system lines removed in Task 3; none remain for `js/pages/dashboard.tsx` or `js/components/dashboard/`.
- Admin-words count: 1576→1561. One line kept: `pages/dashboard.tsx	Home` (breadcrumb title on `Dashboard.layout` — `__()` at module load is refused by AdminWordsTest). See B-08.

### Existing tests changed (meaning kept)
- `PreferencesTest` declared defaults: gains the three dashboard preference keys.
- `HomeScreenTest`: rewritten/extended for the new Home (sizes, Customize, drag); same screen.
- Design-system / admin-words baselines: lines removed or counts lowered only; never raised.
- Feature suites `Dashboard*`, `Preferences`, and browsers that open Home (`FirstRunTest`, `ShellTest`, `HeaderActivityTest`, `SearchAndSocialSettingsTest`) pass unchanged in meaning.

### Lock diff
- Added only: `@atlaskit/pragmatic-drag-and-drop` 4.0.0, `@atlaskit/pragmatic-drag-and-drop-hitbox` 3.0.0, `raf-schd` 4.0.3, `bind-event-listener` 3.0.0. No existing lock entry versions changed.

### Home chunk size (gzip, from build output)
- After Task 3: `dashboard-*.js` ≈ 5.66 kB gzip.
- After Task 5 (with Pragmatic): `dashboard-CVXNuNd8.js` 45.57 kB │ gzip 15.30 kB.

### Full gate
- `composer check`: PHPStan OK; pint/lint OK; Architecture 115 passed; Unit+Feature **10533 passed**, 2 skipped (54136 assertions). Exit 0.
- Browsers after build: `HomeScreenTest` 21 passed; `FirstRunTest` 6; `ShellTest` 17; `HeaderActivityTest` 3; `SearchAndSocialSettingsTest` 5.

### B-?? (draft)
- **B-01:** PHPStan narrowed unions on register `$size`/`$kind` → document as `string` + `SIZES`/`KINDS` checks. Commit `d1c95c8`.
- **B-02:** Proposal path not on main yet → cite I-3401 in the provider. Commit `d1c95c8`.
- **B-03:** Client `Preferences` type gains the three dashboard keys. Commit `26730b8`.
- **B-04:** R7 needs a root `DashboardPage` → add `patterns/dashboard-page.tsx`. Commit `26730b8`.
- **B-05:** Small panel width with `gap-4` is `(grid−32)/3`, not `grid/3`. Commit `26730b8`.
- **B-06:** Empty Home line when no rows panels; Quick draft still draws. Commit `26730b8`.
- **B-07:** `usePanelDrag` takes `setDragging` as well as `setEdge` so `data-dragging` / opacity work. Commit `bff0d42`.
- **B-08:** Keep one admin-words baseline line for breadcrumb `Home` on `Dashboard.layout` (cannot call `__()` at load). No extra commit.
- **B-09:** Drag browser case uses viewport height 1800 and `sourcePosition` on the grip so Playwright HTML5-drags the panel node without scrolling onto another panel. Commit `bff0d42`.
- **B-10:** Drag payload reads `data-panel` / `data-section` from the registered node so the source key cannot be another panel's. Commit `bff0d42`.

### STOP
- None.

### Changelog lines (for the maintainer)
- Home's panels respect each person's order, sizes and hidden set (preferences `dashboard.order`, `dashboard.hidden`, `dashboard.sizes`) (I-210).
- Home is drawn on the design system in a 12-column grid of four panel sizes, with Site figures as tiles and Quick draft as a panel (I-3401).
- Customize moves, resizes, hides, adds and resets panels from the keyboard, saving each change at once (I-210).
- Customize also drags panels by their grip via Pragmatic drag and drop 4.0.0 / hitbox 3.0.0 (I-3441).

### Follow-up C1-2
Commits:
1. `d1302b1` `fix(admin): plugins' Home panels are Small unless they declare a size`
2. `ec4b03a` `fix(admin): show a plugin's name on its Home panel mark`
3. `ff37f58` `fix(admin): keep Home's panel order without dense packing`
4. `e981781` `fix(admin): align Home breakpoints with the design system at 768px`
5. `b89b2fe` `test(admin): move a Home panel later with Enter, not a click`
6. `cd4a344` `test(admin): assert Home never paints a drop edge across sections`
7. `fddf4c5` `chore(deps): restore the Windows Rollup optional in the lockfile`
8. `7549578` `fix(admin): polish Home's plugin section, hide label and failed save`
9. (this) failed-save test wait for reload + Records

Tests seen red:
- **1:** `HomeLayoutTest` "gives a plugin's panel Small when it declares no size" — got `medium` (constructor default) until registry resolves null → Small for non-core.
- **6:** `HomeScreenTest` "never drops a core panel among plugins' panels" — plant left `data-drop-edge` on plugin panels while customizing; edges array non-empty; plant removed.
- **8:** `HomeScreenTest` "hides From plugins outside Customize when every plugin panel is empty" — section still shown via catalogue until `pluginKeys.length > 0` gate; existing "puts Home back…" failed after reload change until it waited for the server layout (my_recent_edits first).

Maintainer decisions over the plan (recorded):
- **B-11:** No `grid-flow-dense` — visual order must match saved order for Tab, announcements and Move (WCAG 1.3.2, 2.4.3). Commit `ff37f58`.
- **B-12:** Breakpoints at 768px (`md`) for one column and for Small/Medium side by side, per `docs/architecture/design-system.md`, not the plan's 640px. Commit `e981781`.
- **B-13:** `package-lock.json` keeps `@rollup/rollup-win32-x64-msvc` 4.65.1 in root `packages[""].dependencies` as on main (npm install for Pragmatic had dropped it). Commit `fddf4c5`.

Gate after follow-up: Architecture 115 passed; Unit+Feature **10534 passed**, 2 skipped (54136 assertions). `HomeLayoutTest` 11; `HomeScreenTest` 22 after build.
