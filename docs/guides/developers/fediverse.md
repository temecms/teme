# Extending the fediverse

This page lands with the developers' handbook. Until then it lists what a plugin can use, so the names are on record.

A plugin reaches the fediverse only through `Teme\Contracts\Fediverse`, from Teme 0.9.0:

- **`fediverse.read`** lets a plugin listen for `ActivityReceived` (an activity another server sent, once its signature is checked) and `ActivitySent` (an activity the site queued), give handlers through `CollectActivityHandlers` and object builders through `CollectObjectBuilders`, change documents with `ObjectBuilt` and `ActorDocumentBuilt`, and ask `FediverseFollowers` who follows the site's accounts.
- **`fediverse.send`** gives the plugin's constructor a `FediverseSender`. Teme stores the activity, then signs and delivers it once the plugin's work commits. The plugin never holds a key.

Teme keeps some things for itself. A handler is never given Follow, Accept, Reject, Undo, Create, Update, Delete, Block, Move or Flag, and the sender refuses them. A transform cannot change an object's `id`, `type`, `attributedTo`, `to` or `cc`, or an account's address, inboxes or `publicKey`: Teme puts them back as it wrote them.

The plan behind this is `docs/plans/fediverse-publishing.md` (D5).
