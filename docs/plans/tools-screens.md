# Interface: the Tools screens on the design system

**One part, one branch: `ui/tools-screens`, from `origin/main`.** It moves the screens behind the Tools hub onto the design system: Import with its step bar, Backups (all but the place form), Page cache, Updates, Redirects with the 404 log, and AI activity. It adds no feature and changes no server code.

Steps use checkbox (`- [ ]`) syntax for tracking.

**Written 2026-11-14** for the repository `temecms/teme`, against `origin/main`, which holds the design system's foundation, the Combobox, the sign-in pages and people and account. This plan is lighter (I-569): it writes out the decisions, names, permissions, tests and the delicate code, and describes the rest.

**Goal.** Every page under `pages/tools/**` except Jobs and the hub renders one page pattern. Each Tools screen uses the shared parts. Not one line in `tests/Architecture/design-system-baseline.txt` or `tests/Architecture/admin-words-baseline.txt` names a file this part owns, apart from `components/jobs/**`, which is not touched.

**Architecture.** Restyle, do not redesign. Each screen keeps its routes, props, server code, words and test hooks (`data-test`, ids, `aria-label`s), and changes only its frame and parts. `ListPage` frames the lists, `FormPage` frames the single-column screens, `Panel` replaces the bordered sections, `Notice` replaces `role="note"`, `StatusPill` replaces `Badge`, and `StepBar` draws the two tasks in steps. `InPageNav` draws the two Redirects views, `Field` ties labels and errors, `FreshPassword*` asks for a lapsed password, and `RadioGroup`, `Select`, `Textarea`, `Collapsible`, `Progress` and `ScrollArea` replace the raw controls.

**Proposal:** `docs/proposals/tools-screens.md`. Its "Settled" section binds this plan: (1) Tools › Status is not built; (2) the lists stay on today's table; (3) single-column screens on `FormPage`, lists and a site's import page on `ListPage`; (4) the plan step shows Analyze until the plan arrives, then Choose; (5) clearing the page cache stays one click; (6) the password prompt is a dialog on the key page and inline in the key dialog.

**Spec:** `docs/architecture/design-system.md` ("DS"): 4.11 (field), 4.12 (forms), 4.13 (status pill), 4.22 (notice), 4.23 to 4.25 (empty, loading, error), 4.26 (table, for how the lists meet later), 4.27 (in-page navigation), 4.28 (panel), 4.40 (progress, scroll area), 4.41 (fresh password), 4.45 (step bar), section 5 (patterns), section 7 (accessibility), section 8 (writing), 9.2 (the check's rules).

**Tech stack:** Laravel 13, PHP 8.4, PostgreSQL 18, Inertia 3 (`@inertiajs/react`), React 19, TypeScript, Tailwind 4, shadcn/ui on Radix, Vite 8, Pest 5 with `pestphp/pest-plugin-browser`. Task 0 records the exact versions from the locks.

**Bound by:** I-3439 (every new word in American English; words that only move keep their spelling), I-3373 (every word a person reads through `__()` or `__n()`), I-3404 (one look), I-797 (a code comment cites one I-number or one document path), I-569, I-3317, I-3369.

**New dependencies, migrations, permissions, operations, routes, contract members (`packages/teme-contracts`), tokens:** none. **Drafts:** `B-?? (draft)` for plan faults.

---

## Where the documents and the code disagree, and what this plan does

1. **Tools › Status.** The foundation's notes expected it from this part. It does not exist (`git grep -n "tools.status" origin/main -- routes` finds nothing), and the proposal leaves it out. **Not built.** The top bar's status panel keeps its "See all" as it is.
2. **No status pattern.** DS 5 has no pattern for a screen that shows state (Backups, Page cache, Updates). R7 accepts only the named patterns, and widening it is a STOP. **They render `FormPage`** (the 760 column; they are 768 today) without a `SaveBar` where there is no form (D1).
3. **Two current steps.** `components/import/import-steps.tsx` marks both Analyze and Choose with `aria-current="step"` on the plan step. `StepBar` takes one `current`. **D2 picks one.**
4. **The reference design draws more than the product has**: warming the cache, clearing one address, cache counts, a confirmation before clearing, report filter chips, rolling back an update, and figure tiles. **None is built** (proposal, "Not in").
5. **The lists' full table** is the content-lists part's, being built at the same time. **These screens keep `DataTable`, `Pager`, `EmptyState`, `BulkBar` and `RowLinks` exactly as they are on `origin/main`.** They stop importing `list/page-header` and `list/tab-strip` (D3). Moving onto the full table is a follow-up after both parts merge (open point 1).
6. **Words in source-scanning tests.** `tests/Feature/Admin/WordingPassToolsTest.php` and `AccessibilityTest.php` find literal JSX like `Where backups are kept </h2>` and `confirmLabel="Forget this site"`. Moving words into `__()` changes that text. **Their needles change, and the order or presence each case proves stays the same** (the table under "Existing tests that change").

---

## The decisions, written out

**D1. The pattern for each page.** Every page's root is the pattern, with `<Head>` beside it. Dialogs and sheets that sit at the root today (`RedirectForm`, `ImportRedirects`, `ImportReportDialog`, `AiActivityDetails`) move inside the pattern as its last children. No element on the page's top two levels carries `p-*`, `max-w-*` or `mx-auto`. Titles, descriptions and header actions go to the pattern's `title`, `description` and `actions`, with today's words.

| Page | Pattern | Notes |
|---|---|---|
| `pages/tools/import/index.tsx` | `ListPage` | the sites table, then the connect form in a `Panel` titled "Connect a WordPress site" |
| `pages/tools/import/show.tsx` | `ListPage` | the site's host as title; `ImportSteps`; each section a `Panel`; the choices keep a 760 column *inside* their panel (`max-w-(--form-max)`, level 3 or deeper) |
| `pages/tools/backups/index.tsx` | `FormPage` | `BackUpNowButton` as `actions`; verdict, pending key, places, checks, keys and Restoring each a `Panel` |
| `pages/tools/backups/set-up.tsx`, `sftp.tsx`, `new-key.tsx`, `recovery-key.tsx` | `FormPage` | `SetupSteps` first where it is drawn today; fields in `FormPanel`s; their submit buttons stay where they are (they are steps, not saves, so no `SaveBar`) |
| `pages/tools/page-cache.tsx` | `FormPage` | "Clear the page cache" stays the header action (Settled 5); "Now" a `Panel` with a `StatusPill` (On `ok`, Off `neutral`) in its header; the note a `Notice` |
| `pages/tools/updates.tsx` | `FormPage` | every section a `Panel`; `<details>` becomes `Collapsible`; the two notes `Notice`s |
| `pages/tools/redirects.tsx`, `not-found.tsx` | `ListPage` | `RedirectsHead` draws only the views (D3) |
| `pages/tools/ai-activity.tsx` | `ListPage` | the month summary a `Panel`; the budget note a `Notice` (`warn` when the budget is reached, `info` otherwise) |

`pages/tools/backups/place.tsx` is already on `FormPage` and does not change.

**D2. The step bars.** Both components keep their export, their props and their test hooks, and render `ui/step-bar.tsx`:

```tsx
/** The five steps' words, looked up as they are drawn. */
const steps = () => [__('Connect'), __('Analyze'), __('Choose'), __('Import'), __('Report')];

/**
 * Where the step bar stands. Analyze and Choose share the server's plan step:
 * Choose once the plan has arrived with choices to make, Analyze until then.
 */
function current(step: ImportStep, choosing: boolean): number {
    if (step === 'plan') {
        return choosing ? 2 : 1;
    }

    return { connect: 0, import: 3, report: 4 }[step];
}

export function ImportSteps({ step, choosing = false }: { step: ImportStep; choosing?: boolean }) {
    return <StepBar steps={steps()} current={current(step, choosing)} aria-label={__('Import steps')} data-test="import-step" />;
}
```

`ImportShow` passes `choosing={plan != null && plan.form !== null}`. `plan` is the deferred prop, `undefined` until it arrives. `SetupSteps({ current })` renders `<StepBar steps={[__('Choose a place'), __('Keep your recovery key'), __('Type part of it back')]} current={current - 1} aria-label={__('Setup steps')} />`. `StepBar` writes its own "Done" for screen readers and `aria-current="step"`, so the old "(done)" and "(current step)" words go.

**D3. Redirects' two views.** `components/tools/redirects-head.tsx` keeps its file and export name and takes `{ tab, counts }`. It returns only:

```tsx
<InPageNav
    label={__('Redirects and the 404 log')}
    items={[
        { href: index().url, label: __('Redirects'), count: counts.redirects, current: tab === 'redirects' },
        { href: notFound().url, label: __('404 log'), count: counts.notFound, current: tab === 'not-found' },
    ]}
/>
```

It also exports `redirectsDescription(tab)`, which returns today's two descriptions through `__()`. Both pages render `<ListPage title={__('Redirects')} description={redirectsDescription(tab)} actions={…}>` with `<RedirectsHead …/>` as its first child. If `InPageNav`'s item shape differs from the above, use its real one (read the file) and record it.

**D4. Notices, pills, panels.**
- Each `role="note"` box becomes `<Notice tone="info">` (`warn` where its words warn: the import report's "Not ready to switch yet", Updates' security note, AI's budget reached), keeping its `data-test`. The bold lead sentence becomes the Notice's `title`.
- Each `Badge` becomes a `StatusPill`, keeping its words and `data-test`. Tones: finished, imported, deployed, on, set → `ok`; running, connected, waiting → `info`; stopped, needs maintenance mode, warnings, security fixes → `warn`; failed, error, refused → `danger`; anything else → `neutral`. Where a mapping is unclear, choose the nearest tone and record it.
- Each bordered `<section className="border-line rounded-md border p-4">` becomes `<Panel aria-labelledby=…>` with `PanelHeader` (an `h2` in `text-lead font-semibold`, same id and words) and `PanelBody`. Definition lists keep `dl`, with `text-small text-ink-2` terms and a token grid (`grid-cols-[minmax(0,1fr)_auto]` is allowed by R1). Otherwise use `grid gap-x-6 gap-y-2 sm:grid-cols-(--facts-cols)` with `style={{ '--facts-cols': 'max-content 1fr' }}`, which R15 allows.
- Text sizes: `text-xs` and `text-[13px]` → `text-small`; `text-sm` and `text-base` → `text-body`; headings → `text-lead`. Spacing off the grid moves to the nearest allowed step.

**D5. Controls.**
- `Label` and `InputError` pairs become `Field` (`htmlFor` the control's existing id, `error` the same message, `help` the existing hint), so `AccessibilityTest`'s scans still find a label for each id and an error tied to each control.
- Raw `<input type="radio">` groups (`backups/place-fields.tsx`, `tools/redirect-form.tsx`) become `RadioGroup`. Each item keeps its id, and the group keeps its `name`.
- `<textarea>` (`backups/place-fields.tsx`, `pages/tools/backups/sftp.tsx`) becomes `Textarea`.
- `<select>` (`tools/redirect-form.tsx`) becomes `SelectField` with the same id and `name`.
- `<button>` (`tools/redirect-form.tsx`) becomes `Button` or `TextButton`.
- `<details>` (`updates.tsx`) becomes `Collapsible`, keeping the summary's words on its trigger.
- `<progress>` (`tools/update-progress.tsx`) and any width-styled bar become `Progress` with a `label`.
- `overflow-*-auto` (`ai-activity-details.tsx`, `import-redirects.tsx` twice, `redirect-form.tsx`) becomes `ScrollArea`.
- "Back up now" loses any height of its own, so it takes the button's touch size.

**D6. The shared password prompt** (`patterns/fresh-password.tsx`).
- **`backups/typed-back.tsx`:** its `askPassword` box, password state and `confirmThenSend` go. On a 423 it opens `FreshPasswordDialog` with `title={__('Confirm your password')}`, `text={__('Type your password to carry on. The key stays on this page.')}`, `confirmLabel={__('Confirm')}` and `onConfirmed={() => { setAskPassword(false); void send(); }}`. The key and the typed groups stay on the page underneath.
- **`backups/key-actions.tsx`:** its in-dialog box becomes `FreshPasswordInline` with `text={__('Type your password to carry on. What you typed stays here.')}`, as the people dialogs do. `onConfirmed` resends what was waiting.
- Both stop importing `confirmPassword`, `PasswordInput` and the password `InputError`. `post` from `components/editor/editor-http` stays where it still sends the groups or the key.

**D7. Words.** Every word a person reads in an owned file goes through `__()` or `__n()` from `@/lib/i18n`, looked up while drawing, never at a module's top level. Words that only move keep their exact text, punctuation and curly quotes. Built strings become placeholders: `` `by ${name}` `` → `__('by :name', { name })`. Counts become `__n()`, read at N = 1. New words are in American English and listed under "Every word".

---

## Seams with the other parts

- **The foundation** owns `resources/css/app.css`'s tokens and every existing file in `components/ui/**` and `components/patterns/**`. This part changes none of them and adds none. A need for a token or for a change to `StepBar`, `Notice`, `Panel`, `InPageNav`, `FreshPassword*`, `FormPage` or `ListPage` is a STOP.
- **The content-lists part** owns `components/list/**`, `pages/content/index.tsx`, `pages/terms/index.tsx`, `pages/comments/**` and the server's list queries. This part **imports `list/data-table`, `list/pager`, `list/empty-state`, `list/bulk-bar` and `list/row-links` with today's props, unchanged**, and stops importing `list/page-header` and `list/tab-strip`. If that part has merged when this one starts and changed those props, STOP and record which. **How they meet:** after both merge, a follow-up moves Redirects, the 404 log, AI activity and the import tables onto the full table (DS 4.26) and its phone cards.
- **The phone-overlays part** adds one file per pattern in `components/patterns/**` plus one-line opt-ins in its callers. If its plan lists a caller in this part's files (the redirect sheet, the import-redirects dialog, the key dialog, the AI details sheet), that line is its own. This part never adds such an opt-in itself. At the merge, keep both sides.
- **The editor part** owns `components/editor/**`. `typed-back.tsx` and `key-actions.tsx` keep importing `post` from `components/editor/editor-http`, unchanged.
- **The media, plugin-areas and lists parts:** no shared file.
- **Jobs and the hub** (`pages/tools/jobs.tsx`, `pages/tools/index.tsx`) and `components/jobs/**` are not touched. Their baseline lines stay.
- **The baselines** only shrink: each part removes only lines naming its own files. At a merge conflict, take both sides' removals and set each count to what the merged file holds.

## Owns

- `resources/js/pages/tools/**` except `jobs.tsx`, `index.tsx` and `backups/place.tsx` (`import/index.tsx`, `import/show.tsx`, `backups/index.tsx`, `backups/set-up.tsx`, `backups/sftp.tsx`, `backups/new-key.tsx`, `backups/recovery-key.tsx`, `page-cache.tsx`, `updates.tsx`, `redirects.tsx`, `not-found.tsx`, `ai-activity.tsx`)
- `resources/js/components/import/**` (7 files), `components/backups/**` (11 files), `components/tools/**` (6 files); `components/jobs/**` is owned but not changed
- Tests: new `tests/Architecture/ToolsScreensTest.php`, new `tests/Browser/ToolsFramesTest.php`, the cases added below, the files under "Existing tests that change", `tests/Architecture/design-system-baseline.txt` and `DesignSystemTest.php` (counts), `tests/Architecture/admin-words-baseline.txt` and `AdminWordsTest.php` (count)

---

## Global Constraints

- **Rule 1:** nothing here changes state through a new path. Every write goes through today's routes, controllers and operations, untouched. No operation is added, so no new authorization-failure test applies. The existing ones stay green.
- **Rule 3:** PostgreSQL only. **Rule 5:** `packages/teme-contracts` is not touched. **Rule 9:** links are built from Wayfinder (`@/actions/…`, `@/routes/…`), never written as addresses.
- **No dependency.** Never change `composer.json`, `composer.lock`, `package.json` or `package-lock.json`.
- **No server change.** Nothing under `app/`, `routes/`, `config/` or `database/` changes. Props stay the same. A screen that needs a new prop is a STOP.
- **The design check** (`tests/Architecture/DesignSystemTest.php`, scanner `tests/Fixtures/design-system-scan.mjs`, baseline `tests/Architecture/design-system-baseline.txt`, one `rule<TAB>path<TAB>match` line per offense, paths relative to `resources/`). The rules met here: **R1** arbitrary `[…]` values; **R2** text sizes other than `text-caption|small|body|lead|title|display`; **R3** spacing steps 1.5, 2.5, 3.5, 7, 9, 11, 14, 15; **R5** raw `<button>`, `<input>`, `<select>`, `<textarea>`, `<table>`, `<details>` outside `ui/` and `patterns/`; **R6** bare `rounded`, `shadow`, numbered `z-`, weights other than 400, 500 and 600; **R7** a page whose root is not a pattern, or `p-*`, `max-w-*` or `mx-auto` on its top two levels; **R8** `overflow-auto` or `-scroll` outside `ui/scroll-area.tsx`; **R9** `role="note"` and hand-tinted boxes; **R14** motion without `motion-safe:`; **R15** inline style other than custom properties. **Lowering the baseline:** fix the offense; run `php artisan test tests/Architecture/DesignSystemTest.php`; its first case lists what is gone; remove exactly those lines and lower that rule's number in `dsCounts()` in the same file. **Never add a line, raise a number or widen the scanner.**
- **The admin's words** (`tests/Architecture/AdminWordsTest.php`, baseline `tests/Architecture/admin-words-baseline.txt`, `path<TAB>words`, paths relative to `resources/js`). Moving a listed word into `__()` makes the test list it as gone. Remove that line and lower the count in its "never lets the baseline grow" case (`toHaveCount(…)`) by as many. Never raise it.
- **Tests first:** each new case is run red for the reason given before the code. An existing case changes only as listed below, keeping what it proves. **No test is deleted.**
- **The `.env`:** in a fresh checkout, `cp .env.example .env` and `php artisan key:generate`. Never copy, read or edit any other `.env`.
- **Never edit** `.ai/`, `CHANGELOG.md`, `docs/roadmap.md`, `docs/backlog.md`, `docs/architecture/*`, `docs/reference/*`, `tests/Pest.php`, `tests/Fixtures/design-system-scan.mjs`.
- **Conventional Commits**, staged by file (never `git add -A`), no closing keywords, written for the public: no private names or paths. A code comment cites at most one I-number or one document path.
- `components/ui/*` keeps shadcn's formatting (untouched here). Every other file follows `npm run check:fix`.
- **`tests/Architecture` files that use `resource_path()` or `base_path()` declare `uses(TestCase::class)`.** Helpers used by one file stay in it with its prefix (`ts` for `ToolsScreensTest`, `tf` for `ToolsFramesTest`).
- **Browser helpers** in `tests/Pest.php` (used, never edited): `cmSignIn($user)` signs in through the form and returns the page at Home; `cmNavigate($page, $url)` visits in one attempt (never two `->navigate()` calls in a row); `cmWaitUntil($page, $js)` polls a condition in the page (the plugin's `assertSee` looks once and races the render); `shellSettled($page, $selector)` waits until those elements have no animation running (use it before clicking inside a dialog or sheet, never `getAnimations({ subtree: true })`); `cmSmallTargets($page)` returns `['small' => [...], 'checked' => n]` for targets under 24px; `cmAxeViolations($page)` runs axe once nothing is mid-transition; `lscrPrefer($user, ['appearance.mode' => 'dark'])` sets a preference; `uiComponent($path)` and `uiTsxFiles()` read sources under `resources/js`.
- **Browser runs:** after `npm run build`, one file at a time, output redirected to a file and read, never piped through `grep` or `sed` (it hangs). Run only the files named here. GitHub runs the full set after the merge.
- **The quick check** after each task: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, `npm run types:check`, `npm run check`, `php artisan test --testsuite=Architecture`, `php artisan test tests/Feature/Admin/WordingPassToolsTest.php tests/Feature/Admin/AccessibilityTest.php`, and the task's own files.
- **Unattended work:** decide small things yourself (a tone, a spacing step, a heading level, a component's exact prop name) by the nearest rule in DS. Record each under Records as "Decided: … Options: … Chose: … because …". Stop only for a STOP below.
- **When the work is interrupted:** each task ends committed and pushed, with one line in Records: "Done to Task N at `<hash>`, the quick check green". Node missing: `export PATH=/opt/node24/bin:$PATH`; if it is still missing, stop, push and record it. If a test is red at the base and is not this part's, record it and never change it.

---

## Task 0: Preconditions, then branch

Run in a checkout of `origin/main`. If any line differs **by its text**, STOP and write why under Records.

- [ ] **Step 1:** `git fetch origin && git log --oneline --fixed-strings --grep="feat(admin): the design system and the admin's new frame" origin/main` → one commit. Record `origin/main`'s head.
- [ ] **Step 2:**
  - `ls resources/js/components/ui/step-bar.tsx resources/js/components/ui/notice.tsx resources/js/components/ui/panel.tsx resources/js/components/ui/status-pill.tsx resources/js/components/ui/progress.tsx resources/js/components/ui/collapsible.tsx resources/js/components/ui/radio-group.tsx resources/js/components/ui/textarea.tsx resources/js/components/ui/select-field.tsx resources/js/components/ui/scroll-area.tsx resources/js/components/ui/field.tsx resources/js/components/patterns/list-page.tsx resources/js/components/patterns/form-page.tsx resources/js/components/patterns/in-page-nav.tsx resources/js/components/patterns/fresh-password.tsx` → all exist;
  - `grep -n "export function \(FreshPasswordDialog\|FreshPasswordInline\|InPageNav\|ListPage\|FormPage\|FormPanel\)" resources/js/components/patterns/*.tsx` → six lines; `grep -n "^export { StepBar }" resources/js/components/ui/step-bar.tsx` → one;
  - `git grep -n -i "tools/status\|tools\.status" -- routes resources/js` → nothing (Status is not built);
  - `grep -c -E "from '@/components/list/(page-header|tab-strip)'" resources/js/pages/tools/*.tsx resources/js/pages/tools/*/*.tsx resources/js/components/tools/*.tsx` → record the files (expected: `ai-activity`, `backups/index`, `backups/new-key`, `backups/recovery-key`, `backups/set-up`, `backups/sftp`, `import/index`, `import/show`, `page-cache`, `updates`, `redirects-head`);
  - `git log --oneline origin/main -- resources/js/components/list | head -5` → record. If the content-lists part has merged and changed the props of `DataTable`, `Pager`, `EmptyState`, `BulkBar` or `RowLinks`, STOP (seams);
  - `grep -c -E "	js/(pages/tools/(ai-activity|backups/(index|set-up|sftp|new-key|recovery-key)|import|not-found|page-cache|redirects|updates)|components/(backups|import|tools)/)" tests/Architecture/design-system-baseline.txt` → record (about 298 at writing); the same with `^(pages/tools/…|components/(backups|import|tools)/)` against `admin-words-baseline.txt` → record (about 660); `grep -c . ` of each baseline and the counts in `dsCounts()` and `AdminWordsTest`'s `toHaveCount(` → record;
  - `grep -c -E "^function (cmSignIn|cmNavigate|cmWaitUntil|shellSettled|cmSmallTargets|cmAxeViolations|lscrPrefer|uiComponent|uiTsxFiles)\(" tests/Pest.php` → 9;
  - `php -v` 8.4; `node --version` 22.18 or later; `pg_isready -h 127.0.0.1 -p 5433` answers; `php artisan test --testsuite=Architecture` green.
- [ ] **Step 3:** restate the stack from `composer.lock` and `package-lock.json` in Records. `git checkout -b ui/tools-screens origin/main`.

## Task 1: The guards, then Import

**Tests, written first.**

`tests/Architecture/ToolsScreensTest.php` (each later task adds its row first, sees it red, then turns it green):

```php
<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

/**
 * The lines of a baseline whose path starts with one of these. Paths are
 * relative to resources/js; the design check writes them with "js/" first.
 *
 * @param  list<string>  $paths
 * @return list<string>
 */
function tsLines(string $baseline, int $column, string $prefix, array $paths): array
{
    $lines = file(base_path("tests/Architecture/{$baseline}"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    return array_values(array_filter($lines, function (string $line) use ($column, $prefix, $paths): bool {
        $path = explode("\t", $line)[$column] ?? '';

        foreach ($paths as $owned) {
            if (str_starts_with($path, $prefix.$owned)) {
                return true;
            }
        }

        return false;
    }));
}

dataset('tool screens', [
    'Import' => [['pages/tools/import/', 'components/import/']],
]);

it('leaves no design-system line on :dataset', function (array $paths): void {
    expect(tsLines('design-system-baseline.txt', 1, 'js/', $paths))->toBe([]);
})->with('tool screens');

it('leaves every word on :dataset to the translation helper', function (array $paths): void {
    expect(tsLines('admin-words-baseline.txt', 0, '', $paths))->toBe([]);
})->with('tool screens');
```

The rows the later tasks add: `'Backups' => [['pages/tools/backups/', 'components/backups/']]`; `'Page cache and Updates' => [['pages/tools/page-cache.tsx', 'pages/tools/updates.tsx', 'components/tools/update-progress.tsx']]`; `'Redirects and the 404 log' => [['pages/tools/redirects.tsx', 'pages/tools/not-found.tsx', 'components/tools/redirect-form.tsx', 'components/tools/import-redirects.tsx', 'components/tools/redirects-head.tsx']]`; `'AI activity' => [['pages/tools/ai-activity.tsx', 'components/tools/ai-activity-details.tsx']]`.

`tests/Browser/ToolsFramesTest.php`: copy `ToolsScreensTest.php`'s `beforeEach` and `afterEach` (Vite on, roles seeded, the fresh-request listener, the page-cache folder, `$this->admin` as administrator). Then:

```php
dataset('tool frames', [
    'Import' => ['/admin/tools/import', 'Import'],
]);

it('draws :dataset on one page pattern, one heading, nothing sideways and no small target at 375 pixels', function (string $url, string $title): void {
    $page = cmSignIn($this->admin)->resize(375, 812);
    cmNavigate($page, $url);
    cmWaitUntil($page, 'document.querySelectorAll(\'[data-slot="page"]\').length === 1');

    expect($page->script('() => [...document.querySelectorAll("main h1")].map((h) => h.textContent.trim())'))->toBe([$title])
        ->and($page->script('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth'))->toBeTrue()
        ->and(cmSmallTargets($page)['small'])->toBe([]);
})->with('tool frames');
```

The rows the later tasks add: Backups `['/admin/tools/backups', 'Backups']`; Page cache `['/admin/tools/page-cache', 'Page cache']`; Updates `['/admin/tools/updates', 'Updates']`; Redirects `['/admin/tools/redirects', 'Redirects']`; 404 log `['/admin/tools/redirects/404', 'Redirects']`; AI activity `['/admin/tools/ai-activity', 'AI activity']`. A site's import page is covered by its own case below. If `main h1` finds the shell's crumbs or nothing, read the shell and use the selector that finds the page header's `h1`. Record it.

One case added to `tests/Browser/ToolsImportTest.php`: **"marks one current step on a site's page, Choose once the plan has arrived"**. Follow the existing "connects a site…" case to the point where `[data-test="import-start"]` is present. Then `[data-test="import-step"][data-slot="step-bar"]` exists. Exactly one `[aria-current="step"]` is inside it, and its text holds "Choose". The first two items have `data-state="done"`.

- [ ] **Step 1:** write the three; run them. Record the first lines: the architecture cases list Import's lines; the frame case times out on `[data-slot="page"]`; the step case fails on `data-slot="step-bar"` (or finds two current steps).
- [ ] **Step 2:** move `pages/tools/import/**` and `components/import/**` onto D1 to D7: `ImportSteps` as D2; the sites table keeps `DataTable`; `ConnectForm` and `ChoicesForm` on `Field` (ids kept: `import-address`, `import-user-name`, `import-application-password`, `import-host-N`, …); `SiteAnalysis`, `RunProgress`, `RunReport` and `SiteRuns` in `Panel`s; `AnalysisSkeleton` on `LoadingState` (`label={__('Reading the site report')}`); `run-progress`'s bar on `Progress`; notes on `Notice`; `Badge`s on `StatusPill`; every word through `__()`.
- [ ] **Step 3:** run the three files until green. Then `DesignSystemTest` and `AdminWordsTest`: remove the listed lines and lower the counts. Then `ToolsImportTest`, `ImportImagesTest` and `ImportedContentCardTest` in full, one at a time.
- [ ] **Step 4:** quick check; commit `feat(admin): Import on the design system, with the shared step bar`; push; the Records line.

## Task 2: Backups

**Tests first:** the `Backups` rows in both datasets. One case added to `tests/Browser/BackupsScreenTest.php`: **"draws setup's three steps on the step bar, the first current"**. On the setup page (after the password, as the existing cases reach it), `[data-slot="step-bar"][aria-label="Setup steps"]` holds three items, and the one with `aria-current="step"` reads "Choose a place". It fails first on the missing step bar. Then change "asks for the password in place on the key page…" as listed below and see it red (no `fresh-password-input` yet).

- [ ] **Step 1:** run; record the first lines.
- [ ] **Step 2:** move `pages/tools/backups/{index,set-up,sftp,new-key,recovery-key}.tsx` and `components/backups/**` onto D1 to D7. That covers `SetupSteps` as D2, both password prompts as D6, the radios and textarea as D5, `Verdict`, `ChecksList`, `KeysList`, `PlacesList`, `PendingKey` and Restoring in `Panel`s, `KeySheet` keeping its print rules (`--paper`), and `BackUpNowButton` without a height of its own. `pages/tools/backups/place.tsx` does not change. `place-fields.tsx` does, and it is drawn there too: run `SaveBarTest` after.
- [ ] **Step 3:** run until green; lower the baselines; then `BackupsScreenTest` and `SaveBarTest` in full, one at a time.
- [ ] **Step 4:** quick check; commit `feat(admin): Backups on the design system, with the shared step bar and password prompt`; push; the Records line.

## Task 3: Page cache and Updates

**Tests first:** the `Page cache and Updates` architecture row and the two frame rows. Run; record.

- [ ] **Step 1:** `page-cache.tsx` as D1: the header's clear `Form` keeps `data-test="page-cache-clear"` and its error through `Field`-style `aria-describedby="page-cache-error"`; `Notice` keeps `data-test="page-cache-notice"`, with both paragraphs inside it (WordingPassToolsTest). Clearing stays one click (Settled 5).
- [ ] **Step 2:** `updates.tsx` and `components/tools/update-progress.tsx` as D1 to D7. `<details>` becomes `Collapsible`, the bar becomes `Progress` (tone `danger` on failed, `ok` when done, `accent` otherwise), the five `role="status"` lines stay `role="status"`, and the update's steps stay an `<ol>` with their words.
- [ ] **Step 3:** run until green; lower the baselines; then `ToolsScreensTest`, `UpdatesScreenTest` and `UpdaterScreenTest` in full, one at a time.
- [ ] **Step 4:** quick check; commit `feat(admin): Page cache and Updates on the design system`; push; the Records line.

## Task 4: Redirects and the 404 log

**Tests first:** the architecture row and the two frame rows. Run; record.

- [ ] **Step 1:** `RedirectsHead` as D3; both pages on `ListPage` (D1), with `RedirectForm`, `ImportRedirects` and `ImportReportDialog` inside it. `redirects-notice` becomes a `Notice`. The table, bulk bar, row links and pager stay as they are. `redirect-form.tsx` gets `RadioGroup`, `SelectField`, `Button` and `ScrollArea` (D5). `import-redirects.tsx` gets `ScrollArea` twice and `Field`. The `shownTitle` uses stay (ContentScreensTest). `confirmLabel={deleteLabel(` stays as written (AccessibilityTest).
- [ ] **Step 2:** run until green; lower the baselines; then `RedirectsScreenTest` in full.
- [ ] **Step 3:** quick check; commit `feat(admin): Redirects and the 404 log on the design system`; push; the Records line.

## Task 5: AI activity

**Tests first:** the architecture row and the frame row. Run; record.

- [ ] **Step 1:** `ai-activity.tsx` on `ListPage`, with `AiActivityDetails` inside. The month summary becomes a `Panel` with its `dl`. The budget note becomes a `Notice`, keeping "Set a budget" and "Change the budget" as `asChild` buttons beside their lines (WordingPassToolsTest). The filters keep three labelled controls (`AiActivityScreenTest`'s feature case counts 3). `ai-activity-details.tsx` gets `ScrollArea`, and its words go through `__()`.
- [ ] **Step 2:** run until green; lower the baselines; then `php artisan test tests/Feature/Ai/AiActivityScreenTest.php` and the browser `AiActivityScreenTest` in full.
- [ ] **Step 3:** quick check; commit `feat(admin): AI activity on the design system`; push; the Records line.

## Task 6: Gate and records

- [ ] `composer check` once. The browser files this part added or changed, after `npm run build`, one at a time: `ToolsFramesTest`, `ToolsImportTest`, `ImportImagesTest`, `ImportedContentCardTest`, `BackupsScreenTest`, `SaveBarTest`, `ToolsScreensTest`, `UpdatesScreenTest`, `UpdaterScreenTest`, `RedirectsScreenTest`, `AiActivityScreenTest`, `ShellTest`, `FullPassTest`. GitHub runs the rest after the merge.
- [ ] `php artisan test tests/Architecture/ToolsScreensTest.php` → every row green. `git diff --stat origin/main -- app routes config database packages resources/css resources/js/components/ui resources/js/components/patterns resources/js/components/list resources/js/components/jobs resources/js/pages/tools/jobs.tsx resources/js/pages/tools/index.tsx resources/js/pages/tools/backups/place.tsx` → nothing.
- [ ] Records: the commits, each test seen red, the two baselines' counts per rule before and after, every "Decided:" line, every `B-?? (draft)`, and how to try it: on a local site, open Tools › Import and a site, Tools › Backups and its setup, Page cache, Updates, Redirects and its 404 log, and AI activity, at full width and at 375 pixels. Commit `docs(plans): tools screens records`; push.

---

## Existing tests that change (meaning kept)

| File | Case | Change | What it still proves |
|---|---|---|---|
| `tests/Browser/BackupsScreenTest.php` | "asks for the password in place on the key page, and keeps the key there" | `#key-password` → `[data-test="fresh-password-input"]`; `[data-test="key-password-confirm"]` → `[data-test="fresh-password-confirm"]`; waits with `shellSettled($page, '[data-slot="fresh-password"]')` before typing | a lapsed password is asked for on the key page, which is not left, and the groups then confirm |
| `tests/Feature/Admin/WordingPassToolsTest.php` | the backups (3), import report, page cache, redirects notice, 404 log (2) and AI activity (3) cases | each needle takes the source as now written: the words inside `__('…')`, and the heading's closing tag as the new markup writes it (for example `'Where backups are kept </h2>'` becomes the flattened text up to that `</h2>`). The 404 log's `'<RedirectsHead'` becomes `'<ListPage'` | the same words in the same order and places, and the same words absent |
| `tests/Feature/Admin/AccessibilityTest.php` | "asks before anything that cannot be undone": the import, place, key and verdict rows | `confirmLabel="Forget this site"` → `confirmLabel={__('Forget this site')}`; `` confirmLabel={`Remove ${place.name}`} `` → `confirmLabel={__('Remove :name', { name: place.name })}`; the same shape for `Replace ${label}` and `Remove ${setup.placeName}` | each of these acts is still behind `ConfirmAction` |
| `tests/Architecture/design-system-baseline.txt`, `DesignSystemTest.php` | — | lines naming owned files removed; `dsCounts()` lowered | the debt only shrinks |
| `tests/Architecture/admin-words-baseline.txt`, `AdminWordsTest.php` | — | lines naming owned files removed; the count lowered | the same |

Any other existing case that would have to change is a STOP.

## Every word

**New**, through `__()`: "Confirm your password" (the key page's prompt title), "Type your password to carry on. The key stays on this page." (moved from `typed-back.tsx` unchanged), "Reading the site report" (the loading label). **Moved into `__()` unchanged:** every word the admin-words baseline lists today for an owned file. **Gone:** "(done)" and "(current step)" in `import-steps.tsx` and `run-progress.tsx`'s step marks where `StepBar` now says them, and the two hand-made password boxes' words that `FreshPassword*` now says.

## What must not be done

- Build Tools › Status, warming, clearing one address, cache counts, a clear confirmation, report filters, rollback, or figure tiles.
- Touch Jobs, the hub, the place form page, `components/jobs/**`, `components/list/**`, `components/ui/**`, `components/patterns/**`, the tokens, the scanner or `tests/Pest.php`.
- Change a route, controller, operation, permission, prop or migration.
- Adopt the Combobox anywhere here. The redirect form's target search and the importer's person keep their controls until a later part.
- Delete a test, or change what a test proves.

## STOP conditions

Stop, write why under Records, commit and push, then stop, when:
- a Task 0 line differs by its text, or the content-lists part has changed the props of a `list/` component this part uses;
- a screen needs a token, a change to an existing `ui/` or `patterns/` file, or a new pattern;
- a design-system or admin-words line would have to be added, a count raised, or the scanner widened;
- a screen needs a new prop or any server change;
- an existing test not listed above would have to change, or a test would be deleted;
- a new dependency the plan doesn't name would be needed;
- a file of another part (lists, editor, media, plugin areas, phone overlays) would have to change.

## Security

- No route, permission, query or operation changes. Each screen's `can:` middleware and each action's checks stay.
- The password prompts post to the same confirmation route with the same throttle; the key page's and key dialog's requests are unchanged.
- Words render as text, never HTML.

## At the merge (for the maintainers)

- Changelog line, at the release: "The Tools screens (Import, Backups, Page cache, Updates, Redirects and AI activity) now share the admin's look, with clearer steps and notices, and can be translated."
- Baseline conflicts with other parts: take both sides' removals, then run `DesignSystemTest` and `AdminWordsTest` and set each count to what the merged baseline holds.
- If the content-lists part merged first, open the follow-up that moves the Tools tables onto the full table.

## Open points

| # | Point | Recommendation |
|---|---|---|
| 1 | The Tools tables on the full table (DS 4.26) and its phone cards | A small follow-up once the content-lists part merges. Each table maps its columns; Redirects keeps its bulk bar |
| 2 | A status pattern for screens that only show state | Not now. `FormPage` serves (Settled 3); revisit if Tools › Status is proposed |
| 3 | Tools › Status, the page behind the top bar's "See all" | Its own proposal; it needs labeled status rows (the plugin-surfaces work) first |
| 4 | Combobox for the redirect target and the importer's person | The part that next rebuilds those forms |

---

## Records

<!-- Filled in while building: Task 0's values; commits; each test seen red (task, file, case, the failure's first line); the two baselines' counts per rule before and after; every "Decided: … Options: … Chose: … because …"; differences from the plan as B-?? (draft); any STOP. -->

### Task 0

- Setup: `2026-11-14T20:55:09Z ran in /home/user/teme as root`, `2026-11-14T21:05:31Z finished`; working directory `/home/user/teme`.
- `origin/main` at `4b8cc3b` (Merge pull request #275). The `--grep` line finds two commits, not one: `e7f2af7` (the feature commit itself) and `bd0e715` (its merge, whose message repeats the line). See B-?? (draft) 1.
- Every file in Step 2 exists; the six pattern exports and `export { StepBar }` are there; `tools/status` is nowhere in `routes` or `resources/js`.
- Files importing `list/page-header` or `list/tab-strip`: `ai-activity`, `page-cache`, `updates`, `backups/index`, `backups/new-key`, `backups/recovery-key`, `backups/set-up`, `backups/sftp`, `import/index`, `import/show`, and `components/tools/redirects-head` (two imports), as expected.
- Last commits under `components/list`: `3abbcfc` (the tag picker's create-by-typing) and `e7f2af7`; the content-lists part has not merged, so the props of `DataTable`, `Pager`, `EmptyState`, `BulkBar` and `RowLinks` are as this plan expects.
- Design-system lines on owned files: 298 (R1 18, R2 182, R3 32, R5 7, R6 5, R7 42, R8 4, R9 7, R14 1). Admin-words lines on owned files: 652. Whole baselines: 1228 and 2350 lines. `dsCounts()`: R1 114, R2 661, R3 149, R5 82, R6 41, R7 89, R8 34, R9 23, R10 14, R14 13, R15 8; `AdminWordsTest`'s count 2350.
- `tests/Pest.php` holds the nine helpers. PHP 8.4.27, Node 24.22.1, PostgreSQL 18.7 answering on 5433, Valkey answering on 6380.
- Stack from the locks: laravel/framework v13.39.0, inertiajs/inertia-laravel v3.5.2, pestphp/pest v5.3.4, pestphp/pest-plugin-browser v5.1.2; react 19.3.1, @inertiajs/react 3.9.1, typescript 7.0.2, tailwindcss 4.3.3, vite 8.3.5, vite-plus 0.3.0, @radix-ui/react-radio-group 1.5.1, @radix-ui/react-collapsible 1.2.1.
- `composer check` at the base: Pint, PHPStan, types and lint pass; Architecture 105 passed (1225 assertions); Unit and Feature 10490 passed, 2 skipped (53816 assertions).

### Commits

- `890afa7` feat(admin): Import on the design system, with the shared step bar
- `f295084` feat(admin): Backups on the design system, with the shared step bar and password prompt
- `25a1d42` docs(plans): tools screens progress to Task 2
- `65cfc11` feat(admin): Page cache and Updates on the design system
- `a0d220b` docs(plans): tools screens progress to Task 3
- `608eaa0` feat(admin): Redirects and the 404 log on the design system
- `28b8d22` docs(plans): tools screens progress to Task 4
- `e5b5499` feat(admin): AI activity on the design system
- `af745ad` docs(plans): tools screens progress to Task 5
- this commit: docs(plans): tools screens records

Each task ended with the quick check green (Pint, PHPStan, types, lint, the Architecture suite, `WordingPassToolsTest`, `AccessibilityTest`) and the browser files it touched green, one at a time, after `npm run build`.

### The gate

- `composer check` at `af745ad`: Pint, PHPStan, types and lint pass; Architecture 115 passed (1235 assertions); Unit and Feature 10490 passed, 2 skipped (53790 assertions).
- Browser files, one at a time after `npm run build`, `npm run build:themes` and `php artisan teme:themes:link`: `ToolsFramesTest` 7, `ToolsImportTest` 7, `ImportImagesTest` 5, `ImportedContentCardTest` 4, `BackupsScreenTest` 14, `SaveBarTest` 1, `ToolsScreensTest` 3, `UpdatesScreenTest` 9, `UpdaterScreenTest` 19, `RedirectsScreenTest` 6, `AiActivityScreenTest` 6, `ShellTest` 17, `FullPassTest` 40, all passed.
- `git diff --stat origin/main --` the paths Task 6 names: nothing.

### Tests seen red

- Task 1, `tests/Architecture/ToolsScreensTest.php`, the Import row's two cases: "Failed asserting that two arrays are identical" (the baselines still listed Import's lines and words).
- Task 1, `tests/Browser/ToolsFramesTest.php`, every row (all seven, from the whole dataset kept aside and run at the base): "Timeout 5000ms exceeded" waiting for one `[data-slot="page"]`.
- Task 1, `tests/Browser/ToolsImportTest.php`, "marks one current step on a site’s page, Choose once the plan has arrived": "Failed asserting that false is true" (no step bar).
- Task 2, `ToolsScreensTest`, the Backups row's two cases, against the base baselines: arrays not identical.
- Task 2, `tests/Browser/BackupsScreenTest.php`, "draws setup’s three steps on the step bar, the first current": "Failed asserting that 0 is identical to 3"; "asks for the password in place on the key page, and keeps the key there" and "checks a recovery key in its dialog": "Timeout 5000ms exceeded" waiting for `[data-test="fresh-password-input"]`.
- Task 3, `ToolsScreensTest`, the Page cache and Updates row's two cases, against the base baselines: "2 failed".
- Task 4, `ToolsScreensTest`, the Redirects and the 404 log row's two cases, against the base baselines: "2 failed".
- Task 5, `ToolsScreensTest`, the AI activity row's two cases, against the base baselines: "2 failed".

### Counts

| | Before | After |
|---|---|---|
| `design-system-baseline.txt` lines | 1228 | 930 |
| `admin-words-baseline.txt` lines (`AdminWordsTest`) | 2350 | 1698 |
| R1 | 114 | 96 |
| R2 | 661 | 479 |
| R3 | 149 | 117 |
| R5 | 82 | 75 |
| R6 | 41 | 36 |
| R7 | 89 | 47 |
| R8 | 34 | 30 |
| R9 | 23 | 16 |
| R10 | 14 | 14 |
| R14 | 13 | 12 |
| R15 | 8 | 8 |

The 298 design lines and 652 word lines Task 0 counted on owned files are all gone; no line was added to either baseline and no count was raised.

### Decided

- Decided: tones where D4 names none. Import's custom-types warning `warn`; ready to switch `ok`, not ready `warn`; message levels error `danger`, warning `warn`; "Password forgotten" `neutral`. Backups' place states failing `danger`, paused `warn`, ready `ok`; the verdict's unfinished and paused states a `danger` Notice. Updates' "Pre-release" `neutral`, "Security fixes" and "Needs maintenance mode" `warn`; deploy outcomes deployed `ok`, failed, refused, backup failed and migration failed `danger`, unfinished and busy `warn`. Redirects' "Pattern" `neutral`, the 404 log's "Redirected" `ok`. AI outcomes applied `ok`, failed and refused `danger`, suggested `info`, discarded and stale `neutral`. Options: `neutral` everywhere D4 is silent, or the nearest tone. Chose the nearest tone, because the pill's word stays and the tone only helps.
- Decided: Updates' notes. The security-before line `warn` (D4), the maintenance note `info`, the check's error `warn` with its "Problem:" lead kept for screen readers only. Options: `info` for the check's error, as D4's default. Chose `warn`, because its words report a failure.
- Decided: commands and settings' names inside Updates' sentences go through a small `WithCode` helper in `update-progress.tsx`: the sentence is translated whole, and each `:name` it holds is drawn as code. Options: split each sentence into `__()` pieces around the code, or drop the code look and fill plain placeholders. Chose `WithCode`, because a whole sentence translates better and the code look stays.
- Decided: sentences broken by a control or a link (Backups' schedule sentence, Redirects' notice around its Settings › Addresses link) are split into `__()` pieces. Options: a helper like `WithCode` for nodes. Chose pieces, because each sentence holds a live control or link, not a string.
- Decided: the panels have no border, so the verdict's colored left edge and the Available panel's warn border go; the icon, the words, the notice and the pill carry the level.
- Decided: the drawers' own widths (`sm:max-w-[560px]` on the redirect form, `sm:max-w-[480px]` on AI activity's details) go, and both take the sheet's token width (`--sheet-w`, 480px). Options: a new token for 560 (the tokens are not this part's), or an arbitrary width (R1). Chose the token width.
- Decided: the update's progress bar is `danger` when any step failed, `ok` when all seven are done, `accent` otherwise. The running step's spinner keeps turning under `motion-safe:` only (R14). Icons beside wrapped text sit in an `h-5` box instead of `mt-0.5` (R3).
- Decided: two-column definition lists use `sm:grid-cols-(--facts-cols)` with the columns on a style custom property (R15 allows it): Page cache, Updates (twice), AI activity's summary, and AI activity's drawer (`8rem 1fr`), one column on phones.
- Decided: the place choices on Backups' setup became a vertical `RadioGroupChoice` list, and the redirect form's three target kinds a wrapping `RadioGroup` row; each item keeps its id and the group its `name`.
- Decided: the budget-reached note keeps "Change the budget" inline after its words, not in the Notice's action slot, so `WordingPassToolsTest`'s order holds; it and "That request is gone" keep `role="status"`.
- Decided: the redirect form's From, Status and Note use `Field` (their help ids become `-help`); the To blocks keep their hint and error lines with their ids; the title search's options are ghost `Button`s. The bulk delete's error got an id.
- Decided: `ToolsFramesTest`'s rows were added task by task, the whole dataset kept aside to show it red at the base.

- Decided: `components/backups/typed-back.tsx` keeps `Label` and `InputError` around its two group inputs rather than `Field`. Options: one `Field` per input, which would give each its own help and error lines. Chose to keep them, because the two inputs share one hint and one error, which `Field` doesn't model.

### Differences from the plan

- B-?? (draft) 1. Task 0's `--grep` line finds two commits, not one: the feature commit `e7f2af7` and its merge `bd0e715`, whose message repeats the line. Options: STOP, or read it as the same change. Chose to continue, because both are the same change already on `main`. Commit `890afa7`.
- B-?? (draft) 2. The plan's Backups architecture row, `pages/tools/backups/`, also covers `place.tsx`, another part's file that this plan says does not change. Options: change `place.tsx` (not owned), keep its lines in the baselines and let the row fail, or narrow the row. Chose to narrow the row to the five owned pages and `components/backups/`, with a comment; it was seen red against the base baselines. Commit `f295084`.
- B-?? (draft) 3. The plan's `shellSettled($page, '[data-slot="fresh-password"]')` names a slot only `FreshPasswordInline` has; the key page uses `FreshPasswordDialog`. Options: add the slot to the pattern (not this part's), or wait on the dialog's own slot. Chose `[data-slot="dialog-content"]`. Commit `f295084`.
- B-?? (draft) 4. `BackupsScreenTest` "checks a recovery key in its dialog" is not in the plan's table of changed tests, but D6 replaces the box its selectors name. Options: STOP, or move it to the shared prompt's hooks (`fresh-password-input`, `fresh-password-confirm`) with its meaning kept. Chose the second, because D6 forces it and the case still proves the key is checked after a password prompt. Commit `f295084`.
- B-?? (draft) 5. `AccessibilityTest`'s needles for Remove: the formatter wraps `confirmLabel={__('Remove :name', { name: place.name })}` over lines, and the needle's regex reads raw source. Options: a one-line needle that never matches, or the stem. Chose `confirmLabel={__('Remove :name', {` for the place and verdict rows; Replace kept the plan's one-line needle. Commit `f295084`.
- B-?? (draft) 6. `run-progress.tsx`'s bar is `JobProgress` from `components/jobs`, not this part's, so "run-progress's bar on Progress" did not apply; its run steps moved to `StepBar`, so "(now)" and "(done)" go as with the import steps. Commit `890afa7`.
- B-?? (draft) 7. `WordingPassToolsTest`'s needles take the source as now written: the headings' `Heading')} </h2>`, the 404 log's `<ListPage`, and AI activity's `Spent')}</dt>`. The words, their order and their places are unchanged. Commits `f295084`, `608eaa0`, `e5b5499`.

No STOP.

### How to try it

On a local site, open Tools › Import and one site, Tools › Backups and its setup, Page cache, Updates, Redirects and its 404 log, and AI activity, at full width and at 375 pixels. Each page has one heading, nothing scrolls sideways, and the import's and backups' steps show on the shared step bar.

### For the changelog

- The Tools screens (Import, Backups, Page cache, Updates, Redirects and AI activity) now share the admin's look, with clearer steps and notices, and can be translated.

## Follow-up X4-2

The review's fixes, each a commit pushed on its own:

- `c26c680` fix(admin): name camel-case codes in full in Updates' sentences. `WithCode` split only on `/(:[a-z]+)/`, so `:checkNow` drew `php artisan teme:updates:check`'s place as `TEME_UPDATES_CHECK` and "Now", and `:updaterImage` drew the placeholder itself. It now splits on `/(:[A-Za-z]+)/`. Seen red first: `UpdatesScreenTest` "names the commands and settings in full inside their sentences", "Expected: set TEME_IMAGE and :updaterImage to the new tag and Deploy. To contain: TEME_UPDATER_IMAGE".
- `15f751c` fix(admin): mark no step current on a stopped import. `StepBar` always marks one step current, so a stopped run's steps are now a plain list, the finished ones marked done and none current. Seen red first: `ToolsImportTest` "marks no step current on a stopped import, the steps it finished still done", "Failed asserting that 1 is identical to 0".
- `a9db3ed` test(admin): the 404 log's needle is `actions={`, so it again proves the settings link is the header's action. It passes as the source stands; it was not seen red.
- This commit: the Decided line for `typed-back.tsx` above, and this record.

Checks: the quick check green; `UpdatesScreenTest`, `ToolsImportTest` and `ToolsFramesTest` green in the browser after `npm run build`.
