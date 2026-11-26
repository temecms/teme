---
title: Adding redirects from a plugin
type: how-to
audience: plugin
summary: How a plugin with the redirects capability adds, lists and removes its own redirects, which Teme writes as the site and marks on Tools › Redirects, and what it can never touch.
rests_on: [packages/teme-contracts/src/Redirects/RedirectWriter.php, packages/teme-contracts/src/Redirects/RedirectRef.php, app/Domain/Plugins/Doors/PluginRedirects.php, app/Domain/Redirects/Operations/CreateRedirect.php, docs/architecture/plugins.md]
---

# Adding redirects from a plugin

A migration plugin that knows a site's old addresses, or an SEO plugin that notices a page's address changed, can add the redirect itself instead of asking someone to type it into Tools › Redirects. The `RedirectWriter` door does that, and only that: it adds, lists and removes the redirects your plugin added.

The contract is in `teme/contracts` from the release that adds it; its `@since` says which.

## Ask for the door

Add `redirects` to the manifest's `capabilities`, and ask for `Teme\Contracts\Redirects\RedirectWriter` in your plugin's constructor. People enabling the plugin read that it will "Add redirects from old addresses of your site, and remove the ones it added". Asking for the door without the capability keeps the plugin from starting.

## Add a redirect

```php
$this->redirects->add('/old-page', '/new-page');                       // 301, moved for good
$this->redirects->add('/campaign', 'https://example.org/launch', 302);  // 302, moved for now
$this->redirects->gone('/retired-offer');                              // 410, gone
```

`from` is a path on this site. `to` is a path on this site or an absolute address. The status is 301 or 302; to say a path is gone, use `gone()`, which answers 410 with nowhere to send readers. A note of up to 200 characters, the third and second argument respectively, shows beside the redirect.

Every call answers a `RedirectRef` with the redirect's `id`, `from`, `to`, `status`, `note` and `answering`. Keep the `id` if you may want to remove it later.

Teme writes the redirect as the site, through the same code Tools › Redirects uses, so every check that screen makes applies: a path the site itself uses, a path that already has a redirect, and a redirect that would make a loop or a chain are refused. A refusal is a `WriteRefused` with one plain sentence, and nothing is written.

A path a page already answers is not refused, as it isn't on Tools › Redirects: the redirect waits, and starts to answer when the page goes. `answering` is `false` until then, and the redirect's row on Tools › Redirects says why it waits.

## List and remove your own

```php
foreach ($this->redirects->mine() as $redirect) {
    // $redirect->from, $redirect->status, ...
}

$this->redirects->remove([$redirect->id]);
```

`mine()` lists the redirects your plugin added, oldest first. `remove()` takes their IDs and answers how many went; an ID that belongs to a person, to another plugin, or to no redirect is left alone and not counted.

## What people see

Each redirect carries your plugin's code. On Tools › Redirects, its origin reads "Added by" and your plugin's name, and the origin filter can show the plugin's redirects alone. A person can edit, switch off or delete a redirect your plugin added, as any other. Redirects stay when the plugin is disabled or removed: they are the site's.

## Test it

With the testing kit, enable your plugin with the `redirects` capability, call your code, and check `mine()` or the page's answer. See [Testing a plugin or theme](../testing.md).

Next: [Plugins writing content](content-write.md), and the generated [contracts reference](../../../reference/contracts.md).
