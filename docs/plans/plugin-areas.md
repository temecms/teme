# Interface part: the plugin areas

**One part, one branch: `ui/plugin-areas`, from `origin/main`.** It rebuilds the Plugins screen on the list pattern with plugin icons and status pills, marks what plugins add to the Tools hub and the menu, labels every row of the site status panel, and builds the `PluginAreaPage` pattern with its error boundary. It runs beside the other wave-2 parts (the lists, the editor, media, tools, the phone overlays) and touches none of their files.

Steps use checkbox (`- [ ]`) syntax for tracking.

**Written 2026-11-14** for the repository `temecms/teme`, from `origin/main` at or after `651bf431` (the Combobox's merge). Everything this part needs from the foundation is written out below, under "What this part relies on"; Task 0 checks it is there. This plan is lighter (I-569): it writes out the decisions, names, permissions, tests and the delicate code, and describes the rest.

**Goal.** Plugins look like part of the admin and are marked wherever they add something: the Plugins screen shows each plugin's icon and state in the design system's words; plugins' cards and menu pages carry the plug mark; every site status row says what it is about; and plugins get the page pattern for their own area, ready for when the contract lets them register one.

**Tech stack** (Task 0 restates the exact versions from the locks): Laravel 13, PHP 8.4, PostgreSQL 18, Inertia 3 (`@inertiajs/react` 3.9), React 19.3, TypeScript, Tailwind 4.3, shadcn/ui "new-york" on Radix, Vite 8, Pest 5 with `pestphp/pest-plugin-browser` 5.1.

**Spec:** `docs/architecture/design-system.md` ("DS", by section number): 4.13 Status pill, 4.17 Menu and the "…" menu, 4.19 Dialog, 4.23 Empty state, 4.25 Error state (a plugin's area), 4.27 Tabs and InPageNav, 4.28 Panel and the clickable card, 4.30 Page header, 4.32 The sidebar (an entry's plug mark), 4.33 The site status panel, 4.40 Plugin mark, 4.47 Plugin icon, section 5 (List, Hub, "A plugin's own area"), 6.1 and 6.2 (what core draws for plugins; the kit), section 7, section 8, section 9. `docs/architecture/plugins.md` › Where plugins appear. Every measure this part needs is written out below.

**Bound by:** I-3405 (every plugin has its own icon; initials until it ships one), I-3396 (a plugin builds anything in its own space; a wall is a missing socket, filed, not worked around), I-3401 (a plugin's own area: one entry, in-page navigation, More), I-216 and I-220 (a plugin's top-level entry: later, with the contract), I-212 (a plugin's own interface code: later), I-3353 (the plugin API's first release: settings and Tools links only), I-3342 (enabling through the password screen: unchanged), I-3397 (the site status panel), I-3408 (Soon tags: InPageNav's `soon`), I-3439 (American English for new words), I-3373 (every word through `__()`), I-797 (a comment cites one I-number or one document path), I-569, I-3317, I-3369.

**New dependencies:** none. **Migrations, permissions, operations, routes, contract members (`packages/teme-contracts`), tokens:** none. **Drafts:** `B-?? (draft)` for plan faults.

---

## Where the documents and the code disagree

1. **DS 4.47 against the reference design's icon sizes.** The reference design draws 56 in the Plugins rows and 64 and 96 in the two dialogs. **DS wins:** 32 in rows, 56 in dialogs, 64 at the head of a plugin's area; 96 is not used here.
2. **The contract has no socket for most of what DS 6.1 draws for plugins.** The manifest's `contributes.admin` holds one surface, `tools`; it has no `icon`, no menu entry, no page. So: icons are initials (`PluginIcon` with no `src`); the plugin entries that can be marked are those that exist (Tools links, and a plugin's settings page under Settings); `PluginAreaPage` is proved in the catalogue. **Never read an undeclared manifest key** (the schema allows additional properties) to get an icon or a page: that is working around a missing socket (I-3396). Each missing socket is listed under "Not in" for the maintainers to file.
3. **DS 4.33's row** is "the label (500) with its plugin mark | its Status pill; its one line; at most one action". Today a banner has no label. This part adds one; the pill keeps today's words (Problem, Needs a look, Note), since DS's "Fine" is never shown (a fine check raises no banner).
4. **The ⋯ button's name.** DS 4.17 writes "More actions for {name}"; the screen says "Actions for :name" and its browser test reads it. **Kept as it is**: renaming it changes a word and a test for nothing a person gains. Recorded for the maintainers' sweep.
5. **R5 counts `<table>` outside `ui/`.** The Plugins rows become a list (decision P3), which removes the line rather than moving it.

## What this part relies on

Written out here so this plan stands alone.

- **The seams.** `resources/css/app.css`'s token block and the existing files in `components/ui/**` and `components/patterns/**` belong to the foundation. A part may **add** new files to `ui/` and `patterns/`; it never edits an existing one or a token. A change to them is asked of the maintainers.
- **Components used, as they are:** `ui/plugin-icon.tsx` (`PluginIcon { name, src?, size: 32 | 56 | 64 | 96 }`, decorative, initials of the first two words on `bg-surface-3 text-ink-2`); `ui/plugin-mark.tsx` (`PluginMark { name, iconOnly? }`; `iconOnly` is a focusable plug with the name in a tooltip and `sr-only` "From :name"); `ui/tabs.tsx` (`Tabs`, `TabsList`, `TabsTrigger { count? }`, `TabsContent`: Radix, ←→ move and show); `ui/status-pill.tsx` (`StatusPill { tone: 'ok'|'neutral'|'info'|'warn'|'danger', children }`, writes `data-tone`); `ui/panel.tsx` (`Panel`, `PanelHeader`, `PanelBody`); `ui/error-state.tsx` (`ErrorState { message, onRetry?, retryLabel? }`, `role="alert"`, "Try again"); `ui/button.tsx` (`variant` `neutral` | `ghost` | `default` | …, `size` `sm`); `ui/dropdown-menu.tsx`; `ui/dialog.tsx`; `patterns/page.tsx` (`Page`); `patterns/page-header.tsx` (`PageHeader { title, description?, actions?, plugin?, badge?, as? }`, the mark after the `h1` when `plugin` is given); `patterns/list-page.tsx` (`ListPage { title, description?, actions?, plugin?, titleAs?, children }`); `patterns/in-page-nav.tsx` (`InPageNav { label, items: { href, label, count?, current?, soon? }[] }`, five shown, the rest under More); `patterns/hub-page.tsx` (`HubPage { title, description?, cards, pluginCards? }`, `HubCard { …, plugin? }`, plugin cards under "From plugins").
- **The values used here** (tokens in `app.css`): colors `bg-surface`, `bg-surface-2`, `bg-surface-3`, `text-ink`, `text-ink-2`, `text-ink-3`, `text-warn-ink`, `text-danger-ink`, `border-line`; type `text-caption` 12, `text-small` 13, `text-body` 15, `text-lead`, `text-title`; heights `h-(--ctl-h)` 36 (44 on touch); radii `rounded-md` 8, `rounded-lg` 12; spacing steps 1, 2, 3, 4, 5, 6, 8 (never 1.5, 2.5, 3.5).
- **The design check.** `tests/Architecture/DesignSystemTest.php` runs `tests/Fixtures/design-system-scan.mjs` over `resources/js` and `resources/css` and compares with `tests/Architecture/design-system-baseline.txt` (`rule<TAB>path<TAB>match`, the path relative to `resources/`). Rules this part meets: **R1** arbitrary `[…]` values; **R2** text sizes other than the six tokens (`text-xs`, `text-[13px]`); **R3** spacing steps 1.5, 2.5, 3.5, 7, 9, 11, 14, 15; **R4** raw colors; **R5** raw `<button>`, `<input>` (not `hidden`), `<select>`, `<textarea>`, `<table>` outside `ui/` and `patterns/`; **R6** bare `rounded`, `shadow`, numbered `z-`, `leading-`, `tracking-`, weights other than 400, 500, 600; **R7** a page whose root is not one Page pattern (`ListPage`, `HubPage`, `PluginAreaPage`, …; dialogs go inside it); **R8** `overflow-auto`/`-scroll` but in `ui/scroll-area.tsx`; **R10** `outline-none` without a `focus-visible:` outline; **R14** motion outside `ui/` without `motion-safe:`. **Lowering:** fix the offense; run `php artisan test tests/Architecture/DesignSystemTest.php`; its first case lists what is gone; remove exactly those lines and lower that rule's number in `dsCounts()`. **Never add a line or raise a number.**
- **The admin's words.** `tests/Architecture/AdminWordsTest.php` lists in `tests/Architecture/admin-words-baseline.txt` every word a person reads that does not pass through `__()` or `__n()` from `@/lib/i18n`. New words always go through them, in American English (I-3439). Moving a listed word into `__()` makes the test list it as gone: remove that line and lower the count in its `toHaveCount(…)` by as many.
- **Browser-test helpers** in `tests/Pest.php` (used, never edited): `cmSignIn(User $person)`; `cmNavigate($page, string $url)` (one attempt; never two `->navigate()` in a row); `cmWaitUntil($page, string $jsCondition)`; `shellSettled($page, string $selector)` (a dialog's or menu's own animations, never `getAnimations({ subtree: true })`); `cmSmallTargets($page)`; `cmAxeViolations($page)`. Plugin fixtures: `PluginFixtures::use('hello', 'widened')`, `PluginFixtures::copy(...)`, `PluginFixtures::forget()`; `PluginRecord::factory()->enabled()`. Copy each file's `beforeEach` (`withVite()`, `RoleSeeder`, the `RequestHandled` reset) into a new browser file.
- **Browser-test rules.** Run after `npm run build`, one file at a time, output redirected to a file and read, never piped through `grep` or `sed`. Select by `role`, `aria-*`, `data-slot`, `data-test`. Run only the browser files named here; GitHub runs the rest after the merge.

## The decisions, written out

**P1. The Plugins screen's frame.** `pages/plugins/index.tsx` returns one `ListPage` (title "Plugins", today's description), the dialogs inside it (R7). Under it: the four views as `Tabs` (P4); in each `TabsContent`, the rows (P3), or the empty line. The page keeps its state, the focus return to the dialog's opener, the pending-enable marker and its `X-Teme-Ask-In-Place` retry (I-3342), byte for byte in behavior.

**P2. The state's pill.** One map, kept in the page:

```ts
/** A state's tone; the word is the server's, so color is never alone. */
const TONES: Record<PluginState, 'ok' | 'neutral' | 'warn' | 'danger'> = {
    enabled: 'ok',
    not_enabled: 'neutral',
    disabled: 'neutral',
    disabled_on_server: 'neutral',
    waiting: 'warn',
    some_parts_stopped: 'warn',
    stopped_by_teme: 'danger',
    cant_be_used: 'danger',
    code_gone: 'danger',
};
```

`<StatusPill tone={TONES[plugin.state]} data-test="plugin-state">{plugin.badge}</StatusPill>`. The words stay the server's (`PluginState::label()`).

**P3. A row** (`components/plugins/plugin-row.tsx`, new, `PluginRow { plugin, onAsk, onDisable, onDetails }`; the page's `RowAction` and `RowMenu` move into it). The rows are a `<ul data-test="plugins-list">` inside a `Panel` (list body: `p-1`), each `<li data-test="plugin-{code or name}">`, padding `px-3 py-3`, radius `md`, a 1px `border-line` between rows. Layout at 768 and up: a grid `auto | minmax(0,1fr) | auto | auto`, gap 4, items at the start:
- `PluginIcon size={32} name={plugin.label}` (no `src`: `// Later (I-3405): the picture, once the manifest can declare one.`);
- the text: the name (`text-body font-semibold text-ink`) and the version (`text-small text-ink-3 tabular-nums`) on one line; the description (`text-small text-ink-2`); `__('By :author', { author })` when there is an author, then the package name (`text-caption font-mono text-ink-3 break-all`); the reason when not empty (`text-small`, `text-warn-ink` for a `warn` tone, `text-danger-ink` for `danger`, else `text-ink-2`; `data-test="plugin-reason"`);
- the pill (P2);
- the actions: the one action (Enable, `Review access for :version`, or Disable: `variant="neutral" size="sm"`, today's `data-test`s and hidden names kept), then the ⋯ (`variant="ghost" size="sm"`, `MoreHorizontal`, `aria-label` "Actions for :name", `data-test="plugin-menu"`), its menu unchanged (Details and access; Settings when `hasSettings`).
Under 768: one column; the icon and the name's line together, then the pill, then the actions wrapping. Nothing scrolls sideways at 375 or 320.

**P4. The views** are `Tabs` (`value` in the page's state, `onValueChange`), `TabsList aria-label={__('Plugins')}`, one `TabsTrigger value={key} count={n} data-test="plugins-tab-{key}"` per view in today's order (All, Enabled, Disabled, Needs attention) and today's membership (`TABS[].holds`). The hand-built `role="tablist"` and its key handler go: Radix gives ←→, Home and End.

**P5. The empty states.** No plugin installed: inside the panel, centered, padding 48 24, gap 12, a 32px `Plug` icon in `text-ink-3`, the title `__('No plugins are installed')` (`text-lead font-semibold`), the line today's sentence ("Whoever runs the server adds a plugin to the site’s build; it then appears here, disabled.", `text-body text-ink-2`), `data-test="plugins-empty"`. A view with no rows: one line `__('Nothing in this view')` in `text-ink-3` inside the panel. The full-sentence empty text the browser test reads today ("No plugins are installed. Whoever runs …") is now split in two; P5's test reads both parts (see Task 1).

**P6. The dialogs.** `components/plugins/consent-dialog.tsx` and `details-dialog.tsx`: in `DialogHeader`, a row with `PluginIcon size={56} name={plugin.label}` then the title and description (gap 4, items centered). Their `text-[13px]`, `text-[11px]`, `text-xs`, `gap-1.5`, `px-1.5`, `leading-4` become `text-small`, `text-caption`, `gap-2`, `px-2` and no `leading-`. Every word, `data-test`, title and focus rule stays.

**P7. Labelled status rows (server).**
- `App\Domain\Status\StatusCheck` gains `public function label(): string;` documented "What the check is about, in a few words, as the site status row's label." Every class implementing it, in `app/` and `tests/`, implements it.
- `StatusBanner` gains two trailing promoted properties with defaults, so no existing `new StatusBanner(...)` changes: `public string $label = ''`, `public ?string $plugin = null`, and a method:

```php
/** The same banner with the row's label, and the plugin's name when a plugin raised it. */
public function labelled(string $label, ?string $plugin): self
{
    return new self($this->level, $this->message, $this->actionLabel, $this->actionUrl, $this->priority, $label, $plugin);
}
```

  `toArray()` adds `'label' => $this->label, 'plugin' => $this->plugin` (its `@return` shape too).
- `StatusChecks::bannersFor()` labels each banner as it collects it: `$banners[] = $banner->labelled($check->label(), $owner === 'core' ? null : $check->label());` (the loop reads `owner` beside `check`). Sorting is unchanged.
- `PluginStatusCheck::label()` returns its `$label` (the plugin's label), so a plugin's row is labelled with the plugin's name and marked.
- Core's labels, each through `__()` in its check (P7 words; Settled 3):

| Check | Label |
|---|---|
| `ApiAddressTaken` | Management API |
| `BackupsSafe` | Backups |
| `LanguageHomeShadowsRedirect` | Languages |
| `NavigationSlotsBound` | Navigation |
| `PageCacheCdnPurges`, `PageCacheFolderShared` | Page cache |
| `PluginsRunning` | Plugins |
| `QueueWorkerRunning` | Background jobs |
| `RepresentedPersonGone` | Search and social |
| `SchedulerRunning` | Scheduled tasks |
| `SearchLanguageRebuilding` | Search |
| `StoredTaxonomiesRefused` | Taxonomies |
| `StoredTypesRefused` | Types |
| `ThemeUsable` | Theme |
| `UpdateAvailable` | Updates |

  If a check's docblock shows it is about something else than its label says, pick the menu's own name for the screen its action opens, and record it.
- `resources/js/types/status.ts`: `StatusBanner` gains `label: string; plugin: string | null`.

**P8. Labelled status rows (client).** `SiteStatusBody` in `components/header/site-status.tsx` (the phone's `status-sheet.tsx` draws the same body and does not change): each row a grid `minmax(0,1fr) auto`, gap 2 then 1: the label (`text-body font-medium text-ink`, `data-test="site-status-label"`), followed when `plugin` is set by `<PluginMark iconOnly name={banner.plugin} />` | the pill (today's words and tones); then the message across both columns; then the action across both. A row with an empty label (a fake in a test) draws the pill and message as today.

**P9. Plugin entries with their mark (server).**
- `ToolLink` gains a trailing `public string $plugin = ''` (the plugin's label); `Contributions` passes `plugin: $label` where it builds each link. Existing `new ToolLink(...)` calls in tests do not change.
- `ToolsHub::pagesFor()`: each plugin link's array gains `'plugin' => $link->plugin` when it is not empty; core's tools have no `plugin` key. The `@return` shape gains `plugin?: string`.
- `SettingsHub::pagesFor()`: a group whose key starts `plugin.` gains `'plugin' => <the group's label>`; core's groups have none.
- `AdminNavigation`'s `screens` map passes it on as `external` is passed: `...(isset($screen['plugin']) ? ['plugin' => $screen['plugin']] : [])`.
- `types/navigation.ts`: `AdminNavScreen` gains `/** The plugin that added this screen (I-3353); absent for Teme's own. */ plugin?: string`. `types/tools.ts`: `ToolPage` gains the same `plugin?: string`.

**P10. Plugin entries with their mark (client).**
- `pages/tools/index.tsx` (the Tools hub): `cards` holds the tools without `plugin`; `pluginCards` the ones with it, each `HubCard` given `plugin={tool.plugin}`. Its four bare words ("Tools" ×3, "Utilities for looking after the site.") move into `__()`. The cards' `data-test`s stay.
- `components/nav-main.tsx`, `SubEntry` only: a screen with `plugin` draws, inside its link after the label, a 14px `Plug` in `text-ink-3` (`aria-hidden`, pushed to the end) and `<span className="sr-only">{__('From :name', { name: item.plugin })}</span>`. Not `PluginMark iconOnly`: that one is focusable, and a focusable thing inside a link is two stops for one entry. No other line of `nav-main.tsx` changes.

**P11. `PluginAreaPage`** (`components/patterns/plugin-area-page.tsx`, new):

```ts
export type PluginAreaPageProps = {
    /** The plugin whose area this is: its name, and its picture once plugins can ship one. */
    plugin: { name: string; icon?: string | null };
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    /** The area's pages, as InPageNav draws them; none or one draws no navigation. */
    pages?: { href: string; label: string; count?: number; current?: boolean; soon?: boolean }[];
    /** `div` where the page already has its h1, as in the catalogue. */
    titleAs?: 'h1' | 'div';
    children: ReactNode;
};
```

It draws `Page`; a row (`flex items-start gap-4`) of `PluginIcon size={64} name={plugin.name} src={plugin.icon ?? null}` (`mt-2`) and, in `min-w-0 flex-1`, `PageHeader` with `title`, `description`, `actions`, `plugin={plugin.name}`, `as={titleAs}`; then `InPageNav label={__(':name pages', { name: plugin.name })} items={pages}` when `pages.length > 1`; then the children inside the boundary:

```tsx
/** What a plugin draws can fail; the admin around it must not (docs/architecture/plugins.md). */
class PluginAreaBoundary extends Component<{ plugin: string; children: ReactNode }, { failed: boolean }> {
    state = { failed: false };

    static getDerivedStateFromError(): { failed: boolean } {
        return { failed: true };
    }

    render(): ReactNode {
        if (this.state.failed) {
            return (
                <Panel data-test="plugin-area-failed">
                    <ErrorState
                        message={__('Couldn’t show this page of :name. The rest of the admin is unaffected.', { name: this.props.plugin })}
                        onRetry={() => this.setState({ failed: false })}
                    />
                </Panel>
            );
        }

        return this.props.children;
    }
}
```

The boundary is keyed by the current page's `href` (the `current` item's, else `title`), so moving to another page of the area clears a failure. It exports `PluginAreaPage` and `PluginAreaPageProps` only.

**P12. The catalogue's section** (`pages/dev/catalogue.tsx`, one `Section id="plugin-area"`, additive, after the plugin icon's section): a `PluginAreaPage` with `titleAs="div"`, plugin `{ name: __('Link checker') }`, title the same, a one-line description, one neutral action, and seven pages ("Overview", "Link report", "Orphaned posts", "Broken links" with a count, "Settings", "Exports", "Logs"), their `href`s `?area=<key>#plugin-area` and `current` read from the page's URL (Overview when none). Its body: one paragraph naming the current page, and a neutral sm button "Make this page fail" (`data-test="plugin-area-break"`) that sets a state making a child component throw. Sample words through `__()`.

---

## Files

**Create:** `resources/js/components/patterns/plugin-area-page.tsx`; `resources/js/components/plugins/plugin-row.tsx`; `tests/Feature/Status/StatusRowLabelsTest.php`; `tests/Feature/Plugins/PluginEntriesMarkedTest.php`; `tests/Browser/Plugins/SiteStatusLabelsTest.php`; `tests/Browser/Plugins/PluginAreaPageTest.php`.

**Modify:** `resources/js/pages/plugins/index.tsx`; `resources/js/components/plugins/consent-dialog.tsx`, `details-dialog.tsx`; `resources/js/components/header/site-status.tsx` (`SiteStatusBody`); `resources/js/components/nav-main.tsx` (`SubEntry`); `resources/js/pages/tools/index.tsx`; `resources/js/pages/dev/catalogue.tsx` (one section); `resources/js/types/status.ts`, `navigation.ts`, `tools.ts` (one field each); `app/Domain/Status/StatusCheck.php`, `StatusBanner.php`, `StatusChecks.php`, `Checks/*.php` (`label()`); `app/Domain/Plugins/Contributions/PluginStatusCheck.php`, `ToolLink.php`, `Contributions.php` (one argument); `app/Domain/Navigation/ToolsHub.php`, `SettingsHub.php`, `Queries/AdminNavigation.php` (the `screens` map); `tests/Browser/Plugins/PluginsScreenTest.php`, `ToolLinksTest.php`; tests that implement `App\Domain\Status\StatusCheck` or compare a banner's or a hub's whole array (Task 2, Task 3); the two baselines and their tests.

## Owns

This part owns, exclusively: `pages/plugins/**`, `components/plugins/**`, `components/patterns/plugin-area-page.tsx`, `components/header/site-status.tsx`, `pages/tools/index.tsx` (the hub), `app/Domain/Status/**`, `app/Domain/Plugins/Contributions/**`, `app/Domain/Navigation/{ToolsHub,SettingsHub}.php`, and the files under "Create". It changes, line by line and nothing else: `nav-main.tsx`'s `SubEntry`, `AdminNavigation.php`'s `screens` map, one field in each of `types/status.ts`, `types/navigation.ts` and `types/tools.ts`, one catalogue section.

**Not this part's:** `components/ui/**`, the token block, existing `components/patterns/**` files, `components/list/**`, `pages/content/**`, `pages/terms/**`, `pages/comments/**`, `components/editor/**`, `components/media/**`, `components/media-drawer.tsx`, `pages/media/**`, `pages/tools/**` other than `index.tsx`, `components/{backups,import,tools,jobs}/**`, `components/palette/**`, `components/header/status-sheet.tsx`, `layouts/settings/**`, `pages/settings/**`, `packages/teme-contracts/**`, `tests/Pest.php`, the scanner.

## Global Constraints

- **Rule 1:** nothing here changes stored state. Enabling and disabling keep `EnablePlugin` and `DisablePlugin`, their routes, the `plugins.manage` permission and the password confirmation. No operation is added, so no authorization-failure test is new; the existing ones (`EnablePluginTest`, `DisablePluginTest`, `PluginsPermissionTest`) stay green unchanged.
- **Who sees what does not widen.** A plugin's status row shows only to holders of `plugins.manage` (`PluginStatusCheck::ability()`), its Tools card only to them (`ToolsHub`), its settings page only to them (`DeclaredSettings`). Task 2 and Task 3 each prove it for a person without it.
- **Rule 3:** PostgreSQL only. **Rule 5:** `packages/teme-contracts` is not touched; the contract's `StatusCheck` and `StatusNotice` do not change.
- **No dependency.** Never change `composer.json`, `composer.lock`, `package.json` or `package-lock.json`.
- **Every word a person reads goes through `__()` or `__n()`** (TS) or `__()` (PHP), in American English (I-3439). Never raise `AdminWordsTest`'s count.
- **The design-system baseline only shrinks.** At the end no line names `js/pages/plugins/index.tsx`, `js/components/plugins/`, `js/components/patterns/plugin-area-page.tsx` or `js/pages/tools/index.tsx`. Only lines of files this part owns are removed. **Adding a line, raising a number, or widening an allowed place in the scanner is a STOP.**
- **No token, and no change to an existing `ui/` or `patterns/` file.**
- **Tests first:** each test below is run red for the reason given before the code.
- **The `.env`:** in a fresh checkout, `cp .env.example .env` and `php artisan key:generate`. Never copy, read or edit any other `.env`.
- **Never edit** `.ai/`, `CHANGELOG.md`, `docs/roadmap.md`, `docs/backlog.md`, `docs/architecture/*`, `docs/reference/*`.
- **Conventional Commits**, staged by file (never `git add -A`), no closing keywords, written for the public. A code comment cites at most one I-number or one document path (I-797).
- `components/ui/*` is not edited; every other file follows `npm run check:fix` and Pint.
- **The quick check** after each task: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, `npm run types:check`, `npm run check`, `php artisan test --testsuite=Architecture`, and the task's own test files.
- **Unattended work: decide small things yourself and record the options.** A choice the plan does not settle and that no STOP covers (a word, a spacing step, a selector, the order of two lines): take the option closest to the DS and the reference values above, finish the task, and write a `B-?? (draft)` in Records with what was found, the options and the one chosen. Never stop for it.
- **When the work is interrupted:** each task ends committed and pushed, with one Records line: "Done to Task N at `<hash>`, the quick check green". A test red at the base and not this part's: record it, never change it.

---

## Task 0: Preconditions, then branch

Run in a fresh checkout. If a line differs **by its text**, STOP and write why under Records.

- [ ] **Step 1:** `git fetch origin && git merge-base --is-ancestor 651bf431 origin/main && echo ok` → `ok`. Record `origin/main`'s head.
- [ ] **Step 2:**
  - `ls resources/js/components/ui/{plugin-icon,plugin-mark,tabs,status-pill,panel,error-state}.tsx resources/js/components/patterns/{page,page-header,list-page,in-page-nav,hub-page}.tsx` → all exist; `ls resources/js/components/patterns/plugin-area-page.tsx resources/js/components/plugins/plugin-row.tsx 2>&1` → neither exists;
  - `grep -n "PluginAreaPage" tests/Fixtures/design-system-scan.mjs` → at least one line (R7 already accepts the pattern's name);
  - `grep -n "role=\"alert\"" resources/js/components/ui/error-state.tsx` → one line; `grep -n "pluginCards" resources/js/components/patterns/hub-page.tsx` → at least one; `grep -n "plugin?: string" resources/js/components/patterns/page-header.tsx` → one;
  - the missing sockets, as Decision 2 above says: `grep -n '"enum": \["tools"\]' packages/teme-contracts/schema/teme-plugin.schema.json` → one line; `grep -c '"icon"' packages/teme-contracts/schema/teme-plugin.schema.json` → 1 (the Tools link's); `grep -n "label" packages/teme-contracts/src/Status/StatusNotice.php` → nothing;
  - `grep -n "function label" app/Domain/Status/StatusCheck.php` → nothing; `git grep -l "implements StatusCheck" -- app tests` → record the list (expected: the 15 files in `app/Domain/Status/Checks/` and `PluginStatusCheck.php`, plus test files);
  - `grep -n "export function SiteStatusBody" resources/js/components/header/site-status.tsx` → one; `grep -n "SiteStatusBody" resources/js/components/header/status-sheet.tsx` → at least one;
  - `grep -n "function SubEntry" resources/js/components/nav-main.tsx` → one; `grep -n "pluginCards" resources/js/pages/tools/index.tsx` → nothing;
  - `grep -c -P "\tjs/(pages/plugins/index\.tsx|components/plugins/)" tests/Architecture/design-system-baseline.txt` → record (29 at writing: R2 17, R3 4, R5 2, R6 1, R7 5); `grep -c -P "\tjs/pages/tools/index\.tsx" tests/Architecture/design-system-baseline.txt` → record; `grep -c -P "^pages/tools/index\.tsx\t" tests/Architecture/admin-words-baseline.txt` → 4; record `AdminWordsTest`'s `toHaveCount(…)` and `dsCounts()`;
  - the other parts' branches, where they exist (`git branch -r --list 'origin/ui/*'`): for each, `git diff --name-only origin/main...<branch> -- resources/js/pages/plugins resources/js/components/plugins resources/js/components/header/site-status.tsx resources/js/pages/tools/index.tsx resources/js/components/nav-main.tsx app/Domain/Status app/Domain/Navigation app/Domain/Plugins/Contributions` → nothing. A file listed: record it and STOP (another part touches this part's files).
  - `php artisan test tests/Architecture/DesignSystemTest.php tests/Architecture/AdminWordsTest.php` → green; `php artisan test tests/Feature/Plugins tests/Feature/Status` → green (record the counts).
- [ ] **Step 3:** restate the stack from `composer.lock` and `package-lock.json` in Records. `git checkout -b ui/plugin-areas origin/main`.

## Task 1: The Plugins screen

**Files:** `pages/plugins/index.tsx`; `components/plugins/plugin-row.tsx` (new); `components/plugins/consent-dialog.tsx`, `details-dialog.tsx`; `tests/Browser/Plugins/PluginsScreenTest.php`.

**Existing cases that change, meaning kept** (each change in this task's commit):
- "filters by tab with each count, and the ⋯ button has a name": `[data-test="plugins-table"] tbody tr` becomes `[data-test="plugins-list"] > li`; the tab text read stays `All3|Enabled0|Disabled1|Needs attention2` (`TabsTrigger` draws the label then the count).
- "shows the empty state": waits for "No plugins are installed" and for "Whoever runs the server adds a plugin to the site’s build; it then appears here, disabled." (P5 splits the sentence into a title and a line).
- "draws the adopted design …": unchanged selectors; it now also proves P1 to P6 through its axe run and its screenshots.

**New cases, written first** (they fail first on the selectors named):

| Case | What it proves |
|---|---|
| "shows each plugin's icon, its initials at 32 pixels, beside its name" | `[data-test="plugin-hello"] [data-slot="plugin-icon"]` exists, its `textContent` is `H`, its box 32×32, `aria-hidden="true"`; one per row |
| "gives each state its pill and tone" | On the `site()` fixture: `plugin-state` of hello has `data-tone="neutral"` and "Disabled", of widened `warn` and "Needs attention", of gone `danger` and "Can’t be used"; the reason under widened has `text-warn-ink` computed color equal to a `text-warn-ink` probe |
| "shows the plugin's icon at 56 pixels in both dialogs" | The consent and the details dialog each hold one `[data-slot="plugin-icon"]` 56×56 inside the dialog's header |
| "moves between the views with the arrow keys" | Focus the All tab, ArrowRight twice: the Disabled tab is selected and focused, its panel shows only disabled rows |
| "stacks each row at 375 and 320 pixels with nothing scrolling sideways, every target 24 pixels or more" | At 375 and 320: `sideways` 0; the ⋯ and the action button within the viewport; `cmSmallTargets()` empty |

- [ ] **Step 1:** write and change the cases; `npm run build`; run the file; record the first failure of each new case.
- [ ] **Step 2:** build P1 to P6. Then the file until green; `EnablePluginTest`, `DisablePluginTest`, `PluginsScreenTest` (Feature) unchanged and green.
- [ ] **Step 3:** `DesignSystemTest`: remove the lines it lists as gone for `js/pages/plugins/index.tsx` and `js/components/plugins/` (all of them, Task 0's count) and lower `dsCounts()`; the new `plugin-row.tsx` adds none. `AdminWordsTest` green (new words through `__()`).
- [ ] **Step 4:** quick check; commit `feat(admin): the plugins screen with plugin icons and status pills (I-3405)`; push; the Records line.

## Task 2: Labelled site status rows

**Files:** P7 and P8's; `tests/Feature/Status/StatusRowLabelsTest.php`; `tests/Browser/Plugins/SiteStatusLabelsTest.php`.

**Feature tests, written first** (`StatusRowLabelsTest`; they fail first on `Call to undefined method …::label()` or the missing `label` key):

```php
it('gives every core status check a label', function (): void {
    $checks = array_map(
        fn (string $file): string => 'App\\Domain\\Status\\Checks\\'.basename($file, '.php'),
        glob(app_path('Domain/Status/Checks/*.php')) ?: [],
    );

    expect($checks)->not->toBeEmpty();

    foreach ($checks as $check) {
        expect(app($check)->label())->toBeString()->not->toBe('', "{$check} has no label");
    }
});
```

| Case | What it proves |
|---|---|
| "gives every core status check a label" | Above |
| "shares a core banner with its label and no plugin" | Register a check in the test (`StatusChecks::register` of a small class in the file, label "Testing", level warning); an administrator's `siteStatus` and `statusBanner` hold `label: 'Testing'`, `plugin: null` |
| "labels a plugin's banner with the plugin's name, for those who manage plugins" | The `NoticingPlugin` fixture enabled as `PluginStatusChecksTest` does: the administrator's row has `label` and `plugin` equal to the plugin's label; an editor (no `plugins.manage`) gets no such row |
| "keeps the most pressing first" | Two checks, error and warning, registered in the other order: the error's row first, each with its own label |

**Existing tests that change, meaning kept:** each class in `tests/` implementing `App\Domain\Status\StatusCheck` gains a `label()` returning a short word; each assertion comparing a whole shared banner (`toArray()`, `statusBanner`, `siteStatus`) gains `'label'` and `'plugin'` with the values the check now gives (Task 0's list; run `git grep -l -E "actionLabel" -- tests` and the Status and Plugins feature folders to find them). No assertion is dropped.

**Browser test, written first** (`SiteStatusLabelsTest`): "labels each site status row, a plugin's with its mark, in both schemes at 1280 and 375": a core check registered in the test (warning, "Testing") and the noticing plugin enabled; open the site status (at 375 the status sheet); the rows' `[data-test="site-status-label"]` read "Testing" and the plugin's label; the plugin's row holds `[data-slot="plugin-mark"]` whose `sr-only` text is "From <label>"; `cmAxeViolations()` empty. Fails first on `site-status-label`.

- [ ] **Step 1:** write the cases; run; record the failures.
- [ ] **Step 2:** P7, P8. Run `tests/Feature/Status`, `tests/Feature/Plugins`, the new browser file, and the browser files that open the site status (`git grep -l "site-status" -- tests/Browser`), one at a time; all green.
- [ ] **Step 3:** quick check; commit `feat(admin): every site status row says what it is about`; push; the Records line.

## Task 3: Plugin entries with their mark

**Files:** P9 and P10's; `tests/Feature/Plugins/PluginEntriesMarkedTest.php`; `tests/Browser/Plugins/ToolLinksTest.php`.

**Feature tests, written first** (`PluginEntriesMarkedTest`; they fail first on the missing `plugin` key):

| Case | What it proves |
|---|---|
| "names the plugin on each of its Tools links, and on none of Teme's" | A `ToolLink` added with `plugin: 'Example Sync'`: `ToolsHub::pagesFor($admin)` gives it `plugin => 'Example Sync'`; no core tool has the key |
| "names the plugin on its settings page in the Settings hub" | The `contributor` fixture's declared settings: the `plugin.<code>` screen under the Settings entry's `screens` in the shared `nav` has `plugin` = the plugin's label; `settings.general` has none |
| "carries the plugin's name into the menu's Tools screens" | The shared `nav`'s Tools entry: the plugin link's screen has `plugin`, core's do not |
| "shows a plugin's entries to no one without plugins.manage" | An editor's `nav` and `ToolsHub::pagesFor()` hold no screen with `plugin` |

**Existing tests that change, meaning kept:** `tests/Feature/Plugins/ToolLinksTest.php` and `OwnersTest.php`, where they compare a plugin link's whole array: a link built with a `plugin` gains that key; one built without it (the existing constructions) has none, so most of these arrays do not change.

**Browser cases, written first** (`ToolLinksTest`):
- "lists plugins' links under From plugins, each with its plugin's mark": on `/admin/tools`, the `tool-plugin.example-sync.open` card sits inside `section[aria-labelledby="hub-from-plugins"]` and holds `[data-slot="plugin-mark"]` with the plugin's name; no core card is in that section. Fails first on the section.
- "marks a plugin's page in the menu's Tools entry, named for screen readers": a `ToolLink` to `settings` added with its plugin; on `/admin/tools` the sub-entry's link holds a `svg[aria-hidden="true"]` after its label and an `.sr-only` "From Example Sync"; the link is one Tab stop. Fails first on the `.sr-only`.

- [ ] **Step 1:** write the cases; run; record the failures.
- [ ] **Step 2:** P9, P10. Run `tests/Feature/Plugins`, `tests/Feature/Navigation` (if it exists), the shell's feature tests that read `nav` (`git grep -l "'screens'" -- tests/Feature`), `ToolLinksTest` (browser), `ShellTest` and `ShellResponsiveTest` (browser), one at a time; all green.
- [ ] **Step 3:** `AdminWordsTest`: remove `pages/tools/index.tsx`'s four lines, lower the count by 4. `DesignSystemTest`: remove any line it lists as gone for `js/pages/tools/index.tsx`.
- [ ] **Step 4:** quick check; commit `feat(admin): plugins' tools and settings pages carry their plugin's mark`; push; the Records line.

## Task 4: `PluginAreaPage`

**Files:** `components/patterns/plugin-area-page.tsx` (new); `pages/dev/catalogue.tsx` (P12); `tests/Browser/Plugins/PluginAreaPageTest.php`.

**Tests, written first** (they fail first on `#plugin-area`):

| Case | What it proves |
|---|---|
| "heads the area with the plugin's icon at 64 pixels, its title and its mark" | In `#plugin-area`: one `[data-slot="plugin-icon"]` 64×64 reading "LC"; the title's text "Link checker"; a `[data-slot="plugin-mark"]` after it |
| "shows five pages, the rest under More, and marks the current one" | `nav[aria-label="Link checker pages"]` holds five links and a More button; Overview has `aria-current="page"`; open More: Exports and Logs; follow Logs: the More button shows "Logs" with `aria-current="page"` and the body names Logs |
| "shows a failed page as one sentence naming the plugin, and Try again brings it back" | Click `plugin-area-break`: `[data-test="plugin-area-failed"] [role="alert"]` reads "Couldn’t show this page of Link checker. The rest of the admin is unaffected."; the catalogue's other sections are still drawn; Try again: the body is back |
| "clears a failure when another page of the area opens" | Break, then follow Link report: no `plugin-area-failed`, the body names Link report |
| "passes axe in both schemes at 1280 and 375, every target 24 pixels or more" | `cmAxeViolations()` empty; `cmSmallTargets()` empty; nothing scrolls sideways at 375 |

- [ ] **Step 1:** write the cases; `npm run build`; run; record the failures.
- [ ] **Step 2:** P11, P12. Run the file until green, then `DesignCatalogueTest` (green; if a case counts the catalogue's sections, its number rises by one: that is the meaning kept, recorded). Plant: remove the boundary's `key`; see "clears a failure …" red; put it back; record the first line.
- [ ] **Step 3:** `DesignSystemTest` (the new pattern adds no line: if it does, fix the file, never the baseline); `AdminWordsTest`.
- [ ] **Step 4:** quick check; commit `feat(admin): the page pattern for a plugin's own area, with its error boundary (I-3401)`; push; the Records line.

## Task 5: Gate and records

- [ ] The full `composer check` once. The browser files this part added or changed, after `npm run build`, one at a time: `Plugins/PluginsScreenTest`, `Plugins/ToolLinksTest`, `Plugins/SiteStatusLabelsTest`, `Plugins/PluginAreaPageTest`, `DesignCatalogueTest`, `ShellTest`, `ShellResponsiveTest`; GitHub runs the rest after the merge.
- [ ] `grep -c -P "\tjs/(pages/plugins/|components/plugins/|components/patterns/plugin-area-page|pages/tools/index)" tests/Architecture/design-system-baseline.txt` → 0. `git diff --stat origin/main -- packages resources/js/components/ui resources/css composer.json package.json` → nothing.
- [ ] Records: the commits, the counts before and after per rule and the admin-words count, every `B-?? (draft)`, the missing sockets as written under "Not in", and how to try it: Site › Plugins (icons, pills, the views by keyboard), Tools (From plugins), the site status button (labels), `/admin/_catalogue#plugin-area` on a local site. Commit `docs(plans): plugin areas records`; push.

---

## Every word

**New**, through `__()`: "By :author", "No plugins are installed", "Couldn’t show this page of :name. The rest of the admin is unaffected.", ":name pages", the fourteen labels of P7 (Management API, Backups, Languages, Navigation, Page cache, Plugins, Background jobs, Search and social, Scheduled tasks, Search, Taxonomies, Types, Theme, Updates), and the catalogue's section name, description and sample words ("Link checker", its line, the seven page names, "Make this page fail"). **Reused:** "From :name", "From plugins", "Try again", "Nothing in this view", "Actions for :name", "Details and access", "Settings", "Enable", "Disable", "Review access for :version". **Moved into `__()` unchanged:** "Tools" and "Utilities for looking after the site." in `pages/tools/index.tsx`.

## What must not be done

- Add an `icon`, a menu surface, a page surface or a status label to the plugin manifest, `packages/teme-contracts`, or read an undeclared manifest key in their place.
- Add a plugin's top-level sidebar entry, a route for a plugin's area, or any way for a plugin to register a page.
- Build bulk actions on plugins, install requests, a directory, update notices or removal requests.
- Change `EnablePlugin`, `DisablePlugin`, their routes, requests or permissions, or the pending-enable marker's behavior.
- Mark plugin items in the command palette (`components/palette/**`), or put the icon on a plugin's settings page (`pages/settings/**`, `layouts/settings/**`).
- Change any existing `ui/` or `patterns/` file, a token, the scanner, or `tests/Pest.php`.
- Delete a test, or change what a test proves.

## STOP conditions

Stop, write why under Records, commit and push, then stop, when:
- a Task 0 line differs by its text, or another part's branch touches a file this part owns;
- the work needs a dependency, a token, a change to an existing `ui/` or `patterns/` file, or a change to `packages/teme-contracts`;
- a design-system or admin-words line would have to be added, a count raised, or the scanner's allowed places widened;
- a test would have to be deleted or its meaning changed, or a browser test not named here would have to change;
- a status row, Tools card or settings page would show to someone without the ability that shows it today;
- a migration, or anything that changes stored rows, seems needed;
- a file of another part (the lists, the editor, media, tools, the phone overlays) would have to change.

## Security

- No route, permission, query or stored data changes. Every plugin entry keeps its ability (`plugins.manage`).
- Plugin-supplied words (label, description, author, reason, status messages) are rendered as text, never HTML, as today.
- The icon component draws no picture from a plugin yet; when it does, the file is sanitized or rasterized and served by core (I-3405), never linked from the plugin's package.

## At the merge (for the maintainers)

- DS 4.33: the row's label is what the check is about; a plugin's row is labelled with the plugin's name. DS 4.17: "Actions for {name}" is what the Plugins screen says (Disagreement 4).
- File one issue per missing socket under "Not in" (a plugin's picture; a plugin's own area; a plugin's top-level entry; a plugin's own label and action on a status row), and one for bulk actions on plugins.
- Changelog line, at the release: "The Plugins screen shows each plugin's icon and state at a glance, and what plugins add to the admin is marked as theirs. Every row of the site status panel now says what it is about."
- Baseline conflicts with other parts: take both sides' removals, then run `DesignSystemTest` and `AdminWordsTest` and set each count to what the merged baseline holds. The catalogue: keep every part's sections.

## Open points

| # | Point | Recommendation |
|---|---|---|
| 1 | Plugin rows as a list in a panel, or a table | A list (P3); a table returns if bulk actions need a selection column (Settled 1) |
| 2 | The views as Tabs, or InPageNav with an address each | Tabs (P4), as DS 4.27 defines them for one address (Settled 2) |
| 3 | The fourteen core labels | As P7 (Settled 3) |
| 4 | Bulk actions on plugins | A part of their own, after the installer's design (Settled 4) |
| 5 | The phone overlays for the two plugin dialogs | Left to the phone-overlays part's own opt-in after both merge, so neither part edits the other's files |

---

## Records

<!-- Filled in while building: commits; each test seen red (task, file, case, the failure's first line); the design-system and admin-words counts before and after; differences from the plan as B-?? (draft); changelog lines; any STOP. -->

### Setup and preconditions

- Setup: `scripts/cloud-setup.sh` ran in the repository root and finished (its log: started 2026-11-14T20:55:10Z, finished 21:06:28Z). PHP 8.4.27, PostgreSQL 18.7 on 5433, Valkey on 6380.
- Task 0: `origin/main` at `4b8cc3b`, which contains `651bf431`. Every precondition line matched by its text: the shared components and patterns exist and the two new files did not; R7 accepts `PluginAreaPage`; the manifest's `contributes.admin` enum is `["tools"]`, `"icon"` appears once, `StatusNotice` has no label; `StatusCheck` had no `label()`; `implements StatusCheck` in `app/` is the 15 core checks and `PluginStatusCheck`, in `tests/` `OwnersTest`, `PluginStatusChecksTest`, `SiteStatusTest` (plus the contract's own `StatusCheck` in three fixtures, which this part does not touch); 29 design-system lines for the Plugins screen (R2 17, R3 4, R5 2, R6 1, R7 5), none for `pages/tools/index.tsx`, 4 admin-words lines for it. No other `ui/*` branch on the remote touched this part's files.
- Stack from the locks: Laravel 13.39.0, Inertia Laravel 3.5.2, `@inertiajs/react` 3.9.1, React 19.3.1, TypeScript 7.0.2, Tailwind 4.3.3, Vite 8.3.5, Pest 5.3.4 with `pestphp/pest-plugin-browser` 5.1.2.
- The full `composer check` was not run before the first task: the brief asks for the quick check per task and one full check at the end.

### Commits

- `9ce00b3` feat(admin): the plugins screen with plugin icons and status pills (I-3405) — Task 1.
- `f338a6f` feat(admin): every site status row says what it is about — Task 2.
- `0b3cb5b` feat(admin): plugins' tools and settings pages carry their plugin's mark — Task 3.
- `aa3b81d` feat(admin): the page pattern for a plugin's own area, with its error boundary (I-3401) — Task 4.
- Done to Task 4 at `aa3b81d`, the quick check green.

### Tests seen red (run against `origin/main`'s code, the new tests in place)

- Task 1, `tests/Browser/Plugins/PluginsScreenTest.php`: "filters by tab …" — `Failed asserting that false is identical to true` (no `plugins-list`); "shows each plugin's icon …" — `Failed asserting that two arrays are identical` (no icon); "gives each state its pill and tone" — the same (no `data-tone`); "shows the plugin's icon at 56 pixels in both dialogs" — `false is identical to true`; "moves between the views with the arrow keys" — `false is identical to true` (the Disabled tab was not selected and focused after two ArrowRight presses). "stacks each row at 375 and 320 pixels …" was **green at the base**: the old table already stacked on a phone; it is kept as a guard for the new rows. "shows the empty state" stays green by design (it reads the two halves of the old sentence).
- Task 2, `tests/Feature/Status/StatusRowLabelsTest.php`: "gives every core status check a label" — `Call to undefined method App\Domain\Status\Checks\ApiAddressTaken::label()`; "shares a core banner …" and "labels a plugin's banner …" — `Property [siteStatus.0.label] does not exist.`; "keeps the most pressing first" — `ErrorException` (undefined property `label`). `tests/Browser/Plugins/SiteStatusLabelsTest.php`, all four datasets — `Timeout 5000ms exceeded` waiting for `site-status-label`.
- Task 3, `tests/Feature/Plugins/PluginEntriesMarkedTest.php`: "names the plugin on each of its Tools links …" — `Unknown named parameter $plugin`; "names the plugin on its settings page …" and "carries the plugin's name into the menu's Tools screens" — `Failed asserting that null is identical to 'Example Sync'.` "shows a plugin's entries to no one without plugins.manage" was green at the base, as a guard should be. `tests/Browser/Plugins/ToolLinksTest.php`, all three cases — `Unknown named parameter $plugin`.
- Task 4, `tests/Browser/Plugins/PluginAreaPageTest.php`, all eight — `Timeout 5000ms exceeded` waiting for `#plugin-area`.
- The plant (Task 4 Step 2): with the boundary's `key` removed, "clears a failure when another page of the area opens" stayed **green**. See B-?? (draft) 6.

### Counts

| Baseline | Before | After |
|---|---|---|
| Design system R2 | 661 | 644 |
| Design system R3 | 149 | 145 |
| Design system R5 | 82 | 80 |
| Design system R6 | 41 | 40 |
| Design system R7 | 89 | 84 |
| Admin words | 2350 | 2346 |

No line was added and no number raised. No design-system line names `js/pages/plugins/`, `js/components/plugins/`, `js/components/patterns/plugin-area-page` or `js/pages/tools/index` (0).

### Gate

- Full `composer check` at `aa3b81d`: Pint, PHPStan, types, lint, the Architecture suite (105 passed, 1227 assertions) and the feature suite (10498 passed, 2 skipped, 53925 assertions). Green.
- Browser files, after `npm run build`, one at a time: `Plugins/PluginsScreenTest` 13 passed; `Plugins/SiteStatusLabelsTest` 4 passed; `Plugins/ToolLinksTest` 3 passed; `Plugins/PluginAreaPageTest` 8 passed; `DesignCatalogueTest` 18 passed; `ShellTest` 17 passed; `ShellResponsiveTest` 10 passed. The rest of the Browser suite was not run here.
- `git diff --stat origin/main -- packages resources/js/components/ui resources/css composer.json package.json`: nothing.

### Decisions (B-?? drafts)

1. **B-?? (draft) The hub screens' shape in `NavHub`.** PHPStan refused `isset($screen['plugin'])` in `AdminNavigation`'s screens map, because `NavHub::pagesFor()`'s `@return` shape has no `plugin` key. Options: add `plugin?: string` to that one docblock line in `app/Domain/Navigation/NavHub.php` (not in the Owns list, but in `app/Domain/Navigation`, which Task 0 showed no other part touches); a cast or an ignore (forbidden); reading the key another way (the same error). Chose the docblock line, the honest type. `0b3cb5b`.
2. **B-?? (draft) The site status panel's first focus.** Opening the panel (and the phone's status sheet) focuses its first stop; on a plugin's row that is `PluginMark iconOnly`, whose tooltip then opens, and axe reports `region` for the tooltip's popper outside every landmark. Options: a non-focusable plug as in the menu (but P8 names `PluginMark iconOnly`, and the test reads its `data-slot`); changing `header-panel.tsx` or `status-sheet.tsx` (not this part's); letting the tooltip close before the axe run. Chose the last, in `SiteStatusLabelsTest`: the active element is blurred, the test waits for the tooltip to go, runs axe, and then proves the panel is still open with both rows. Left open for the maintainers: whether the panel should focus its heading rather than the first stop. `f338a6f`.
3. **B-?? (draft) Where the menu's plug is checked.** The menu opens a hub's screens only on one of the hub's pages, and `/admin/tools` itself is not one, so the plan's "on `/admin/tools` the sub-entry's link …" finds no sub-entry. Options: open Tools › Jobs (`/admin/tools/jobs`); change `nav-main.tsx` to open the hub's screens on the hub's own page (more than `SubEntry`). Chose Tools › Jobs. `0b3cb5b`.
4. **B-?? (draft) Registering the plugin in the feature tests.** `PluginEntriesMarkedTest` and `StatusRowLabelsTest` register the plugin through `Contributions::add()` (as `ContributionsTest` does) rather than enabling the `contributor` fixture. Options: the fixture with discovery and an enabled record; `Contributions::add()`. Chose the latter: it is the path that passes the plugin's label, with no discovery to set up, and it proves the same thing. The browser `ToolLinksTest`'s existing link gains `plugin: 'Example Sync'`, so the new case can find it under From plugins; the existing case's meaning is unchanged. `f338a6f`, `0b3cb5b`.
5. **B-?? (draft) The row's layout.** P3 describes a grid `auto | minmax(0,1fr) | auto | auto`; written as arbitrary columns it is an R1 offence (the scanner allows only `minmax(0,1fr)` and `minmax(0,1fr)_auto`). Options: an arbitrary grid (a new baseline line, a STOP); a CSS variable as the hub does (a pattern's device); flex. Chose flex: the icon, the text (`flex-1`), the pill and the actions on one line from 768 up; under 768 it wraps, the pill and the actions each across the width, indented by the icon and gap (`ps-12`) so they line up under the text. `9ce00b3`.
6. **B-?? (draft) The boundary's key plant stayed green.** An Inertia visit to another page of the catalogue's area remounts the whole page component (state is not preserved by default), so a failure clears whether or not the boundary is keyed. Options: keep the key as P11 writes it (it matters when a plugin's pages preserve state, as an in-page tab switch would); make the catalogue's links preserve state (`InPageNav` is not this part's); drop the case. Chose to keep the key and the case, and record that the case does not prove the key. `aa3b81d`.
7. **B-?? (draft) The catalogue's failing page.** A page made to fail by state would throw again after Try again, so Try again could never bring it back. The sample sets its "fail" flag back once the boundary has caught the failure (an effect after the commit), so the next render — Try again, or another page — draws the body. `aa3b81d`.
8. **B-?? (draft) Small choices.** The Tools hub's breadcrumb moved from the module-level `ToolsIndex.layout` into `setLayoutProps()` while drawing, as the Plugins page does, because `__()` may not run where a module loads. `nav-main.tsx` changed one import line (`Plug`) besides `SubEntry`. The failure panel's `ErrorState` gets `pt-5`, since it sits in a panel with no header. The catalogue's sample words beyond the plan's list: "This is the :page page.", "Check now", "Finds links on the site that lead nowhere, and posts nothing links to.", the section's title "A plugin’s own area" and its line. The row's action button is `neutral` for Enable too, as P3 says (it was the accent before). `9ce00b3`, `0b3cb5b`, `aa3b81d`.

No STOP. No contract the plan names changed: `packages/teme-contracts` is untouched; `App\Domain\Status\StatusCheck` gained `label()` as P7 says.

### Missing sockets (for the maintainers to file)

- A plugin's own picture: an `icon` field in the manifest, and an install step that sanitizes or rasterizes an SVG and serves it from core (I-3405).
- A plugin's own area as a real screen: a manifest surface for admin pages, and the way a plugin ships its own interface code (I-212).
- A plugin's top-level menu entry: a menu surface with its section, order, badge and the site-wide cap (I-216, I-220).
- A plugin's own label on a status row, for a plugin with several checks, and a row action other than "open its settings".
- And, not a socket: bulk actions on plugins.

### Left open

- B-?? 2: whether the site status panel should open with focus on its heading, so a plugin's mark doesn't show its tooltip at once.
- B-?? 6: a test that proves the boundary's key needs a plugin page that keeps its state between pages.

### Changelog line (at the release)

"The Plugins screen shows each plugin's icon and state at a glance, and what plugins add to the admin is marked as theirs. Every row of the site status panel now says what it is about."

### How to try it

On a local site with a plugin installed: Site › Plugins (each plugin's initials, its state as a pill, the views with ← and →, the 56px icon in Details and access and in the consent dialog); Tools (a plugin's links under From plugins with its mark; on Tools › Jobs, the menu's plug after a plugin's page); the site status button in the top bar (each row starts with its label, a plugin's with its plug); `/admin/_catalogue#plugin-area` (the area's head, seven pages with Exports and Logs under More, "Make this page fail" and Try again).

