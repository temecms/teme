---
title: The developer handbook
type: concept
audience: both
summary: The index of Teme's handbook for people and agents who build plugins and themes.
rests_on: [docs/guides/developers, docs/architecture/documentation.md, docs/architecture/plugins.md]
---

# The developer handbook

This handbook is for people and agents who build Teme plugins and themes. It sits beside the code in this repository. Reference pages under `docs/reference/` are generated; these guides are written.

It does not cover running a server or writing content. Those have their own guides.

## The order to read it in

1. [Plugin or theme?](start/plugin-or-theme.md), then [Your first plugin](start/first-plugin.md) or [Your first theme](start/first-theme.md).
2. [The manifest](concepts/manifest.md) and [Hooks](concepts/hooks.md).
3. [Operations](concepts/operations.md), then [Testing a plugin or theme](testing.md).
4. [For agents](for-agents.md) if you are building with an agent.

## Pages that land with later work

| Page | Lands with |
|---|---|
| [Plugins writing content](plugins/content-write.md) | plugins/content-write |
| [Jobs and schedules](plugins/jobs.md) | plugins/jobs |
| [Public routes](plugins/public-routes.md) | plugin public routes |
| [The plugin import socket](plugins/import.md) | plugin import socket |

## Generated reference

| Page | What it lists |
|---|---|
| [Hooks](../../reference/hooks.md) | Every hook class in `teme/contracts` |
| [Plugin manifest](../../reference/plugin-manifest.md) | Keys of `teme-plugin.json` |
| [Theme manifest](../../reference/theme-manifest.md) | Keys of `teme-theme.json` |
| [Theme view data](../../reference/theme.md) | What a theme template can read |
