---
title: Reading Teme's directory
type: how-to
audience: plugin
summary: How a plugin with directory.read reads the plugins and themes Teme's directory lists, as Teme verified them, and why an answer may be empty.
rests_on: [packages/teme-contracts/src/Directory, app/Domain/Plugins/Doors/PluginDirectory.php, app/Domain/Extensions/Queries/DirectoryPackages.php, docs/guides/operators/extensions.md]
---

# Reading Teme's directory

Teme reads the plugin and theme directory about once an hour, checks its list's signature against the directory's key, and keeps the last good list. A plugin granted `directory.read` can read what Teme kept, such as a site that shows the directory to its visitors. It never fetches the directory itself, and never sees a list Teme refused.

The contracts are in `teme/contracts` from 1.3.0.

## Ask for the door

Add `directory.read` to the manifest's `capabilities`, and ask for `Teme\Contracts\Directory\VerifiedDirectory` in your plugin's constructor. People enabling the plugin read that it will "Read the plugins and themes listed in Teme’s directory, as Teme verified them".

## Read it

- `index()` answers the list as Teme verified it: its number, when it was made and runs out, and every package's entry.
- `package($name)` answers one package's detail, read from its file and checked against the list: its readme, screenshots, permissions, links and releases.

Each answers a `DirectoryAnswer`. When `found()` is true, `data` holds the array; otherwise `data` is null and `reason` says why in a sentence, such as the directory being switched off on this site, Teme holding no key to check it with, a list that ran out, or a package the list doesn't name. Show the reason; don't retry at once, since Teme reads the directory again on its own.

```php
$answer = $this->directory->package('acme/links');
$shown = $answer->found() ? $answer->data['readme'] : $answer->reason;
```

Next: [Plugin settings and secrets](settings.md), and the generated [contracts reference](../../../reference/contracts.md).
