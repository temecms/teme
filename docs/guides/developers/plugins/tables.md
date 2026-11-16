---
title: Your plugin's own tables
type: how-to
audience: plugin
summary: How a plugin creates tables of its own with migrations, the names they must have, how they are read and written, run after an update, and removed.
rests_on: [app/Domain/Plugins/Tables, app/Domain/Plugins/Operations, app/Console/Commands/MigratePluginsCommand.php, app/Console/Commands/RemovePluginDataCommand.php, docs/architecture/plugins.md]
---

# Your plugin's own tables

Most plugins need no table: settings hold their configuration, and content holds what people write. When a plugin keeps records of its own, such as the links it shortened or the deliveries it made, it creates tables for them with migrations. Teme runs them, checks what each one created, and records which tables are the plugin's.

## Name and list them

Your tables' names start with your plugin's code, hyphens written as underscores, then an underscore: a plugin with the code `link-helper` names its tables `link_helper_links`, `link_helper_clicks` and so on. A name is lower-case letters, digits and underscores, starting with a letter, at most 63 characters.

List every table under `owns.tables` in `teme-plugin.json`:

```json
"owns": { "tables": ["link_helper_links", "link_helper_clicks"] }
```

## Write the migrations

Put Laravel migrations in your package's `database/migrations` folder, each file returning a migration as Laravel's own do, named so that they sort in the order they must run. Write only `up()`: Teme never runs `down()`, and doesn't roll a plugin back.

Teme runs each migration inside a transaction, and lists the database's tables before and after it. A table it created must start with your prefix, be listed under `owns.tables`, and not be another plugin's; a table it dropped must be one your plugin created. Otherwise the whole run is undone and the plugin isn't enabled. A migration that asks to run outside a transaction is refused.

Teme can't see a migration change one of Teme's own tables, so never write one that does: a plugin is trusted code, and the site's owner trusts it with their database.

## When they run

Enabling a plugin runs its migrations that haven't run, in the same transaction that enables it. When an update adds migrations, the plugin stops starting, and its public routes stop answering, until they run. Someone who manages plugins runs them from the plugin's row in **Plugins**, which asks for their password, or the operator runs `teme:plugins:migrate` with the plugin's Composer name, or with none for every plugin waiting. Teme records each migration that ran on the plugin's row, with the version that ran it and the tables it created.

## Read and write them

Nothing in `teme/contracts` covers a plugin's own tables. A plugin may use Laravel's query builder on its own tables, and only on them, never on Teme's: Teme's tables are written through Operations, so that every change is checked, recorded and announced. Using the query builder ties your plugin to the version of Laravel that Teme runs; keep that code in one class, so an upgrade touches one place.

A public route's handler is built with none of your plugin's doors, so reading your own tables is how it answers from what your plugin has recorded.

## Remove them

Disabling a plugin keeps its tables and their rows, so enabling it again finds them. To drop them, disable the plugin first; then someone who manages plugins removes its data from its row in **Plugins**, after their password, or the operator runs `teme:plugins:remove-data` with its Composer name. Teme drops the tables it recorded for that plugin, and keeps the plugin's row and what was agreed to. It reads the tables from that record alone, so it works even when the plugin's code is gone.

Next: [Public routes](public-routes.md), and [Jobs and schedules](jobs.md).
