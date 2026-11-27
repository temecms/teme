---
title: The manifest
type: how-to
audience: both
summary: How to write teme-plugin.json and teme-theme.json, and where the key tables live.
rests_on: [packages/teme-contracts/schema, docs/reference/plugin-manifest.md, docs/reference/theme-manifest.md, docs/reference/contracts.md]
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
| `"routes.public"` | Answering the public addresses you declare under `routes`. See [Public routes](../plugins/public-routes.md). |
| `"import.wordpress"` | Receiving the WordPress data you declare under `imports` when the site is imported. See [The plugin import socket](../plugins/import.md). |

A constructor that asks for a door its capability doesn't open is refused at enable. The [contracts reference](../../../reference/contracts.md) lists every door a constructor may ask for, with the capability that gives it.

## Settings

Settings you declare under `contributes.settings` form one group, keyed `plugin.` followed by your `code`, which Teme draws in **Settings** and your plugin reads by that key. See [Plugin settings and secrets](../plugins/settings.md).

## Which Teme you require

`requires.teme` names the Teme versions your package works with, as a Composer constraint. Make it accept at least the highest **Since** of every contract and hook you use, which the [contracts reference](../../../reference/contracts.md) gives for each. See [Hooks](hooks.md#since-and-the-teme-you-require).

## Jobs and schedules

Declare background work under `jobs`, and how often it runs under `schedules`. Both need the `"jobs"` capability.

- Each entry in `jobs` has a `key` (lower-case letters, digits and hyphens, starting with a letter, at most 48 characters) and a `label` people see, up to 80 characters. Optionally `long` marks work that may run long, `tries` sets how many times it is tried (1 to 5, 3 by default), and `backoff` lists the seconds to wait between tries.
- Each entry in `schedules` names a declared `job` and how often it runs, in `every`: `"minute"`, `"five-minutes"`, `"fifteen-minutes"`, `"hour"`, `"day"` or `"week"`.

Your plugin class implements `PluginHasJobs` and answers a `JobHandler` for each declared key. See [Jobs and schedules](../plugins/jobs.md).

## Routes and tables

Public addresses your plugin answers are declared under `routes`, each with an `id`, a `method`, a `path` and a `handler`; see [Public routes](../plugins/public-routes.md). Tables your plugin creates are listed under `owns`, each name starting with your code as a prefix; see [Your plugin's own tables](../plugins/tables.md).

## Content types

`contributes.types` names a file in your package, in the format Settings › Types exports, with the content types, taxonomies and fields your plugin brings. They work while it is enabled. See [Content types from your plugin](../plugins/content-types.md).

## The key tables

The full key table is generated: [Plugin manifest reference](../../../reference/plugin-manifest.md). Regenerate it with:

```sh
php artisan teme:docs:manifest
```

## Theme manifests

A theme’s file is `teme-theme.json`. Required keys include `manifest`, `code`, `name`, `version`, `description`, and `requires`. The theme’s folder name must match `code` when the theme lives in `teme.theme_paths`.

Optional keys cover templates, navigations, regions, settings, assets, fonts, and tokens. See [Theme manifest reference](../../../reference/theme-manifest.md). Core reads three settings by fixed name when you declare them: `color_scheme`, `featured_image_style`, and `use_brand_colour`. A theme that offers a switch to use the site's brand as its accent must use the exact key `use_brand_colour`. See [Tokens, styles and fonts](../themes/tokens-and-styles.md).

## Check before you ship

- Plugins: enable only after the manifest matches what you agree to on the consent screen (`php artisan teme:plugins:enable`).
- Themes: run `php artisan teme:theme:check` and fix every error.
