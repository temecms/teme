---
title: The developer handbook
type: concept
audience: both
summary: The index of Teme's handbook for people and agents who build plugins and themes.
rests_on: [docs/guides/developers, docs/architecture/documentation.md, docs/architecture/plugins.md]
---

# The developer handbook

This handbook is for people and agents who build Teme plugins and themes, and the services outside a site that write to it. It sits beside the code in this repository. Reference pages under `docs/reference/` are generated; these guides are written.

It does not cover running a server or writing content. Those have their own guides.

## The order to read it in

1. [Plugin or theme?](start/plugin-or-theme.md), then [Your first plugin](start/first-plugin.md) or [Your first theme](start/first-theme.md), and [Set up a development site](start/development-site.md).
2. [The manifest](concepts/manifest.md) and [Hooks](concepts/hooks.md).
3. [Operations](concepts/operations.md), [Code standards for plugins and themes](start/code-standards.md), then [Testing a plugin or theme](testing.md).
4. For a plugin, [Plugin settings and secrets](plugins/settings.md). For a theme, [Regions, site code and featured posts](themes/regions.md).
5. [Rules for plugins and themes](rules/plugins-and-themes.md) before you publish one.
6. [For agents](for-agents.md) if you are building with an agent.

## Sockets for plugins and themes

| Page | Built in |
|---|---|
| [Plugins writing content](plugins/content-write.md) | plugins/content-write |
| [Jobs and schedules](plugins/jobs.md) | plugins/jobs |
| [Public routes](plugins/public-routes.md) | plugin public routes |
| [The plugin import socket](plugins/import.md) | plugin import socket |
| [Content inside articles](plugins/insertions.md) | render/insertion-points |
| [The fediverse](plugins/fediverse.md) | fediverse/actors and fediverse/publishing |
| [List columns](plugins/list-columns.md) | plugins/list-columns |
| [The site's AI connection](plugins/ai.md) | sockets3/plugin-ai |
| [Languages in a theme](themes/languages.md) | i18n/readers and i18n/screens |
| [The management API](outside/management-api.md) | api/manage-foundation |

## Generated reference

| Page | What it lists |
|---|---|
| [Hooks](../../reference/hooks.md) | Every hook class in `teme/contracts` |
| [Plugin manifest](../../reference/plugin-manifest.md) | Keys of `teme-plugin.json` |
| [Theme manifest](../../reference/theme-manifest.md) | Keys of `teme-theme.json` |
| [Theme view data](../../reference/theme.md) | What a theme template can read |
