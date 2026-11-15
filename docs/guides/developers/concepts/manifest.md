---
title: The manifest
type: how-to
audience: both
summary: How to write teme-plugin.json and teme-theme.json, and where the key tables live.
rests_on: [packages/teme-contracts/schema, docs/reference/plugin-manifest.md, docs/reference/theme-manifest.md]
---

# The manifest

Every plugin and every theme declares itself in a JSON file at its root. Teme validates that file before it loads the package’s code. Declared before coded: capabilities, hooks, and contributions are data in the manifest.

## Plugin manifests

A plugin’s file is `teme-plugin.json`. Required keys include `manifest`, `name`, `code`, `label`, `description`, `version`, `requires`, and `plugin` (the class that implements the plugin contract).

Ask only for the capabilities you need. Name every hook you subscribe to under `hooks`. A hook that needs `content.read` is refused if you did not ask for it.

## Capabilities

Each capability unlocks hooks or a door Teme hands your plugin's constructor. People enabling the plugin agree to each one by name, so ask for none you don't use.

| Capability | What it gives |
|---|---|
| `"content.read"` | Hooks that carry content, such as `ContentPublished` |
| `"media.read"` | Hooks that carry media, such as `MediaStored` |
| `"content.write"` | `ContentWriter`: create, change, publish and schedule items, for the person the plugin acts for. See [Plugins writing content](../plugins/content-write.md). |
| `"media.write"` | `MediaWriter`: add a file fetched from one of your `hosts` to the media library. |
| `"content.insert"` | Placing markup inside articles, and `InsertionsChanged`. See [Content inside articles](../plugins/insertions.md). |
| `"http"` | `OutboundHttp`, which reaches only the hosts you list under `hosts` |
| `"jobs"` | `Jobs`: queue the jobs you declare, and run them on the schedules you declare. See [Jobs and schedules](../plugins/jobs.md). |

`"content.write"`, `"media.write"`, `"content.insert"` and `"jobs"` land with v1; they are joined on the `join/v1-wave1` branch. A constructor that asks for a door its capability doesn't open is refused at enable.

## Jobs and schedules

Declare background work under `jobs`, and how often it runs under `schedules`. Both need the `"jobs"` capability.

- Each entry in `jobs` has a `key` (lower-case letters, digits and hyphens, starting with a letter, at most 48 characters) and a `label` people see, up to 80 characters. Optionally `long` marks work that may run long, `tries` sets how many times it is tried (1 to 5, 3 by default), and `backoff` lists the seconds to wait between tries.
- Each entry in `schedules` names a declared `job` and how often it runs, in `every`: `"minute"`, `"five-minutes"`, `"fifteen-minutes"`, `"hour"`, `"day"` or `"week"`.

Your plugin class implements `PluginHasJobs` and answers a `JobHandler` for each declared key. See [Jobs and schedules](../plugins/jobs.md).

## The key tables

The full key table is generated: [Plugin manifest reference](../../../reference/plugin-manifest.md). Regenerate it with:

```sh
php artisan teme:docs:manifest
```

## Theme manifests

A theme’s file is `teme-theme.json`. Required keys include `manifest`, `code`, `name`, `version`, `description`, and `requires`. The theme’s folder name must match `code` when the theme lives in `teme.theme_paths`.

Optional keys cover templates, navigations, regions, settings, assets, fonts, and tokens. See [Theme manifest reference](../../../reference/theme-manifest.md).

## Check before you ship

- Plugins: enable only after the manifest matches what you agree to on the consent screen (`php artisan teme:plugins:enable`).
- Themes: run `php artisan teme:theme:check` and fix every error.
