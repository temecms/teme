---
title: Plugin settings and secrets
type: how-to
audience: plugin
summary: How a plugin declares its settings screen in the manifest, reads and writes its values, keeps API keys as secrets, reaches hosts a person types into them, adds actions and lists to the page, and adds a Tools link.
rests_on: [app/Domain/Plugins/Contributions, app/Domain/Plugins/Doors, app/Domain/Plugins/Lists, app/Domain/Plugins/SettingHosts.php, packages/teme-contracts/src/Settings, packages/teme-contracts/src/Lists, packages/teme-contracts/schema/teme-plugin.schema.json, docs/architecture/settings.md]
---

# Plugin settings and secrets

A plugin does not draw its own settings screen. It declares its settings in `teme-plugin.json`, and Teme draws them with the same fields, checks, history and save bar as its own settings. This page describes how that works on `main` today.

## Declare the settings

List them under `contributes.settings` in the manifest. Each entry needs a `key`, a `type`, a `label` and a `description`, for example `{"key": "api_key", "type": "secret", "label": "API key", "description": "The key the service gave you."}`.

A key is 2 to 41 lower-case letters, digits and underscores, starting with a letter. The types are `text`, `textarea`, `number`, `boolean`, `select`, `multiselect`, `url`, `urls`, `email`, `secret`, `color`, `person`, `term` and `type`. An entry may also give:

- `default`, the value before anyone changes it;
- `options`, value to label, for a `select` or a `multiselect`;
- `min` and `max` for a number, `maxLength` for text;
- `taxonomy`, for a `term`, which it must name, and `multiple`, true to choose several terms;
- `required`, `example` (a hint shown with the field) and `unit` (shown beside a number).

Teme checks the declarations when the plugin is enabled and again each time it starts. If any one is wrong, it registers none of the plugin's contributions and the plugin doesn't start. Nothing is stored by declaring: a plugin that stops leaves its saved values in place for when it starts again.

## Where they appear

The settings form one group, keyed `plugin.` followed by the plugin's `code`, so the example plugin's would be `plugin.first-plugin`. Teme lists it in **Settings** under the heading **Plugin settings**, named by the plugin's label. Only people with the plugins.manage permission can open or save it.

Each save is an Operation, so it is checked, recorded in the settings history, and announced after it commits. A group with any `secret` setting is sensitive: saving it asks for the person's password again, and the change is written to the security log.

## Read the values

Ask for `SettingsReader` in your plugin's constructor, as [Your first plugin](../start/first-plugin.md) does, and read your group by its key: `string()`, `int()`, `bool()`, `stringList()`, `get()` for one value, or `group()` for every value at once. Each answers the saved value, or the declared default.

A `person` setting is drawn with core's person picker, offering the people who may write, and saved only as someone with an account. The reader answers it as that account's ID, a string, or an empty string while nobody is chosen: use it, for example, as the person a plugin credits by default.

A `term` setting offers the terms of the taxonomy it names, such as `category`, by name, up to the first 500. It is saved only as terms that taxonomy has, and the reader answers their slugs: one slug as a string, or with `multiple` a list of slugs (read it with `stringList()`), empty while none is chosen. A `type` setting offers the content types with public addresses, and the reader answers the chosen type's key, such as `post`. A term or type that is removed later stays saved until someone saves the group again, so check it still exists before you use it.

The reader never answers a secret. Read the settings when you need them, not once in the constructor, so a saved change counts on the next request.

## Keep secrets secret

Store API keys, tokens and passwords as `secret` settings. A secret is encrypted in the database, never cached, and never sent to the browser: the form only shows whether one is set, and leaving the box empty keeps the saved one.

Read a secret through `SecretReader` in your constructor, by group and key: `$this->secrets->secret('plugin.your-code', 'api_key')`.

The reader Teme gives a plugin answers only that plugin's own group, and answers null for any other. It answers null too while nothing is saved, so check before you use it. Never log a secret, put it in a page, or hand it to a theme.

## Hosts from your settings

The outbound door reaches only the hosts listed under `hosts` in the manifest. When the person using the plugin chooses the service, such as their own hub, list the `url` or `urls` settings they type it into under `hostsFrom`, at most three, for example `"hostsFrom": ["hub_url"]`. The plugin needs the `http` capability.

- **When the plugin is enabled**, the person sees, under the outbound line, "and the hosts you enter in" those settings, and the hosts they name now, defaults included. Agreeing lets the plugin reach those hosts.
- **When someone saves a new host** in one of those settings, the settings page shows "Saving lets the plugin connect to" that host, with a box to agree. A save that names a new host without the box ticked is refused, and nothing is written. With the box ticked, saving asks for the person's password again, then records the host and who agreed.
- **The plugin reaches a host only while a setting still names it.** Remove it and the door refuses it; put it back and it is reachable again without another yes.
- Only exact host names count, never a wildcard or an IP address, and the plugin can never agree for itself.

An update that takes hosts from another setting waits until someone agrees again.

## Hear when they change

To act when someone saves your settings, list `SettingsChanged` under `hooks` in the manifest and subscribe to it in `register()`. It needs no capability. It carries the group's key, the keys that changed (never their values) and who saved them, and it arrives after the save commits. Check the group's key first: you hear every group's saves.

## Write your own settings

A plugin may save values in its own group, for example a key it generated the first time it ran. Ask for `PluginSettingsWriter` in your constructor; Teme offers it to every plugin that declares settings, with no capability to ask for.

Call `write()` with values by declared key and the ID of the person the work is for: `$this->settings->write(['site_key' => $key], $personId)`. The save is the one that person could make on the settings page. Teme refuses it, writing nothing, unless:

- that person is the one the current work runs for: the signed-in person, or the person a job runs as. A job queued by the command line runs as no one, and can't write;
- that person may manage plugins, and your plugin is enabled;
- every key is declared in your own group, and its type is `text`, `textarea`, `number`, `boolean`, `select`, `multiselect`, `email` or `color`. A plugin never writes a `secret`, `url` or `urls` setting: secrets need the person there, and an address may name a host the person hasn't agreed to;
- every value passes the checks the settings page would make.

A refusal throws `WriteRefused`, with one plain sentence saying why. The settings history records the person and your plugin as the way it was saved, and `SettingsChanged` is sent after the save commits, as for a save from the page.

## Actions

An action is a button on your settings page that queues one of your declared jobs, such as **Send a test ping**. It needs the `jobs` capability. Declare at most three under `contributes.actions`, each with an `id`, a `label` of up to 40 characters, an optional `description` of up to 160, and the `job` it queues: `{"id": "test-ping", "label": "Send a test ping", "job": "ping"}`.

The job must be one you declare under `jobs`, or the plugin doesn't start. Only people who may manage plugins can run an action. It queues the job as that person, with the payload `{"action": "test-ping"}` so your handler knows where it came from, and the action's label as the job's label. A second click while that run still waits queues nothing more. The person is told the job is queued and can follow it in **Tools › Jobs**.

## Lists

A list is a small table on your settings page, such as the last pings sent and what came back. Declare at most two under `contributes.lists`, each with an `id`, a `label`, an optional `description`, one to six `columns` (each an `id`, a `label` and a `kind` of `text`, `number` or `badge`), and `rows`, the most it shows, from 1 to 50 (20 when absent).

Implement `HasPluginLists` beside `Plugin`, returning a `PluginList` for each declared id. A declared list with no class, or a class for a list you don't declare, stops the plugin from starting. Teme asks each list for `rows()` once, with the most rows it shows, only while someone opens the page. Each row is an array of `Cell` values by column id; a missing cell shows empty. Make cells with `Cell::text()`, `Cell::number()` and `Cell::badge()`, as for [list columns](list-columns.md): Teme cleans and shortens the text and shows markup as text.

Keep it fast: a list gets 100 ms, and the page 250 ms for all of them. A list that takes longer, or throws, shows `This list couldn’t be shown this time.` instead, and the fault is recorded under your plugin. A cell of another kind than its column's, or for a column you don't declare, is left out, and so are rows past the most it shows; that is recorded too.

Teme checks both declarations and answers both on the page's data today; the panels that draw them below the settings form are still to come.

## A link in Tools

To give people a way in from **Tools**, add an entry under `contributes.admin` with `surface` set to `tools`, an `id`, a `label` and where it goes in `to`: `settings` opens your settings group, or an `https://` address opens that. An `icon` and a `description` are optional.

Next: [Hooks](../concepts/hooks.md), and the generated [Plugin manifest reference](../../../reference/plugin-manifest.md) for every key.
