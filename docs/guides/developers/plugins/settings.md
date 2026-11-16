---
title: Plugin settings and secrets
type: how-to
audience: plugin
summary: How a plugin declares its settings screen in the manifest, reads its values, keeps API keys as secrets, and adds a Tools link.
rests_on: [app/Domain/Plugins/Contributions, app/Domain/Plugins/Doors, packages/teme-contracts/src/Settings, packages/teme-contracts/schema/teme-plugin.schema.json, docs/architecture/settings.md]
---

# Plugin settings and secrets

A plugin does not draw its own settings screen. It declares its settings in `teme-plugin.json`, and Teme draws them with the same fields, checks, history and save bar as its own settings. This page describes how that works on `main` today.

## Declare the settings

List them under `contributes.settings` in the manifest. Each entry needs a `key`, a `type`, a `label` and a `description`, for example `{"key": "api_key", "type": "secret", "label": "API key", "description": "The key the service gave you."}`.

A key is 2 to 41 lower-case letters, digits and underscores, starting with a letter. The types are `text`, `textarea`, `number`, `boolean`, `select`, `multiselect`, `url`, `urls`, `email`, `secret` and `color`. An entry may also give:

- `default`, the value before anyone changes it;
- `options`, value to label, for a `select` or a `multiselect`;
- `min` and `max` for a number, `maxLength` for text;
- `required`, `example` (a hint shown with the field) and `unit` (shown beside a number).

Teme checks the declarations when the plugin is enabled and again each time it starts. If any one is wrong, it registers none of the plugin's contributions and the plugin doesn't start. Nothing is stored by declaring: a plugin that stops leaves its saved values in place for when it starts again.

## Where they appear

The settings form one group, keyed `plugin.` followed by the plugin's `code`, so the example plugin's would be `plugin.first-plugin`. Teme lists it in **Settings** under the heading **Plugin settings**, named by the plugin's label. Only people with the plugins.manage permission can open or save it.

Each save is an Operation, so it is checked, recorded in the settings history, and announced after it commits. A group with any `secret` setting is sensitive: saving it asks for the person's password again, and the change is written to the security log.

## Read the values

Ask for `SettingsReader` in your plugin's constructor, as [Your first plugin](../start/first-plugin.md) does, and read your group by its key: `string()`, `int()`, `bool()`, `stringList()`, `get()` for one value, or `group()` for every value at once. Each answers the saved value, or the declared default.

The reader never answers a secret. Read the settings when you need them, not once in the constructor, so a saved change counts on the next request.

## Keep secrets secret

Store API keys, tokens and passwords as `secret` settings. A secret is encrypted in the database, never cached, and never sent to the browser: the form only shows whether one is set, and leaving the box empty keeps the saved one.

Read a secret through `SecretReader` in your constructor, by group and key: `$this->secrets->secret('plugin.your-code', 'api_key')`.

The reader Teme gives a plugin answers only that plugin's own group, and answers null for any other. It answers null too while nothing is saved, so check before you use it. Never log a secret, put it in a page, or hand it to a theme.

## Hear when they change

To act when someone saves your settings, list `SettingsChanged` under `hooks` in the manifest and subscribe to it in `register()`. It needs no capability. It carries the group's key, the keys that changed (never their values) and who saved them, and it arrives after the save commits. Check the group's key first: you hear every group's saves.

## A link in Tools

To give people a way in from **Tools**, add an entry under `contributes.admin` with `surface` set to `tools`, an `id`, a `label` and where it goes in `to`: `settings` opens your settings group, or an `https://` address opens that. An `icon` and a `description` are optional.

Next: [Hooks](../concepts/hooks.md), and the generated [Plugin manifest reference](../../../reference/plugin-manifest.md) for every key.
