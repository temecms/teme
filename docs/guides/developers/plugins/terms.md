---
title: Creating terms from a plugin
type: how-to
audience: plugin
summary: How a plugin with the terms.write capability creates, updates, lists and removes terms of the taxonomies it brought, and what it can never touch.
rests_on: [packages/teme-contracts/src/Taxonomy/TermWriter.php, packages/teme-contracts/src/Taxonomy/TermRef.php, app/Domain/Plugins/Doors/PluginTermWriter.php, app/Domain/Taxonomy/Operations/CreateTerm.php, docs/architecture/plugins.md]
---

# Creating terms from a plugin

A plugin that brings its own taxonomy — a directory's categories, a recipe plugin's cuisines — needs to fill it. The `TermWriter` door does that, and only that: it creates, updates, lists and removes terms of the taxonomies your plugin's types file brought.

The contract is in `teme/contracts` from the release that adds it; its `@since` says which.

## Ask for the door

Add `terms.write` to the manifest's `capabilities`, and ask for `Teme\Contracts\Taxonomy\TermWriter` in your plugin's constructor. People enabling the plugin read that it will "Create, change and remove terms of the taxonomies it brings". Asking for the door without the capability keeps the plugin from starting.

## Create, update, list and remove

```php
$italian = $this->terms->create('cuisine', 'Italian', 'italian', null, 'From Italy');
$tuscan = $this->terms->create('cuisine', 'Tuscan', null, $italian->id);
$all = $this->terms->list('cuisine');
$italian = $this->terms->update($italian->id, 'Italia', 'italia', null, 'Updated');
$this->terms->remove($tuscan->id);
```

`create` takes the taxonomy key, a name, an optional slug (made from the name when left out), an optional parent term ID, and an optional description. `update` renames a term, and may change its slug, parent and description. `list` answers every term of that taxonomy, oldest first, with no page size — it is meant for filling a small owned vocabulary, not for paging a large one. `remove` deletes a term that nothing uses and that has no children.

Every call that writes answers a `TermRef` with the term's `id`, `taxonomy`, `name`, `slug`, `parentId` and `language`. Keep the `id` if you may want to update or remove it later.

Teme writes through the same Operations the admin uses, as the site. A refusal is a `WriteRefused` with one plain sentence, and nothing is written: a taxonomy your plugin did not bring, a parent that does not belong, a parent or term that is a translation, a term that is in use, or a term that still has children.

## What it never does

- It never touches core's taxonomies, the site's own, or another plugin's.
- It never makes or changes a translation of a term, and it refuses a parent that is a translation.
- Removing a term that items still use, or that has children, is refused.

## ContentWriter still creates terms by name

`ContentWriter` (and editor suggestions) can still create a missing term by name when filing an item, under the acting person's rights, on any taxonomy that person may manage. That path stays. Use `TermWriter` when your plugin owns the taxonomy and needs to fill, rename or clear it without attaching it to an item.

## Test it

With the testing kit, enable your plugin with the `terms.write` capability, call your code, and check `list()` or the term rows. See [Testing a plugin or theme](../testing.md).

Next: [Content types from your plugin](content-types.md), [Plugins writing content](content-write.md), and the generated [contracts reference](../../../reference/contracts.md).
