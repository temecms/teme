---
title: Hooks
type: concept
audience: plugin
summary: The five verbs, the after-commit rule, capabilities, what happens when a listener fails, the Since version and requires.teme, and where the generated hook list lives.
rests_on: [packages/teme-contracts/src/Hooks, app/Domain/Hooks/HookDispatcher.php, app/Domain/Plugins/Faults, docs/reference/hooks.md, docs/reference/contracts.md, docs/architecture/plugins.md]
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

## Hooks for media, terms and purged pages

These are all Notifications, sent after commit.

| Hook | When it is sent | Capability |
|---|---|---|
| `MediaUpdated` | A file's title, alt text, caption, credit, licence or focal point changed. | `media.read` |
| `MediaTrashed` | A file was moved to the trash. | `media.read` |
| `MediaRestored` | A file was taken out of the trash. | `media.read` |
| `MediaDeleted` | A file in the trash was deleted for good. It carries the file as it was. | `media.read` |
| `TermCreated` | A category or tag was created. | `content.read` |
| `TermUpdated` | A category or tag was renamed, or its slug, parent or description changed. | `content.read` |
| `TermDeleted` | A category or tag was deleted. It carries the term as it was before its row went. | `content.read` |
| `TermAssigned` | An item's terms changed. | `content.read` |
| `PagesPurged` | A change removed public pages from the page cache. | `content.read` |
| `PersonInvited` | Someone was invited to the site. Carries their account ID and display name only, never a role or email. | `people.read` |
| `PersonJoined` | An invited person chose their password and joined. Carries their account ID and display name only, never a role or email. | `people.read` |

`TermAssigned` is sent once for the item and the change, with a map keyed by taxonomy: for each taxonomy that changed, the IDs of the terms added and the IDs taken off. A save that leaves the terms as they were sends nothing. It is a separate hook from `ContentSaved`, which a save also sends: `ContentSaved` says the item was saved and carries no terms, so listen to `TermAssigned` when you care which terms moved. Changing an item's language sends one `TermAssigned`: its old terms are removed and their versions in the new language are added. Terms deleted outright are not named as removed; listen to `TermDeleted` for those.

`PagesPurged` is sent once for each purge, with the cache tags it removed. Nothing is sent when the page cache is off. The addresses it carries are only the cached pages Teme warms again after a purge, so they are empty when warming is off, and a purge can touch pages they do not list. A plugin that purges a CDN should purge by tag, and treat the addresses as a hint.

An import run does not send term hooks yet, nor any of the other hooks above: what an import run writes is not announced to plugins.

## Capabilities

A hook may require a capability, declared on the class. `ContentPublished` needs `content.read`. Teme refuses a subscription when the plugin did not ask for that capability in `teme-plugin.json`, and refuses enable when the manifest’s `hooks` and `capabilities` do not match.

## When a listener fails

A listener that throws, or answers a value of the wrong type, never breaks the page or the change that fired the hook. Teme reports the failure to the site's log with your plugin's name, the subscription and the hook, and then:

- for a Notification, the other listeners still hear it;
- for a Transform or a Collect, your listener is skipped and the others' work stands, unless the hook is marked to fail the change (`Failure` on the hook), when the whole change is refused instead;
- for a Gate, the action is refused, with the words that your plugin couldn't check it, so it was stopped to be safe;
- for a Resolve, the hook answers nothing, as if no listener had answered.

A plugin whose `register()` throws is not started for that request, and anything it subscribed before throwing is removed. A job's handler that throws is reported and tried again, as [Jobs and schedules](../plugins/jobs.md) describes, and a public route's handler that throws answers the site's error page, as [Public routes](../plugins/public-routes.md) describes.

Nothing counts a plugin's failures or turns a plugin off for failing yet: a listener that fails on every call fails on every call, and the log is where you see it.

## Since, and the Teme you require

Every hook, interface and class in `teme/contracts` that a plugin or theme codes against carries a `@since` tag in its docblock: the Teme version that first offered it. The generated hooks reference shows it as **Since**: `ContentPublished`, for one, has been there since 0.5.0, and `SettingsReader` since 0.1.0. A contract added later carries the later version: the jobs door and the content door, for two, since 1.0.0.

Set `requires.teme` in your manifest to at least the highest **Since** of anything you use. A plugin that listens to `ContentPublished` needs Teme 0.5.0 or later; the handbook's first plugin asks for `"^1.0"`. The [contracts reference](../../../reference/contracts.md) gives the **Since** of every contract, and the newest version a checkout offers in its first lines. Teme refuses a package whose `requires.teme` doesn't accept the running version, so the check happens at enable rather than as an error on a live page.

In your own plugin's or theme's code, use `@since` however you like, or not at all. Teme neither reads nor checks it there.

## Ordering and the generated list

Listeners run in the order Teme registers them for that process. The full list of hooks — name, verb, capability, parameters, and since — is generated:

```sh
php artisan teme:docs:hooks
```

Read [Hooks reference](../../../reference/hooks.md). Do not edit that file by hand.
