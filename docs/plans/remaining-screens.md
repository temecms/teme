# Interface: the remaining screens on the design system

**One part, one branch: `ui/remaining-screens`.** It moves six screens onto the page patterns of the design system (Themes, Types, Taxonomies, Navigation, Regions and the Inbox), fixes the touch and layout faults found on them, and gives Themes a second page for each theme's own settings. It adds no feature.

Steps use checkbox (`- [ ]`) syntax for tracking.

**Written 2026-11-14** against the integration branch at the merge of the design system's foundation. This plan is lighter (I-569): it writes out the decisions, names, permissions, tests and the delicate code, and describes the rest.

**Goal.** Every page under `pages/{themes,types,taxonomies,navigation,regions,inbox}/**` renders one page pattern, uses the shared controls, and leaves the design check's baseline with fewer lines; the Themes page is split into the list and each theme's settings; the touch targets named below reach 44 pixels on touch.

**Architecture.** Restyle, do not redesign. Each screen keeps its server code, routes, props, words and test hooks (`data-test`, ids, `aria-label`s), and changes only its frame and controls: `ListPage` for lists, `FormPage` with `FormPanel`s and the one `SaveBar` for forms, `StatusPill` for states, `TermChips` for card lists, `Notice` for notes, `Sheet` and `Dialog` as today. One server change: a read-only route for a theme's settings page, which reuses the existing screen query. One new shared file: `components/patterns/section-tabs.tsx`, the row of in-page tabs for the long Types and Taxonomies forms.

**Proposal:** `docs/proposals/remaining-screens.md` (accepted). Its "Settled" section binds this plan: (1) the Types and Taxonomies forms use a row of in-page tabs, with no side list; (2) Themes is two pages, the list and each theme's own settings; (3) if the content-lists part is late, these screens ship on today's tables; (4) Navigation keeps buttons and keys, with no dragging.

**Spec:** `docs/architecture/design-system.md` ("DS"): 4.12 (forms and the one save bar), 4.13 (status pill), 4.14 (chips and "+N"), 4.17 (the "…" menu), 4.19 and 4.20 (dialog, sheet), 4.22 (notice), 4.23 (empty state), 4.27 (tabs and in-page navigation), 4.28 (card and panel), section 5 (page patterns), section 7 (accessibility), section 8 (writing), section 9 (the check).

**Tech stack:** Laravel 13, PHP 8.4, PostgreSQL 18, Inertia 3 (`@inertiajs/react`), React 19, TypeScript, Tailwind 4, shadcn/ui on Radix, Vite 8 on Rolldown, Pest 5 with `pestphp/pest-plugin-browser`. Task 0 records the exact versions from the locks.

**Bound by:** I-3439 (every new word in American English; words that only move keep their spelling), I-3373 (every word a person reads through `__()` or `__n()`), I-3404 (one look), I-3311 (the icon set stays one lazy file), I-797 (a code comment cites one I-number or one document path), I-569, I-3317, I-3369.

**New dependencies, migrations, permissions, operations, contract members (`packages/teme-contracts`), tokens:** none. **New route:** one `GET`, behind the existing `settings.design.manage` ability (Task 1). **Drafts:** `B-?? (draft)` for plan faults.

---

## Where the documents and the code disagree, and what this plan does

1. **Theme settings live on the Themes page today**, not in a Settings page: `pages/themes/index.tsx` draws them in a `#theme-settings` section under the list, with the autosaved draft, the per-section Preview and the Publish and Discard bar. The settings-pages part therefore owns none of it. **This part moves them** to a new page, `pages/themes/settings.tsx`, at `/admin/themes/{code}/settings` (D1). The settings registry is not touched: each theme's group keeps `screen: 'themes.index'`, so `/admin/settings/theme.{code}` still leads to the list, whose card links on (open point 2).
2. **Only one theme has settings to change at a time.** The screen query (`DesignScreen::data()`) reads the settings of the *chosen* theme only: the previewed one, else the one in use. "Each theme's own settings" is therefore the chosen theme's page; any other theme's address leads back to the list (D1). Changing a theme's settings without previewing it would draft a group the publish step does not expect, which is a feature, not a restyle.
3. **"In-page tabs"** (Settled 1). DS 4.27 has two components with one look: `Tabs` (panels of one screen) and `InPageNav` (links that change the address). Neither fits a long form: Tabs would hide every other section's fields (the tests type into fields of several sections in a row), and `InPageNav` renders Inertia `Link`s, which would visit the page again and lose what was typed. **This plan adds `SectionTabs`**: the same look, plain links to the sections of the one form, the section in view marked, no visit and no change of address (D3; open point 1).
4. **Per-item "Mark as read" in the Inbox** (proposal, "Inbox"). The server has no such route: an item is marked read when it is opened (`inbox.open`, which also follows its link) or with all the others (`inbox.read-all`). A new route and Operation are a feature. **Not built here**; the title stays the control that opens and marks read (open point 3).
5. **The "+N" chip at 44 pixels on touch** (proposal, "The problem"). The chip lives in `components/ui/term-chips.tsx` (24 tall), which belongs to the foundation. **Not changed here**; Regions uses it as it is (open point 4).
6. **Phone cards for the lists** (proposal, decision 2) come from the content-lists part. Settled 3 applies: **these screens stay on today's `DataTable`**, restyled only by the tokens it already reads. Moving them onto the shared table and cards is a follow-up when that part merges (open point 5).
7. **DS 4.12 puts Save in the form's footer only.** The menu editor's "Save menu" sits in the Items header today; Types and Taxonomies use the settings pages' always-visible save bar. **All three move to the one `SaveBar`**, which is hidden until something changes (D4). Two tests that save with nothing changed, and one that reads "Unsaved changes", change their steps, not their meaning (the table under "Existing tests that change").
8. **"Activate" on each card** (proposal, "Themes"). Today a theme is activated only after it is previewed. **The card offers Activate only on the previewed theme**, beside the panel's own; no direct activation is added.

---

## The decisions, written out

**D1. The theme's settings page.**

Route, inside the existing `themes.` group (which already carries `can:settings.design.manage`), after `{code}/screenshot`:

```php
Route::get('{code}/settings', [ThemesController::class, 'settings'])
    ->where('code', ManifestValidator::CODE_PATTERN)
    ->name('settings');
```

Controller method, added to `app/Http/Admin/ThemesController.php` (it changes nothing; Rule 1 holds):

```php
/**
 * One theme's own settings and menu places. Only the theme the design names
 * has them to change (the one previewed, else the one in use), so any other
 * installed theme's address leads back to the list.
 */
public function settings(string $code, DesignScreen $screen, ThemeRegistry $themes): Response|RedirectResponse
{
    $theme = $themes->find($code) ?? abort(404);
    $data = $screen->data();

    if ($theme->code !== $data['theme'] || ! $theme->usable()) {
        return to_route('themes.index');
    }

    return Inertia::render('themes/settings', $data);
}
```

The class's docblock gains "and each chosen theme's settings page". `themes/index` keeps every prop it sends today (its feature tests read them); the list page simply stops drawing the settings. The page's type: `export type DesignSettingsPage = Omit<DesignPage, 'regions' | 'previewRegions' | 'canEditRegions'>` in `resources/js/types/design.ts`.

**D2. Themes, two pages, one draft.**

- **The draft logic moves, unchanged, into `components/themes/use-design-draft.ts`**: `edits`, `errors`, `draft`, `saveState`, the 800 ms timer, `save()` with its in-flight queue, `change()`, `preview(section)`, `settled()`, `previewTheme(code)`, `stopPreview(code)`, `activateTheme(code)`, `finish(action)`, and the "new props win" reset. It returns those and the derived `themeValues`, `errorOf`, `unbound`. Lift the code as it is: its comments explain three races (a change waiting when Stop is pressed is dropped; a change waiting when Activate or a section's Preview is pressed is saved first; a tab opened after a request is blocked as a pop-up). Both pages call it; only the settings page calls `change()`.
- **The list (`pages/themes/index.tsx`)** on `ListPage` (title "Themes", today's description). Under the header: the save-state line (`role="status"`), the note "The site's name, tagline and brand colour stay when you change theme." with its "Open Settings › General" button, then the `ThemePreviewPanel` while previewing (with its regions, as today), then the cards, then the regions the theme in use places (`ThemeRegions`, as today), then the draft bar.
- **Cards** (`ThemeList`): the `ul[data-test="theme-list"]` stays, the active theme first; each `li` (`data-test="theme-{code}"`) becomes a `Panel` card in a grid, `grid-cols-(--theme-cols)` with `style={{ '--theme-cols': 'repeat(auto-fill, minmax(min(100%, 300px), 1fr))' }}` on the `ul` (a custom property is what R15 allows; one column on a phone). Inside: the screenshot (`aspect-[4/3]`, full card width, `rounded-md`), the name, the version (`text-small text-ink-3`), the state as a `StatusPill` keeping `data-test="theme-state"` and its word (`active` ok, `previewing` info, `available` neutral, `incompatible` and `broken` danger), the description, the errors, then the actions at the foot: Preview (`available`, `data-test="theme-preview"`), Activate (`previewing`, `data-test="theme-card-activate"`, calling `activateTheme`), and **Theme settings** as a link to `ThemesController.settings({ code })` on the card whose code is `page.theme` and is usable (`data-test="theme-settings-link"`).
- **The settings page (`pages/themes/settings.tsx`)** on `FormPage`: title `__(':name settings', { name })`, description `__('Changes are saved as a draft as you make them. Visitors see them once you publish.')`; while previewing, `ThemePreviewPanel` without its regions child (Stop, Activate, Open the site), the line "Visitors see these once you activate :name.", then one `FormPanel` per fieldset (Site, Latest posts, Content, Archive) with its Preview button as the panel's first row (DS 4.12 forbids actions only for Save), then the Menus `FormPanel` whose description holds "A menu you choose here shows once you publish…" and the "Edit menus" link, then the draft bar. A theme with no settings: one `EmptyState` line, "{name} has no settings or menu places of its own." Every control keeps its id (`design-{key}`); `FieldInput` moves into `components/themes/design-field.tsx` and its booleans become the shared `Checkbox` inside a `Field`. Crumbs: Themes › {name} settings.
- **The draft bar** (`components/themes/design-draft-bar.tsx`, on both pages, hidden while previewing as today): `role="region"` with `aria-label="Unpublished design changes"` (kept), sticky at the foot of the view like the save bar's frame (`sticky bottom-4`, `bg-surface shadow-float rounded-lg`, under 640 fixed full width), the line "Unpublished design changes · by {name}, {when}", the theme error, then Discard (neutral) and Publish (primary). **Discard asks first:** a `Dialog` `sm` (`data-test="design-discard-dialog"`), title "Discard the unpublished design changes?", line "Every change waiting to go live is discarded, and the site stays as it is.", buttons "Keep them" (neutral, focused first) and "Discard changes" (danger, `data-test="design-discard-confirm"`), which calls `finish('discard')`.
- **The preview panel** (`ThemePreviewPanel`): its `role="note"` box becomes a `Notice` (`tone="info"`); its ids, `data-test`s and buttons stay.

**D3. `SectionTabs`, written out** (`resources/js/components/patterns/section-tabs.tsx`, a new file; existing patterns are not changed):

```tsx
import { useEffect, useState } from 'react';
import type { MouseEvent } from 'react';
import { ScrollArea } from '@/components/ui/scroll-area';

/** One section of a long form: the id of its wrapper and its tab's words. */
export type FormSection = { id: string; label: string };

/** InPageNav's item, the section in view marked instead of the page. */
const ITEM =
    'text-body text-ink-2 hover:bg-surface-3 hover:text-ink aria-[current=location]:bg-surface-3 aria-[current=location]:text-ink focus-visible:outline-ring inline-flex h-(--ctl-h) shrink-0 items-center rounded-md px-3 font-medium whitespace-nowrap focus-visible:outline-2 focus-visible:-outline-offset-2';

/**
 * A long form's sections as a row of tabs (docs/architecture/design-system.md,
 * section 4.27's look): each a link to its section of the same form, the one
 * in view marked. Nothing is visited and the address does not change, so the
 * form keeps what was typed and the leave guard is never asked.
 */
export function SectionTabs({ label, sections }: { label: string; sections: FormSection[] }) {
    const [current, setCurrent] = useState(sections[0]?.id ?? '');
    const ids = sections.map((section) => section.id).join(' ');

    useEffect(() => {
        // The section nearest the top of the view, once its top has passed the upper third.
        const observer = new IntersectionObserver(
            (entries) => {
                const shown = entries
                    .filter((entry) => entry.isIntersecting)
                    .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];

                if (shown !== undefined) {
                    setCurrent(shown.target.id);
                }
            },
            { rootMargin: '0px 0px -66% 0px' },
        );

        for (const id of ids.split(' ')) {
            const element = document.getElementById(id);

            if (element !== null) {
                observer.observe(element);
            }
        }

        return () => observer.disconnect();
    }, [ids]);

    const go = (event: MouseEvent<HTMLAnchorElement>, id: string) => {
        const target = document.getElementById(id);

        if (target === null) {
            return;
        }

        event.preventDefault();
        target.scrollIntoView({
            block: 'start',
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        });
        target.focus({ preventScroll: true });
        setCurrent(id);
    };

    return (
        <nav aria-label={label} data-slot="section-tabs" className="bg-page sticky top-0 z-(--z-sticky) mb-4 py-2">
            <ScrollArea axis="x">
                <ul className="flex gap-1">
                    {sections.map((section) => (
                        <li key={section.id} className="flex">
                            <a
                                href={`#${section.id}`}
                                aria-current={current === section.id ? 'location' : undefined}
                                className={ITEM}
                                onClick={(event) => go(event, section.id)}
                            >
                                {section.label}
                            </a>
                        </li>
                    ))}
                </ul>
            </ScrollArea>
        </nav>
    );
}
```

Each section's wrapper is `<div id={id} tabIndex={-1} className="scroll-mt-16">` around its `FormPanel` (the panel takes no id), and the wrappers sit in one `grid gap-4`. If `ScrollArea`'s prop is not `axis`, use its real one (read the file) and record it. If `--z-sticky` or `bg-page` is not a token on the branch, STOP (tokens belong to the foundation).

**D4. The one save bar on the three forms.** `SaveBar` is the last child of its form, hidden until `dirty`, with Discard and Save, and asks before the page is left (DS 4.12).

- **Types (`pages/types/show.tsx`) and Taxonomies (`pages/taxonomies/show.tsx`).** `saveLabel` today's label ("Add type"/"Save type", "Add taxonomy"/"Save taxonomy"), `testId` today's (`type-save`, `taxonomy-save`), `what` the plural name or `__('this type')` / `__('this taxonomy')`, `processing` from the `Form`. **`dirty`**, written out, because Inertia's `isDirty` sees only what is typed, not what the icon picker or a Radix control sets:

```ts
// What the form holds, in one shape for the saved type and for the state.
const snapshot = (value: TypeFormState): string => JSON.stringify(value);

const state: TypeFormState = { singular, plural, key, segment, icon, single, isPublic, archive, searchable, comments, taxonomies, supports, permissions };
// From the saved type on every render, so a save that comes back with new props is clean at once.
const dirty = isDirty || snapshot(state) !== snapshot(savedState(saved));
```

`savedState(saved)` repeats, exactly, the expressions the `useState` calls start from (`archive: saved.public && saved.archive`, `searchable: saved.public && saved.searchable`, `comments: saved.public ? saved.comments : null`, and the rest as saved). **Discard** remounts the form: `TypesShow` keeps `const [discards, setDiscards] = useState(0)` and keys `TypeForm` by `` `${page.mode}:${page.type.key}:${discards}` ``. The same for Taxonomies with its own state.

- **The menu editor (`pages/navigation/edit.tsx`).** The Items section becomes a `<form onSubmit={(event) => { event.preventDefault(); save(); }}>` whose last child is `SaveBar` with `dirty`, `processing={saving}`, `saveLabel={__('Save menu')}`, `testId="menu-save"`, `what={__('“:name”', { name: navigation.name })}`, and `onDiscard={() => { setTree(withKeys(items)); setDirty(false); setShowErrors(true); }}`. The header's "Save menu" button and its "Unsaved changes" span go. "Add item" stays in the Items header.

**D5. The menu editor on a phone.** `TreeEditor`'s rows keep, from 640 up, the label field, the new-tab switch, the four move buttons and Remove. Below 640 (`max-sm:hidden` on the button group, `sm:hidden` on the menu), each row is the label field and one "…" `DropdownMenu` trigger (ghost icon button, `MoreHorizontal`, `aria-label={__('More actions for :name', { name })}`, `data-control="more"`). Its items: the four moves with today's words, each disabled with its refusal as the item's second line (`text-small text-ink-3`, since a `title` is never seen on touch), a separator, then "Remove" as the danger item. A move from the menu calls `apply(path, move, 'more')`, so focus comes back to the row's "…". Alt and the arrow keys work on every width. No dragging (Settled 4).

**D6. Lists.** Types, Taxonomies, Navigation and Regions keep `DataTable`, `RowLinks` and `RowActions` from `components/list/` as they are (Settled 3), inside `ListPage`; the pages stop importing `components/list/page-header`. Small changes inside cells only:
- **Counts that open a list** (Types' items and "in the trash", Taxonomies' terms, Navigation's items where they link): `inline-flex min-h-(--ctl-h-sm) items-center` on the link, so they are 44 on touch.
- **States:** Types' and Taxonomies' source ("Code", "Made here", "Can't be used") become `StatusPill`s (neutral, info, danger) with today's words; the refused row keeps its `bg-danger-soft`.
- **Regions' cards:** `TermChips` with `terms={row.cardLabels.map((label) => ({ id: label, name: label }))}`, `noun={{ one: __('card'), many: __('cards') }}`, `emptyLabel={__('No cards')}`, wrapped in `data-test="region-cards"`; where `cardLabels` is empty, today's "Text only" instead.
- **The Inbox** stays a list, not a table: `InboxList` keeps its export and its `inPanel` prop (the shell's inbox panel uses it). Each row: an 8px unread dot (kept, `aria-hidden`, with "(unread)" for screen readers), the title as today's `Link` `as="button"` drawn as a `TextButton`-style link with `min-h-(--ctl-h-sm)` (44 on touch, from 23 today), the text and the time in `text-small text-ink-2`. The header's "{n} unread" and "Mark all as read" stay as the `ListPage`'s actions.

**D7. Radios.** The raw `<input type="radio">` groups in `pages/types/show.tsx`, `pages/taxonomies/show.tsx` and `components/types/field-sheet.tsx` move onto `ui/radio-group.tsx`, each item keeping its id and the group its `name` (Radix writes a hidden input inside a form). Their tests read `aria-checked` instead of `.checked` (named below). `.disabled` keeps working on Radix's item button. The field sheet's `<textarea>` becomes `ui/textarea.tsx`. Scrolling boxes (`overflow-*-auto` in the add-item panel, the field sheet, the icon picker and the permissions table's frame) become `ScrollArea`; the permissions table's region keeps `role="region"` and `tabIndex={0}` (a test finds it so).

**D8. Words.** Words that stay in their file may stay as they are. **A word moved into a new file goes through `__()` unchanged** (a new file has no baseline lines). New words are in American English and listed under "Every word".

---

## Seams with the other parts

- **The foundation** owns `resources/css/app.css`'s tokens and every existing file in `components/ui/**` and `components/patterns/**`. This part changes none of them; it **adds** `components/patterns/section-tabs.tsx`. A need for a new token or a change to an existing `ui/` or `patterns/` file is a STOP; the maintainers make it once on the integration branch.
- **The settings-pages part** owns `pages/settings/**`, `layouts/settings/**`, `components/fields/**`. Today `pages/types/show.tsx` and `pages/taxonomies/show.tsx` import `components/fields/settings-panel` and `settings-save-bar`. **After this part, no owned file imports `components/fields/**`**, so that part may change or remove them. The theme settings were never on a Settings page, so nothing moves between the two parts; "Open Settings › General" stays a link to `settings.show` with `group: 'general'`.
- **The content-lists part** owns `components/list/**`. This part only imports `data-table`, `row-links`, `row-actions`, `empty-state` and `pager`, unchanged, and stops importing `list/page-header`. If that part has merged when this one starts, record it in Task 0 and continue on today's table (D6).
- **The Combobox part** owns `components/ui/combobox.tsx` and `components/term-picker.tsx`: neither is used here. **People and account** (`pages/users/**`, `pages/account/**`, `layouts/account/**`) and **sign-in** (`pages/auth/**`, `layouts/auth*`): not touched.
- **The editor part** owns `components/editor/**`: `components/navigation/add-item-panel.tsx` keeps importing `useLinkTargets` from it, unchanged; `pages/themes/*` keep `send` from `components/editor/editor-http`, unchanged.
- **The shell**: `components/header/inbox-bell.tsx` draws `InboxList` with `inPanel`; its file is not touched and its tests run (Task 7).
- **The baselines:** each part removes only lines naming its own files; at a merge conflict, take both sides' removals and set each count to what the merged file holds.

## Owns

- `resources/js/pages/themes/**` (`index.tsx`; new `settings.tsx`)
- `resources/js/pages/types/**` (`index.tsx`, `show.tsx`, `fields.tsx`)
- `resources/js/pages/taxonomies/**` (`index.tsx`, `show.tsx`)
- `resources/js/pages/navigation/**` (`index.tsx`, `edit.tsx`)
- `resources/js/pages/regions/**` (`index.tsx`)
- `resources/js/pages/inbox/**` (`index.tsx`)
- `resources/js/components/themes/**` (`theme-list.tsx`, `theme-preview-panel.tsx`, `theme-regions.tsx`; new `use-design-draft.ts`, `design-field.tsx`, `design-draft-bar.tsx`)
- `resources/js/components/types/**` (`builder-parts.tsx`, `field-sheet.tsx`, `icon-picker.tsx`)
- `resources/js/components/navigation/**` (`add-item-panel.tsx`, `tree-editor.tsx`)
- `resources/js/components/inbox/**` (`inbox-list.tsx`)
- New: `resources/js/components/patterns/section-tabs.tsx`
- One type added: `resources/js/types/design.ts` (`DesignSettingsPage`)
- Server: `routes/admin.php` (the one route), `app/Http/Admin/ThemesController.php` (the one method)
- Tests: the files under "Existing tests that change", the cases added below, `tests/Architecture/design-system-baseline.txt`, `DesignSystemTest.php` (counts), `tests/Architecture/admin-words-baseline.txt`, `AdminWordsTest.php` (count)

---

## Global Constraints

- **Rule 1:** no state changes through a new path. The new route reads; every write goes through today's routes and their Operations. No Operation is added, so no authorization-failure write test applies; the new route's refusal is tested (Task 1).
- **Rule 3:** PostgreSQL only; feature tests use `RefreshDatabase` as their siblings do.
- **Rule 5:** `packages/teme-contracts` is not touched.
- **Rule 9:** links are built from routes (`@/actions/…`, `@/routes/…`), never written as addresses.
- **No dependency.** Never change `composer.json`, `composer.lock`, `package.json` or `package-lock.json`.
- **Every word through `__()` or `__n()`** from `@/lib/i18n` where it is new or moves file (D8). Look words up while drawing, never at a module's top level (`AdminWordsTest` checks it). Read every count at N = 1 ("1 card").
- **The design check's baseline only shrinks.** `tests/Architecture/DesignSystemTest.php` scans `resources/` with fifteen rules (DS 9.2: R1 arbitrary values such as `text-[13px]`; R2 sizes off the scale, the six allowed being `text-caption|small|body|lead|title|display`; R3 spacing off the grid; R5 raw `<button>`, `<input>`, `<textarea>`, `<select>`, `<table>`, `<details>`, `<dialog>` outside `ui/` and `patterns/`; R6 off-scale shape and weight such as bare `rounded` or `z-10`; R7 a page whose root is not a pattern, or padding and width on a page's top two levels; R8 scrolling without `ScrollArea`; R9 `role="note"` and hand-made tinted chips; R10 focus removed; R14 motion; R15 inline style other than custom properties). `tests/Architecture/design-system-baseline.txt` holds one `rule<TAB>path<TAB>finding` line per offence that existed when the check arrived. To remove lines: run `php artisan test tests/Architecture/DesignSystemTest.php`; the first case lists what is gone; delete exactly those lines and lower those rules' numbers in `dsCounts()`. **Adding a line, raising a number or widening an allowed place in the scanner is a STOP.** The same mechanics hold for `tests/Architecture/admin-words-baseline.txt` (`path<TAB>word` lines) and the count in its "never lets the baseline grow" case.
- **The end state for owned files:** no R1, R2, R3, R6, R7, R8 or R9 line names an owned file; R5 lines remain only for the four `<table>`s (`pages/types/fields.tsx` three, `pages/types/show.tsx` one), since there is no shared table component yet. The words baseline loses every line of a file whose words moved.
- **The icon set stays one lazy file** (I-3311): `IconPicker` stays `lazy(() => import('@/components/types/icon-picker'))` in both forms; nothing in an owned file imports `@/components/types/icon-picker`, `@/lib/lucide-icons` or `@/lib/dynamic-icon` statically. `TypeBuilderTest`'s case "preloads at most 48 files on the media screen…" must stay green; if it fails, STOP (raising 48 is not this part's).
- **Tests first:** each new case is run red for the reason given before the code. An existing case is changed only as listed below, with its meaning kept.
- **The `.env`:** in a fresh checkout, `cp .env.example .env` and `php artisan key:generate`. Never copy, read or edit any other `.env`.
- **Never edit** `.ai/`, `CHANGELOG.md`, `docs/roadmap.md`, `docs/backlog.md`, `docs/architecture/*`, `docs/reference/*`, `tests/Pest.php`.
- **Conventional Commits**, staged by file (never `git add -A`), no closing keywords, written for the public: no private names or paths. A code comment cites at most one I-number or one document path.
- `components/ui/*` keeps shadcn's formatting (untouched here); every other file follows `npm run check:fix`.
- **Browser tests:** run after `npm run build`, one file at a time, output redirected to a file and read, never piped through `grep` or `sed`. The helpers in `tests/Pest.php`: `cmSignIn($user)` signs in through the form and returns the page at Home; `cmNavigate($page, $url)` visits with the plugin's whole timeout in one attempt; `cmWaitUntil($page, $js)` polls a condition in the page (the plugin's own `assertSee` looks once); `cmSmallTargets($page)` returns `['small' => [...], 'checked' => n]` from axe's 24-pixel target rule; `cmAxeViolations($page)` runs axe once nothing is mid-transition; `shellSettled($page, $selector)` waits until those elements have no animation running (use it before clicking inside a dialog, sheet or menu; never `getAnimations({ subtree: true })`); `lscrPrefer($user, ['appearance.mode' => 'dark'])` sets a preference. A new case copies its file's `beforeEach` (`withVite()`, `RoleSeeder`, the `RequestHandled` reset).
- **Touch sizes:** under 768 pixels, and on a coarse pointer, `--ctl-h` and `--ctl-h-sm` are 44; a phone-width case at 375 pixels therefore measures touch sizes.
- **The quick check** after each task: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, `npm run types:check`, `npm run check`, `php artisan test --testsuite=Architecture`, and the task's own feature files.
- **When the work is interrupted:** each task ends committed and pushed, with one line under Records: "Done to Task N at `<hash>`, the quick check green". Node missing: `export PATH=/opt/node24/bin:$PATH`; still missing, stop, push and record it (the scanner's tests skip without Node: a known gap, not a pass; never commit a baseline or count no run produced). PostgreSQL or Valkey stopped: `docker compose up -d`, then run again before believing a failure. A test red at the base and not this part's: record it, never change it. Work that starts again reads Records first and treats anything after the last checkpoint as untested.

---

## Task 0: Preconditions, then branch

Run in a checkout of the integration branch. If a line differs **by its text**, STOP and write why under Records.

- [ ] **Step 1:** `git fetch origin`. `git log --oneline -1 --grep="the design system and the admin's new frame" origin/feat/interface-foundation` prints one commit; if the branch is gone, the same on `origin/main` prints one (then `origin/main` is the integration branch for every step below). Neither: STOP. Record the head.
- [ ] **Step 2:** in that checkout:
  - `ls resources/js/components/patterns/{page,page-header,list-page,form-page,in-page-nav,save-bar}.tsx` → all exist; `ls resources/js/components/patterns/section-tabs.tsx resources/js/pages/themes/settings.tsx resources/js/components/themes/use-design-draft.ts 2>&1` → none exists;
  - `grep -n "export function SaveBar\|testId\|onDiscard" resources/js/components/patterns/save-bar.tsx` → the export and both props; `grep -n "export function FormPanel\|export function FormPage" resources/js/components/patterns/form-page.tsx` → two lines; `grep -n "export function ListPage" resources/js/components/patterns/list-page.tsx` → one;
  - `ls resources/js/components/ui/{status-pill,term-chips,radio-group,textarea,checkbox,dropdown-menu,dialog,sheet,notice,panel,scroll-area,field}.tsx` → all exist; `grep -n "axis" resources/js/components/ui/scroll-area.tsx` → record what it shows;
  - `grep -n -- "--z-sticky\|--ctl-h-sm" resources/css/app.css` → both defined;
  - `git grep -n "from '@/components/fields/" -- resources/js/pages/types resources/js/pages/taxonomies` → exactly four lines (`types/show.tsx` two, `taxonomies/show.tsx` two);
  - `grep -n 'href="#theme-settings"' resources/js/components/themes/theme-list.tsx` → one; `grep -n 'id="theme-settings"' resources/js/pages/themes/index.tsx` → one;
  - `git grep -n "'{code}/settings'\|themes.settings" -- routes app resources/js` → nothing;
  - `git grep -n "lazy(() => import('@/components/types/icon-picker'))" -- resources/js` → `pages/types/show.tsx` and `pages/taxonomies/show.tsx`;
  - `git grep -n "InboxList" -- resources/js` → `components/header/inbox-bell.tsx`, `components/inbox/inbox-list.tsx`, `pages/inbox/index.tsx`;
  - `grep -n "pages/themes/index.tsx" tests/Architecture/SharedControlsTest.php` → one line; `grep -c "pages/themes/index.tsx" tests/Feature/Admin/WordingPassListsTest.php` → 2;
  - record: `grep -c -E "js/(pages/(themes|types|taxonomies|navigation|regions|inbox)|components/(themes|types|navigation|inbox))/" tests/Architecture/design-system-baseline.txt` (217 when this plan was written), the same per rule, `dsCounts()`'s numbers, `grep -c -E "^(pages/(themes|types|taxonomies|navigation|regions|inbox)|components/(themes|types|navigation|inbox))/" tests/Architecture/admin-words-baseline.txt` (481), and `grep -n "toHaveCount(" tests/Architecture/AdminWordsTest.php`;
  - record whether the content-lists part has merged (`git log --oneline --merges -20` and `git diff --stat <this plan's base>.. -- resources/js/components/list`); either way, continue (D6).
- [ ] **Step 3:** record the versions from `composer.lock` and `package-lock.json` (Laravel, Inertia both sides, React, Tailwind, Vite, Rolldown, Pest, the browser plugin). `git checkout -b ui/remaining-screens origin/feat/interface-foundation` (or `origin/main`, per Step 1).

## Task 1: Themes, the list and each theme's settings

**Files:** `routes/admin.php`, `app/Http/Admin/ThemesController.php`, `resources/js/types/design.ts`, `pages/themes/index.tsx`, `pages/themes/settings.tsx`, `components/themes/**`; tests below.

**Feature cases, written first** (added to `tests/Feature/Themes/ThemesScreenTest.php`, whose setup activates Fixture Good with Fixture Plain and Fixture Broken installed; they fail first with 404 on the route):

| Case | What it proves |
|---|---|
| "draws the theme in use's settings at its own address, and refuses it to anyone who may not change the design" | `GET /admin/themes/good/settings` as the administrator: component `themes/settings`, `themeGroup` `theme.good`, `slots` 2; as an editor: 403 |
| "sends another theme's settings address back to the list, and an unknown theme's to nothing" | `/admin/themes/plain/settings` and `/admin/themes/broken/settings` redirect to `/admin/themes`; `/admin/themes/nosuch/settings` is 404 |
| "draws the previewed theme's settings once it is previewed" | After `SaveDesignDraft` drafts `design.theme` = `default` (as the file's other cases do), `/admin/themes/default/settings` renders with `preview.code` `default`, and `/admin/themes/good/settings` redirects to the list |

**Browser cases, written first** (`tests/Browser/ThemesScreenTest.php`): "keeps Themes inside the page at 375 pixels: one column of cards, nothing scrolling sideways, every target 24 pixels or more" (`resize(375, 812)`; `scrollWidth <= clientWidth`; every `[data-test="theme-list"] > li` has one `left`; `cmSmallTargets` empty, `checked > 0`); "asks before discarding the unpublished design changes, and discards on Discard changes" (draft a change to `theme.good` with `SaveDesignDraft` in the setup of the case; on the list, press Discard: `[data-test="design-discard-dialog"]` opens and nothing is discarded; "Keep them" closes it, the draft row is still there; Discard again, then `[data-test="design-discard-confirm"]`: the toast "Unpublished design changes discarded." and no `draft_values` left). They fail first on the dialog's absence and on the cards' layout.

- [ ] **Step 1:** write the cases; run; record each first failure.
- [ ] **Step 2:** the route and method (D1); `npm run build` regenerates Wayfinder's functions. The hook, the two pages, the cards, the draft bar and the preview panel (D2).
- [ ] **Step 3:** change the existing cases as listed below; run `tests/Feature/Themes/ThemesScreenTest.php`, `tests/Feature/Design/` (whole folder), `tests/Feature/Admin/WordingPassListsTest.php`, `tests/Architecture/SharedControlsTest.php`; then, after `npm run build`, `tests/Browser/ThemesScreenTest.php`. All green.
- [ ] **Step 4:** the baselines for the themes files (Global Constraints); quick check; commit `feat(admin): Themes on the page patterns, each theme's settings on its own page`; push; the Records line.

## Task 2: Types: the list and a type's form

**Files:** `pages/types/index.tsx`, `pages/types/show.tsx`, `components/types/builder-parts.tsx`, `components/patterns/section-tabs.tsx`.

The list on `ListPage` (the download and "Add new type" as actions; the lock's two lines as a `Notice` `tone="info"`), D6's counts and states. A type on `FormPage`: title and source pill as today, "Open {plural}" as the header's action, the code and lock notes as `Notice`s, then `SectionTabs` (`label={__('Sections of this type')}`) over the sections **Names, In the admin, On the site, In the editor, Fields, Permissions**, and for a type made here **Delete this type** (ids `type-section-names`, `-admin`, `-site`, `-editor`, `-fields`, `-permissions`, `-delete`), each a `FormPanel`; the code-declared and locked views use the same sections with their facts. `SaveBar` as D4. Radios as D7. "Delete {plural}" stays a danger-quiet small button at the foot of its panel, confirmed as today.

**Browser cases, written first** (`tests/Browser/TypeBuilderTest.php`):

| Case | What it proves |
|---|---|
| "moves between Films' sections from the row of tabs without leaving the page, and keeps what was typed" | On `/admin/types/film`, type " (new)" after the plural; click the tab "Permissions": `#type-section-permissions`'s top is within 80 pixels of the view's top, the tab has `aria-current="location"`, the path is still `/admin/types/film`, `[data-test="leave-guard"]` is absent, `#type-plural`'s value still ends with " (new)", and `[data-test="save-bar"]` is not hidden |
| "shows the save bar when only the icon changes, and Discard puts the icon back" | On Films, change the icon through the picker and nothing else: the save bar is not hidden; Discard: it is hidden and `input[name="icon"]` holds the saved icon again |

Both fail first on the missing tab row and save bar.

- [ ] **Step 1:** the cases; run; record. Then build; change the cases listed below; run `TypeBuilderTest` (all, the preload case included), `tests/Feature/Types/` (whole folder).
- [ ] **Step 2:** plant red: make `dirty` read `isDirty` alone: the icon case fails; restore; green; record the first line.
- [ ] **Step 3:** baselines; quick check; commit `feat(admin): Types on the page patterns, the form in sections with one save bar`; push; Records.

## Task 3: A type's fields

**Files:** `pages/types/fields.tsx`, `components/types/field-sheet.tsx`, `components/types/icon-picker.tsx` (its scrolling box only).

On `ListPage` (today's title, line and "Add a field" action). The sortable list, "From Teme" and "Removed fields" with Restore keep their tables (R5 `<table>` stays), their drag grip, Move up and Move down, and every `data-test`; their frames become `Panel`s; raw buttons become `Button` (ghost, `icon-sm` where they are icons), keeping `aria-label`s, `title`s and `data-test`s. The sheet: today's `Sheet`, its radios and textarea as D7, its scrolling body in `ScrollArea`. The remove dialog stays inside the pattern (R7 root).

No new case: `FieldsPageTest` already holds a 375-pixel case and axe in both schemes.

- [ ] **Step 1:** change the cases listed below (`.checked` reads); run `FieldsPageTest` first to see them red on the new radios once the sheet is converted; record.
- [ ] **Step 2:** build; run `FieldsPageTest`, `tests/Feature/Admin/FieldsScreenTest.php`, and `TypeBuilderTest`'s preload case alone (`--filter="preloads at most 48"`). Green.
- [ ] **Step 3:** baselines; quick check; commit `feat(admin): a type's fields on the page patterns`; push; Records.

## Task 4: Taxonomies

**Files:** `pages/taxonomies/index.tsx`, `pages/taxonomies/show.tsx`.

As Types: the list on `ListPage` (the "Used by" column names the types, as today), a taxonomy on `FormPage` with `SectionTabs` (`__('Sections of this taxonomy')`) over **Names, Terms, In the admin, On the site** and, for one made here, **Delete this taxonomy** (ids `taxonomy-section-…`), `SaveBar` as D4 (`testId="taxonomy-save"`), radios as D7.

**Browser case, written first** (`tests/Browser/TaxonomyBuilderTest.php`): "moves between Genres' sections from the row of tabs without leaving the page" (as Task 2's, on the "On the site" tab and `#taxonomy-plural`). Fails first on the missing row.

- [ ] **Step 1:** the case; run; record. Build; change the cases listed below; run `TaxonomyBuilderTest`, `tests/Feature/Types/TaxonomiesScreenTest.php`, `CategoriesAndTagsTest` (the term screens link to Set up).
- [ ] **Step 2:** baselines; quick check; commit `feat(admin): Taxonomies on the page patterns, the form in sections with one save bar`; push; Records.

## Task 5: Navigation: the menus and a menu's editor

**Files:** `pages/navigation/index.tsx`, `pages/navigation/edit.tsx`, `components/navigation/tree-editor.tsx`, `components/navigation/add-item-panel.tsx`.

The list on `ListPage` ("Create menu" stays where it is today, `#new-menu` kept). The editor on `FormPage` (crumbs Navigation › {name}; Rename and Delete as header actions, as today): "Show in" as a `FormPanel` whose selects save at once, as today; Items as a `FormPanel` holding the form of D4 and the tree; the hint about Alt and the arrow keys under it. The "Add an item" and "Rename the menu" sheets stay `Sheet`s, inside the pattern; the add panel's raw buttons become `Button`s and its scrolling body a `ScrollArea`. The tree's warning chip ("Needs an item under it") becomes a `StatusPill tone="warn"` with the same words. D5 for phones.

**Browser cases, written first** (`tests/Browser/NavigationScreensTest.php`):

| Case | What it proves |
|---|---|
| "keeps each item on one line at 375 pixels, its moves in the item's menu" | `resize(375, 812)` on the Main menu's editor: no sideways scroll; for each `[data-item-key]` row, the label field's and `[data-control="more"]`'s tops differ by under 2 pixels; no `[aria-label^="Move up:"]` is visible; `cmSmallTargets` empty |
| "moves an item down from its menu on a phone, focus back on its menu button" | At 375, open "More actions for Harbour", `shellSettled` the menu, choose "Move down": the order of labels changes as the buttons would, the menu closes, `document.activeElement` is that row's `[data-control="more"]`, the save bar shows |
| "says in the menu why a move can't be made" | At 375, after adding the heading "Club" under Harbour as the existing third-level case does, its menu's "Move under the item above" is `aria-disabled="true"` and holds the line "A heading can't go to the third level, where nothing can go under it." |

They fail first on the missing `[data-control="more"]`.

- [ ] **Step 1:** the cases; run; record.
- [ ] **Step 2:** build; change the cases listed below; run `NavigationScreensTest`, `tests/Feature/Navigation/` (whole folder), `ThemesScreenTest` (its menu places).
- [ ] **Step 3:** plant red: render the move buttons at every width (drop `max-sm:hidden`): the one-line case fails; restore; record.
- [ ] **Step 4:** baselines; quick check; commit `feat(admin): Navigation on the page patterns, moves in the item's menu on a phone`; push; Records.

## Task 6: Regions

**Files:** `pages/regions/index.tsx`, `components/themes/theme-regions.tsx` (Themes draws the same rows).

On `ListPage`: the active theme's regions in today's `DataTable` with D6's chips, then "From other themes" (`data-test="unplaced"`, kept) as a second table under its heading, and the empty line where the theme declares none. `theme-regions.tsx`'s card lists use the same `TermChips` (keeping "Callout, Button · At most 5 blocks" readable: the chips, then the limit line).

**Browser case, written first** (`tests/Browser/RegionsScreenTest.php`): "keeps Regions inside the page at 375 pixels, its cards as chips, every target 24 pixels or more" (`resize(375, 812)`; no sideways scroll of the page; `[data-test="region-cards"] [data-slot="term-chip"]` present, with `[data-test="term-chips-more"]` where both do not fit; `cmSmallTargets` empty, `checked > 0`). It fails first on the missing chips. The "+N" itself is the foundation's component and is tested there.

- [ ] **Step 1:** the cases; run; record. Build; change the cases listed below; run `RegionsScreenTest`, `ThemesScreenTest`, `tests/Feature/Regions/`.
- [ ] **Step 2:** baselines; quick check; commit `feat(admin): Regions on the page patterns, card lists as chips`; push; Records.

## Task 7: The Inbox

**Files:** `pages/inbox/index.tsx`, `components/inbox/inbox-list.tsx`.

On `ListPage` (title, line, "{n} unread" and "Mark all as read" as actions, keeping `data-test="inbox-unread"` and `inbox-read-all`), the list in a `Panel` (no border of its own when `inPanel`), D6's rows, the pager under it. `EmptyState` as today ("Nothing new").

**Browser case, written first** (`tests/Browser/InboxScreenTest.php`): "gives each item's title a 44-pixel target at 375 pixels, with nothing scrolling sideways" (`resize(375, 812)`; every `[data-test="inbox-item"] button` is at least 44 tall; `scrollWidth <= clientWidth`). It fails first at 24 or so.

- [ ] **Step 1:** the case; run; record. Build; run `InboxScreenTest`, `HeaderActivityTest` and `ShellResponsiveTest` (the shell's inbox panel), `tests/Feature/Inbox/`.
- [ ] **Step 2:** baselines; quick check; commit `feat(admin): the Inbox on the list pattern, item targets at 44 on touch`; push; Records.

## Task 8: Gate and records

- [ ] The full `composer check` once.
- [ ] Browser files, after `npm run build`, one at a time: `ThemesScreenTest`, `TypeBuilderTest`, `FieldsPageTest`, `TaxonomyBuilderTest`, `CategoriesAndTagsTest`, `NavigationScreensTest`, `RegionsScreenTest`, `InboxScreenTest`, `HeaderActivityTest`, `ShellResponsiveTest`, `FullPassTest` (it visits Navigation, a menu, Regions and Themes), `DesignCatalogueTest`. GitHub runs the rest after the merge.
- [ ] `grep -E "js/(pages/(themes|types|taxonomies|navigation|regions|inbox)|components/(themes|types|navigation|inbox))/" tests/Architecture/design-system-baseline.txt` → only the four R5 `<table>` lines; `git grep -n "from '@/components/fields/\|from '@/components/list/page-header'" -- resources/js/pages/{themes,types,taxonomies,navigation,regions,inbox}` → nothing; `git diff --stat origin/feat/interface-foundation -- resources/js/components/ui resources/css` → nothing; `git diff --stat origin/feat/interface-foundation -- resources/js/components/patterns` → only `section-tabs.tsx`.
- [ ] Records: commits; each case seen red (task, file, case, first line); the plants; baseline counts per rule and the admin-words count before and after; every `B-?? (draft)`; how to try it (on a local site: Site › Themes, a card's "Theme settings", change a setting, Publish, then Discard asks first; Site › Types › a type, the row of tabs; Content › Navigation › a menu at phone width, an item's "…"). Commit `docs(plans): remaining screens records`; push.

---

## Existing tests that change (meaning unchanged)

| File | Case | Change |
|---|---|---|
| `tests/Browser/ThemesScreenTest.php` | "previews a theme from its row, focusing the panel…" | After the preview, follow the previewed card's `[data-test="theme-settings-link"]` to `/admin/themes/default/settings`, change `#design-show_author_box` there and press `[data-test="theme-stop"]` there; the rest as written (the toast, the panel gone, nothing drafted after 1.5 s) |
| same | "activates a previewed theme with a change made a moment before…" | The change and `[data-test="theme-activate"]` happen on the settings page; then the list is visited and its first card read |
| same | "takes the active theme's row to its settings…" | Click `[data-test="theme-good"] [data-test="theme-settings-link"]`; wait for `location.pathname === '/admin/themes/good/settings'`; the `h1` reads "Fixture Good settings"; the Menus panel holds "A menu you choose here shows once you publish"; the page holds no "brand colour stay when you change theme", and the list does |
| `tests/Feature/Admin/WordingPassListsTest.php` | "draws Open Settings › General as a button…" | Unchanged file (`pages/themes/index.tsx`); the end marker `{page.preview !== null && (` is replaced by the preview panel's opening as written in the new file: record the exact text |
| same | "puts Edit menus in the Menus panel's header on Themes" | Reads `pages/themes/settings.tsx`; the slice runs from `title={__('Menus')}` to the panel's first field, and holds "Edit menus" and `asChild` |
| `tests/Architecture/SharedControlsTest.php` | "gives no page its own select styling" | The dataset's `pages/themes/index.tsx` becomes `components/themes/design-field.tsx` (where the select now lives) |
| `tests/Browser/TypeBuilderTest.php` | "saves a type that isn't public, has items and was saved as searchable…" | Before Save, change the singular name to "Memo" (the bar shows only after a change); the assertions as written |
| same | "gives Films their own permissions…" | `document.querySelector("#type-permissions-own").checked` reads `getAttribute("aria-checked") === "true"` |
| `tests/Browser/TaxonomyBuilderTest.php` | any read of a radio's `.checked` | `aria-checked`, as above; `.disabled` reads stay |
| `tests/Browser/FieldsPageTest.php` | "turns a date field date only while no movie holds a value…" | `#field-ask-date`'s `.checked` reads `aria-checked` |
| `tests/Browser/NavigationScreensTest.php` | "saves a changed label with Save menu…" | `assertSee('Unsaved changes')` becomes `assertSee('You have unsaved changes')`, and `assertDontSee` the same |
| `tests/Browser/RegionsScreenTest.php` | "passes axe on Regions in both schemes…" | The cards are read from `[data-test="region-cards"] [data-slot="term-chip"]` |

Any other existing case that has to change is a STOP.

## Every word

**New** (American English), through `__()` or `__n()`: "Changes are saved as a draft as you make them. Visitors see them once you publish.", "Discard the unpublished design changes?", "Every change waiting to go live is discarded, and the site stays as it is.", "Keep them", "Discard changes", "Sections of this type", "Sections of this taxonomy", "this type", "this taxonomy", "More actions for :name", "card", "cards", "No cards", "“:name”" (the save bar's what), and ":count more :noun: :names" read at N = 1 ("1 more card: Callout"). **Moved into `__()` unchanged:** every word of the theme settings that moves into `pages/themes/settings.tsx`, `design-field.tsx` and `design-draft-bar.tsx` (":name settings", "Visitors see these once you activate :name.", "Menus", "Edit menus", "Preview", "None", "Unpublished design changes", "Discard", "Publish", and the rest), and "Save menu". Words that stay in their file stay as written.

## What must not be done

- Change server behaviour beyond D1's route and method; add a route, an Operation or a migration; change `DesignScreen`, the settings registry or any group's `screen`.
- Change any existing file in `components/ui/**`, `components/patterns/**`, `components/list/**`, `components/fields/**`, `components/editor/**`, `components/header/**`, a token, the scanner or `tests/Pest.php`.
- Add dragging to Navigation; a per-item "Mark as read"; a direct Activate for a theme not previewed; an "Install a theme" button; anything drawn for plugins that are not installed; a custom type's own list.
- Move the lists onto a table or card layout other than today's `DataTable`.
- Import the icon set statically; add a dependency; delete a test; change a test's meaning.

## STOP conditions

Stop, write why under Records, commit and push, then stop, when:
- the integration branch lacks the foundation's commit, or a Task 0 line differs by its text;
- a token, or a change to an existing `ui/`, `patterns/`, `list/` or `fields/` file, is needed;
- a baseline line would have to be added, a count raised or an allowed place widened;
- an existing test not in the table above would have to change, or one in it would change its meaning;
- `TypeBuilderTest`'s preload case fails;
- a theme's settings cannot be drawn from `DesignScreen::data()` as it is, or the hook cannot be lifted without changing what it sends;
- Radix's radio group cannot carry today's ids and `name` inside the Inertia `Form`, so a save would send something different.

## Security

- The new route sits inside the group that requires `settings.design.manage`; it reads what `/admin/themes` already sends to the same people, and changes nothing. The `{code}` is matched by the manifest's code pattern and looked up in the registry; an unknown one is 404.
- Every write stays on today's routes, with their abilities and Operations.

## At the merge (for the maintainers)

- `docs/architecture/design-system.md`: 4.27 gains `SectionTabs` (the tab look, links to sections of one form, `aria-current="location"`, no visit); 4.12 notes the theme settings' draft bar (Publish and Discard) as the design's footer in place of a save bar.
- `docs/architecture/themes.md` (or wherever the Themes screen is described): the list and `/admin/themes/{code}/settings`.
- Changelog, at the release: "Themes, Types, Taxonomies, Navigation, Regions and the Inbox now share the new admin design; each theme's settings have their own page."
- Baseline conflicts: take both sides' removals, then run `DesignSystemTest` and `AdminWordsTest` and set each count to what the merged baseline holds.

## Open points

| # | Point | Recommendation |
|---|---|---|
| 1 | Settled 1's "in-page tabs" read as links to the form's sections (`SectionTabs`), not Radix `Tabs` panels, which would hide the other sections' fields | Keep; if panels were meant, a later change hides the sections and the tests switch tabs first |
| 2 | `/admin/settings/theme.{code}` and "Find a setting" still lead to the list, not to the theme's settings page | A later small change gives a settings group's `screen` a route parameter |
| 3 | Per-item "Mark as read" in the Inbox needs a route and an Operation | A later issue, with its authorization-failure test |
| 4 | The "+N" chip is 24 tall on touch (`ui/term-chips.tsx`) | The foundation's maintainers decide whether chips reach 44 on touch |
| 5 | Types, Taxonomies, Navigation and Regions on the shared table with phone cards | Done in the content-lists part's follow-up once it merges |
| 6 | `SectionTabs` repeats `InPageNav`'s item classes | Fold both onto one exported class string when the foundation next changes `in-page-nav.tsx` |

---

## Records

<!-- Filled in while working: commits; each case seen red (task, file, case, first line); the plants; the design-system and admin-words counts before and after; differences from the plan as B-?? (draft); any STOP. -->

Setup: `scripts/cloud-setup.sh` ran in `/home/user/teme` from 2026-11-14T14:50:18Z to 15:01:41Z and finished with "Setup complete"; `pwd` is `/home/user/teme`.

### Task 0

- The integration branch is `origin/main` (the hand-off's instruction in place of `origin/feat/interface-foundation`); its head was `e77848d` (Merge #268), and `git log --grep="the design system and the admin's new frame" origin/main` prints `bd0e715`.
- Every Step 2 line matched by its text. `ui/scroll-area.tsx`'s prop is `axis` (`'y' | 'x' | 'both'`).
- This part's design-system baseline lines: 217 (R1 14, R2 117, R3 27, R5 17, R6 8, R7 28, R8 4, R9 2). `dsCounts()`: R1 131, R2 823, R3 183, R5 102, R6 55, R7 135, R8 39, R9 25, R10 15, R14 19, R15 9. Admin-words lines for these paths: 481; `toHaveCount(2552)`.
- The content-lists part had not merged (the last merges are #268 and #267; nothing under `components/list` changed).
- Versions: laravel/framework v13.39.0, inertiajs/inertia-laravel v3.5.2, pestphp/pest v5.3.4, pestphp/pest-plugin-browser v5.1.2; @inertiajs/react 3.9.1, react 19.3.1, tailwindcss 4.3.3, vite 8.3.5, rolldown 1.2.15, vite-plus 0.3.0.

### Commits

- `16803b7` feat(admin): Themes on the page patterns, each theme's settings on its own page
- `604f8bf` feat(admin): Types on the page patterns, the form in sections with one save bar
- `5465c58` feat(admin): a type's fields on the page patterns
- `1d97877` feat(admin): Taxonomies on the page patterns, the form in sections with one save bar
- `850d369` feat(admin): Navigation on the page patterns, moves in the item's menu on a phone
- `d6e5703` feat(admin): Regions on the page patterns, card lists as chips
- `1fed269` feat(admin): the Inbox on the list pattern, item targets at 44 on touch

### Cases seen red

The feature cases ran against the branch before the route; the browser cases ran against a build of `origin/main`'s `resources/js` with the new cases in place.

| Task | File | Case | First failure |
|---|---|---|---|
| 1 | Feature/Themes/ThemesScreenTest | the three new cases | "Expected response status code [201, 301, 302, 303, 307, 308] but received 404." (and 404 where a 200 was expected) |
| 1 | Browser/ThemesScreenTest | "asks before discarding the unpublished design changes…" | Timeout 5000ms at the click on `[aria-label="Unpublished design changes"] [data-test="design-discard"]` |
| 1 | Browser/ThemesScreenTest | "keeps Themes inside the page at 375 pixels…" | **Not seen red**: today's list is already one column and nothing scrolls sideways (B-15) |
| 2 | Browser/TypeBuilderTest | "moves between Films' sections…" | Timeout waiting for `nav[aria-label="Sections of this type"]` |
| 2 | Browser/TypeBuilderTest | "shows the save bar when only the icon changes…" | Timeout 5000ms at the click on `[data-test="save-bar-discard"]` |
| 4 | Browser/TaxonomyBuilderTest | "moves between Genres' sections…" | Timeout waiting for `nav[aria-label="Sections of this taxonomy"]` |
| 5 | Browser/NavigationScreensTest | "keeps each item on one line at 375 pixels…" | "Failed asserting that 5 is identical to 0." (no `[data-control="more"]` in any row) |
| 5 | Browser/NavigationScreensTest | "moves an item down from its menu on a phone…" | Timeout waiting for "More actions for Home" |
| 5 | Browser/NavigationScreensTest | "says in the menu why a move can't be made" | Timeout waiting for "More actions for Club" |
| 6 | Browser/RegionsScreenTest | "keeps Regions inside the page at 375 pixels…" | Timeout waiting for `[data-test="region-cards"] [data-slot="term-chip"]` |
| 7 | Browser/InboxScreenTest | "gives each item's title a 44-pixel target…" | "Failed asserting that 24 is equal to 44 or is greater than 44." |
| 3 | Browser/FieldsPageTest | "turns a date field date only…" (before its read changed) | "Failed asserting that two arrays are identical." (`.checked` is undefined on Radix's item) |

### Plants

- Task 2: `dirty` reading `isDirty` alone: "shows the save bar when only the icon changes…" fails with "Failed asserting that true is false." (the bar stays hidden). Restored; green.
- Task 5: the move buttons at every width (no `max-sm:hidden`): "keeps each item on one line…" fails with "Failed asserting that 5 is identical to 0." on the visible Move up count. Restored; green.

### Counts

- Design-system baseline, this part's lines: 217 → 4 (the four R5 `<table>` lines: `types/fields.tsx` three, `types/show.tsx` one). `dsCounts()`: R1 131 → 117, R2 823 → 706, R3 183 → 156, R5 102 → 89, R6 55 → 47, R7 135 → 107, R8 39 → 35, R9 25 → 23; R10, R14 and R15 unchanged.
- Admin words: 2552 → 2529 (this part's lines 481 → 458).
- Task 8's greps: only the four `<table>` lines; no page imports `components/fields/` or `components/list/page-header`; nothing changed under `components/ui` or `resources/css`; under `components/patterns` only the new `section-tabs.tsx`.

### Decisions (drafts)

- **B-1 (draft)** `FormPage` has no badge slot. Found: the plan's "title and source pill as today" on a form page. Options: a `badge` prop on `FormPage` (a change to `patterns/`, refused by the plan), the pill in the header's actions, the pill at the start of the description line. Chose the description line: no shared file changes and the pill still sits under the title. `604f8bf`, `1d97877`.
- **B-2 (draft)** The preview panel on the list says nothing about unchosen menu places. Found: "Choose them under Menus below" is true only on the settings page, where the Menus panel is. Options: the same line on both pages, the line on the settings page only. Chose the settings page only (`unbound={[]}` on the list). `16803b7`.
- **B-3 (draft)** A previewed theme's card has its own Activate button ("Activate :name"). Found: the cards hold Preview, and the plan names activating from the settings page and the preview panel. Options: no Activate on the card, "Activate" alone, "Activate :name". Chose "Activate :name" through `__()`, calling the same activation as the panel. `16803b7`.
- **B-4 (draft)** `SectionTabs` wraps its row. Found: the existing 375-pixel cases of TypeBuilderTest and TaxonomyBuilderTest need every link in `main` inside the page, and a row scrolling sideways leaves tabs past the edge. Options: wrap the row, hide the row on phones, change the cases (refused). Chose `flex-wrap` on the list. `604f8bf`.
- **B-5 (draft)** The Films and Genres section cases run at 1440 × 700, and "the view" is the nearest box that scrolls the section, else the window. Found: the window scrolls the admin, and at the default 1117 pixels tall the page cannot scroll Permissions (or On the site) to its top, being near the foot. Options: a shorter view, a section nearer the top, a weaker check. Chose the shorter view, keeping the plan's tab and its 80-pixel check. `604f8bf`, `1d97877`.
- **B-6 (draft)** A code-declared taxonomy's read-only page has no "In the admin" tab. Found: that view draws no In the admin panel today. Options: an empty panel for the tab, leave the tab out. Chose to leave it out. `1d97877`.
- **B-7 (draft)** The icon picker's height and columns are custom properties set in `style`. Found: its `max-h-[50vh]` and its column template are arbitrary values (R1). Options: a token (refused), custom properties (R15 allows them). Chose custom properties. `5465c58`.
- **B-8 (draft)** The tree editor's focus fallback selector is written `input[data-control='label']`. Found: the scanner reads the string `[data-control="label"]` as an arbitrary class (R1). Options: keep it (a new R1 line, refused), reword the selector. Chose the selector with the element and single quotes; same element. `850d369`.
- **B-9 (draft)** "moves an item down from its menu on a phone" moves Home, not Harbour. Found: Harbour is the last item under Visit, so its Move down is refused ("It is already last."). Options: Home, Partners, or moving Harbour up. Chose Home, the first item, whose Move down reorders the top level as its button would. `850d369`.
- **B-10 (draft)** On a phone a row's label field is 44 tall and shares a line with its "…" in a group of their own. Found: the plan's one-line case compares the two tops, and a 32-tall field beside a 44-tall button differs by 6. Options: align the tops (`items-start`), the field at 44 below 640. Chose 44, which also gives the field a touch-sized target. `850d369`.
- **B-11 (draft)** Focus comes back to the row's "…" from the menu's close, not from the move itself. Found: the menu holds focus until it closes, so focusing the button during the move was undone. Options: a timer, the menu's `onCloseAutoFocus`. Chose `onCloseAutoFocus`, which focuses the moved row's "…" (found again by its key, since a row moved under another is drawn anew). `850d369`.
- **B-12 (draft)** The add-item panel's tabs are ghost `Button`s with a raised look when selected, not the underline. Found: an underline needs `rounded-none` (R6), and `ui/tabs.tsx` would replace the ids the tests click. Options: `ui/tabs`, the underline with a baseline line (refused), `Button`s with `aria-selected` drawn as `ui/tabs` draws its active tab. Chose the last. `850d369`.
- **B-13 (draft)** The menu editor's crumbs are set when the page draws (Navigation › the menu's name) with `setLayoutProps`; the static layout stays, as MenuWordsTest reads it. `850d369`.
- **B-14 (draft)** Themes' region overview keeps its words line ("Callout, Button · At most 5 blocks") instead of chips. Found: ThemesScreenTest reads that line, and it is not in the table of cases that change. Options: chips (that case would change: a STOP), chips with the words hidden for the test, the words line with the tokens' sizes. Chose the words line. Regions itself has the chips. `d6e5703`.
- **B-15 (draft)** "keeps Themes inside the page at 375 pixels…" was not seen red: today's list already passes it. Kept as written; it now guards the cards. `16803b7`.
- **B-16 (draft)** The draft bar on a phone sits 8 pixels inside the edges with its corners, not square across the width. Found: `rounded-none` is R6. `16803b7`.
- **B-17 (draft)** WordingPassListsTest's first case needed no change: `pages/themes/index.tsx` still holds `{page.preview !== null && (` after "Open Settings › General". `16803b7`.
- **B-18 (draft)** The quick check after each task ran on a working tree that already held later tasks' code; each commit removed only its own baseline lines, and the Architecture suite is green from `850d369` on.
- **B-19 (draft)** Two editor guides name moved words. Found: the full check's `tests/Feature/Guides/Editors/EditorPagesTest` failed twice ("**Unsaved changes**" in `organising.md`; "**Publish**" and "**Discard**" in `settings.md`), the words having moved into `patterns/save-bar.tsx` and `components/themes/design-draft-bar.tsx`. Options: point the guides' `sources` at those files (the test keeps its meaning), reword the guides, keep the words in the old files. Chose the sources, the rule of the standing instruction for a test that reads a file this part changes. `b996bf5`.

### Gate

- Full `composer check` (on `1fed269`): Pint, PHPStan, types, lint and format, the themes' build passed; Architecture 101 passed (1207 assertions); Unit and Feature 10437 passed, 2 skipped, 2 failed (the two guide cases of B-19), in 1674 s. After `b996bf5`, `tests/Feature/Guides/` passes (289) and nothing else changed, so the full check was not run a second time.
- Browser files after `npm run build`, one at a time: ThemesScreenTest 10, TypeBuilderTest 14 (the preload case included), FieldsPageTest 11, TaxonomyBuilderTest 10, CategoriesAndTagsTest 7, NavigationScreensTest 12, RegionsScreenTest 7, InboxScreenTest 4, HeaderActivityTest 3, ShellResponsiveTest 10, FullPassTest 40, DesignCatalogueTest 18: all passed. (One earlier ShellResponsiveTest run failed axe on the palette button's contrast in the dark scheme, a file outside this part; it passed on the rerun.)
- No STOP.

### How to try it

On a local site: Site › Themes, a card's "Theme settings", change a setting, then Publish; Discard asks first. Site › Types › a type: the row of tabs moves through the sections without leaving the page, and the save bar shows after any change, the icon's included. Content › Navigation › a menu at phone width: each item's "…" holds its moves and Remove.

### Follow-up W5-2

The review's fixes, one commit each, pushed after each.

| # | Commit | Seen red first |
|---|---|---|
| 1 | `1fa137a` fix(admin): clear the save bar after a type or a taxonomy is saved (`setDefaultsOnSuccess` on both edit forms; `taxonomies`, `supports` and `types` sorted in the snapshots) | TypeBuilderTest "changes Films while they have items…", with the new wait after "Saved Movies.": Timeout 5000ms waiting for the save bar to be hidden |
| 2 | `3cb5bb7` test(admin): the in-view check runs after the plural is typed and asks for `!bar.hidden`, a height, and the window's bounds | A test-only change; it passes on the fixed screen |
| 3 | `456e3c1` fix(admin): `max-sm:static` on `SectionTabs`' nav, with TypeBuilderTest "keeps the row of tabs from covering a section's top at 375 pixels" | "Failed asserting that false is true." (the section's top was above the nav's bottom) |
| 4 | `af8c77e` fix(admin): the label field `h-(--ctl-h)`; after Remove from the "…" menu, focus goes to the item now in its place, else the one before, else its parent; NavigationScreensTest "puts focus on the next item's menu button after Remove…" | Timeout waiting for focus on "More actions for About" |

- **B-20 (draft)** TypeBuilderTest "…the Save bar in view" restored to its meaning: its in-view check ran before any change, while the bar was hidden and its box all zeros, so it proved nothing. Options: drop the check, move it after a change. Chose to move it after `type('#type-plural', 'Movies')`, adding `!bar.hidden` and a non-zero height. `3cb5bb7`.
- **B-21 (draft)** The 375-pixel tabs case taps "On the site", not the last tab. Found: the last section (Delete this type) sits at the page's foot, so the page can't scroll it to the top and the case passed without the fix. Options: the last tab with a taller page, a middle tab. Chose "On the site", and the case waits for the scroll to settle before comparing, seen red before the fix. `456e3c1`.
- **B-22 (draft)** The label field is now 36 tall from 640 up (the token), not 32; the reviewer's choice. `af8c77e`.
- Checks: Pint, PHPStan, types and lint passed; Architecture 101 passed; `tests/Feature/Navigation/`, `tests/Feature/Types/` and `tests/Feature/Guides/` 758 passed. After `npm run build`: TypeBuilderTest 15, TaxonomyBuilderTest 10 and NavigationScreensTest 13 passed.
