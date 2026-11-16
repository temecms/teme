# Plan: plugin sockets, round 2

**Three parts, three branches, built side by side where the work is separate:** A `sockets2/plugin-area` (a plugin's own admin area, drawn by core from data; repeaters and show/hide rules, on settings pages too), B `sockets2/inserts` (inserted content beyond the article body, and administrator-approved inline scripts), C `sockets2/small` (a `custom` verifier without a secret, translated manifest words, schedules that say when they are due). Proposal: `docs/proposals/plugin-sockets-2.md` (its Settled section is binding). Evidence: the Ad Placr port's `FINDINGS.md` (F-08, F-09, F-11, F-15, F-17, F-18, F-19) and `FEATURES.md` (S6, A1, A11 to A14, P6 to P9, R4, T7, T8), and the Ad Inserter checklist in the proposal.

**When each part starts.**

| Part | Branches from | Waits for |
|---|---|---|
| A `sockets2/plugin-area` | `origin/main` | round 1's `sockets/plugin-admin` merged (it reuses that part's actions, list tables, `ColumnClock` budgets and `group.tsx` panels). A's screen tasks (A-T7, A-T8) also wait for the maintainers' approval of the drawings. A-T9 waits for B merged. |
| B `sockets2/inserts` | `origin/main` | nothing from round 1; its shared-file lines are additive. B-T6 (the Site code panel) waits for the drawing's approval. |
| C `sockets2/small` | `origin/main` | round 1's `sockets/outbound-feeds` (A-W13 handler doors, A-W14 plugin `lang` files) and `sockets/plugin-admin` (B5 `--as` and `ScheduleRunner::problem()`) merged. |

**Bound by:** I-3396 (a wall is a missing socket, fixed in core), I-200 (declared entries core draws, with a budget; a full admin page drawn with core components), I-212 (declared by default; no plugin interface code in this round), I-3401 (a plugin with many pages: one menu entry, navigation inside the page, More), I-216 and I-220 (a plugin's menu entry in its task section, with a site-wide cap; hubs take growth first), I-3405 (plugin icon: initials until it ships one), I-3418 (a plugin adds; it never replaces core's), I-064 and I-228 (insertions; core owns head, body start and body end; the theme says where, the plugin what), I-3127 (named snippets: not this plan), I-3375 (the public CSP: hashes, declared origins, report-only first), I-3353 (a plugin gets only what its capabilities open; faults recorded under the plugin), I-392 (whom work runs as), I-214 and `.ai/rules/contracts.md`, rules 1, 2, 4, 5, 6, 7 and 9, I-087 (no new singleton), I-569 (lighter plan), I-3439 (American English), I-3373 (every word through `__()`), I-797, I-3317.

This plan is lighter (I-569): decisions, names, JSON shapes, contracts, permissions, tests and the delicate code are written out; every other class is described by its file, its job and its public signature, and is the builder's to write in the style of its siblings.

## What exists (read on `public/main` at `9b29abb3`, 2026-11-16)

- **Settings.** `DeclaredSettings::group()` builds `plugin.{code}` from `contributes.settings` (types `text` … `color`, eleven) and ignores every other key, `visible_when` and `visibleWhen` included. `Setting` (contract) already carries `visibleWhen` (`array<string, scalar|list<scalar>>`); theme settings read it (`app/Domain/Design/ThemeSettings.php:86`, validated in `app/Domain/Themes/ManifestValidator.php:460`, earlier keys only) and `resources/js/components/themes/design-field.tsx:12` and `components/seo/who-publishes.tsx:63` evaluate it. `SettingsForm` passes it to the page (`types/settings.ts:38`) but `settings/group.tsx` draws every field. `SettingsPanels::for()` splits only General; every other group is one panel. The schema's settings items allow extra keys, so an undeclared key passes silently (F-17).
- **Plugin admin.** `contributes.admin` is Tools links only (`surface: tools`, `to: settings|https://…`). `PluginAreaPage` (pattern, `components/patterns/plugin-area-page.tsx`) exists with its error boundary and `InPageNav`, proved only in the catalogue. Round 1 B adds `contributes.actions`, `contributes.lists`, `PluginSettingsWriter`, `RunPluginAction` and `QueueOptions`.
- **Lists.** `Teme\Contracts\Lists\Cell` (`text`, `number`, `badge`, `TONES`), `PluginColumns`' cleaning and `ColumnClock` budgets; `components/list/data-table.tsx`, `patterns/list-page.tsx`, `components/dashboard/site-figures.tsx`. No chart component and no chart dependency in `package.json`.
- **Permissions.** No plugin may declare one. `TypePermissions` makes and removes permission rows inside an Operation with the query builder (the precedent for B-free permission rows). Roles: `Roles::LABELS` (administrator, editor, author, contributor).
- **Menu.** `NavigationRegistry` of `NavEntry` (sections `home`, `content`, `site`; `owner`; `ability`; `hub`); Tools and Settings hubs.
- **Insertions.** `InsertionContributor` / `Insertion` / `Position` / `ArticleFacts` (`@since 0.9.0`), drawn by `PlanBuilder` and `DocumentRenderer` on published article pages only; `InsertionCleaner` keeps `<script src>`, `<iframe>`, `<img>`, `<ins>`, `<style>`, `data-*`, and removes inline scripts, `on*` and unsafe addresses; `InsertionOrigins` feeds `script-src`, `frame-src`, `img-src` after `PublicPage` starts the policy; `PageTags::INSERTIONS`; `CoreInsertionsChanged`.
- **Placements.** `Placement` (`head`, `body-start`, `body-end`) and `Placements` (contributors under `teme.document.placements`, plugin, site, core by key), with `// Later (I-697)` for plugin fragments. Site code (`app/Domain/SiteCode/**`) writes there: `site-code.manage`, elevation, security log, `TEME_DISALLOW_SITE_CODE`, `SiteCodePolicy::of()` hashing inline scripts and `on*` as a browser does, `PagePolicy::allowScriptHashes()`.
- **CSP.** `PagePolicy`: `script-src 'self'` plus hashes and declared origins; `connect-src 'self'`; `contributes.csp` is in the schema and refused by `ContributionsValidator` ("read only with the site.output permission, which Teme doesn’t offer yet").
- **Regions and lists.** `<x-teme::region name="…" />` draws a region's published document; the default theme draws one, `after-content`. Listing templates (`archive`, `latest`, `index`, `search`) loop `$page->items` with no hook between items.
- **Routes.** `RouteCollisions::verifyErrors()` accepts `custom` without `secret`, but `RouteVerifier` reads `plugin.{code}.''` and throws (F-15).
- **Schedules.** `DueSchedules`: interval from the last run (`minute` … `week`), the first pass after enable is due at once, a plugin with `consented_by` null is skipped silently (round 1 B5 fixes the silence).

## V, the release in every new `@since` (all parts)

As round 1: in Task 0 run `git tag -l 'v0.9.0*'`. **None:** `V = 0.9.0`. **Any:** `V = 0.10.0`. Written once, never edited. Growing a class marked `@since 0.9.0` (`ArticleFacts`, B6) is allowed only while V is `0.9.0`; otherwise follow the same design through a new class and record a `B-?? (draft)`.

## Shared files (additions only; never edit or remove another part's or round 1's lines)

| File | A adds | B adds | C adds |
|---|---|---|---|
| `packages/teme-contracts/src/Plugins/Capability.php` | none | case `PagesInsert = 'pages.insert'` (last), its docblock sentence | none |
| `packages/teme-contracts/src/Settings/SettingType.php`, `Setting.php` | cases `Code`, `Repeater`; `Setting::code()`, `Setting::repeater()`, `->section()` | none | none |
| `packages/teme-contracts/schema/teme-plugin.schema.json` | `contributes.permissions`, `contributes.area`, `contributes.settingsSections`; settings items `visibleWhen`, `section`, types `code`, `repeater` (with `fields`, `minItems`, `maxItems`, `itemLabel`, `itemTitle`), `scripts` | the `csp` description (now read), `contributes.fixedLabel` | `verify.secret` optional for `custom` |
| `app/Domain/Plugins/Contributions/ContributionsValidator.php`, `Contributions.php` | `permissions`, `area`, `settingsSections`, settings `visibleWhen`/`section`/`code`/`repeater` | `csp` accepted for `content.insert`/`pages.insert` | none |
| `app/Domain/Plugins/Contributions/DeclaredSettings.php` | the new keys and types | `cacheTags` gains `PageTags::PAGE_INSERTIONS` for a plugin that inserts on pages | translated words (C2) |
| `app/Domain/Plugins/Doors/PluginDoors.php`, `Seams/PluginServices.php` (`OFFERED`) | none | `InlineScripts` | none |
| `app/Domain/Plugins/Operations/EnablePlugin.php`, `DisablePlugin.php` | make and keep permission rows (A1) | purge `page-insertions` after commit | none |
| `app/Console/Commands/EnablePluginCommand.php`, `app/Domain/Plugins/PluginWords.php`, `resources/js/pages/plugins/index.tsx` | permission and area lines | `pages.insert` and insertion hosts lines | none |
| `app/Domain/Cache/PageTags.php` | none | `PAGE_INSERTIONS = 'page-insertions'` | none |
| `app/Domain/Security/SecurityEventType.php` | none | three cases (B4) | none |
| `resources/js/pages/settings/group.tsx` | sections, `visibleWhen`, repeater, code | none | none |
| `tests/Unit/Contracts/PluginContractsTest.php` | dataset rows | dataset rows | none |

Where two parts add to one validator, schema or class, the join keeps both. **Do not regenerate** `docs/reference/plugin-manifest.md`, `docs/reference/hooks.md` or `docs/reference/fields.md`; the join does, once.

---

## Part A: a plugin's own area (`sockets2/plugin-area`)

Walls F-11, F-17, S6, A1, A11 to A14, T7, T8.

### Owns

- **New:** `packages/teme-contracts/src/Admin/` (`HasPluginArea`, `PluginArea`, `AreaContext`, `Figure`, `Chart`, `TableQuery`, `RowPage`, `Row`, `Saved`); `app/Domain/Plugins/Area/**` (`DeclaredArea`, `AreaValidator`, `AreaForms` (rules), `VisibleWhen`, `AreaReads` (budgeted reads), `AreaWords`, `PluginPermissions`, `AreaEntries` (menu)); `app/Domain/Plugins/Operations/SavePluginRecord.php`, `ActOnPluginRecords.php` and their inputs; `app/Domain/Plugins/Queries/PluginAreaScreen.php`, `PluginRecordForm.php`; `app/Http/Admin/PluginAreaController.php`; `resources/js/pages/plugins/area.tsx`, `pages/plugins/record.tsx`; `resources/js/components/plugins/area/` (`area-figures.tsx`, `area-chart.tsx`, `area-table.tsx`, `area-form.tsx`, `area-actions.tsx`); `resources/js/components/fields/repeater-field.tsx`, `code-field.tsx`; `resources/js/lib/visible-when.ts`; the fixture plugin `tests/Fixtures/plugins/placard/` (an ad-like area: overview, records table, record form with a repeater and conditions, settings with sections); tests `tests/Feature/Plugins/Area/**`, `tests/Unit/Plugins/VisibleWhenTest.php`, `tests/Browser/Plugins/PluginAreaScreensTest.php`, `tests/Browser/Plugins/PluginSettingsSectionsTest.php`; `docs/guides/developers/plugins/admin-area.md`.
- **Changed:** `app/Domain/Settings/Operations/UpdateSettings.php` (hidden settings untouched, A4); `app/Domain/Settings/Queries/SettingsPanels.php` (plugin sections); `app/Domain/Settings/SettingRules.php` (code and repeater rules); `app/Domain/Navigation/NavigationRegistry.php` or its provider (area entries, A6); `routes/admin.php` (the area routes, one block); `resources/js/types/settings.ts`, `types/plugins.ts`; `docs/guides/developers/plugins/settings.md`; the shared files above.
- **Not owned:** B and C; round 1's files except the additive lines named; `PluginAreaPage` itself (used as it is; a needed change is a `B-?? (draft)`).

### Decisions

**A1. Plugin permissions.** Manifest `contributes.permissions`, at most 3:

```json
"permissions": [
  { "id": "manage", "label": "Manage ads", "description": "Create, change and switch ads, and see their statistics.", "roles": ["editor"] }
]
```

- `id` `^[a-z][a-z0-9-]{1,23}$`; the permission's name is `plugin.{code}.{id}`. `label` 1 to 40 characters, `description` up to 160, `roles` a subset of `editor`, `author`, `contributor` (administrators always hold it; never listed).
- **Made by `EnablePlugin`**, inside its transaction, with the query builder as `TypePermissions` does (`PluginPermissions::ensure(code, declared)`), and given to administrators and the listed roles when the row is new. A later role change is a person's (a Roles screen, not this plan); an update never re-grants a role someone took away. `DisablePlugin` keeps the rows (inert while disabled); `teme:plugins:remove-data` deletes them. Spatie's cache is reset after commit.
- **Consent** lists each: `Lets editors :label` (or `Administrators only: :label`). An update that adds a permission or a role waits for consent (`Consent::covers()` false).

**A2. The area, declared.** Manifest `contributes.area` (one per plugin), needs at least one permission:

```json
"area": {
  "label": "Ads",
  "description": "Ads placed in articles and around the site, and how they do.",
  "permission": "manage",
  "menu": "site",
  "purges": ["insertions", "page-insertions"],
  "pages": [
    { "id": "overview", "label": "Overview", "blocks": [
      { "type": "figures", "id": "totals", "items": [
        { "id": "impressions", "label": "Impressions, last 30 days", "format": "number" },
        { "id": "ctr", "label": "Click rate", "format": "percent" } ] },
      { "type": "chart", "id": "daily", "label": "By day, last 30 days", "kind": "line",
        "series": [ { "id": "impressions", "label": "Impressions" }, { "id": "clicks", "label": "Clicks" } ] },
      { "type": "actions", "id": "upkeep", "actions": ["prune-now"] } ] },
    { "id": "ads", "label": "Ads", "blocks": [
      { "type": "table", "id": "ads", "record": "ad",
        "columns": [ { "id": "name", "label": "Name", "kind": "text" }, { "id": "status", "label": "Status", "kind": "badge" },
                     { "id": "impressions", "label": "Impressions", "kind": "number", "sortable": true } ],
        "search": true, "filters": [ { "id": "status", "label": "Status", "options": { "active": "Active", "paused": "Paused" } } ],
        "perPage": 20, "rowActions": ["edit", "duplicate", "enable", "disable", "delete"], "bulkActions": ["enable", "disable", "delete"] } ] }
  ],
  "records": [
    { "id": "ad", "label": "Ad", "plural": "Ads", "titleField": "name", "sections": [
      { "id": "basics", "label": "Basics", "fields": [
        { "key": "name", "type": "text", "label": "Name", "required": true, "maxLength": 120 },
        { "key": "location", "type": "select", "label": "Where", "options": { "after_paragraph": "After a paragraph", "between_items": "Between items on lists" } },
        { "key": "paragraph", "type": "number", "label": "Paragraph", "min": 1, "max": 50, "visibleWhen": { "location": "after_paragraph" } } ] },
      { "id": "versions", "label": "Versions", "fields": [
        { "key": "versions", "type": "repeater", "label": "Versions", "itemLabel": "Version", "itemTitle": "label", "minItems": 1, "maxItems": 10, "fields": [
          { "key": "label", "type": "text", "label": "Label", "maxLength": 40 },
          { "key": "code", "type": "code", "label": "Ad code", "scripts": true },
          { "key": "weight", "type": "number", "label": "Weight", "min": 1, "max": 100, "default": 1 } ] } ] },
      { "id": "rules", "label": "Where it shows", "fields": [
        { "key": "types", "type": "types", "label": "Content types" },
        { "key": "terms", "type": "terms", "taxonomy": "category", "label": "Categories" },
        { "key": "region", "type": "region", "label": "Theme region", "visibleWhen": { "location": "region" } } ] } ] }
  ]
}
```

- **Blocks**, at most 8 a page, 8 pages, 3 records: `figures` (1 to 4 items, `format` `number` | `percent` | `text`), `chart` (`kind` `line` | `bar`, 1 to 3 series), `table` (a record's list, below), `actions` (ids of round 1's `contributes.actions`, at most 3), `notice` (`text` up to 300 characters, `tone` from `Cell::TONES`). A record's form is its `sections` (1 to 8, each 1 to 30 fields, 60 fields a form in all, repeater sub-fields counted).
- **Field types:** the settings types (`text`, `textarea`, `number`, `boolean`, `select`, `multiselect`, `email`, `url`, `color`) and five more: `code` (markup, monospace, up to 65,535 characters; `scripts: true` sends its inline scripts for approval, A-T9), `datetime` (stored as ISO 8601 in the site's time zone), `types` (content-type keys, core's list), `terms` (term IDs of the named taxonomy, core's picker), `region` (a slug of the active theme's regions), and `repeater` (below). `url` here is a value only; it never widens what the plugin may reach (round 1 A2 governs outbound hosts).
- **Repeater:** `fields` from every type above but `repeater` (one level), `minItems` 0 to 50, `maxItems` 1 to 50 (default 20), `itemLabel` (the Add button: `Add :item`), `itemTitle` (a sub-field key whose value titles a collapsed item). Items can be added, removed, moved (buttons, and dragging where dnd-kit is installed; the buttons are the single-pointer path, I-1720), collapsed and expanded. The value is a list of objects keyed by sub-field.
- **`visibleWhen`**, on any field or sub-field: `{ key: value | [values] }`, keys naming **earlier** fields of the same level (a sub-field names its siblings), every key must match (AND), a list matches any (OR). The same shape and rule as theme settings (`ThemeSettings`, `design-field.tsx`); Task 0 records that rule exactly and A follows it.
- **`menu`**: `content` or `site` (the task section, I-216) or `tools` (a Tools hub card, I-3401's smaller plugin). **At most 5 plugin menu entries site-wide**; a sixth area's entry goes to the Tools hub, in enable order, never refused (I-3396: budgets order, they don't refuse). The entry's ability is `plugin.{code}.{permission}`; its icon is `PluginIcon` initials (I-3405).
- **`purges`**: a closed list, `insertions` and `page-insertions` (B); after a write that changed something, core purges these tags after commit.
- Every string a person reads in the area goes through `AreaWords::text(code, string)`, which returns it unchanged in A; the join points it at C2's translator.

**A3. The contract.** In `Teme\Contracts\Admin`, `@since V`, no framework type:

```php
/** A plugin whose manifest declares an area. @since V */
interface HasPluginArea
{
    /** @since V */
    public function pluginArea(): PluginArea;
}

/** The data behind a plugin's area, and its writes. Core calls it; the plugin never draws. @since V */
interface PluginArea
{
    /** @return array<string, Figure> by declared item id; a missing item shows "—". @since V */
    public function figures(string $page, string $block, AreaContext $context): array;

    /** @since V */
    public function chart(string $page, string $block, AreaContext $context): Chart;

    /** One page of a record's rows, as the query asks. @since V */
    public function rows(string $record, TableQuery $query, AreaContext $context): RowPage;

    /**
     * A record's stored values by field key, or null when there is no such record.
     *
     * @return array<string, mixed>|null
     *
     * @since V
     */
    public function values(string $record, string $id, AreaContext $context): ?array;

    /**
     * Stores a record core has already checked, inside core's transaction.
     *
     * @param  array<string, mixed>  $values  only fields that are shown; a hidden field is left out and must be left as it is
     *
     * @throws \Teme\Contracts\Content\WriteRefused  with one plain sentence; nothing is kept
     *
     * @since V
     */
    public function save(string $record, ?string $id, array $values, AreaContext $context): Saved;

    /**
     * Runs a built-in row action on these records: duplicate, enable, disable or delete.
     *
     * @param  list<string>  $ids
     *
     * @throws \Teme\Contracts\Content\WriteRefused
     *
     * @since V
     */
    public function act(string $record, string $action, array $ids, AreaContext $context): Saved;
}
```

Value classes, each `final readonly`, `@since V`: `AreaContext(string $personId, string $locale, string $timezone)`; `Figure` (`::number(int|float $value, ?float $change = null)`, `::percent(float $ratio, ?float $change = null)`, `::text(string)`; `$change` is a ratio shown as an up or down arrow and a percentage); `Chart(list<string> $labels, array<string, list<int|float>> $series)` (labels are `Y-m-d` dates or category words; at most 366 labels; every series as long as the labels; unknown series ids dropped); `TableQuery(int $page, int $perPage, string $search, array<string, string> $filters, ?string $sort, string $direction)`; `RowPage(list<Row> $rows, ?int $total)`; `Row(string $id, array<string, Cell> $cells, list<string> $unavailable = [])` (`$unavailable`: action ids not offered on this row, so Enable hides on an active ad); `Saved(string $id, bool $changed = true)`. Every constructor checks its own bounds and throws `InvalidArgumentException`.

**A4. Show/hide on settings pages (F-17), and sections.** `DeclaredSettings` reads `visibleWhen` (validated as theme settings are: earlier keys, values among a select's options) and `section` (an id of `contributes.settingsSections`, each `{ id, label, description? }`, at most 8). `SettingsPanels::for()` returns a plugin group's sections as panels, in declared order, settings without a section in a first untitled panel. **Hidden is untouched:** `UpdateSettings`, for a plugin group, evaluates `VisibleWhen` against the values as they will be saved and leaves out every hidden setting (neither validated nor changed). Task 0 records what core groups with `visibleWhen` do today (WhoPublishes); if core already has one rule, A follows it for plugins and records which.

**A5. Repeater and code as settings (F-11's smaller answer).** `SettingType::Code` and `SettingType::Repeater` (cases last), `Setting::code(...)` and `Setting::repeater(string $key, string $label, string $description, string $since, list<Setting> $fields)` with `->min()`/`->max()` meaning item counts, and `Setting::section(string $id)`. A repeater setting is stored as a JSON list in the setting's value (rule 4: no `serialize()`); its sub-fields are the scalar types and `code`, never `secret`, `url`, `urls`, `media`, `person` or another repeater. `SettingRules` validates each item. Round 1's `WritePluginSettings` refuses `code` (a plugin can't write code that asks for scripts) and accepts `repeater` only when no sub-field is `code`.

**A6. The screens' plumbing.** Routes, in `routes/admin.php`, one block, `auth` and the admin middleware:

| Method | Path | Name | Does |
|---|---|---|---|
| GET | `admin/plugin/{code}/{page?}` | `plugins.area.show` | `PluginAreaScreen` |
| GET | `admin/plugin/{code}/records/{record}/new` | `plugins.area.new` | `PluginRecordForm` |
| GET | `admin/plugin/{code}/records/{record}/{id}` | `plugins.area.edit` | `PluginRecordForm` |
| POST | `admin/plugin/{code}/records/{record}` and `…/{id}` | `plugins.area.save` | `SavePluginRecord` |
| POST | `admin/plugin/{code}/records/{record}/act` | `plugins.area.act` | `ActOnPluginRecords` |

Actions blocks post to round 1's `plugins.actions.run`, authorized by the area's permission when the action is shown in an area (round 1's `RunPluginAction` gains `?string $permission = null`, last, additive). Each block's data is an Inertia **deferred prop** with a skeleton (the Inertia v3 rule), so one slow block never holds the page.

**A7. Reads, under budgets.** `AreaReads` calls the plugin with `ColumnClock`: 250 ms a call, 1,000 ms a page; over it, or a throw, the block draws `This part of :plugin couldn’t be shown this time.` and the fault is recorded through `ListenerFaults` (`{name}#area`). Cells are cleaned by `PluginColumns`' rules; figure text cut to 40, chart labels to 24; numbers must be finite. `rows()` gets `perPage` at most the declared (≤ 100) and `page` at most 1,000; `search` is cut to 100 characters. Values read for a form are filtered to declared keys and types; a value of the wrong type shows empty and is recorded once as a fault.

**A8. Writes, as Operations (rule 1).** `SavePluginRecord` (input: code, record, `?id`, values, actorId):
- **authorize:** the person holds `plugin.{code}.{permission}`; the plugin is enabled and its class implements `HasPluginArea`; the record is declared.
- **validate:** `AreaForms::rules()` from the declaration (types, required, bounds, options, `types`/`terms`/`region` against core's current lists, repeater counts, `datetime` parsing); hidden fields (`VisibleWhen`, evaluated in declared order, a hidden field counting as empty for later conditions) are dropped and not validated.
- **perform:** call `save()` inside the Operation's transaction, timed (2,000 ms); `WriteRefused` becomes a form error at the top and the transaction rolls back; any other throw or the budget rolls back, is recorded through `ListenerFaults`, and answers `:plugin couldn’t save this. Nothing was changed.` Nothing the plugin wrote survives a refusal (its tables are in the same connection and transaction; Task 0 proves it).
- **after commit:** when `Saved::$changed`, purge the area's `purges` tags.
`ActOnPluginRecords` (input: code, record, action, ids (1 to 100), actorId): the same authorize; the action must be declared in the table's `rowActions` or `bulkActions`; `delete` requires the form's `confirmed: true` (the dialog), never elevation; perform calls `act()` as above. Each refusal writes nothing. No plugin write ever runs as the operator or with elevation.

**A9. What core draws.** `area.tsx` is `PluginAreaPage` with the area's pages as `InPageNav` (More beyond 5, as the pattern does), blocks top to bottom: `figures` as `site-figures`' cards; `chart` as a new `area-chart.tsx`, an inline SVG line or bar chart drawn by core (axis labels, a legend, a visually hidden table of the same numbers for screen readers; no chart dependency, open question 6); `table` as `data-table.tsx` (search, filters, sortable declared columns, paging, a checkbox column when bulk actions exist, row actions in the row's menu, "Add :item" when the record has a form, empty state `No :plural yet.`); `actions` as round 1's buttons; `notice` as `Notice`. `record.tsx` is a `SettingsPage`-shaped form: sections as `SettingsPanel`s, the side list with "Find a setting" when there are more than 3 sections (v10 `settings-imgverse`), one Save bar, the leave guard. Every value arrives as text; never `dangerouslySetInnerHTML`.

### The delicate code: `VisibleWhen` (server; `lib/visible-when.ts` mirrors it line for line)

```php
/** @param array<string, scalar|list<scalar>> $when  @param array<string, mixed> $values */
public static function shows(array $when, array $values): bool
{
    foreach ($when as $key => $wanted) {
        $have = self::flat($values[$key] ?? null);
        $want = self::flat($wanted);
        if (array_intersect($want, $have) === []) {
            return false;                       // every key must match (AND)
        }
    }

    return true;                                // a list matches any of its values (OR)
}

/** @return list<string> */
private static function flat(mixed $value): array
{
    $list = is_array($value) ? array_values($value) : [$value];

    return array_map(fn (mixed $one): string => match (true) {
        is_bool($one) => $one ? 'true' : 'false',
        $one === null => '',
        default => (string) $one,
    }, array_filter($list, fn (mixed $one): bool => is_scalar($one) || $one === null));
}

/** Drops hidden fields in declared order; a hidden field counts as empty for the fields after it. */
public static function visibleValues(array $fields, array $values): array
{
    $kept = [];
    foreach ($fields as $field) {
        if (self::shows($field['visibleWhen'] ?? [], $kept)) {
            $kept[$field['key']] = $values[$field['key']] ?? null;
        }
    }

    return $kept;
}
```

If Task 0 finds `design-field.tsx` compares otherwise (for example, booleans as `true`/`false` values), both copies follow it and `VisibleWhenTest`'s dataset records it.

### Tasks (tests first; each seen red for the reason given)

0. **Checks.** Record: V; `git log --oneline -1`; that round 1's `sockets/plugin-admin` is on `main` (`git log --oneline -1 -- app/Domain/Plugins/Operations/RunPluginAction.php`; **if not, STOP**); the `visibleWhen` rule in `design-field.tsx` and `ThemeSettings`; what `UpdateSettings` does with WhoPublishes' hidden fields; that a plugin's `DB::table()` write inside an Operation rolls back with it; whether `@dnd-kit/*` is installed; the tags `site-figures.tsx` and `data-table.tsx` accept. A difference that changes a decision is a STOP.
1. **Permissions.** `PluginPermissionsTest` with `placard`: enabling makes `plugin.placard.manage`, held by administrators and editors; disable keeps it; remove-data deletes it; an update adding a role waits for consent; the manifest refusals (more than 3, a bad id, an unknown role, an area naming no declared permission). Red.
2. **Show/hide and sections on settings pages.** `VisibleWhenTest` (dataset: scalars, lists, booleans, empties, chains); `PluginSettingsSectionsTest` (feature): the page's props carry sections as panels and each field's `visibleWhen`; saving with a hidden field leaves its stored value as it was and doesn't validate it; the manifest refusals (a later key, an unknown option, an unknown section). Red: F-17's behavior.
3. **Repeater and code settings.** `RepeaterSettingTest`: a repeater stores a JSON list; item count bounds; a sub-field's rules per item; a `code` value up to 65,535 characters; refused sub-field types; round 1's `WritePluginSettings` refuses `code`; nothing written on refusal. Red.
4. **The area declared.** `DeclaredAreaTest`: the manifest refusals for every bound in A2, with one plain sentence each; a valid area builds; the schema accepts it. Red.
5. **Reads.** `AreaReadsTest`: figures, chart and rows reach the props cleaned; slow (stepping clock) and throwing calls give the failure line and a recorded fault; per-page budget; markup arrives as text; a person without the permission gets 403 and the plugin is never called. Red.
6. **Writes.** `SavePluginRecordTest`, `ActOnPluginRecordsTest`: a holder saves; hidden fields never reach `save()`; `WriteRefused` shows its sentence and the plugin's own table row is gone (rolled back); a slow `save()` rolls back and is recorded; purges after commit only, not on rollback; refused **writing nothing**: no permission, disabled plugin, undeclared record or action, a value the rules refuse, `delete` unconfirmed, more than 100 ids. Red.
7. **The screens.** Only after the maintainers approve the drawings (proposal, "The screens to draw"); until then, STOP before this task. `area.tsx`, `record.tsx`, the block components, `repeater-field.tsx`, `code-field.tsx`, `lib/visible-when.ts`, and `group.tsx`'s sections, conditions and repeater. Menu entries (A2 `menu`, the cap of 5). `PluginAreaScreensTest` (browser, 1280 and 375): the entry opens the area; pages switch in-page; figures, chart (and its hidden table), table search, filter, sort, paging, row menu, bulk enable; Add opens the form; a condition shows and hides a field; a repeater adds, moves, collapses and removes an item; save shows the toast; delete asks first; axe in light and dark. `PluginSettingsSectionsTest` (browser): sections and conditions on a plugin's settings page.
8. **Menu budget.** `AreaEntriesTest`: five areas get menu entries in their sections, the sixth a Tools card; a person without the permission sees neither. Red.
9. **Scripts in code fields** (after B is merged; rebase first). `SavePluginRecord` and `UpdateSettings` (plugin groups) pass every `code` value declared `scripts: true` to B's `ScriptRequests::fromCode(owner, name, code, actorId)` inside their transaction; the form shows `:count script waits for an administrator’s approval in Settings › Site code.` under the field. `CodeFieldScriptsTest`: a saved code field with one inline script makes one waiting request; saving it again makes none; a refused save makes none. Red.
10. **Handbook and records.** `plugins/admin-area.md` (the area, blocks, fields, permissions, the contract, budgets, what core draws), `plugins/settings.md` (sections, `visibleWhen`, repeater, code); Records; the gate.

### Quick check

The builders' gate after each task, with `php artisan test --parallel --compact` on `tests/Feature/Plugins/Area`, `tests/Feature/Plugins`, `tests/Feature/Settings`, `tests/Unit/Plugins/VisibleWhenTest.php` and `tests/Unit/Contracts`. Once at the end: `composer check`, and `composer check:browser` for the two new browser files and the Settings and Plugins screens' existing browser tests.

### Records (the builder's)

#### Commits on `sockets2/plugin-area`

- `5a44902a` feat(plugins): a plugin declares up to three permissions of its own (Task 1)
- `a358703f` feat(plugins): sections and conditions on a plugin's settings page (Task 2)
- `c469eba1` feat(settings): repeater and code settings, a plugin's included (Task 3)
- `dce49ad3` feat(plugins): a plugin's area, declared in its manifest and read once (Task 4)
- `139d1c90` feat(plugins): a plugin's area, its pages, record forms and writes (Tasks 5 and 6)
- `8254c1d8` feat(settings): draw a plugin's sections, conditions, repeaters, actions and lists (Task 7, settings screens)
- `02bd537e` feat(plugins): a plugin's area in the menu, five at most, the rest on Tools (Task 8, and Task 7's area browser test)
- `ffeec8e5` feat(plugins): a code field's inline scripts wait for approval on the form (Task 9)
- `09b8a5b3` docs(handbook): a plugin's own area, and settings sections, conditions, lists and markup (Task 10)
- `4c621921` fix(types): reserve the plugin segment a plugin's area lives under (the gate)

#### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|
| 1 | `tests/Feature/Plugins/Area/PluginPermissionsTest.php` | Teme doesn’t offer the permissions surface to plugins yet. |
| 2 | `tests/Unit/Plugins/VisibleWhenTest.php` | Class "App\Domain\Plugins\Area\VisibleWhen" not found |
| 2 | `tests/Feature/Plugins/Area/PluginSettingsSectionsTest.php` | Property [panels] does not match the expected value. |
| 3 | `tests/Feature/Plugins/Area/RepeaterSettingTest.php` | Its settings can’t be used: Setting [plugin.settings.fallback] has an unknown type [code]. |
| 4 | `tests/Feature/Plugins/Area/DeclaredAreaTest.php` | Class "App\Domain\Plugins\Area\DeclaredArea" not found |
| 5 | `tests/Feature/Plugins/Area/AreaReadsTest.php` | Interface "Teme\Contracts\Admin\HasPluginArea" not found |
| 6 | `tests/Feature/Plugins/Area/SavePluginRecordTest.php` | Target class [App\Domain\Plugins\Operations\SavePluginRecord] does not exist. |
| 6 | `tests/Feature/Plugins/Area/ActOnPluginRecordsTest.php` | Target class [App\Domain\Plugins\Operations\ActOnPluginRecords] does not exist. |
| 6 | `tests/Feature/Plugins/Area/AreaActionsTest.php` | Inertia Flash Data [toast] does not match expected value. |
| 7 | `tests/Browser/Plugins/PluginSettingsSectionsTest.php` | Timeout 5000ms exceeded. (the repeater's sub-fields weren't sent) |
| 7 | `tests/Browser/Plugins/PluginAreaScreensTest.php` | axe: aria-hidden-focus, empty-table-header |
| 8 | `tests/Feature/Plugins/Area/AreaEntriesTest.php` | Failed asserting that two arrays are equal. |
| 9 | `tests/Feature/Plugins/Area/CodeFieldScriptsTest.php` | Failed asserting that two arrays are identical. (no request made); Route [plugins.area.script] not defined. |

#### Task 0

- V is 0.9.0; built on `main` at `9f27e413` (PR #380), as the revised go said; round 1's `RunPluginAction` is on `main`.
- `@dnd-kit/*` is not installed: items move with Move up and Move down (decided Q7).
- `design-field.tsx` compares strictly; `ThemeSettings` names earlier keys and scalar values. `VisibleWhen` follows this plan's rule, which also matches `"3"` with `3`.
- `UpdateSettings` validated whatever was sent; WhoPublishes' hidden fields aren't drawn, so aren't sent.
- A plugin's `DB::table()` write inside an Operation rolls back with it (SavePluginRecordTest).

#### Architecture baselines

| Baseline | Before | After |
|---|---|---|
| design-system-baseline.txt | 222 | 222 |
| admin-words-baseline.txt | 876 | 876 |

#### Gate

- Base (`9f27e413`): Pint and PHPStan clean, Architecture 139 passed; Unit and Feature 12,530 passed, 3 skipped, 1 failed (FirstPluginTest, flaky in parallel).
- Branch: Pint and PHPStan clean, Architecture 139 passed; Unit and Feature 12,673 passed, 3 skipped, 3 failed, in 1,840 s. `TypeRoutingTest` was fixed in `4c621921` (380 passed after). `GenerateManifestReferenceTest` and `GenerateContractsReferenceTest` wait for the join, which regenerates `docs/reference/` once, as this plan says.
- Browser, one file at a time: `tests/Browser/Plugins` (all six files, 48 tests) and the Settings screens' files (SettingControls, SettingsPanels, SettingsPlacements, SettingsSaveBar, SiteCodeSettings, WhoPublishesSettings, MenuAndSettings; 63 tests), all passed.

#### B-?? (draft)

- **The validator lands whole in Task 1.** `AreaValidator` (permissions, area, settings sections and conditions) came with Task 1, because the fixture's manifest needs it to enable. `5a44902a`.
- **Consent lines.** Permissions are kept in the consent as `id` and `id:role`; taking a role away doesn't ask again. `5a44902a`.
- **Markup in cells.** A plugin's markup reaches the page as its characters, drawn as text (as round 1's lists), never stripped. `139d1c90`.
- **The chart's hidden table** is a table by ARIA roles, because R5 keeps raw tables in `ui/` and patterns. `139d1c90`.
- **Frame props** are `tabs` and `current`, not `pages` and `page`, because NoTypeLiteralsTest keeps a type's name off keys. `139d1c90`.
- **Charts** use recharts 3.10.1 inside core's `area-chart.tsx` (decided Q6 overrides A9). npm also dropped a stale `@rollup/rollup-win32-x64-msvc` line from the lock's root dependencies. `139d1c90`.
- **An empty repeater** still checks its `minItems`. `139d1c90`.
- **Area actions.** Options: round 1's `plugins.manage` only, or the area's permission too. Chosen: `RunPluginActionInput` gains `?string $permission` (last, null by default) and `RunPluginAction` allows either, passed only for actions the area's pages offer, so an editor holding the area's permission can run them. `139d1c90`.
- **The record form** uses `FormPage` and `FormPanel`, with the delete dialog inside (R7), not `SettingsPage` as A9 words it; `SectionTabs` for more than three sections. `139d1c90`.
- **A hidden setting** sits in a disabled, hidden fieldset (only settings with `visibleWhen`), so the browser neither checks nor sends it. `8254c1d8`.
- **Round 1's plugin lists** are drawn with core's `DataTable`. `8254c1d8`.
- **SettingsForm** sends a repeater's `fields` and `itemTitle`, for repeaters only. `8254c1d8`.
- **Menu entries** are merged in `AdminNavigation` at query time (as type entries are), not registered in `NavigationRegistry`; Tools cards are added in `ToolsHub::pagesFor` before its plugins.manage gate. The icon is `plug`. `02bd537e`.
- **Screens** were built from the approved drawing, with I-3468: the menu entry and the area's heading show the plugin's own name; the top bar names only the parents. `139d1c90`, `02bd537e`.
- **Task 9 on main, not after B.** The revised go built Task 9 on `main` without merging `sockets2/inserts`; B's `ScriptRequests::fromMarkup()` (not `fromCode()`) and `ApprovePluginScript` are on `main`. `ffeec8e5`.
- **Approving on the form (decided Q2).** Options: wait for B's site-code routes, or a route of A's own. Chosen: `plugins.area.script` in the area block, running B's `ApprovePluginScript` (site-code permission and the password, asked in place with a 423). Under each code field the form shows each waiting script: Approve script for a person who may change site code, Waiting for approval for anyone else. This replaces A-T9's count line. `ffeec8e5`.
- **Who asks.** Only a plugin enabled with `content.insert` or `pages.insert` asks (B's `RequestPluginScript` rule); the fixture gains `content.insert`. `ffeec8e5`.
- **Settings' code fields** ask on save, but the settings page doesn't draw the waiting scripts yet: that needs one line in `SettingsController`, outside A's Owns. Settings › Site code (B) lists them. Left for the join. `ffeec8e5`.
- **The notice to approvers** (decided Q2) needs a notice kind in `BuiltInNotices`, outside A's Owns. Left for the join. `ffeec8e5`.
- **The `plugin` segment.** P4's `admin/plugin/{code}` needs `plugin` in `ContentTypeRegistry::RESERVED` (TypeRoutingTest), outside A's Owns. Options: move the area under `plugins/`, or reserve the word. Chosen: reserve it, one word, since P4 decided the path. `4c621921`.

#### Contract changes

- New in `Teme\Contracts\Admin`, `@since 0.9.0`: `HasPluginArea`, `PluginArea`, `AreaContext`, `Figure`, `Chart`, `TableQuery`, `RowPage`, `Row`, `Saved`.
- `Teme\Contracts\Settings`: `SettingType::Code`, `SettingType::Repeater`; `Setting::code()`, `Setting::repeater()`, `->section()`.
- The manifest schema: `contributes.permissions`, `contributes.area`, `contributes.settingsSections`; settings `visibleWhen`, `section`, `scripts`, types `code` and `repeater`.

#### STOP

- None.

#### Changelog lines for the maintainers

- A plugin can have screens of its own in the admin: an overview of figures and charts, tables of its records, and a form for each, all drawn by Teme.
- A plugin can declare up to three permissions of its own, and give them to editors, authors or contributors.
- A plugin's settings page can be split into sections, show a setting only when another has a value, and hold lists and markup.
- An inline script in a plugin's code field waits for approval, and a person who may change site code approves it on the plugin's own form.

#### Try it

- Enable the `tests/placard` fixture, then open **Placard** in the Site menu as an editor: the overview, the Ads table, and an ad's form with its versions.
- Save an ad whose code holds `<script>…</script>`, then open it as an administrator and approve the script there.

---

## Part B: inserted content beyond the article, and approved inline scripts (`sockets2/inserts`)

Walls F-08, F-18 (2), P6 to P9, R4.

### Owns

- **New:** `packages/teme-contracts/src/Insertions/PageInsertionContributor.php`, `PageInsertion.php`, `PagePosition.php`, `PageFacts.php`; `packages/teme-contracts/src/Insertions/InlineScripts.php`; `app/Domain/Site/Insertions/PageInsertions.php`, `ListingPlan.php`, `HeadCleaner.php`, `FixedInsertions.php`, `PluginPlacements.php` (a `PlacementContributor`); `app/Domain/Site/Insertions/Scripts/**` (`ScriptRequests`, `ApprovedScripts`, model `PluginScript`); `app/Domain/Site/Insertions/Operations/RequestPluginScript.php`, `ApprovePluginScript.php`, `RefusePluginScript.php`, `RevokePluginScript.php` and inputs; one migration `create_plugin_scripts_table`; `app/View/Components/Teme/BetweenItems.php` and its view; `resources/views/components/fixed-insertions.blade.php`; `resources/js/components/site-code/plugin-scripts-panel.tsx`; the fixture plugin `tests/Fixtures/plugins/billboard/`; tests `tests/Feature/Site/Insertions/PageInsertionsTest.php`, `HeadInsertionsTest.php`, `BetweenItemsTest.php`, `RegionInsertionsTest.php`, `FixedInsertionsTest.php`, `InsertionHostsTest.php`, `PluginScriptsTest.php`, `PageInsertionCacheTest.php`; `tests/Browser/Site/FixedInsertionsTest.php`, `tests/Browser/SiteCode/PluginScriptsPanelTest.php`; `docs/guides/developers/plugins/page-insertions.md`.
- **Changed:** `packages/teme-contracts/src/Insertions/ArticleFacts.php` (last param, B6); `app/Domain/Site/Insertions/InsertionCleaner.php` (approved scripts kept), `InsertionOrigins.php` (hashes and declared hosts), `InsertionPoints.php` (facts' path), `CoreInsertionsChanged.php` (both tags); `app/Domain/Site/Document/Placements.php` (the `Later (I-697)` line done); `app/Domain/Site/ListingViews.php`, `LatestViews.php` and the search view (the listing plan); `app/View/Components/Teme/Region.php` and its view (before and after); the document shell's body end (fixed insertions); `app/Domain/SiteCode/Queries/SiteCodeScreen.php` and `resources/js/pages/settings/site-code.tsx` (the panel); `app/Http/Admin/Settings/SiteCodeController.php` (three actions) and `routes/admin.php` (three routes in the site-code block); `themes/default/views/templates/archive.blade.php`, `latest.blade.php`, `index.blade.php`, `search.blade.php` (one line each); `docs/guides/developers/plugins/insertions.md` (cache truths, cleaner list, `notify()` not needed for settings); the shared files above.
- **Not owned:** A and C; the editor; feeds; Site code's own entries.

### Decisions

**B1. Inserting on every page.** Capability `pages.insert` (`Capability::PagesInsert`), consent words `Add content to every page of your site: in the head, at the top and bottom of the page, between items on lists, in the theme’s regions, and fixed to the screen’s edge`. Contract, `@since V`:

```php
/** Adds markup to public pages at declared places. @since V */
interface PageInsertionContributor
{
    /** @return list<PageInsertion>  @since V */
    public function pageInsertions(PageFacts $page): array;
}

/** @since V */
final readonly class PageInsertion
{
    /** @since V */
    public function __construct(public string $key, public PagePosition $at, public string $html) {}   // key as Insertion::KEY_PATTERN
}

/** Where on the page: head, body start or end, between list items, a theme region, or fixed to an edge. @since V */
final class PagePosition
{
    public static function head(): self;                                   // @since V
    public static function bodyStart(): self;                              // after the skip link, above the header
    public static function bodyEnd(): self;                                // before core's scripts
    public static function afterItem(int $n): self;                        // after the nth item of a list (1 to 100)
    public static function everyItems(int $n, int $max = 3): self;         // after every nth item, at most $max (≤ 5)
    public static function region(string $slug, string $where = 'after'): self;  // before or after a theme region
    public static function fixed(string $edge, int $width = 0): self;      // bottom, top, left, right; rails 160 or 300 wide
}

/** What a page is, so a contributor can decide. Never anything about the visitor. @since V */
final readonly class PageFacts
{
    /**
     * @param  'article'|'home'|'latest'|'archive'|'term'|'author'|'date'|'search'  $kind
     * @param  array<string, list<string>>  $termIdsByTaxonomy  an article's terms, or a term archive's own term
     * @param  list<string>  $authorIds
     * @since V
     */
    public function __construct(
        public string $kind, public string $path, public string $language, public ?string $type,
        public ?string $contentId, public array $termIdsByTaxonomy, public array $authorIds, public int $pageNumber,
    ) {}
}
```

`$path` is the page's path, relative, starting with `/` and with no query (rule 9; a query never reaches a cached page). Asked on public HTML pages only: not on the error, not-found, password, preview, feed, API, `.md` or root-file answers, and not on an item with "No inserted content" on. Contributors are found as `InsertionPoints` finds them (started plugins, through `LoadedPlugins`, whose record grants `pages.insert`; no singleton), timed by `ColumnClock` (100 ms each, 250 ms a page), faults recorded through `ListenerFaults` (`{name}#page-insertions`). One key once, first by plugin code order.

**B2. Where each position is drawn.**
- `head`: through `PluginPlacements` (precedence plugin) into `Placement::Head`, cleaned by `HeadCleaner`, which keeps only `<script src>` with `async`, `defer`, `crossorigin`, `type="module"` or no type, `<meta name content>` (never `robots`, `googlebot`, `description`, `viewport`, `referrer`, `theme-color`, `color-scheme`, `generator`, `charset` or `http-equiv`), `<link rel="preconnect|dns-prefetch">` to https, and approved inline scripts (B4). Everything else is dropped.
- `bodyStart`, `bodyEnd`: through `PluginPlacements`, cleaned by `InsertionCleaner`.
- `afterItem`, `everyItems`: by `<x-teme::between-items :after="$loop->iteration" :last="$loop->last" />`, which a theme puts inside its list loop; nothing after the last item. The default theme's four listing templates get the line. A theme without it draws none; `teme:theme:check` notes `Lists don’t call x-teme::between-items, so plugins can’t place content between items.` (a note, not a failure).
- `region`: drawn by `<x-teme::region>` before or after the region's own document, **also when the region is empty**, on pages whose template draws that region; the slug must be one the active theme declares, else nothing.
- `fixed`: drawn once at body end by `FixedInsertions`: `<aside class="teme-fixed" data-edge="bottom" aria-label="…">`, the plugin's cleaned HTML, and a close button (`Close`, an icon button with that name). One per edge per page, first by plugin code order. Bottom and top: full width, at most `min(30vh, 250px)` high, the page gets matching `padding-block` so nothing is covered at the end. Left and right rails: `width` 160 or 300, shown only at a viewport at least `content + 2 × (width + 48)` pixels wide (from the theme's `layout.content`), else not drawn by CSS. No animation; dismissed for the browser tab's session in `sessionStorage` by core's own small script, hashed as core's other inline scripts are (I-3375). Its label is `contributes.fixedLabel` (1 to 24 characters, default `Advertisement`). A theme may refuse fixed insertions with `"fixed": false` in its manifest.
- Article positions are unchanged (`InsertionContributor`, `Position`).

**B3. Hosts for inserted content.** `contributes.csp` (already in the schema) is read for a plugin granted `content.insert` or `pages.insert`: its hosts go into the named directives **only on pages where that plugin's insertions were drawn**, through `InsertionOrigins`. Consent lists them (`Lets the content it inserts load from: :hosts`); an update adding a host waits for consent. `ContributionsValidator` keeps refusing `csp` for a plugin without either capability, with the old sentence's reason updated: `A plugin’s csp is read only with content.insert or pages.insert.`

**B4. Approved inline scripts (F-08).** A plugin's inserted HTML keeps an inline `<script>` (no `src`; no `type`, or `text/javascript`) **only when its exact text was approved for that plugin** by a person who holds `site-code.manage`, with the password asked again, and only while `TEME_DISALLOW_SITE_CODE` is off. A plugin can never approve: approvals happen only on Settings › Site code, through core's Operations.
- **Table `plugin_scripts`:** `id` ulid; `plugin_code` varchar(64); `name` varchar(80); `hash` varchar(64) (`PagePolicy::hash()` of the text, as `SiteCodePolicy` hashes it); `script` text (at most 4,096 characters); `state` `waiting|approved|refused`; `requested_by` bigint null; `requested_at`; `decided_by` bigint null; `decided_at` null; unique `(plugin_code, hash)`. No JSON, no `serialize()`.
- **Requests.** (a) Core, from a `code` field or setting declared `scripts: true` when it is saved (A-T9): one request per new inline script, named `:record — :field` or the setting's label. (b) The door `Teme\Contracts\Insertions\InlineScripts::request(string $name, string $script): void` (`@since V`, offered to a plugin granted `content.insert` or `pages.insert`), through `RequestPluginScript` as the acting person (`ActingPerson::require()`; never the operator), for scripts the plugin builds itself. Both: at most 20 waiting per plugin (`Too many scripts wait for approval. Ask an administrator to look at Settings › Site code.`); a text already approved or waiting makes nothing; one refused stays refused until revoked.
- **Decisions.** `ApprovePluginScript`, `RefusePluginScript`, `RevokePluginScript` share `ChangesSiteCode`'s rules (ability `site-code.manage`, elevation, the host's switch, the security log with before and after, new `SecurityEventType` cases `PluginScriptApproved`, `PluginScriptRefused`, `PluginScriptRevoked`) and purge `insertions` and `page-insertions` after commit.
- **Drawing.** `InsertionCleaner` and `HeadCleaner` look up the plugin's approved hashes (`ApprovedScripts::for(code)`, memoized per request, cleared after a decision as `SiteCodeEntries` is); a match is kept byte for byte and its hash goes to `script-src` through `InsertionOrigins`; anything else is removed as today. The CSP is a second line; the cleaner is the gate (true while the policy is report-only, I-3375).
- **The screen.** Settings › Site code gains a panel **Scripts from plugins**: waiting first, then approved; each row the plugin, the name, the script in a read-only monospace box, who asked and when, and Approve / Refuse (waiting) or Revoke (approved). A plugin page shows `:count script waits for an administrator’s approval in Settings › Site code.` (A-T9). Disabling a plugin keeps its rows inert; remove-data deletes them.

**B5. The page cache, written down (F-18 (2)).** Every page that asked page contributors carries `PageTags::PAGE_INSERTIONS`; enabling or disabling a plugin with `pages.insert`, saving its settings, or a write in its area with that purge (A2) purges it after commit. `InsertionsChanged::notify()` purges both tags. The handbook says plainly: a public page is cached whole for everyone, so a random or weighted pick, and anything chosen per visitor, is frozen into the cached copy until it is purged; choose per device with CSS media queries, never on the server; choose per visitor only in the browser, with a script the plugin serves from its own route (same origin) or an approved inline script; a plugin whose rules live in its own settings needs no `notify()`, because saving them purges.

**B6. Facts carry the path (R4).** `ArticleFacts` gains `public string $path = ''` (last; see V), filled by `InsertionPoints` from the article's current address, relative.

### The delicate code: keeping an approved script

In `InsertionCleaner` (and `HeadCleaner`), where an inline script is removed today:

```php
if ($tag === 'script' && ! $element->hasAttribute('src')) {
    $type = strtolower(trim((string) $element->getAttribute('type')));
    $hash = PagePolicy::hash(str_replace(["\r\n", "\r"], "\n", (string) $element->textContent));

    if (($type === '' || $type === 'text/javascript') && ! SiteCode::disallowed() && $this->approved->has($this->owner, $hash)) {
        $this->origins->addScriptHash($hash);   // into script-src after the policy starts
        continue;                               // kept exactly as written; no attribute but type and nonce-free
    }

    $element->remove();
    continue;
}
```

The text hashed is the text a browser hashes (the parser's own, as `SiteCodePolicy::of()` reads it); `PluginScriptsTest` proves a script approved from a `code` value with CR LF line ends matches the page's hash. Attributes on a kept inline script other than `type` are removed.

### Tasks (tests first; each seen red for the reason given)

0. **Checks.** Record: V; `git log --oneline -1`; where the document shell writes body end and how core's own inline scripts are hashed; how `ListingViews`, `LatestViews` and search build `$page->items`; the page kinds `PathResolver` produces; `SiteCodePolicy::of()`'s text normalization; that the region component is drawn inside the page cache's record. A difference that changes a decision is a STOP.
1. **Contract and asking.** `PageInsertionsTest` with `billboard`: a contributor is asked with each page kind's facts; not on error, not-found, password or preview pages, feeds, the API or `.md`; not without `pages.insert`; a slow or throwing contributor is skipped and recorded; one key once. Red.
2. **Head, top and bottom.** `HeadInsertionsTest`: a `<script src async>` and a `<meta name="google-adsense-account">` reach the head; a `<meta name="robots">`, a stylesheet link, an inline script and an `<iframe>` don't; body start and end are cleaned as article insertions are; origins reach the policy. Red.
3. **Between items and regions.** `BetweenItemsTest`: after item 3; every 4th at most 3; never after the last; on home, archive, term, author and search pages; the default theme's four templates draw it. `RegionInsertionsTest`: before and after a declared region, also when it is empty; an undeclared slug draws nothing. Red.
4. **Fixed.** `FixedInsertionsTest`: one per edge; the close button and label; the hashed close script in `script-src`; rails' media query from the theme's content width; a theme with `"fixed": false` draws none. Red.
5. **Hosts and cache.** `InsertionHostsTest`: `contributes.csp` hosts appear only on pages where the plugin drew; the consent line; refused without the capabilities. `PageInsertionCacheTest`: pages carry `page-insertions`; enable, disable, settings save and `notify()` purge after commit and not on rollback; `ArticleFacts::$path` is filled. Red.
6. **Scripts.** `PluginScriptsTest`: an unapproved inline script is removed; a request waits; approval needs `site-code.manage` and the password, and is refused under `TEME_DISALLOW_SITE_CODE`; once approved, the script is kept and its hash is in `script-src`; revoked, it's removed again and pages purged; a plugin's door can request but never approve; 20 waiting at most; every refusal writes nothing; the security log rows. Then, **only after the maintainers approve the panel's drawing**, the panel and `PluginScriptsPanelTest` (browser, 1280 and 375, axe in light and dark). Red.
7. **One browser test of the public side** (`tests/Browser/Site/FixedInsertionsTest.php`, 1280 and 375): the bottom bar sits fixed, closes, stays closed on the next page in the tab; the rail hides at 375; axe.
8. **Handbook and records.** `plugins/page-insertions.md`; `plugins/insertions.md` (cache truths, what the cleaner keeps: `<style>`, `<ins>`, `data-*`; `notify()` not needed for settings; approved scripts); Records; the gate.

### Quick check

The builders' gate after each task, with `php artisan test --parallel --compact` on `tests/Feature/Site/Insertions`, `tests/Feature/SiteCode`, `tests/Feature/Policy`, `tests/Feature/Plugins` and `tests/Feature/Cache`. Once at the end: `composer check`, and `composer check:browser` for the two new browser files, `InsertionPointsTest` and `SiteCodeSettingsTest`.

### Records (the builder's)

Setup: `cat /tmp/teme-cloud-setup.log` printed two lines, "ran in /home/user/teme as root" (09:11:02Z) and "finished" (09:24:47Z); `pwd` was `/home/user/teme`. Built from `origin/main` at `df2f85d`.

#### Commits on `sockets2/inserts`

- `665b7a4` feat(contracts): page insertion contracts and the pages.insert capability
- `d8d8037` feat(site): ask plugins granted pages.insert for each public page's insertions
- `82f3931` feat(site): draw page insertions in the head and at the top and bottom of the body
- `6a1bf33` feat(themes): draw page insertions between list items and around regions
- `defde21` feat(site): fix page insertions to the screen's edges, with a label and a close button
- `fcfcb4a` feat(plugins): agree to the hosts inserted content loads from, and purge page insertions
- `f8c02cb` feat(site): keep a plugin's inline script once a person approved its exact text
- `430e4bb` docs(plugins): content on every page, and what the page cache means for insertions
- `761c2ae` test(site): fixed insertions in a browser, each edge named for screen readers
- `261c924` fix(site): read an article's address once, and a person through Actors
- `e646c9f` feat(languages): the new reader words in each shipped language, and the handbook's index
- this commit: docs(plans): sockets2 inserts' records

#### Tests seen red (first line of the failure)

The code for Tasks 1 to 6 was drafted before its tests were run, so each test was seen red by running it against `main`'s code (the branch's `app`, `packages`, `database`, `resources` and `themes` set aside, tests and the fixture kept). That shows each test fails without the code, but most fail at the same first step, not each for the reason the plan gives; I record it as it happened.

| Task | File | First failure |
|---|---|---|
| 1 | `PageInsertionsTest.php` (16 red) | `Tests\Fixtures\Plugins\Billboard\Billboard asks for Teme\Contracts\Insertions\InlineScripts; a plugin may ask only for what Teme offers plugins.` (the fixture can't be enabled without the door and `pages.insert`) |
| 1 | `tests/Unit/Contracts/PluginContractsTest.php` | `include(.../teme/contracts/src/Insertions/PageInsertionContributor.php): Failed to open stream` (five contracts), and the capability list `Failed asserting that two arrays are identical` (no `pages.insert`) |
| 2 | `HeadInsertionsTest.php` (3 red) | as Task 1: the fixture can't be enabled |
| 3 | `BetweenItemsTest.php` (10 red), `RegionInsertionsTest.php` (1 red) | as Task 1 |
| 4 | `FixedInsertionsTest.php` (5 red) | as Task 1 |
| 5 | `InsertionHostsTest.php` (3 red), `PageInsertionCacheTest.php` (5 red), `tests/Feature/Policy/PluginCspTest.php` | as Task 1; `PluginCspTest`: `Failed asserting that two arrays are identical` (the old sentence) |
| 6 | `PluginScriptsTest.php` (17 red) | as Task 1 |
| 7 | `tests/Browser/Site/FixedInsertionsTest.php` | `1 Accessibility issues found - [moderate] Landmarks should have a unique role or role/label/title` (two edges both named "Sponsored"); fixed by naming each edge |

Once the code was back, the first runs were red for real reasons, each fixed: a plugin with `csp` failed to start because the start-up check of its contributions had no capabilities to read (B-07); the page cache tests needed the cache switched on; `Consent::added()` grew a key existing tests didn't expect (B-08).

#### Task 0

- V is `0.9.0`: no `v0.9.0*` tag exists. `git log --oneline -1` at the start: `df2f85d Merge pull request #344 from temecms/docs/plan-sockets-2`.
- The document shell writes the body's end through `Placements` (`PlacementContributor`s tagged under `Placements::CONTRIBUTORS`, already used by site code); core's own inline scripts go through `<x-teme::inline-script>`, whose hashes join `script-src`.
- `ListingViews`, `LatestViews` and the search view build `$page->items` as lists the templates loop over with `@foreach`; the loop's `$loop` gives the item's number and whether it is the last.
- `PathResolver` answers `view`, `redirect` or `notfound`; a `view` carries a `ViewIntent` whose kind is `single`, `latest`, `archive`, `taxonomy`, `author`, `date` or `search` (`isFront` on the home page), plus `private` for a private item.
- `SiteCodePolicy::of()` hashes `textContent` as HTML's own parser gives it, which turns CR LF into LF; `ApprovedScripts::hash()` does the same before `PagePolicy::hash()`.
- The region component is drawn inside the page cache's record, so a tag it adds reaches the stored page.
- No difference changed a decision.

#### Architecture baselines

| Baseline | Before | After |
|---|---|---|
| design-system-baseline.txt | 222 lines | 222 lines |
| admin-words-baseline.txt | 876 lines | 876 lines |

#### Gate

- Base (`main` at `df2f85d`, before any change): Pint and PHPStan passed; Architecture 139 passed; Unit and Feature 11,710 passed, 2 skipped, 1 failed: `Tests\Feature\Plugins\DiscoveryTest > it finds the configured folders` saw `tests/jobs` in the registry, a leftover of another test in the same worker; it passes alone (8 passed).
- Branch, first full run: Pint, PHPStan, Architecture (139) passed; Unit and Feature 14 failed. Fixed: `ReaderWordsTest` (the 15 new reader words translated in the seven shipped languages), `PublicReadersTest` (`RequestPluginScript` reads a person through `Actors`), `StatementCountsTest` (an article read its address twice; now once), `GenerateHandbookIndexTest` (`llms.txt` regenerated). `DatabaseDownTest` failed once and passes alone.
- Branch, final full run (`composer check`): Pint passed; PHPStan no errors; `npm run types:check` and `npm run check` passed; Architecture 139 passed; Unit and Feature 11,783 passed, 2 skipped, 2 failed: `GenerateManifestReferenceTest` and `ThemeReferenceTest`, the generated `docs/reference/plugin-manifest.md` and `docs/reference/theme.md`, which this part may not write (B-13).
- Browser: `tests/Browser/Site/FixedInsertionsTest.php` 2 passed (1280 and 375, axe); `tests/Browser/InsertionPointsTest.php` 3 passed; `tests/Browser/SiteCodeSettingsTest.php` 5 passed; after `npm run build`, `npm run build:themes` and `teme:themes:link`.

#### B-?? (draft)

- **B-01 Where the new views live.** Options: `resources/views/components/` (the plan) or `resources/views/teme/components/` (where `<x-teme::region>` and core's other theme components are). Chose the second, so themes call them `<x-teme::between-items>`. Commits `6a1bf33`, `defde21`.
- **B-02 No `ListingPlan`.** Options: a plan built by `ListingViews`/`LatestViews`/search and handed to the views, or the `<x-teme::between-items>` component asking `PageInsertions` for its item. Chose the component: the listings stay unchanged, and every list a theme draws gets it by one line. `index.blade.php` has no list loop, so it has no line. Commit `6a1bf33`.
- **B-03 Fixed insertions drawn as a placement.** Options: edit the document shell's body end, or draw them from `PluginPlacements`, a `PlacementContributor` at the body's end. Chose the placement: the shell is untouched and site code's own fragments keep their order. Commit `defde21`.
- **B-04 Whose scripts and hosts an article insertion may use.** `InsertionCleaner` stays static; the approved hashes are passed in, and `InsertionPoints::owned()` gives each insertion with its plugin's code. Commit `fcfcb4a`, `f8c02cb`.
- **B-05 Revoking deletes the row.** Options: keep a revoked row as refused, or delete it. Chose delete, so the plugin may ask again and the panel shows only live decisions; the security log keeps the script as it was. Approve and refuse act only on a waiting script. Commit `f8c02cb`.
- **B-06 The door's person.** `InlineScripts::request(name, script)` names no person, so the door uses the job's person while a job runs, else the signed-in person, as `ActingPerson` reads them; never the operator. Commit `f8c02cb`.
- **B-07 `csp` checked once.** `ContributionsValidator::errors()` takes the capabilities as `?array`: the manifest's validator passes them and refuses `csp` without an insert capability; the start-up path (`Contributions::add()`, whose seam is unchanged) passes none, which means "already checked". Commit `fcfcb4a`.
- **B-08 `Consent::added()`** lists `insertionHosts` only when there are any, as it does `imports`, so existing callers and tests read the same array. Commit `fcfcb4a`.
- **B-09 Small readings.** A rail fixed with width 0 is 160 wide; an author archive's `authorIds` is empty (the page lists one author, but the facts give IDs only for an article); a term archive's term is found by taxonomy and slug. Commits `665b7a4`, `d8d8037`.
- **B-10 Each fixed edge is named.** Two `<aside>`s labelled the same failed axe (landmark-unique). The visible label stays the plugin's; the landmark's name adds the edge ("Sponsored, at the bottom of the screen"). Commit `761c2ae`.
- **B-11 Remove-data forgets scripts.** `RemovePluginData` deletes the plugin's `plugin_scripts` rows with its tables (B4, "remove-data deletes them"); a plugin with no tables has no remove-data, so its rows stay inert. Commit `f8c02cb`.
- **B-12 Files changed outside Owns, each a line or two:** `Consent.php`, `PluginsScreen.php`, `PluginRow.php`, `ContributionsCheck.php`, `ManifestValidator.php`, `CheckTheme.php`, `InsertionsServiceProvider.php`, `PublicController.php`, `ArticleViews.php`, `RemovePluginData.php`, `GenerateHandbookIndex.php` and the handbook README (the new page's line), `tests/Feature/Plugins/ContributionsSchemaTest.php` (`fixedLabel` added to the list of `contributes` keys, as the plan's schema change makes it). `PluginCspTest`'s second test changed as the plan names (the new sentence, and `[]` passed for "no capabilities").
- **B-13 The generated references.** `ThemeReferenceTest` fails because `docs/reference/theme.md` doesn't yet list `<x-teme::between-items>` or the region's new sentence, and `GenerateManifestReferenceTest` because `docs/reference/plugin-manifest.md` doesn't list `pages.insert` and `fixedLabel`. The rules forbid writing `docs/reference/*.md` here, so both are left for the join, which already regenerates `plugin-manifest.md`; add `php artisan teme:docs:theme` there.
- **B-14 Reader words.** Words under `app/Domain/Site` count as reader words, so the script Operations' refusals and the fixed edges' words are translated in `lang/*.json` (seven files, our lines only). Commit `e646c9f`.

#### Contract changes

- None beyond the plan: `PageInsertionContributor`, `PageInsertion`, `PagePosition`, `PageFacts`, `InlineScripts` (all `@since 0.9.0`), `Capability::PagesInsert`, `ArticleFacts::$path` (last, default `''`), and the schema's `pages.insert`, `csp` description and `contributes.fixedLabel`.

#### STOP

- None. Left for a follow-up, as the owner asked, until the maintainers approve their drawing: the **Scripts from plugins** panel on Settings › Site code (`SiteCodeScreen`, `site-code.tsx`, `SiteCodeController`'s three actions and routes, `PluginScriptsPanelTest`), the in-form approval control, and the notice to people who may approve. The Operations those screens will call are built and tested.

#### Changelog lines for the maintainers

- Plugins granted `pages.insert` can add content to every public page: in the head, at the top and bottom of the page, between items on lists, before or after a theme's regions, and fixed to the screen's edge with a label and a close button.
- A plugin's inline script is kept only after a person who may change site code approves its exact text; its hash joins the page's content security policy.
- The hosts a plugin's inserted content loads from (`contributes.csp`) are shown and agreed to when the plugin is enabled, and added to a page's policy only where the plugin drew.
- `teme:theme:check` warns about a theme whose lists don't draw what plugins place between items.

#### Try it

- `php artisan test tests/Feature/Site/Insertions` and `php artisan test tests/Browser/Site/FixedInsertionsTest.php`.
- With the `tests/billboard` fixture enabled (`TEME_PLUGIN_PATHS` pointing at `tests/Fixtures/plugins/billboard`), open the home page: a bar at the bottom labelled Sponsored, a rail on the right on a wide screen, and markup after the third item of the list.

#### Follow-up R1

The review's four IMPORTANT findings, each with its test written first and seen red, then fixed. The LATER findings (5 to 9) are not part of this follow-up.

| Finding | Commit | Test, and its first failure before the fix |
|---|---|---|
| 1. Removing a plugin's data left its approvals cached | `61bc15f` | `PluginScriptsTest` "forgets a plugin's approvals with its data, so enabled again its old script is removed": `Expecting '<!doctype html>…' not to contain '(window.billboard = window.billboard \|\| []).push({});'` |
| 2. A plugin could replace site code or core's fixed block by reusing its key | `abccb90` | `HeadInsertionsTest` "never replaces site code or core's fixed block with an insertion that reuses its key": `To contain: <div data-test="consent">Consent</div>` |
| 3. An author archive's facts had no author | `9644145` | `PageInsertionsTest` "gives an author archive its author's ID": `Failed asserting that two arrays are identical` (`[]` where the author's ID was expected) |
| 4. The handbook put approval on Settings › Site code | `a03a340` | Words only, no test: the page now says approval happens where the ad is written, by a control core draws, and that Site code lists approved and waiting scripts and revokes; both screens follow |

- Fix 1: `RemovePluginData::invalidateCache()` calls `ApprovedScripts::forget()` after commit.
- Fix 2: `PluginPlacements` keys each fragment `plugin.<code>.<key>`, or `plugin.` and a hash when that is too long for `Fragment::KEY_PATTERN`.
- Fix 3: `ListingViews::forAuthor()` tells `PageInsertions` whose archive it draws; `PageFacts::$authorIds` holds that ID, as the contract says. `ListingViews.php` is one more file outside Owns (the plan lists it as changed).
- B-13 stands: add `php artisan teme:docs:theme` to the Joining step, beside `plugin-manifest.md`.
- Gate (`composer check`): Pint passed; PHPStan no errors; `npm run types:check` and `npm run check` passed; Architecture 139 passed; Unit and Feature 11,784 passed, 2 skipped, 4 failed: `GenerateManifestReferenceTest` and `ThemeReferenceTest` (B-13), and `DatabaseDownTest` and `DiscoveryTest`, which failed in a worker and pass alone (9 passed).
- Baselines unchanged: 222 and 876 lines.

---

## Part C: three small sockets (`sockets2/small`)

Findings F-15, F-09, F-19.

### Owns

- **New:** `app/Domain/Plugins/ManifestText.php`; `app/Console/Commands/ListPluginSchedules.php` (`teme:plugins:schedules`); tests `tests/Feature/Plugins/Sockets/CustomVerifierTest.php`, `ManifestWordsTest.php`, `ScheduleDueTest.php`.
- **Changed:** `app/Domain/PublicRoutes/Verifiers/RouteVerifier.php`, `RouteCollisions.php` (the `custom` branch); `app/Domain/Plugins/Jobs/DueSchedules.php` (`nextDue()`), `app/Console/Commands/RunDuePluginSchedules.php` (`--plugin`); the builders of plugin words on screens (`DeclaredSettings` labels and descriptions, Tools links, round 1's actions and lists, list columns, `PluginWords`' consent labels); `docs/guides/developers/plugins/public-routes.md`, `jobs.md`, `translations` section of the handbook, `testing.md`; the shared files above.
- **Not owned:** A and B. A's `AreaWords` is pointed at `ManifestText` at the join.

### Decisions

**C1. A `custom` verifier needs no secret (F-15).** For `kind: custom`, `secret` is optional; when given it must still name a declared secret setting (the check `RouteCollisions` makes for `hmac` and `secret`). `RouteVerifier` passes `''` to the verifier when no `secret` is named, without reading the secrets store. The handbook lists the answers a declared route gives before the handler runs (Task 0 reads them from `RouteDispatcher`: a failed verification, a body over the limit, a throttle, a wrong method).

**C2. Manifest words translate (F-09).** Every string a person reads from a plugin's manifest (its `label` and `description`, settings' labels, descriptions, options, units and section names, Tools links, actions, lists, columns, permissions, and the area's words through A) is looked up by its English text in **that plugin's own** `lang/{locale}.json` (round 1 A-W14 loads them), never in core's or another plugin's, and falls back to the English text. `ManifestText::for(string $code)->text(string $english, string $locale): string`. The locale is the person's admin language, as core's own words use it. A plugin's values (what people typed) are never translated. `teme:plugins:enable` and the consent stay in the operator's or person's language as today.

**C3. When a schedule is due, and how a test fires it (F-19).** Written into `jobs.md` and kept by code:
- A schedule is due on the first pass of the scheduler after the plugin is enabled, then each time its interval (`minute` 60 s … `week` 604,800 s) has passed **since the last run was queued**; it is not aligned to the clock or the calendar, and a missed pass catches up once, never several times.
- It runs as the administrator named at enable (round 1 B5); without one it never runs, and `teme:plugins`, the Plugins screen and `teme:plugins:schedules` say why.
- `teme:plugins:run-due-schedules` is what the site's scheduler runs every minute; `--plugin=<code>` limits a pass to one plugin.
- `teme:plugins:schedules` lists each enabled plugin's schedules: job, every, last queued, next due (`DueSchedules::nextDue(PluginRecord, string $job): ?CarbonImmutable`), and the reason it can't run, if any.
- In a test: enable with an administrator, `Queue::fake()`, run the command (queued), `$this->travel(1)->days()`, run it again (queued again); the handbook shows it.

### Tasks (tests first; each seen red for the reason given)

0. **Checks.** Record: V; `git log --oneline -1`; that round 1's A-W13, A-W14 and B5 are on `main` (**if not, STOP**); the status codes `RouteDispatcher` answers before a handler; how A-W14 loads a plugin's JSON and which locale the admin uses.
1. **Custom verifier.** `CustomVerifierTest`: a `custom` route without `secret` passes its verifier `''` and answers the handler's response; a failed verification answers as Task 0 recorded; a `custom` route naming an undeclared secret is refused at enable. Red: the 500 of F-15.
2. **Manifest words.** `ManifestWordsTest`: with a fixture's `lang/es.json`, a Spanish-speaking administrator sees the plugin's settings labels, options, Tools link and permission in Spanish; a missing key falls back to English; core's translation of the same English word is never used; values are untranslated. Red.
3. **Schedules.** `ScheduleDueTest`: the first pass queues; a second pass the same minute doesn't; after the interval it does; two missed intervals queue once; `--plugin` limits; `nextDue()` and `teme:plugins:schedules` agree; a plugin without its administrator is listed with the reason. Red: `nextDue()` and the command missing.
4. **Handbook and records.** `public-routes.md` (verify keys, `custom` without a secret, the answer codes), the translation section (manifest words), `jobs.md` and `testing.md` (C3); Records; the gate.

### Quick check

The builders' gate after each task, with `php artisan test --parallel --compact` on `tests/Feature/Plugins/Sockets`, `tests/Feature/Plugins` (which holds `PublicRouteVerifiersTest`) and `tests/Feature/Cache/DeclaredRouteCacheTest.php`. Once at the end: `composer check`. No browser test.

### Records (the builder's)

#### Commits on `sockets2/small`

-

#### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|
| | | |

#### Task 0

-

#### Gate

-

#### B-?? (draft)

-

#### Contract changes

-

#### STOP

-

#### Changelog lines for the maintainers

-

#### Try it

-

---

## What must not be done (every part)

- Run a plugin's JavaScript or CSS in the admin, or accept markup from a plugin anywhere in the admin: figures, charts, rows and values are plain values drawn by core.
- Let a plugin approve a script, widen its own hosts, grant a permission, or write as the operator or with elevation.
- Keep a hidden field's submitted value, or validate it.
- Choose anything per visitor on the server for a cached page; read cookies, the user agent or the visitor's address for an insertion.
- Hand a plugin the container, a model, a query builder on Teme's tables, or a framework type.
- Add a singleton, a dependency (a chart library, dnd-kit when it is absent), a core permission, or an `.ai/` edit; widen an Architecture allow-list; raise a baseline.
- Change `composer.json`, `composer.lock` or `package.json`; regenerate `docs/reference/*.md`.
- Edit another part's or round 1's lines in a shared file.

## STOP conditions

Stop, write why under that part's Records, commit and push, then stop, when: a Task 0 line differs in a way that changes a decision; a branch this part waits for isn't on `main`; a contract named here doesn't fit the code; making it work needs a singleton, a dependency, an `.ai/` edit or a wider allow-list; the words people read would have to change; a screen task (A-T7, B-T6's panel) is reached before its drawing is approved.

## Joining

Merge A, B and C into one join branch (B before A's Task 9 if A was built first; A rebases for Task 9). Join the schema's, validators' and `DeclaredSettings`' additions; point `AreaWords::text()` at `ManifestText` (one line) and add the area to `ManifestWordsTest`; regenerate `docs/reference/plugin-manifest.md`, `docs/reference/hooks.md` and `docs/reference/fields.md` (the last also closes F-14: the `insertions_off` field's ID); run the Architecture suite and `tests/Feature/Plugins`, `tests/Feature/Site/Insertions`, `tests/Unit/Contracts`; let GitHub run the rest. Ad Placr then moves onto the sockets in its own part (not this plan): an area with ads as records, versions in a repeater, statistics on the overview, header, footer, list and fixed positions, its AdSense units' `push({})` approved once, `custom` verifier without its unused secret, translated manifest words, and its prune schedule tested as C3 shows.
