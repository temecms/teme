---
title: The fediverse
type: how-to
audience: plugin
summary: What core does on the fediverse by itself, and the sockets a plugin can use to hear activities, handle them, change what is sent, and send its own.
rests_on: [packages/teme-contracts/src/Fediverse, app/Domain/Fediverse, docs/architecture/plugins.md]
---

# The fediverse

Teme speaks ActivityPub itself, so Mastodon and other fediverse servers can follow a site, read its posts and reply to them. A plugin doesn't build any of that. It joins in through a few sockets: it hears what arrives and what leaves, handles activity types core leaves alone, changes the documents core sends, and sends activities of its own.

The contracts are in `teme/contracts`, under its Fediverse namespace, from Teme 0.9.0.

## What core does by itself

An administrator turns the fediverse on under **Settings › Fediverse**, and chooses who can be followed: the blog, its authors, or both. From then on core:

- answers WebFinger and NodeInfo, and each of the site's accounts as an actor document;
- keeps every account's key, signs every delivery, and checks the signature of everything that arrives; no plugin ever holds a key;
- accepts follows, removes followers who leave, and skips or blocks servers;
- sends a Create when a federated item is first published, an Update when it changes, and a Delete when it is unpublished, trashed or deleted, and delivers each to the followers' inboxes, retrying a server that doesn't answer;
- takes replies to the site's posts as comments, through the site's own discussion rules and moderation.

Core keeps these activity types for itself: Follow, Accept, Reject, Undo, Create, Update, Delete, Block, Move and Flag. A plugin is never handed one to handle, and can't send one. An import never sends anything.

## Two capabilities

| Capability | What it gives |
|---|---|
| `fediverse.read` | The six fediverse hooks below, and `FediverseFollowers` in your plugin's constructor |
| `fediverse.send` | `FediverseSender` in your plugin's constructor |

Ask only for what you use. As with every hook, list each one you subscribe to under `hooks` in the manifest, or Teme refuses to enable the plugin. See [Hooks](../concepts/hooks.md).

## Hearing what arrives and leaves

`ActivityReceived` is a Notification. It tells you of an activity another server sent, once its signature checked out and its server isn't blocked: a Like, an Announce, a Follow or any other. It carries the activity as it arrived, its type, and the address of the account that signed it. It is sent from the queued job that takes the activity, so a slow listener never holds up the other server.

`ActivitySent` is a Notification too. It tells you of an activity the site queued for its followers, core's own or a plugin's, with the activity as it is sent, its type and the sending account's address. Its deliveries go after it.

## Handling an activity type

To act on a type core leaves alone, such as Like or Announce, subscribe to `CollectActivityHandlers` and answer a list of your `ActivityHandler` objects. A handler names the types it takes in `types()`, and `handle()` takes one activity and the address of the account that signed it.

Each handler runs as tracked work in the queue, as the administrator who enabled your plugin, so it shows among the site's jobs. Keep these in mind:

- a handler's class must be in your plugin class's namespace, or Teme can't tell whose it is and leaves it out;
- an activity larger than 64 KB, with what goes with it, is not handed to a handler;
- a handler changes the site only through your plugin's own Operations, as every write does (see [Operations](../concepts/operations.md));
- a reply to one of the site's posts is core's, and becomes a comment, so it never reaches a handler.

## Changing what core sends

Three sockets let you change documents before another server reads them. In each, core puts back the keys that say what the document is and who sent it to whom, as it wrote them, whatever your code returns.

- **Object builders.** Subscribe to `CollectObjectBuilders` and answer your `ObjectBuilder` objects. A builder names the content types it builds in `contentTypes()`, and `build()` takes the item and the object core made and answers the object to send, such as an Event for an events type. The first builder that names an item's type builds it. Core keeps `id`, `attributedTo`, `to` and `cc`. A builder that throws is logged, and core's own object is sent.
- **`ObjectBuilt`** is a Transform. Your listener takes an item's object and answers it changed, such as with a tag added. Core keeps `id`, `type`, `attributedTo`, `to` and `cc`.
- **`ActorDocumentBuilt`** is a Transform over one of the site's accounts as other servers read it, such as to add profile fields. Core keeps `@context`, `id`, `type`, `preferredUsername`, `inbox`, `outbox`, `followers`, `following`, `endpoints` and `publicKey`.

## Who follows the site

With `fediverse.read`, ask for `FediverseFollowers` in your constructor. `actors()` answers the addresses of the site's accounts that can be followed now; `count()` how many follow one of them; and `followers()` their addresses, oldest follow first, up to 1000 at a time from an offset.

## Sending an activity

With `fediverse.send`, ask for `FediverseSender` in your constructor and call `send()` with an activity type, its object (an object with an `id`, or an address) and, optionally, the address of the site's account that sends it; the blog's account sends it when you give none. It answers the new activity's address.

Teme stores the activity and one delivery for each follower's inbox, then signs and delivers them once your work commits. It throws `SendRefused`, with the reason as its message, when:

- the type is one core keeps for itself, or the object is one of the site's own posts or accounts;
- the object has no address, or the site has no such account;
- the fediverse is paused;
- nobody can be said to send it. A plugin sends for the person signed in, or, for work the server runs, such as a queued job, a schedule or a command, for the administrator who enabled it; for a visitor who isn't signed in, it sends nothing. That person must be allowed to manage the fediverse, or be sending as their own author account.

Next: [Jobs and schedules](jobs.md), for work your handlers start, and [Hooks](../concepts/hooks.md).
