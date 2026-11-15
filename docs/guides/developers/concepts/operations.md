---
title: Operations
type: concept
audience: plugin
summary: Every change goes through an Operation; what a plugin may and may not touch.
rests_on: [docs/architecture/operations.md, docs/architecture/plugins.md, packages/teme-contracts/src/Plugins]
---

# Operations

Every state mutation in Teme goes through an Operation: create a draft, publish a revision, enable a plugin, update settings. Controllers, console commands, queue jobs, and AI tools call Operations. None of them write Eloquent models directly.

## What that means for a plugin

A plugin does not open the database and change a row. When plugins gain content-write abilities, they will call the same Operations the admin uses, so every change is a normal revision with the same permission checks and hooks.

Until those sockets land, a plugin with `content.read` may listen and read through the contracts Teme hands its constructor — for example the settings reader — and must not reach into application code under the App namespace.

## What a plugin may touch

- Contracts in `teme/contracts` that its constructor asks for and its capabilities unlock.
- Hooks it declared and was granted.
- Its own classes under its package namespace.

## What a plugin may not touch

- Classes in Teme’s own App namespace.
- The container.
- Extending Laravel framework classes for its plugin entry point.
- Writing code or files into the running site from a web request.

Teme scans a plugin’s code at enable and refuses packages that reach into Teme’s own code. Treat that refusal as a hard stop: move the work behind a contract or an Operation, do not work around the scan.
