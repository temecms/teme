---
title: Files at the site's root
type: how-to
audience: plugin
summary: How a plugin declares a small text file at the site's root, such as a verification key, which core serves without running the plugin's code.
rests_on: [app/Domain/PublicRoutes, app/Http/Site/Middleware, packages/teme-contracts/schema/teme-plugin.schema.json, docs/guides/developers/plugins/public-routes.md]
---

# Files at the site's root

Some services check that you own a site by asking for a small text file at its root. IndexNow, for one, fetches `/{your key}.txt` and expects the key back. A plugin never answers such a file itself. It declares the file in `teme-plugin.json`, and core answers it.

## Declare a root file

List it under `rootFiles`. The plugin must ask for `routes.public`, and may declare at most two:

```json
"rootFiles": [ { "id": "indexnow-key", "name": "{indexnow_key}.txt", "body": "{indexnow_key}" } ]
```

Each entry has three parts:

- `id`, lower-case letters, digits, hyphens and underscores, starting with a letter, once per plugin;
- `name`, either a literal such as `abcd1234.txt`, 8 to 128 letters, digits and hyphens followed by `.txt`, or exactly `{key}.txt`, where `key` is one of the plugin's own `text` settings;
- `body`, either a literal of at most 1,024 bytes, or exactly `{key}`, one of its own `text` settings.

A setting that names a file can't be a `secret`, since a root file is public. Only `.txt` files are served.

## What core answers

For a GET at `/{name}`, core looks at the enabled plugins granted `routes.public` that may start now, and fills each name and body from their settings as they are at that moment. A match answers `200` with the body as `text/plain; charset=utf-8`, with `X-Robots-Tag: noindex`, `X-Content-Type-Options: nosniff` and `Cache-Control: no-store`. The page cache never stores it, so a new key answers at once.

A name filled from a setting answers nothing while the setting is empty, or while its value doesn't make a name of the shape above. A body that is empty or longer than 1,024 bytes answers nothing too. Any address no plugin answers passes on unchanged, so redirects and the "not found" page still apply. No plugin code runs on these requests, and on a safe page, or for a disabled plugin, no root file answers at all.

## No two owners

A literal name can't be one of core's own addresses, and can't be a literal name another enabled plugin already declares: enabling the plugin is refused, naming the plugin that owns it. A name filled from a setting is checked when the setting is saved. If another plugin answers that name now, the save is refused with "Another plugin already answers /name." If two plugins still meet, the one enabled first answers, and the other's clash is recorded under it.

## What the person agrees to

The consent lists each root file under the access to the public site, the same way it lists public routes: `GET /abcd1234.txt`, or `GET /{indexnow_key}.txt (named by its setting “IndexNow key”)` for a name filled from a setting. An update that adds a root file, or changes one's name, asks again before it starts.

For addresses under the plugin's own code, or answers the plugin's code has to work out, declare a public route instead: see [Public routes](public-routes.md).
