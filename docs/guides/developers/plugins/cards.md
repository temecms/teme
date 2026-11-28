---
title: Cards for a theme's regions
type: how-to
audience: plugin
summary: How a plugin with pages.insert offers cards that editors place in a theme's regions, with fields core draws, options it gives while a card is filled in, and markup it draws on the public page.
rests_on: [packages/teme-contracts/src/Cards, docs/guides/developers/examples/plugins/ad-cards, app/Domain/Cards/Plugin, app/Domain/Regions, docs/guides/developers/themes/regions.md]
---

# Cards for a theme's regions

A plugin can offer cards that editors place in a theme's regions, as WordPress plugins add widgets to widget areas: an ad network's ad slot, a newsletter sign-up form, an author box. The editor chooses where; the plugin draws what. Page insertions are the other way round: the plugin decides where its markup goes (see [Content on every page](page-insertions.md)).

The contracts are in `teme/contracts`, under its Cards namespace, from Teme 1.5.0.

## Ask for pages.insert

Cards need `pages.insert` in the manifest's `capabilities`; its consent sentence already says "in the theme's regions". Without it Teme refuses the plugin's `cards` and never asks it to draw one.

## Declare the cards

List them under `contributes.cards`, at most 20:

```json
// docs/guides/developers/examples/plugins/ad-cards/teme-plugin.json
    "contributes": {
        "cards": [
            {
                "id": "ad-slot",
                "label": "Ad",
                "description": "One ad from your list.",
                "icon": "megaphone",
                "keywords": ["ad", "banner"],
                "fields": [
                    { "key": "ad", "type": "choice", "label": "Ad", "description": "The ad to show.", "required": true },
                    { "key": "label", "type": "text", "label": "Label", "description": "Shown above the ad.", "maxLength": 24 }
                ]
            }
        ]
    }
```

- `id` is 2 to 41 lower-case letters, digits and hyphens, starting with a letter, unique in the plugin. The card's key is `{code}:{id}`, such as `ads:ad-slot`; a theme that narrows a region names the card by it.
- `label` (up to 40 characters) and `description` (up to 160) are what editors see in Add card, under your plugin's name.
- `icon` is a Lucide icon's name. The editor draws a square for one it doesn't know.
- `keywords` are up to 10 more words Add card finds the card by.
- `fields` are up to 20, declared as [settings](settings.md) are, with the same types, `required`, `default`, `options`, `min`, `max` and `maxLength`, except that `secret`, `code` and `repeater` are refused and `section` and `visibleWhen` aren't read. Core draws them in the editor as it draws your settings.

One more type is a card's own: `choice`, a value of up to 191 characters chosen from options your plugin gives while the card is filled in, such as the ads it keeps in its own tables.

## Implement HasCards

The class named under `plugin` implements `HasCards` beside `Plugin`. Its `cards()` answers one `PluginCard` for each declared id, no more and no fewer; a plugin whose ids and classes don't match doesn't start, and the reason shows on Plugins.

```php
// docs/guides/developers/examples/plugins/ad-cards/src/Ads.php
    public function cards(): array
    {
        return ['ad-slot' => new AdSlot($this->ads)];
    }
```

The whole example, with the card that draws an ad and lists the ads to choose from, is in `docs/guides/developers/examples/plugins/ad-cards`.

`PluginCard::render(CardInstance $card)` answers the card's markup, or `''` to draw nothing. `CardInstance` holds:

| Property | What it holds |
|---|---|
| `id` | The card's node ID, the same in every revision of the region |
| `values` | Its fields' values, by key, as stored; a field never filled in is missing |
| `region` | A `CardRegion`: the region's `slug`, its `theme`, and its `size`, `width` and `height` when the theme gives one |
| `language` | The page's language tag |

Escape the values you write: they are stored as an editor typed them. Nothing about the visitor is given, because the page is cached whole for everyone.

A value can outlive what it names. When an ad your card picked is deleted, draw nothing for it; Teme doesn't refuse the region for it.

## Give the options of a choice field

A card with `choice` fields implements `OffersCardChoices`. While someone fills the card in, the editor asks `choices(string $field, CardRegion $region, string $search)` and lists what it answers, at most 50 `CardChoice` values: the `value` stored, the `label` shown, a short `hint`, and `fits`, whether it fits the region's size, or null when that doesn't apply. Sort the options that fit first. The call has 250 ms; one that throws or runs over gives no options and is recorded as your plugin's fault.

## What core does with what you draw

Core asks `render()` only on a public web page, never in feeds, Markdown or the API. Each card has 100 ms and every plugin card on a page 250 ms together; one that throws or runs over draws nothing and is recorded as your plugin's fault.

What you answer is cleaned as a page insertion is, with the same rules for scripts, approved inline scripts and the hosts your manifest declares under `csp`, and it joins the page's security policy the same way. Core wraps it in

```html
<div class="card-plugin card-plugin-{code}-{id}" data-plugin-card="{code}:{id}">…</div>
```

and draws nothing at all when you draw nothing. Plugins load no stylesheet of their own yet: the theme styles your card, by those classes, so keep your markup plain and say in your plugin's description what it needs.

A page that draws one of your cards carries the cache tag `plugin:{code}`. Saving your plugin's settings purges those pages. When something else your cards draw changes, such as an ad in your own tables, ask for `CardsChanged` in your constructor and call `notify()` inside the Operation that changed it. A job can call it too, with the same rules as `InsertionsChanged`: a list of content IDs, or with no list the pages that carry `plugin:{code}`, at most once a minute.

## While the plugin is disabled

A region keeps your cards when your plugin is disabled. They draw nothing on the public site, and the editor shows each as from your plugin, which is disabled, with its settings read-only; editors may remove it. When the plugin is enabled again, the cards come back as they were. Removing a plugin's data leaves its cards in the regions that hold them.

Next: [Regions](../themes/regions.md) and [Settings](settings.md).
