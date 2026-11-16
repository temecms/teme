---
title: Notices from plugins
type: how-to
audience: plugin
summary: How a plugin with notices.send declares kinds of notice, who each reaches, and what Teme lets a notice say and how many it sends.
rests_on: [packages/teme-contracts/src/Notices, app/Domain/Plugins/Notices, packages/teme-contracts/schema/teme-plugin.schema.json]
---

# Notices from plugins

A plugin can tell people on the site that something happened: an article it was writing for them failed, or its queue stopped. Teme sends the notice by email or into the person's inbox, as each person chose, with the same layout as its own notices. The plugin gives plain words; it never writes the email.

The contracts are in `teme/contracts` from Teme 0.9.0.

## Ask for notices.send and declare each kind

Add `notices.send` to the manifest's `capabilities`. List each kind of notice under `contributes.notices`, at most five, each with:

- an `id`, lower-case letters, digits and underscores, such as `job_failed`;
- a `label`, the line a person reads among their notification choices, such as "Email me when an article I started fails";
- an `audience`, either `starter` or `plugins.manage`;
- the `channels` it may use, `mail`, `inbox` or both, and the `defaults` a person starts with.

People enabling the plugin read each label. Declaring kinds without asking for `notices.send` keeps the plugin from starting.

## Who a kind reaches

A `starter` kind goes only to the person the plugin's current work acts for: the person whose request is running, or the person a job runs for. A `plugins.manage` kind goes to the people who may manage plugins. Each kind shows among a person's notification choices only while the plugin is enabled, and only to the people its audience reaches.

## Send through Notices

Ask for `Notices` in your plugin's constructor; it is offered only with `notices.send`. Call `send()` with your kind's id, the account IDs, and a `NoticeMessage`. Teme refuses, with an InvalidArgumentException:

- a kind the plugin doesn't declare;
- a `starter` kind sent to anyone but the one person the work acts for;
- `sendToAddress()`, since a plugin sends notices only to people with an account.

## What a notice may say

The subject is one to 120 characters of plain words. The body is at most five lines of at most 500 characters each. A plugin's notice carries no quoted words. Its button may lead only to the plugin's own settings or to an item's editor, by the item's ID.

## How many

A plugin delivers at most 100 notices an hour. The rest are dropped, and the Plugins page records it once an hour, so a loop never floods anyone's inbox.

Delivery waits for the outermost commit, so a notice about work that rolled back is never sent.

Next: [Your own hooks](your-own-hooks.md) and [Jobs and schedules](jobs.md).
