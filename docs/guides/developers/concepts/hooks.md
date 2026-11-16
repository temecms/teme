---
title: Hooks
type: concept
audience: plugin
summary: The five verbs, the after-commit rule, capabilities, the Since version and requires.teme, and where the generated hook list lives.
rests_on: [packages/teme-contracts/src/Hooks, docs/reference/hooks.md, docs/architecture/plugins.md]
---

# Hooks

Hooks are how Teme tells a plugin what happened, or asks it to take part in a decision. Each hook is a class in `teme/contracts`. A plugin lists the hooks it listens to in its manifest, and subscribes in `register()`.

## The five verbs

| Verb | What it does |
|---|---|
| Notification | Tells listeners what happened. A failure in one listener never stops the others. |
| Transform | Lets listeners change a value, one after another. |
| Gate | Lets listeners refuse something before it happens. |
| Resolve | Asks listeners for one answer; the first wins. |
| Collect | Gathers items from every listener. |

`ContentPublished` is a Notification. It carries the item, the public revision, whether this was the first publish, when it was first published, and who acted.

## After commit

Hooks fire after the change is saved, never during the database transaction. A listener that reads the item sees the committed state. If the transaction had rolled back, no listener would have run for a change that never happened.

## Capabilities

A hook may require a capability, declared on the class. `ContentPublished` needs `content.read`. Teme refuses a subscription when the plugin did not ask for that capability in `teme-plugin.json`, and refuses enable when the manifest’s `hooks` and `capabilities` do not match.

## Since, and the Teme you require

Every hook, interface and class in `teme/contracts` that a plugin or theme codes against carries a `@since` tag in its docblock: the Teme version that first offered it. The generated hooks reference shows it as **Since**: `ContentPublished`, for one, has been there since 0.5.0, and `SettingsReader` since 0.1.0. A contract added later, such as the ones v1 brings, carries the later version.

Set `requires.teme` in your manifest to at least the highest **Since** of anything you use. A plugin that listens to `ContentPublished` needs Teme 0.5.0 or later, so `"^0.5"` or higher; the handbook's first plugin asks for `"^0.8"`. Teme refuses a package whose `requires.teme` doesn't accept the running version, so the check happens at enable rather than as an error on a live page.

In your own plugin's or theme's code, use `@since` however you like, or not at all. Teme neither reads nor checks it there.

## Ordering and the generated list

Listeners run in the order Teme registers them for that process. The full list of hooks — name, verb, capability, parameters, and since — is generated:

```sh
php artisan teme:docs:hooks
```

Read [Hooks reference](../../../reference/hooks.md). Do not edit that file by hand.
