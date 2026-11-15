---
title: Hooks
type: concept
audience: plugin
summary: The five verbs, the after-commit rule, capabilities, and where the generated hook list lives.
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

## Ordering and the generated list

Listeners run in the order Teme registers them for that process. The full list of hooks — name, verb, capability, parameters, and since — is generated:

```sh
php artisan teme:docs:hooks
```

Read [Hooks reference](../../../reference/hooks.md). Do not edit that file by hand.
