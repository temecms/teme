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
