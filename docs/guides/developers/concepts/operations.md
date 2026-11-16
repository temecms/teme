---
title: Operations
type: concept
audience: plugin
summary: Every change goes through an Operation; what a plugin may and may not touch.
rests_on: [docs/architecture/operations.md, docs/architecture/plugins.md, packages/teme-contracts/src/Plugins, app/Domain/Plugins/Seams/PluginServices.php, app/Domain/Plugins/PluginLoader.php]
---

# Operations

Every state mutation in Teme goes through an Operation: create a draft, publish a revision, enable a plugin, update settings. Controllers, console commands, queue jobs, and AI tools call Operations. None of them write Eloquent models directly.

## What that means for a plugin

A plugin never changes Teme's own rows. When it writes content, it does so through the content door, `ContentWriter`, which calls the same Operations the editor uses, on behalf of a named person: every change is a normal revision, with the same permission checks and hooks. See [Plugins writing content](../plugins/content-write.md).

What a plugin may do is set by the contracts Teme hands its constructor, each given only for a capability the plugin was granted. The [contracts reference](../../../reference/contracts.md) lists them, with the capability that gives each; a constructor that asks for anything else is refused.

The one place a plugin opens the database is its own tables: those its migrations created and its manifest lists, never Teme's. See [Your plugin's own tables](../plugins/tables.md).

## What a plugin may touch

- Contracts in `teme/contracts` that its constructor asks for and its capabilities unlock.
- Its own tables, through Laravel's query builder.
- Hooks it declared and was granted.
- Its own classes under its package namespace.

## What a plugin may not touch

- Classes in Teme’s own App namespace.
- The container.
- Extending Laravel framework classes for its plugin entry point.
- Writing code or files into the running site from a web request.

Teme scans a plugin’s code at enable and refuses packages that reach into Teme’s own code. Treat that refusal as a hard stop: move the work behind a contract or an Operation, do not work around the scan.
