# Slice 5.2, first release: a plugin's own tables

**One part, one branch: `plugins/tables`, from `origin/main`** after the admin redesign's wave 2 has merged (the Plugins screen's files are wave 2's until then). It lets a plugin ship Laravel migrations for tables of its own: run when it is enabled, run again for what a new version adds, checked against what it declares, recorded on its row, and removed on request.

Steps use checkbox (`- [ ]`) syntax for tracking.

**Written 2026-11-15** for the repository `temecms/teme`. This plan is lighter (I-569): it writes out the decisions, names, schema, permissions, tests and the delicate code, and describes the rest.

**Goal.** A plugin such as an internal-linking plugin, with dozens of tables of its own, can be built for Teme: its tables appear when it is enabled, change in step with its versions, and go when someone removes them, and Teme always knows which tables are whose.

**Tech stack:** Laravel 13, PHP 8.4, PostgreSQL 18, Inertia 3, React 19, Pest 5 with the browser plugin.

**Spec:** `docs/proposals/plugin-tables.md` (its Settled section is binding; every item in it is confirmed). `docs/architecture/plugins.md` › Lifecycle, Plugin data. `docs/studies/2026-11-11-plugin-api.md` §7 (Craft's install migration in a transaction; October's and Winter's recorded versions; Drupal's checks before removal, parked here).

**Bound by:** I-3442 (this release's scope, the maintainers' trim, and what is parked), I-3353 (the plugin API's first release: rows, consent, safe mode), I-3365, I-3396 (a wall is a missing socket), I-3323 (small releases), rule 1 (every change through an Operation), rule 2 (no key-value table: the record is JSONB on the row), rule 6 (cache and queue restarts after commit), I-3439 (American English), I-569, I-3317.

**New dependencies:** none. **Migrations:** one, adding a column. **Permissions:** none new (`plugins.manage`). **Operations:** `MigratePlugin`, `RemovePluginData`; `EnablePlugin` changes. **Routes:** two. **Contract:** `owns` in `packages/teme-contracts/schema/teme-plugin.schema.json`. **Drafts:** `B-?? (draft)` for plan faults.

---

## What main does today (Task 0 confirms each, with the line it finds)

- Laravel's package discovery leaves every `teme-plugin` package out, so a plugin's service providers are never registered (`app/Domain/Plugins/PackagesWithoutPlugins.php:11-16`, bound at `bootstrap/app.php:197`). A provider's `loadMigrationsFrom()` never runs.
- A plugin's class is built by `PluginLoader::start()` and only calls `register(Hooks $hooks)` (`packages/teme-contracts/src/Plugins/Plugin.php`). It has no install or upgrade step.
- `migrate` and `migrate:*` are safe commands (`app/Domain/Plugins/SafeMode.php:37`): no plugin starts during them.
- The manifest validator ignores keys it doesn't know (`ManifestValidator::errorsGiven()`), so `owns` is read by nothing today.

So a plugin's migrations are never run, by anything. A plugin could create tables from its own code while it runs, untracked. This part gives it the supported way.

## The decisions, written out

**T1. Where migrations live.** `database/migrations/*.php` in the plugin's folder, each returning a Laravel migration (`return new class extends Migration { … }`), as Laravel's own do. Read in name order. A plugin with no such folder has no migrations, and nothing changes for it.

**T2. `owns.tables` in the manifest.**

```json
"owns": { "tables": ["link_helper_links", "link_helper_clicks"] }
```

The prefix is the plugin's code with `-` written `_`, then `_` (`link-helper` → `link_helper_`). The validator checks: `owns` is an object whose only key is `tables`; `tables` is a list of distinct strings; each matches `^[a-z][a-z0-9_]*$`, starts with the prefix, and is at most 63 characters (PostgreSQL's limit). A plugin with migrations and no `owns.tables`, or the reverse, is fine: the run's check (T4) decides. Add `owns` to `teme-plugin.schema.json` with a description and the release it ships in.

**T3. The record: `plugins.migrations`**, a migration adding to `plugins`:

```php
$table->jsonb('migrations')->default('{}'); // migration name => {version, ran_at, tables: [...]}
```

and a check `jsonb_typeof(migrations) = 'object'`. The name is the file's name without `.php`. `version` is the plugin's version that ran it; `ran_at` an ISO time; `tables` the tables that run created. **The plugin's tables** are every `tables` entry across the record, less any later dropped (T4 removes a dropped table from the entry that created it), that still exist.

**T4. The runner, `App\Domain\Plugins\Tables\PluginMigrations`** (delicate; written out). Called inside an Operation's transaction, with the plugin's row locked.

```php
/**
 * Runs a plugin's migrations that haven't run, in name order, inside the
 * caller's transaction (PostgreSQL changes tables transactionally), and
 * checks what each created and dropped against the plugin's manifest.
 * Returns the row's new record. Throws PluginTablesRefused, which undoes
 * the whole run with the caller's transaction.
 */
public function run(FoundPlugin $plugin, array $record): array
{
    foreach ($this->waiting($plugin, $record) as $name => $file) {
        $migration = (static fn (string $path): object => require $path)($file);

        if (! $migration instanceof Migration) {
            throw PluginTablesRefused::notAMigration($plugin, $name);
        }

        if ($migration->withinTransaction === false) {
            throw PluginTablesRefused::outsideTransaction($plugin, $name);
        }

        $before = $this->tables();
        $migration->up();
        $after = $this->tables();

        $created = array_values(array_diff($after, $before));
        $dropped = array_values(array_diff($before, $after));

        $this->check($plugin, $record, $name, $created, $dropped); // T5
        $record = $this->forgetDropped($record, $dropped);
        $record[$name] = ['version' => $plugin->manifest->version(), 'ran_at' => now()->toIso8601String(), 'tables' => $created];
    }

    return $record;
}

/** The tables in Teme's schema now, by name. */
private function tables(): array
{
    return DB::table('pg_tables')->where('schemaname', DB::raw('current_schema()'))->pluck('tablename')->all();
}
```

`waiting()` lists `database/migrations/*.php` under `$plugin->path`, sorted by name, less the names in `$record`. A migration whose file is gone stays in the record.

**T5. The check.** A run is refused, and nothing it did stays, when:
- a created table doesn't start with the plugin's prefix, or isn't in `owns.tables`;
- a created table starts with the longer prefix of another installed plugin (codes `acme` and `acme-x`: `acme_x_links` is `acme-x`'s), or is in another plugin's record;
- a dropped table isn't one of this plugin's tables (T3).
`PluginTablesRefused` carries a sentence naming the migration and the table, for the screen and the console: "The migration 2026_01_01_000000_create_links of Link Helper created the table links, which isn't one it lists under owns.tables." Teme can't see a migration change one of Teme's own tables: plugins are trusted code (`docs/architecture/plugins.md` › Trust). Record that in the runner's docblock.

**T6. `EnablePlugin`** runs `PluginMigrations::run()` in `perform()`, after locking the row and before setting it enabled, and saves the returned record on the row. A refusal becomes a `ValidationException` on `plugin` with the runner's sentence, so the transaction undoes the row's insert, the record and every table, and the plugin stays as it was.

**T7. Changes waiting.** A plugin whose `database/migrations` holds a name its record lacks has changes waiting. Read cheaply: `PluginRegistry`'s discovery (cached by `teme:plugins:cache`) gains each plugin's migration names, as a new `public array $migrations` (list of names) on `FoundPlugin`; the record comes with the enabled rows `PluginRecords` already caches. Then:
- `PluginLoader::start()` doesn't start an enabled plugin with changes waiting (no fault recorded: it isn't a failure).
- `PluginList` shows it as `PluginState::Waiting`, with the reason `__('Version :version has database changes to run.', ['version' => …])`, after the consent check (an update that asks for more access waits for that first).
- The Plugins row's action for it is **Run database changes** (`data-test="plugin-migrate"`), through the password screen as Enable is.

**T8. `MigratePlugin`** (`RequiresElevation`, `AuthorizesPluginChanges`). Input: the plugin's name. Validates that the plugin is found, usable, enabled, and its consent covers what it asks (else "Look again before you run its changes."). Locks the row, runs the runner, saves the record, records `SecurityEventType::PluginMigrated` (new case) with the names run, and after commit calls `PluginRecords::changed()`. Returns the names run (empty when none waited). Route: `POST admin/plugins/{code}/migrate`, name `plugins.migrate`. Command: `teme:plugins:migrate {name?}`: one plugin, or every enabled plugin with changes waiting, as the operator; prints each name run, or the refusal, and ends non-zero on a refusal.

**T9. `RemovePluginData`** (`RequiresElevation`, `AuthorizesPluginChanges`). Input: the plugin's name. Validates that the plugin has a row, is not enabled (disabled, or its code gone), and has tables (T3). **It works from the row alone:** the tables come from `plugins.migrations` (T3), never from the plugin's code or manifest, so it works for a plugin whose code is gone (`PluginState::CodeGone`, `PluginLoader.php:69`, `PluginList.php:107`), which the registry no longer finds. Locks the row; `DROP TABLE` the plugin's tables in one statement, each name quoted with `DB::getQueryGrammar()->wrapTable()`, **without** `CASCADE` (a Teme table never points at a plugin's, and if something does, the drop fails and nothing is lost); empties the record (`'{}'`); keeps the row and its consent. Records `SecurityEventType::PluginDataRemoved` with the tables. After commit, `PluginRecords::changed()`. Returns the tables dropped. Route: `POST admin/plugins/{code}/remove-data`, name `plugins.remove-data`. Command: `teme:plugins:remove-data {name} {--force}`: lists the tables and their approximate rows, asks unless `--force`.

**T10. "Remove its data…"** in the Plugins row's "…" menu, shown only when the row is not enabled and has tables (the screen's row gains `tables: list<{name, rows}>`, rows from `pg_class.reltuples`, `-1` read as unknown). It opens a confirmation dialog: title `__('Remove :name’s data?', …)`, the line `__('These tables and everything in them are deleted. This can’t be undone.')`, the list of tables with "about :count rows" each, the danger button `__('Remove data')`. Then the password screen, as Enable. A plugin's tables appear nowhere else.

**T11. Permissions.** `plugins.manage` with the password confirmed, or the operator, for `MigratePlugin` and `RemovePluginData`, as for `EnablePlugin`. Nothing is shown to anyone without `plugins.manage` (the routes sit in the existing `can:plugins.manage` group).

## Tasks

### Task 0: confirm the start

- [ ] Read every file named under "What main does today" and confirm each statement with its line. Write a lasting test in `tests/Feature/Plugins/PluginTablesTest.php`: a fixture plugin with a migration, enabled by a factory row, and `php artisan migrate` run: its table doesn't exist afterwards. Record any difference as `B-?? (draft)` and follow the code.

### Task 1: the fixture, the manifest and the record

**Files:** `tests/Fixtures/plugins/tables/` (new: `composer.json`, `teme-plugin.json` with `owns.tables` `["tables_links", "tables_clicks"]`, `src/Tables.php`, `database/migrations/2026_01_01_000000_create_tables_links.php`, `2026_01_02_000000_create_tables_clicks.php`, with a foreign key from clicks to links); `ManifestValidator`; `teme-plugin.schema.json`; the migration adding `plugins.migrations`; `FoundPlugin`, `PluginRegistry`.

- [ ] Tests first (`ManifestTest`, `ManifestSchemaTest`): each `owns` error in T2, by its sentence; a valid `owns` passes; the schema accepts it. `DiscoveryTest`: the fixture's migration names are found, in order.
- [ ] Build; the quick check.

### Task 2: the runner and enabling

**Files:** `app/Domain/Plugins/Tables/PluginMigrations.php`, `PluginTablesRefused.php`; `EnablePlugin`.

- [ ] Tests first, in `PluginTablesTest`: enabling the fixture creates both tables and records both names with the version and tables; enabling a copy (`PluginFixtures::copy`) whose second migration creates `links` (no prefix), or a table not listed, or drops a core table, is refused with the sentence, and afterwards **no** table it made exists, the row is as before, and the plugin is not enabled; a migration with `$withinTransaction = false` is refused; a copy coded `tables-x` beside `tables` can't create `tables_x_…` from `tables`; enabling again with nothing waiting runs nothing.
- [ ] The existing `EnablePluginTest` cases still pass, with their meaning.
- [ ] Build; the quick check.

### Task 3: changes waiting and `MigratePlugin`

**Files:** `MigratePlugin`, its input, `SecurityEventType`, `PluginLoader`, `PluginList`, `PluginsController::migrate`, the route, `MigratePluginsCommand`.

- [ ] Tests first: after a third migration is added to an enabled copy, the loader doesn't start it and `PluginList` shows Waiting with the reason; `MigratePlugin` runs only the third and records it, and then the plugin starts; a refused run leaves the record and tables as they were; **refused when the actor lacks `plugins.manage`, and writes nothing** (record, tables, security log compared); without the password it asks for it; the console command runs it as the operator, and ends non-zero on a refusal.
- [ ] Build; the quick check.

### Task 4: `RemovePluginData`

**Files:** `RemovePluginData`, its input, `SecurityEventType`, `PluginsController::removeData`, the route, `RemovePluginDataCommand`.

- [ ] Tests first: refused while enabled; refused with no tables; for a disabled plugin, drops both tables (the foreign key between them is no obstacle), empties the record, keeps the row and its consent; **code gone:** the fixture is enabled (both tables made), then its folder is removed (`PluginFixtures::forget()` or a copy deleted) so the registry no longer finds it, and `RemovePluginData` drops exactly its two recorded tables, no other table changed, the record emptied; **refused for an actor without `plugins.manage`, and writes nothing** (tables still there, record unchanged); enabling afterwards creates the tables again; the command lists the tables and asks unless `--force`.
- [ ] Build; the quick check.

### Task 5: the Plugins screen

**Files:** `PluginsScreen`/`PluginRow` (the `tables` list; the waiting action), `pages/plugins/index.tsx` and its row component, a new `components/plugins/remove-data-dialog.tsx`.

- [ ] Browser tests first, in `tests/Browser/Plugins/PluginsScreenTest.php`: a plugin with changes waiting shows the reason and Run database changes, which goes through the password screen and back, after which the plugin is Enabled; a disabled plugin with tables offers Remove its data… in its menu, the dialog lists both tables, and confirming removes them; a code-gone row (the fixture enabled, then its folder removed) offers Remove its data… and removes both tables; an enabled plugin's menu doesn't offer it; axe clean in light and dark at 1280 and 375.
- [ ] Every new word through `__()`; the design-system and admin-words lists only shrink.
- [ ] `npm run build`; the touched browser file; the quick check.

### Task 6: the documents and records

- [ ] `docs/architecture/plugins.md`: Plugin data says where migrations live, the prefix, `owns.tables`, the record on the row (not `plugin_history`, which is parked with the broader history, I-3442), and the check; Lifecycle says when migrations run; "Not built yet" lists the parked items. The `teme:plugins:*` commands' help in the docs where the others are listed.
- [ ] The full `composer check` once. Fill Records; changelog lines for the maintainers.

## Quick check

`php artisan test --compact tests/Feature/Plugins tests/Architecture`, then `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse --memory-limit=2G` on the changed files, and `npx vp check` on changed front-end files.

## Owns

`app/Domain/Plugins/**`, `app/Http/Admin/PluginsController.php`, the two new console commands, the plugins routes in `routes/admin.php`, the one migration, `packages/teme-contracts/schema/teme-plugin.schema.json`, `resources/js/pages/plugins/**`, `resources/js/components/plugins/**`, `tests/Feature/Plugins/**`, `tests/Browser/Plugins/PluginsScreenTest.php`, `tests/Fixtures/plugins/tables/**`, `docs/architecture/plugins.md`. Shared, may only shrink: `tests/Architecture/*baseline*` and their counts.

## Records

(The builder fills this.)
