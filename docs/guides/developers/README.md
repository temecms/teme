---
title: The developer handbook
type: concept
audience: both
summary: The index of Teme's handbook for people and agents who build plugins and themes.
rests_on: [docs/guides/developers, docs/architecture/documentation.md, docs/architecture/plugins.md, docs/reference/contracts.md]
---

# The developer handbook

This handbook is for people and agents who build Teme plugins and themes, and the services outside a site that write to it. It sits beside the code in this repository. Reference pages under `docs/reference/` are generated; these guides are written.

It does not cover running a server or writing content. Those have their own guides.

## The order to read it in

1. [Plugin or theme?](start/plugin-or-theme.md), then [Your first plugin](start/first-plugin.md) or [Your first theme](start/first-theme.md), and [Set up a development site](start/development-site.md).
2. [The manifest](concepts/manifest.md) and [Hooks](concepts/hooks.md).
3. [Operations](concepts/operations.md), [Code standards for plugins and themes](start/code-standards.md), then [Testing a plugin or theme](testing.md).
4. For a plugin, [Plugin settings and secrets](plugins/settings.md) and [Your plugin's own tables](plugins/tables.md). For a theme, [Regions, site code and featured posts](themes/regions.md).
5. [Rules for plugins and themes](rules/plugins-and-themes.md) before you publish one, then [Listing your plugin or theme in Teme's directory](start/publishing.md).
6. [For agents](for-agents.md) if you are building with an agent.

## Sockets for plugins and themes

- [Plugins writing content](plugins/content-write.md)
- [Reading content](plugins/content-read.md)
- [What visitors send from a public form](plugins/submissions.md)
- [Reading Teme's directory](plugins/directory.md)
- [Jobs and schedules](plugins/jobs.md)
- [Public routes](plugins/public-routes.md)
- [Files at the site's root](plugins/root-files.md)
- [The plugin import socket](plugins/import.md)
- [Content inside articles](plugins/insertions.md)
- [Content on every page](plugins/page-insertions.md)
- [The fediverse](plugins/fediverse.md)
- [List columns](plugins/list-columns.md)
- [Feeds and the site](plugins/feeds-and-site.md)
- [The site's AI connection](plugins/ai.md)
- [Your own hooks, and plugins that need other plugins](plugins/your-own-hooks.md)
- [Notices from plugins](plugins/notices.md)
- [A plugin's own area](plugins/admin-area.md)
- [A panel in the editor](plugins/editor-panels.md)
- [Search engine plugins](plugins/seo.md)
- [Content types from your plugin](plugins/content-types.md)
- [Languages in a theme](themes/languages.md)
- [The management API](outside/management-api.md)

## Generated reference

| Page | What it lists |
|---|---|
| [Hooks](../../reference/hooks.md) | Every hook class in `teme/contracts` |
| [Contracts](../../reference/contracts.md) | Every interface, class and enum in `teme/contracts`, with its Since, and what a plugin's constructor may ask for |
| [Plugin manifest](../../reference/plugin-manifest.md) | Keys of `teme-plugin.json` |
| [Theme manifest](../../reference/theme-manifest.md) | Keys of `teme-theme.json` |
| [Theme view data](../../reference/theme.md) | What a theme template can read |
