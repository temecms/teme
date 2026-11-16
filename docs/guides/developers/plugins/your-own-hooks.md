---
title: Your own hooks, and plugins that need other plugins
type: how-to
audience: plugin
summary: How a plugin declares hooks of its own for other plugins, sends them through HookSender, and how an add-on requires the plugin it extends and listens to those hooks.
rests_on: [packages/teme-contracts/src/Hooks, app/Domain/Plugins/Hooks, app/Domain/Plugins/Dependencies.php, packages/teme-contracts/schema/teme-plugin.schema.json]
lands_with: sockets3/hooks-notices
---

# Your own hooks, and plugins that need other plugins

A plugin can offer hooks of its own, so that another plugin can change what it does without either one reaching into the other's code. A free plugin can declare a hook that collects extra prompt modules, and a paid add-on can require it and listen. Teme runs those hooks exactly as it runs its own: the same verbs, the same order, the same fault rules.

This page describes the code on the `sockets3/hooks-notices` branch. The contracts are in `teme/contracts` from Teme 0.9.0.

## Declare each hook

A hook is a small class in your plugin's own namespace that implements one verb: `Notification` to tell listeners something happened, `Transform` to let them change a value, or `Collect` to gather items from each. Mark it with the `HookContract` attribute, as Teme's own hooks are; name a capability there when only plugins granted it may listen. Its properties are plain values.

List each one in the manifest under `contributes.hooks`, at most ten, each with a `name`, the `class` and a one-line `description`. The name starts with a capital letter and has only letters, three to 48 of them, such as PromptModules. Teme refuses to start a plugin whose declared class is outside its namespace, isn't one of the three verbs, or needs a capability the plugin itself doesn't ask for, and shows why on the Plugins page.

Other plugins know each hook by its full name, your plugin's `code`, a colon and the name, such as `shop:OrderPlaced`.

## Send it with HookSender

Ask for `HookSender` in your plugin's constructor. It is offered only to a plugin that declares hooks, and it sends only those: any other class is refused with `HookRefused`.

- `notify()` tells each listener. Inside a transaction it waits for the outermost commit, as Teme's own notifications do, and nobody is told when the transaction rolls back.
- `transform()` hands the value through each listener in order and returns what the last good one left.
- `collect()` returns every good item, in listener order.

A listener that throws is recorded under its own plugin and skipped; the next one still runs.

## Require the plugin you extend

An add-on names the plugins it needs under `requires.plugins`, an object of Composer names and version constraints, at most five, such as `"acme/shop": "^1.0"`. It lists each hook it listens to under `hooks` by its full name, beside any of Teme's own.

Teme then holds these rules:

- The add-on can't be enabled until every plugin it requires is enabled at a version it accepts. The Plugins page says which one, and at what version.
- A plugin can't be disabled while an enabled plugin requires it. The refusal names the plugins to disable first.
- Plugins start in order, so the plugin you require has started before yours registers its listeners.
- When an update leaves a required plugin outside your constraint, your add-on stops and shows why, until either one changes.
- Listening to a plugin's hook without requiring that plugin, or to a hook it doesn't declare, keeps the add-on from starting.

People enabling your add-on read which plugin it needs, and that it must stay enabled.

## A free plugin and a paid add-on

The free plugin declares PromptModules, a `Collect` hook whose items are strings, and calls `collect()` on it before it writes each article, adding whatever comes back. The paid add-on requires the free plugin, lists the hook under `hooks` by its full name, and in `register()` listens to the hook's class, returning its own modules. With the add-on disabled the free plugin works alone; with it enabled, it gets the extra modules, and a fault in the add-on never stops the free plugin's work.

Next: [Hooks](../concepts/hooks.md) and [Notices from plugins](notices.md).
