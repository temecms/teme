# A plugin's own tables: created, upgraded and removed

| | |
|---|---|
| Status | draft |
| Created | 2026-11-15 |
| Authors | TemeCMS maintainers |
| Issue | none yet |
| Plan | docs/plans/plugin-tables.md |
| Release | — |
| Replaces | — |

## The problem

A plugin can keep its data in Teme's own places today: its settings, fields on content, Teme's lists. A plugin that needs tables of its own has no way to get them. Nothing runs a plugin's migrations: Laravel never sees a plugin's service providers, a plugin's class only subscribes to hooks, and `php artisan migrate` is a safe command, so no plugin even starts during it. A plugin could create tables from its own code at run time, but then Teme wouldn't know they exist, couldn't upgrade them in step with the plugin, and couldn't remove them.

Real plugins need this. An internal-linking plugin, for example, keeps about 45 tables of its own on WordPress: links, keywords, clicks, suggestions, queues and logs, one of them over 100 MB.

## What we will build

The first release of slice 5.2, and only this:

**Tables created with the plugin.** A plugin ships Laravel migrations in its `database/migrations` folder and lists its tables in its manifest under `owns.tables`, each named with its prefix. When it is enabled, Teme runs its migrations that haven't run, in name order, inside the enable's own transaction. PostgreSQL changes tables inside a transaction, so if one migration fails, or creates a table that isn't the plugin's, nothing is left: the plugin stays disabled and the screen says why. Each migration that runs is recorded on the plugin's row: its name, the plugin's version, when, and the tables it created.

**Upgrades run only what's new.** When a new version of an enabled plugin brings new migrations, the plugin doesn't start until they run, so its code never meets the old tables. The Plugins screen shows it as needing attention, with "Run database changes". The same runs from the console with `php artisan teme:plugins:migrate`.

**Removing a plugin's tables.** For a plugin that is disabled, or whose code is gone, the Plugins screen offers "Remove its data…". The dialog lists the tables Teme recorded for it, with their approximate rows, and removing them asks for the password. Teme drops exactly those tables and forgets their records. Enabling the plugin again creates them afresh.

## The decisions

1. **Migrations run when the plugin is enabled, and when someone runs a new version's changes**, never on an ordinary page load: changing tables takes locks, and two processes must never race to do it.
2. **One transaction for all of a plugin's waiting migrations.** A migration that can't run inside a transaction (Laravel's `$withinTransaction = false`, needed for example by `CREATE INDEX CONCURRENTLY`) is refused, with the reason, in this release.
3. **Teme checks what each run created**, by listing the database's tables before and after: every new table must start with the plugin's prefix and be listed in `owns.tables`, and a table the run dropped must be one the plugin created. Otherwise the run is undone. Teme can't see a migration that changes one of Teme's own tables: plugins are trusted code, and the consent screen already says so.
4. **The record is a JSONB object on the plugin's row** (`plugins.migrations`), keyed by migration name, as the row's faults are. No new table.
5. **Removing data is its own Operation**, separate from disabling: disabling keeps everything, so switching a plugin off and on loses nothing.
6. **The same permission and password as enabling:** `plugins.manage`, with the password confirmed, or the operator at the server's console.

## In and not in

**In:** `owns.tables` in the manifest and its schema; the migration runner with its table check; the record on the plugin's row; the plugin held back while it has changes waiting; "Run database changes" on the Plugins screen and `teme:plugins:migrate`; "Remove its data…" on the Plugins screen and `teme:plugins:remove-data`; tests, including that each Operation writes nothing when refused.

**Not in**, each parked with what brings it back:
- **Fuller safe mode** (the basic safe mode stays as it is). Back when a plugin locks someone out in a way the safe pages don't cover.
- **Undo scripts** (a migration's `down()`), and rolling a plugin back to an earlier version. Back when a plugin needs to go back a version without losing its data.
- **Deeper checks before removal**, such as refusing while content uses a card or field type the plugin provides, or while another plugin needs it. Back with the first plugin that provides a card or field type, or depends on another plugin.
- **A broader, append-only history** of every install, upgrade and removal. Back when someone needs to see a plugin's past beyond what its row records.
- **Removing a plugin's settings and field values** with its tables. Back with the deeper checks above.
- **Running plugins' changes during `teme:deploy`.** Back when an updated plugin waiting for its changes after a deploy becomes a real nuisance on a live site.
- **Migrations outside a transaction.** Back with the first plugin that needs one.

## How it fits

- **Server:** a migration adding `migrations jsonb not null default '{}'` to `plugins`; the runner and its check in `App\Domain\Plugins\Tables`; two new Operations, `MigratePlugin` and `RemovePluginData`; `EnablePlugin` calls the runner; the loader holds back a plugin with changes waiting; two routes beside enable and disable; two console commands.
- **Contract:** `owns` in `packages/teme-contracts/schema/teme-plugin.schema.json` (documented with the release it ships in).
- **Screen:** the Plugins row's state and action for changes waiting; "Remove its data…" in the row's menu, with its dialog.
- **Dependencies:** none.

## Settled

Every item below is confirmed (2026-11-15).

1. Migrations run at enable and on "Run database changes", inside one transaction, with the table check. (confirmed, 2026-11-15)
2. The prefix is the plugin's code with hyphens written as underscores, then `_`: the code `link-helper` owns tables named `link_helper_…`, as the plugin document's example `acme_link_index` does. (confirmed, 2026-11-15)
3. A plugin with changes waiting doesn't start until someone runs them; a deploy doesn't run them in this release. (confirmed, 2026-11-15)
4. Removing a plugin's tables is offered only while it is disabled or its code is gone, and keeps its row, with what was agreed to. (confirmed, 2026-11-15)
5. **Removing data works for a plugin whose code is gone** (`PluginState::CodeGone`): it reads the tables recorded in `plugins.migrations`, never the plugin's code, so a plugin deleted from the site's build can still have its tables removed. (confirmed, 2026-11-15)
