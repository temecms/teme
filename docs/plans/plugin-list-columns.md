# Plan: plugin columns in the content lists

**One part, one branch: `plugins/list-columns`, from `origin/main` after the v1 join (`join/v1-wave2`) is merged.** v1 scope item A6, wave 3. Proposal: `docs/proposals/plugin-list-columns.md` (its Settled section is binding). **Bound by:** I-200 (declared entries core draws, in fixed regions, with a budget; list columns sortable only when backed by an indexed value the plugin declares), I-204 (columns from extensions; each person's columns), I-3120 (an offered column is off until someone turns it on), I-3396 (a wall is a missing socket), I-3353 (a plugin's failure is recorded under the plugin and shows nothing), I-087 (no new singleton), I-140 (a list page's cost stays flat), rule 5 (the contracts import nothing from `App\` or `Illuminate\`), I-569 (lighter plan), I-3439 (American English), I-3373 (every word through `__()`), I-797, I-3317.

This plan is lighter (I-569): the decisions, names, JSON shapes, contract, tests and the delicate code are written out; every other class is described by its file, its job and its public signatures, and is the builder's to write in the style of its siblings.

## What exists (read on `join/v1-wave2`, 2026-11-16)

- **The lists.** `app/Http/Admin/Contents/ContentListController.php` renders `content/index` for every listed type. `resources/js/pages/content/index.tsx` builds its columns: `select` and `title` (pinned), `status` (always shown, not in Columns: B-29 of the content-lists part), then the optional columns `author`, `taxonomy-{key}`, `comments` (when the type takes comments), `languages` (multilingual sites only, off by default: the multilingual-screens part, B-12), `date`, then `menu`. Each `Column` already carries `owner: string` (`components/list/data-table.tsx`, with a `// Later (I-200)` line at its top).
- **The person's choice.** `ColumnChooser` (`components/list/column-chooser.tsx`) saves `lists.{type}.columns` (the ids, in order) and `lists.per_page` through `savePreferences()`, which posts to `PreferencesController`, which calls the Operation `UpdatePreferences`. `BuiltInPreferences` declares `lists.{type}.columns` as a `list` whose `allowed` is `['featured', 'languages', ...columnsOf(type)]`, with `maxItems: count(columns) + 2`, and a `// Later (I-200): plugin columns join these options in phase 5` line. `PreferencesFor` reads a stored list through `keepAllowedItems()`, so an id that is no longer allowed is dropped and the rest kept. The default is the core list, so `featured` and `languages` are off until chosen.
- **Phone width.** Below 640 the table becomes cards (`CardLayout`, facts `['status', 'date', 'author']`); the chooser is not drawn there.
- **Plugins' contributions.** `contributes` in `teme-plugin.json` holds `settings` and `admin` (Tools links); `csp` is reserved and refused. `ContributionsValidator` checks them; `ManifestValidator` calls it and also checks each door against a capability (for example "asks for InsertionsChanged, but the plugin doesn’t ask for content.insert"). `Contributions::add()` registers them at start, all or none; a non-empty answer makes the plugin fail to start (`PluginLoader::start()`). `PluginHasJobs` is the model for a plugin interface whose keys must match the manifest (`Contributions::jobHandlers()`).
- **Capabilities.** `Teme\Contracts\Plugins\Capability::ContentRead` (`content.read`) exists, with consent words in `PluginWords` and `EnablePluginCommand`. Nothing new to add there.
- **Faults.** There is no `PluginFaults` class. A plugin's failure is recorded through `App\Domain\Hooks\ListenerFaults::record(owner, id, hook, failure)`, bound to `SpooledFaults` (it reports to the log with the plugin as context). `InsertionPoints` records a contributor's throw this way and skips it; this part does the same.
- **No new singleton.** `NoStaticStateTest` refuses one. `InsertionPoints` reads the started plugins from `LoadedPlugins::running()` and their consent from `PluginRecords::enabled()` (memoized) per call; this part does the same.
- **Versions.** The newest tag is `v0.8.0-alpha.1`; the join's new contracts carry `@since 0.9.0` (74 places). See D12.

## Owns

- **New:** `packages/teme-contracts/src/Lists/**` (`HasListColumns`, `ListColumn`, `ListRows`, `ListRow`, `Cell`); `app/Domain/Plugins/Columns/**` (`PluginColumns`, `DeclaredColumn`, `ColumnCells`, `ColumnClock`, `SystemClock`, `ColumnTooSlow`, `ColumnAnswerRefused`); `resources/js/components/list/plugin-cell.tsx`; `resources/js/types/plugin-columns.ts`; the fixture plugin `tests/Fixtures/plugins/columns/`; `tests/Unit/Contracts/ListCellTest.php`; `tests/Feature/Plugins/ListColumns/**`; `tests/Feature/Admin/PluginListColumnsScreenTest.php`; `tests/Browser/PluginListColumnsTest.php`; `docs/guides/developers/plugins/list-columns.md`.
- **Changed:** `packages/teme-contracts/schema/teme-plugin.schema.json` (`contributes.columns`); `app/Domain/Plugins/Contributions/ContributionsValidator.php`; `app/Domain/Plugins/Contributions/Contributions.php`; `app/Domain/Plugins/ManifestValidator.php` (one check); `app/Domain/Preferences/BuiltInPreferences.php` (the columns preference's `allowed` and `maxItems`, and its `Later (I-200)` line); `app/Http/Admin/Contents/ContentListController.php` (three props); `app/Providers/PluginServiceProvider.php` (one `bind`); `resources/js/pages/content/index.tsx`; `resources/js/components/list/column-chooser.tsx` (an optional `plugin` on a chooser column); `resources/js/components/list/data-table.tsx` (its `Later (I-200)` line only); `resources/js/types/content.ts` (the page's three props); `tests/Unit/Contracts/PluginContractsTest.php` (dataset rows); `tests/Feature/Plugins/ContributionsSchemaTest.php` (D3); `docs/architecture/plugins.md` (one line, T6); `docs/reference/plugin-manifest.md` (regenerated, T6).
- **Not owned:** Categories, Tags, Comments and Media lists; row actions, bulk actions and filters from plugins; the editor; `components/ui/**` and `components/patterns/**` (used as they are).

## Decisions

**D1. A column is declared in the manifest, under `contributes.columns`.** Each entry:

```json
{
    "id": "score",
    "label": "SEO score",
    "kind": "badge",
    "width": "narrow",
    "types": ["post", "page"]
}
```

- `id`: required, `^[a-z][a-z0-9-]{1,39}$` (the Tools link's pattern), unique within the plugin.
- `label`: required, 1 to 40 characters after trimming. It is the heading and the name in Columns.
- `kind`: required, one of `text`, `number`, `badge`.
- `width`: optional, one of `narrow` (100 px), `medium` (128), `wide` (168), the widths DS 4.26 gives numbers, the author and tags. Default: `narrow` for `number` and `badge`, `medium` for `text`.
- `types`: optional, a list of content type keys (`^[a-z][a-z0-9_]{0,31}$`). Absent means every listed type. A key no type has today is allowed (the type may be created later) and simply matches nothing.
- **At most 3 columns per plugin** (I-200's budget).
- Any other key is refused. `sortable` gets its own words (D8).

The column's key, used in the preference and on the page, is **`plugin:{code}:{id}`**: a colon is in neither a plugin's code nor a column's id, so the key is never ambiguous and never collides with core's ids (`author`, `taxonomy-{key}`, …).

**D2. A plugin with columns needs `content.read`.** The values describe content, and drafts are listed, so the consent is the one that already says "Read your posts, pages and other content, including drafts". `ManifestValidator` refuses a manifest that declares `contributes.columns` without `content.read` in `capabilities`, with: `It adds columns to the content lists, but the plugin doesn’t ask for content.read.` At ask time, `PluginColumns` also requires the enabled record's consent to grant `content.read`, as `InsertionPoints` requires `content.insert`.

**D3. The contract** (`Teme\Contracts\Lists`, no `App\` or `Illuminate\` types, every public member `@since V`, V from D12):

```php
interface HasListColumns            // implemented beside Plugin, as PluginHasJobs is
{
    /** @return array<string, ListColumn> by declared column id */
    public function listColumns(): array;
}

interface ListColumn
{
    /**
     * One page of a list's rows; the cells by row id. A row left out shows an empty cell.
     *
     * @return array<string, Cell>
     */
    public function cells(ListRows $rows): array;
}

final readonly class ListRows
{
    /** @param list<ListRow> $rows in the list's order */
    public function __construct(public string $type, public array $rows) {}

    /** @return list<string> */
    public function ids(): array;
}

final readonly class ListRow
{
    /** @param 'draft'|'review'|'scheduled'|'published' $status */
    public function __construct(
        public string $id,            // the content item's ID
        public string $status,
        public ?string $language,     // its language tag; null on a site with one language
        public string $updatedAt,     // ISO 8601, so a plugin can key its own cache on it
        public bool $trashed = false,
    ) {}
}

final readonly class Cell
{
    public const string TEXT = 'text';
    public const string NUMBER = 'number';
    public const string BADGE = 'badge';

    /** The tones a badge may take: the Status pill's. */
    public const array TONES = ['ok', 'neutral', 'info', 'warn', 'danger'];

    private function __construct(public string $kind, public string|int|float $value, public ?string $tone) {}

    public static function text(string $text): self;                           // plain text, never HTML
    public static function number(int|float $number): self;                   // throws InvalidArgumentException when not finite
    public static function badge(string $label, string $tone = 'neutral'): self; // throws for an empty label or a tone not in TONES
}
```

`InvalidArgumentException` is PHP's own, so rule 5 holds. The contract keeps what it is given; core cleans and cuts (D6). A value class grows only at its end (contracts rule).

**D4. One call per shown column per page.** The list page asks each plugin column the person shows, once, with every row on that page (at most `lists.per_page`, 100). A hidden column is never asked; an empty page asks nothing. Columns are asked in the person's left-to-right order, so the budget (D5) favors what they placed first. This is the batch Yoast had to build by hand (`Admin_Columns_Cache_Integration` fills a cache from the page's IDs before the first cell) and Craft offers as `EVENT_PREP_QUERY_FOR_TABLE_ATTRIBUTE`.

**D5. The budget** (provisional numbers, P1). PHP cannot interrupt a call, so the budget is measured after each call:

- **Per call, 100 ms.** A call that takes longer has its cells dropped (empty), and is recorded as a `ColumnTooSlow` fault.
- **Per page, 300 ms** for all plugin columns together. Once spent, the columns not yet asked are not asked; their cells are empty.
- **A call that throws** has its cells dropped and is recorded with its own exception.
- Time is read through `ColumnClock` (`nanoseconds(): int`), bound to `SystemClock` (`hrtime(true)`) with `bind()`, never `singleton()`; tests bind a stepping fake.

Faults go to `ListenerFaults::record($pluginName, "{$pluginName}#column:{$id}", ListColumn::class, $failure)`. Every plugin whose column ended empty for any of these reasons is named in the page's `pluginColumnProblems`, and the list says so once (D9).

**D6. What core keeps of an answer** (`PluginColumns::kept()`, written out below): only string keys that are IDs of this page's rows; only `Cell` instances of the column's declared `kind`; numbers that are finite; text and badge labels cleaned: every control character (`\p{Cc}`, which includes newlines and tabs) and the bidirectional overrides U+202A to U+202E and U+2066 to U+2069 become a space, runs of white space become one space, then trimmed; text is cut to **80** characters and a badge label to **24**, the last of them `…`; a value that is empty after cleaning, or not valid UTF-8, is dropped. If anything was dropped for being the wrong shape (not a row, not a `Cell`, wrong kind), one `ColumnAnswerRefused` fault is recorded for that call and the rest are kept. Markup is never interpreted: `<b>x</b>` is the eight characters `<b>x</b>`, drawn as text by React (D9).

**D7. Off until a person turns it on.** A plugin column joins the `allowed` ids of `lists.{type}.columns` for the types it declares, and never its default, so it is offered in Columns and shown only once someone ticks it: the rule `featured` (I-3120) and `languages` already follow. A plugin cannot ask to be shown by default in v1 (open question 1). When a plugin is disabled, its keys drop out of `allowed`, so `keepAllowedItems()` drops them from each person's list and keeps the rest; when it is enabled again, they are offered again, off. `maxItems` becomes `null` for this preference: `allowsList()` already refuses duplicates and anything not allowed, so the allowed set bounds the list, and a fixed number would have to know how many plugins run. Saving goes through `savePreferences()` and `UpdatePreferences`, unchanged.

**D8. No sorting by a plugin column in v1.** Teme sorts lists in the database by indexed columns of `contents` (I-140: a deep page costs the same as the first). A plugin's values live in its own tables or are worked out on the fly; sorting by them means either joining a plugin's table that core cannot index or rank, or sorting in PHP after loading every row. That is the `wp_postmeta` `orderby` Yoast's `column_sort_orderby()` builds through a meta query, the cost rule 2 exists to avoid. I-200 already says a plugin column sorts only "when backed by an indexed value the extension declares"; no plugin can declare one yet. So the manifest refuses `sortable` with `Teme doesn’t sort a list by a plugin’s column yet.`, and the heading is never a sort button. Parked with its trigger (proposal, Later 1). Strapi's i18n column is not sortable either (`sortable: false`).

**D9. How core draws it** (DS 4.26 and 6.1):

- **Heading:** the label, then `PluginMark` with `iconOnly` (a focusable plug, the plugin's name in a tooltip, `sr-only` "From :name"). `nowrap`, as every heading.
- **Cells** (`components/list/plugin-cell.tsx`): `text` as `<span className="block truncate">`; `number` right-aligned and tabular (`text-end tabular-nums` on the column), formatted with `Intl.NumberFormat` in the admin's locale; `badge` as `StatusPill` with the tone; no value as `<span aria-hidden="true">—</span><span className="sr-only">{__('No value')}</span>`. Never `dangerouslySetInnerHTML`.
- **Columns:** each plugin column is one entry in the chooser's single ordered list, with its plug mark after its name (DS 4.26: "each column a checkbox with its name, its plug mark"); `ChooserColumn` gains an optional `plugin?: string`. New columns the person's list does not name follow, in declared order, as today.
- **Problems:** when `pluginColumnProblems` is not empty, one line above the table (`role="status"`, `text-small text-ink-2`): `__('Some plugin columns could not be shown this time: :plugins. The rest of the list is unaffected.', { plugins })`, the plugin labels joined with ", " (v9's boundary line, which said the same per plugin).
- **Phone width:** the cards' facts are `['status', 'date', 'author', ...]` followed by **at most two** plugin columns the person shows, in their order. The chooser stays off below 640, as today.

**D10. What the page receives** (three new props on `content/index`):

```ts
// resources/js/types/plugin-columns.ts
export type PluginColumn = {
    key: string;             // plugin:{code}:{id}
    label: string;
    plugin: string;          // the plugin's label, for the mark
    kind: 'text' | 'number' | 'badge';
    width: number;           // 100 | 128 | 168
};
export type PluginCellValue =
    | { kind: 'text'; value: string }
    | { kind: 'number'; value: number }
    | { kind: 'badge'; value: string; tone: 'ok' | 'neutral' | 'info' | 'warn' | 'danger' };
// pluginColumns: PluginColumn[]                                   every column offered for this type
// pluginCells: Record<string, Record<string, PluginCellValue>>    key -> row id -> cell, shown columns only
// pluginColumnProblems: string[]                                  plugin labels
```

**D11. Who sees it.** Anyone who may open the list sees the columns they turn on; there is no per-column permission in v1 (open question 5). Safe mode loads no plugins, so no plugin columns.

**D12. `@since`.** In Task 0 the builder runs `git tag -l 'v0.9.0*'`. **None:** this part ships in the join's release, so `V = 0.9.0`, as the join's other new contracts. **Any:** `V = 0.10.0`. Written once and never edited (contracts rule). The maintainers may name another release (open question 2).

**D13. No core cache across requests.** The values are the plugin's: a view count or a score changes when the plugin's data changes, which core cannot see, so a core cache could only be wrong or purged by a door that does not exist. Inside one request each column is asked once (D4). A plugin may keep its own cache; `ListRow::$updatedAt` lets it key on the item's version. The values arrive with the page, not as a deferred prop (open question 4): D5's 300 ms bounds the wait, and deferring would run the list query a second time.

## Names

- Contract: `Teme\Contracts\Lists\{HasListColumns, ListColumn, ListRows, ListRow, Cell}` (D3).
- Domain: `App\Domain\Plugins\Columns\PluginColumns` (plain class, resolved per use):
  - `for(string $type): list<DeclaredColumn>`: columns of running plugins (`LoadedPlugins::running()`) that implement `HasListColumns`, whose enabled record (`PluginRecords::enabled()`) grants `content.read`, whose manifest (`PluginRegistry::all()[$name]->manifest->contributes()['columns']`) declares the type or no `types`; in plugin name order, then declared order.
  - `keysFor(string $type): list<string>`.
  - `cells(string $type, list<string> $shownKeys, list<ListRow> $rows): ColumnCells`.
  - Constants: `CALL_BUDGET_MS = 100`, `PAGE_BUDGET_MS = 300`, `MOST_TEXT = 80`, `MOST_BADGE = 24`.
- `DeclaredColumn` (readonly): `key`, `owner` (Composer name), `code`, `id`, `label`, `plugin` (label), `kind`, `width` (px), `toArray()` giving D10's `PluginColumn`.
- `ColumnCells` (readonly): `values` (D10's `pluginCells`), `problems` (list of plugin labels, each once).
- `ColumnClock` (interface, `nanoseconds(): int`), `SystemClock implements ColumnClock`, bound in `PluginServiceProvider::register()` with `$this->app->bind(ColumnClock::class, SystemClock::class)`.
- `ColumnTooSlow`, `ColumnAnswerRefused` (final, extend `RuntimeException`; messages name the column key and, for the first, the milliseconds taken).
- `ContributionsValidator` constants: `COLUMN_KINDS = ['text', 'number', 'badge']`, `COLUMN_WIDTHS = ['narrow' => 100, 'medium' => 128, 'wide' => 168]`, `COLUMN_ID` (same pattern as `LINK_ID`), `TYPE_KEY = '/^[a-z][a-z0-9_]{0,31}$/D'`, `MOST_COLUMNS = 3`.
- Fault id: `{pluginName}#column:{id}`; hook: `ListColumn::class`.

## The delicate code: asking under the budget

```php
public function cells(string $type, array $shownKeys, array $rows): ColumnCells
{
    if ($rows === [] || $shownKeys === []) {
        return new ColumnCells([], []);
    }

    $byKey = [];
    foreach ($this->for($type) as $column) {
        $byKey[$column->key] = $column;
    }

    $ids = array_fill_keys(array_map(fn (ListRow $row): string => $row->id, $rows), true);
    $listRows = new ListRows($type, array_values($rows));
    $running = $this->loaded->running();               // LoadedPlugins, read once per call
    $spent = 0;
    $values = [];
    $problems = [];

    foreach ($shownKeys as $key) {                       // the person's order (D4)
        $column = $byKey[$key] ?? null;
        if ($column === null) {
            continue;                                    // core's id, or a plugin no longer running
        }

        if ($spent >= self::PAGE_BUDGET_MS * 1_000_000) {
            $problems[$column->plugin] = true;           // not asked (D5)
            continue;
        }

        $started = $this->clock->nanoseconds();

        try {
            $handler = $running[$column->owner]->listColumns()[$column->id] ?? null;
            if (! $handler instanceof ListColumn) {
                throw new ColumnAnswerRefused("{$column->key} has no ListColumn.");
            }
            $given = $handler->cells($listRows);
        } catch (Throwable $failure) {
            $spent += $this->clock->nanoseconds() - $started;
            $this->fault($column, $failure);
            $problems[$column->plugin] = true;
            continue;
        }

        $took = $this->clock->nanoseconds() - $started;
        $spent += $took;

        if ($took > self::CALL_BUDGET_MS * 1_000_000) {
            $this->fault($column, new ColumnTooSlow($column->key, intdiv($took, 1_000_000)));
            $problems[$column->plugin] = true;
            continue;
        }

        $values[$column->key] = $this->kept($column, $given, $ids);
    }

    return new ColumnCells($values, array_keys($problems));
}
```

`kept()` (D6): iterate `is_array($given) ? $given : []`; for each `$id => $cell`, keep it only when `is_string($id) && isset($ids[$id]) && $cell instanceof Cell && $cell->kind === $column->kind`; otherwise count it as refused. For text and badge: `$clean = preg_replace('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $value)`; `null` (invalid UTF-8) drops the cell; then `trim(preg_replace('/\s+/u', ' ', $clean))`; drop when `''`; cut with `mb_substr($clean, 0, $most - 1).'…'` when `mb_strlen($clean) > $most`. For numbers: keep when `is_finite((float) $value)`. One `ColumnAnswerRefused` fault per call when anything was refused. A non-array answer is one refusal with nothing kept.

`fault()` resolves `ListenerFaults` from the container, as `InsertionPoints::fault()` does, and never throws.

## Tasks (each test first, seen red for the reason given)

### Task 0: checks

Record in Records: `git log --oneline --merges -5` (the join's merge is on `main`); `git tag -l 'v0.9.0*'` and the V it gives (D12); `grep -n "Later (I-200)" app/Domain/Preferences/BuiltInPreferences.php resources/js/components/list/data-table.tsx` (both present); `grep -n "'settings', 'admin', 'csp'" tests/Feature/Plugins/ContributionsSchemaTest.php` (present). A difference is a STOP.

### Task 1: the contract

- **Tests:** `tests/Unit/Contracts/PluginContractsTest.php`: add the five classes to its dataset with V. New `tests/Unit/Contracts/ListCellTest.php`:
  - "keeps text exactly as given, markup included" (`Cell::text('<b>x</b>')->value === '<b>x</b>'`, kind `text`, tone null);
  - "refuses a number that is not finite" (dataset `NAN`, `INF`, `-INF`: `InvalidArgumentException`);
  - "refuses a badge with an empty label or a tone it doesn't know" (`''`, `'pink'`);
  - "gives a badge the neutral tone unless told otherwise";
  - "lists a page's row ids in order" (`ListRows::ids()`).
- **Red:** `Class "Teme\Contracts\Lists\Cell" not found`.
- **Commit:** `feat(contracts): list columns: HasListColumns, ListColumn, ListRows, ListRow and Cell`

### Task 2: the manifest

- **Tests:** new `tests/Feature/Plugins/ListColumns/ColumnsManifestTest.php`, through `ManifestValidator::errors()` on copies of the fixture (`PluginFixtures::copy('columns', [...])`):
  - "accepts the fixture's three columns" (no errors);
  - "refuses columns from a plugin that doesn't ask for content.read" (exact D2 words);
  - a dataset "refuses a column that …" with its exact words: a bad id (`Score`), two columns with one id, an empty label, a label over 40 characters, an unknown kind (`chart`), an unknown width (`huge`), a type key `Post`, a `sortable` key (D8's words), a fourth column (`A plugin can add at most 3 columns.`), an unknown key (`A column can’t have :key.`, the key named).
  - **Modify** `ContributionsSchemaTest`: the keys become `['settings', 'admin', 'csp', 'columns']`, and the schema's column `kind` and `width` enums, `id` and `types` item patterns equal `COLUMN_KINDS`, `array_keys(COLUMN_WIDTHS)`, `COLUMN_ID` and `TYPE_KEY` (the test's `$between` helper as for links). Meaning kept: the schema and the validator agree.
- **Build:** the schema's `contributes.columns` (array, `maxItems` 3, items with `required: ["id", "label", "kind"]`, `additionalProperties: false`); `ContributionsValidator` accepts `columns` and checks D1 (the `sortable` words come before the unknown-key words); `ManifestValidator` adds D2's check beside the door checks. The fixture `tests/Fixtures/plugins/columns/` (`tests/columns`, code `columns`, label `Columns`, `capabilities: ["content.read"]`, columns `score` badge "SEO score", `views` number "Views" `types: ["post"]`, `note` text "Note" `width: "wide"`) with its plugin class implementing `Plugin` and `HasListColumns`. Its behavior per test (answer, throw, take a set time on the fake clock, answer the wrong shape, answer markup) is switched through one declared setting, as `tests/inserts` has one.
- **Red:** `Teme doesn’t offer the columns surface to plugins yet.`
- **Commit:** `feat(plugins): declare list columns under contributes.columns, with content.read`

### Task 3: start and the person's choice

- **Tests:** new `tests/Feature/Plugins/ListColumns/ColumnsStartTest.php`:
  - "fails to start a plugin that declares a column it has no handler for" (`It declares column "note" but has no handler for it.`), and "…that has a handler for a column it doesn't declare" (`It has a handler for column "extra", which isn’t declared.`), and "…that declares columns but doesn't implement HasListColumns" (`It declares columns but doesn’t implement Teme\Contracts\Lists\HasListColumns.`): `LoadedPlugins::failure()` holds the words, `PluginColumns::for(post)` is empty;
  - "offers a running plugin's columns for the types it names" (`for('post')` gives three `DeclaredColumn`s, keys `plugin:columns:score`, `plugin:columns:views`, `plugin:columns:note`, widths 100, 100, 168; `for('page')` gives `score` and `note` only);
  - "offers nothing from a disabled plugin, or from one whose consent lacks content.read" (the second by editing the record's consent in the test's own setup);
  - "saves a person's columns with a plugin column, through UpdatePreferences" (`['author', 'plugin:columns:score', 'date']` stored in that order);
  - "refuses a plugin column that isn't offered" (`plugin:columns:nope`; `plugin:columns:views` on `lists.page.columns`) and writes nothing;
  - "drops a disabled plugin's column from a person's columns and keeps the rest" (`PreferencesFor` gives `['author', 'date']`);
  - "never shows a plugin column by default" (`lists.post.columns`'s default holds no `plugin:` key).
  - Check, don't change: `ScreenOptionsTest` and `PreferencesTest` pass as they are.
- **Build:** `Contributions::add()` checks columns against `listColumns()` keys as `jobHandlers()` does for jobs, before anything is registered; it registers nothing for columns (they are read from the manifest when asked; see Names). `PluginColumns::for()` and `keysFor()`. `BuiltInPreferences`: `allowed` adds `...app(PluginColumns::class)->keysFor($type->key)`; `maxItems: null` with a one-line reason; the `Later (I-200)` line now says only row actions, bulk actions and filters wait.
- **Red:** the first case starts the plugin.
- **Commit:** `feat(plugins): offer a running plugin's list columns in each person's Columns`

### Task 4: asking for values

- **Tests:** new `tests/Feature/Plugins/ListColumns/ColumnCellsTest.php`, with a stepping fake `ColumnClock` and a recording `ListenerFaults` (`tests/Fixtures/plugins/ReportedFailures.php` if it fits; else a small fake beside it):
  - "asks a shown column once with every row of the page, in order, and gives its cells by row id" (the fixture records the `ListRows` it got: type, ids, status, language, `updatedAt`);
  - "never asks a column the person doesn't show, or any column for an empty page";
  - "asks columns in the person's order";
  - "leaves a throwing column empty, records the fault under its plugin, and fills the others" (owner `tests/columns`, id `tests/columns#column:note`, hook `ListColumn::class`; `problems` is `['Columns']`);
  - "drops a column's cells when its call takes more than 100 ms, and records ColumnTooSlow" (the fake clock steps 101 ms across the call);
  - "stops asking once 300 ms are spent on the page" (three columns at 99 ms each with a fourth plugin column from a second fixture copy: the fourth isn't asked, its plugin is in `problems`, no fault is recorded for it);
  - "keeps only cells of this page's rows and of the column's kind, and records one ColumnAnswerRefused" (an unknown id, a `Cell::number` in a badge column, a plain string);
  - a dataset "cleans text before it is shown": `"two\nlines"` → `two lines`; `"a\u{202E}b"` → `a b`; 100 characters → 79 and `…`; a 30-character badge → 23 and `…`; `"  "` → dropped; `"<img src=x onerror=alert(1)>"` → kept exactly.
- **Build:** `PluginColumns::cells()` and `kept()` as written above; `ColumnCells`, `ColumnClock`, `SystemClock` and its `bind`, the two exceptions.
- **Red:** `Call to undefined method App\Domain\Plugins\Columns\PluginColumns::cells()`.
- **Commit:** `feat(plugins): ask list columns once per page under a time budget, recording faults`

### Task 5: the list screen

- **Tests:** new `tests/Feature/Admin/PluginListColumnsScreenTest.php` (Inertia assertions on `content.index` for posts):
  - "offers the type's plugin columns and sends values only for those the person shows" (`pluginColumns` has three for posts; with `['plugin:columns:score']` chosen, `pluginCells` has only that key, one entry per row, `{kind: 'badge', value, tone}`);
  - "sends no plugin values to a person who hasn't chosen any" (`pluginCells` is `[]`);
  - "still lists the page when a plugin column throws, and names the plugin" (200; `pluginColumnProblems` `['Columns']`; `rows` complete);
  - "sends markup from a plugin as the text it is" (`pluginCells[...][id]['value']` is `<img src=x onerror=alert(1)>`);
  - "draws plugin cells without dangerouslySetInnerHTML" (reads `resources/js/pages/content/index.tsx` and `resources/js/components/list/plugin-cell.tsx`; neither contains the word).
- **Browser:** new `tests/Browser/PluginListColumnsTest.php`, 1280 and 375:
  - "turns on a plugin column in Columns and keeps it after a reload" (open Columns, the SEO score row shows its plug mark, tick, Apply; reload; the heading "SEO score" with a plug mark whose `sr-only` reads "From Columns"; each row's pill reads the fixture's label);
  - "shows a plugin's markup as text" (the Note column: the cell's `textContent` is the markup and the cell holds no `img`);
  - "says once that a column couldn't be shown, and keeps the rest of the list" (the throwing setting: dashes in that column, the status line names "Columns");
  - "puts a shown plugin column in a card's facts on a phone, after the core facts" (375: `data-card-facts` ends with the plugin key; no sideways scroll);
  - each case ends with `cmAxeViolations($page)` empty; number cells' right edge is the column's right edge (measured).
- **Build:** the controller reads the person's `lists.{type}.columns`, keeps its `plugin:` keys, maps `$page['rows']` to `ListRow`s, calls `PluginColumns::cells()`, and sends D10's props. `index.tsx` adds one `Column` per `pluginColumns` entry to `optional` (`owner` the plugin's label, `label`, header D9, `width`, `className: 'text-end tabular-nums'` for numbers, `cell` reading `pluginCells[key][row.id]` through `PluginCell`), passes `plugin` to the chooser's columns, draws the problems line, and extends the cards' facts (D9). New words, through `__()`: "No value", "Some plugin columns could not be shown this time: :plugins. The rest of the list is unaffected." Design-system and admin-words baselines: no line added, no count raised.
- **Red:** `Property [pluginColumns] does not exist`.
- **Commit:** `feat(admin): draw plugin columns in the content lists, marked, escaped and off by default`

### Task 6: the handbook and the records

- `docs/guides/developers/plugins/list-columns.md` (beside `jobs.md`, same shape): what a column is for; the manifest entry; `HasListColumns` with a short example (an SEO score as a badge, read from the plugin's own table filled on `ContentSaved`); the budget and what happens over it; text only, never HTML; no sorting yet; off until a person turns it on; `content.read`.
- `docs/architecture/plugins.md`: in "Where plugins appear", surface 1's Budget cell adds "List columns: at most 3 per plugin, asked once per page under 100 ms each and 300 ms a page (`docs/guides/developers/plugins/list-columns.md`)".
- Regenerate `docs/reference/plugin-manifest.md` with `php artisan teme:docs:manifest --output=docs/reference` (`GenerateManifestReferenceTest` compares it).
- **Commit:** `docs(plugins): list columns in the handbook, the architecture and the manifest reference`

## Quick check

The `.ai/rules/builders.md` gate after each task, with `php artisan test --parallel --compact tests/Feature/Plugins/ListColumns`, `tests/Unit/Contracts`, `tests/Feature/Plugins/ContributionsSchemaTest.php` and, from Task 5, `tests/Feature/Admin/PluginListColumnsScreenTest.php`. Once at the end: `composer check`, and `composer check:browser` for `tests/Browser/PluginListColumnsTest.php` plus `ContentListPatternTest` and `ListHeaderTest` (the screens this part changes).

## What must not be done

- Add a singleton, a migration, a route, a permission, a capability or a dependency.
- Accept HTML, a link, an icon or a color from a plugin's cell; draw a cell with `dangerouslySetInnerHTML`.
- Sort or filter by a plugin column; add plugin columns to Categories, Tags, Comments or Media.
- Edit `components/ui/**`, `components/patterns/**`, a token, or a baseline upward.
- Change `UpdatePreferences` or `PreferenceDefinition`.

## STOP conditions

Stop, write why under Records, commit and push, then stop, when: a Task 0 line differs; a decision (D1 to D13) or the contract (D3) does not fit the code; making it work needs a singleton, an `.ai/` edit or a wider allow-list; the words people read would have to change.

## Records (the builder's)

### Commits on `plugins/list-columns`

-

### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|
| | | |

### Task 0

-

### Architecture baselines

| Baseline | Before | After |
|---|---|---|
| design-system-baseline.txt | | |
| admin-words-baseline.txt | | |

### Gate

-

### Drafts (B-numbers from this block)

- B-01:
- B-02:
- B-03:
- B-04:
- B-05:
- B-06:
- B-07:
- B-08:
- B-09:
- B-10:
- B-11:
- B-12:
- B-13:
- B-14:
- B-15:
- B-16:
- B-17:
- B-18:
- B-19:
- B-20:

### Contract changes

-

### STOP

-

### Changelog lines for the maintainers

-

### Try it

-
