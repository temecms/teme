---
title: Feeds and the site
type: how-to
audience: plugin
summary: How a plugin adds links to the site's feeds, reads the site's address, name, language and feeds, hears which feeds a change touched, and posts a form to a service outside the site.
rests_on: [packages/teme-contracts/src/Site, packages/teme-contracts/src/Feeds, packages/teme-contracts/src/Http, app/Domain/Feeds, app/Domain/Plugins/Doors, packages/teme-contracts/schema/teme-plugin.schema.json]
---

# Feeds and the site

These are the sockets a plugin uses to tell services outside the site about its feeds, such as a WebSub hub or a search engine's ping address. Each one is available from Teme 1.0.0, so a plugin that uses them says `"requires": {"teme": "^1.0"}`.

## Links in every feed

A plugin can add a `<link rel="hub">` to every feed the site serves: the site's own, each language's, each type's archive, each term's and each author's. It asks for the `feeds.links` capability, which the person enabling it sees as "Add links to your site’s feeds, such as the hub feed readers subscribe through", and declares the links under `contributes.feedLinks`, at most two:

```json
"feedLinks": [{ "rel": "hub", "href": "{hub_url}" }]
```

- `rel` is `hub`. The feed's own `self` link is core's.
- `href` is an `https://` address of at most 2,048 characters, or `{key}`, naming one of the plugin's own `url` or `urls` settings. A `urls` setting gives one link for each of its first five addresses.
- Core writes the XML, straight after the feed's `self` link, and escapes every value. A value that isn't an absolute `https` address is left out, and the same link from two plugins appears once.
- The links appear only while the plugin runs and was granted `feeds.links`. Saving the plugin's settings, enabling it or disabling it clears the stored copies of the feeds, so readers see the change at once.

The plugin runs no code to draw the links.

## The site's facts

Every plugin may ask for `SiteInfo` in its constructor. It needs no capability, because everything it answers is public:

- `address()`, the site's address, absolute, with no trailing slash;
- `name()`, the site's name from Settings › General;
- `language()`, the site's default language, such as `en` or `pt-BR`;
- `home()`, the home page's address;
- `feeds()`, the main feed's address in each language readers are shown, the default language's first.

It answers the same in a request, a queued job and the console, so build addresses for a service outside the site from it, never from the request.

## Hear which feeds changed

List `FeedsChanged` under `hooks` and subscribe to it in `register()`. It needs no capability. It carries `urls`, the absolute addresses of the feeds a change touched, and `all`, which is true when the change may touch every feed, such as a new site name. Teme builds it from the same record it uses to clear its stored pages:

- a published post's change touches the main feeds, its type's archive feed where the type has one, each of its terms' feeds and its authors' feeds;
- a change to the site's settings touches every main feed, with `all` set;
- a draft's save touches none, and sends nothing.

It arrives after the change is saved, never for a change that was undone, and never during an import. One notification carries at most 200 addresses; beyond that it carries the main feeds with `all` set. Teme reads none of this while no plugin listens.

## Post a form

`OutboundHttp::postForm()` sends `application/x-www-form-urlencoded` fields, as WebSub hubs and many ping services expect. Give each field a string, or a list of strings to repeat its name:

```php
$this->http->postForm($hub, ['hub.mode' => 'publish', 'hub.url' => $feeds]);
```

Every rule of the outbound door applies, as for `postJson()`: `https` to port 443, only hosts the person agreed to, only public addresses, no redirects, the time limit, and 1 MB each way. A field that is neither a string nor a list of strings is refused before anything is sent, and a `Content-Type` header you pass is dropped. It needs the `http` capability. To reach hosts a person types into your settings, such as their own hub, see [Hosts from your settings](settings.md#hosts-from-your-settings).

Next: [Plugin settings and secrets](settings.md), and the generated [Hooks reference](../../../reference/hooks.md).
