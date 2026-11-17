---
title: Public routes
type: how-to
audience: plugin
summary: How a plugin answers addresses on the public site, which paths it may take, how inbound posts are verified, cached and limited, what Teme answers before a handler runs, and which addresses Teme answers itself.
rests_on: [app/Domain/PublicRoutes, packages/teme-contracts/src/Routes, packages/teme-contracts/schema/teme-plugin.schema.json, routes/public.php, app/Domain/Feeds]
---

# Public routes

A plugin can answer addresses on the public site: a feed of its own, a callback a service posts to, a discovery document under `/.well-known/`. It declares each address in `teme-plugin.json`, and Teme answers it with a small handler class. The plugin never registers routes itself.

A small text file at the site's root, such as a verification key, needs no route: see [Files at the site's root](root-files.md).

## Declare a route

Ask for the `routes.public` capability, and list each address under `routes`:

- `id`, unique within the plugin: lower-case letters, digits, hyphens and underscores, starting with a letter;
- `method`, GET or POST; a GET route answers HEAD too;
- `path`, starting with `/`; `{code}` in it is replaced by your plugin's code, and any other `{name}` is a parameter, one path segment unless `where` gives its pattern;
- `handler`, the class that answers it.

The optional keys are `when`, `verify`, `cache`, `maxBody`, `throttle` and `where`, described below. The generated [Plugin manifest reference](../../../reference/plugin-manifest.md) lists each with its type and limits.

## Which paths a plugin may take

Teme checks the routes when the plugin is enabled and refuses the plugin if any one breaks a rule:

- A route that matches every request to its path must sit under your own code, `/{code}/` (or be `/{code}` itself), or under `/.well-known/`.
- A route may sit anywhere, `/` included, only with a `when` condition: the query parameters it needs, each a pattern (`when.query`), or the types the request's Accept header must name (`when.accept`). A request that doesn't meet the condition goes on to the public site.
- No route may start with `admin`, `_teme`, `api`, `up`, `build` or `storage`.
- No route may take an address Teme answers itself: `robots.txt`, `sitemap.xml`, `sitemap.xsl`, the sitemap pages `sitemap-…xml`, `llms.txt`, `feed`, `wp-sitemap.xml` and `sitemap_index.xml`, nor the `.well-known` names `webfinger`, `nodeinfo`, `host-meta`, `security.txt` and `change-password`.
- No two routes, your own or another enabled plugin's, may match one request.

A plugin enabled before another took the same address keeps it: the second is refused when someone enables it.

## Write the handler

The handler implements `PublicRoute`. Its `handle()` receives a `PublicRequest`, plain values only: the method, the path, the path parameters, the query, the headers with lower-case names, the raw body and the client's address. It answers a `PublicResponse` with a status, a content type, a body, and any of the headers `content-language`, `link`, `location` and `x-robots-tag`; Teme drops any other header and always sets Cache-Control itself.

Teme builds the handler as it builds your plugin's class: with what its constructor asks for, among the doors your plugin is given, such as the settings reader or the `Jobs` door, and with nothing when it asks for nothing. A handler that asks for a door its plugin doesn't ask for is refused when the plugin is checked. It can also read its own tables, as [Your plugin's own tables](tables.md) describes.

A handler has 30 seconds. When it throws, Teme reports the exception to the site's log with your plugin's name and the route's ID, and the visitor gets the site's own error page.

## Verify inbound posts

Every POST route needs a `verify` block, so nobody but the service you expect can post to it. Its `kind` is one of:

- `secret`: the value named by `from` must equal a secret setting. `from` is `header:` and a header name, `field:` and a form field, or `query:` and a query parameter. `secret` names the setting, which must be one of your plugin's declared settings of type `secret`.
- `hmac`: the header named by `header` must hold the SHA-256 HMAC of the raw body, in lower-case hex, keyed with the secret setting `secret` names.
- `custom`: `class` names your own class implementing `InboundVerifier`; its `verify()` is given the request and the value of the secret setting `secret` names, and answers whether to let it through. For `custom`, `secret` is optional: a verifier that checks something else, such as a signature with a public key, leaves it out and is given an empty string. When it is given, it must name one of your declared settings of type `secret`, as for the other two kinds.

Teme answers these itself, before your handler runs, with an empty body:

- 405 to a POST, or any method but GET and HEAD, that matches no route;
- 413 to a body over `maxBody` bytes, 1,048,576 by default and 10,485,760 at most, judged first by its Content-Length, before the body is read;
- 401 to a post that fails its check;
- 429 to a client over the route's limit, described below.

Only a request that passes all four reaches the handler.

## Caching and limits

A GET route with a `cache` block may be kept by the site's page cache and by a cache in front of the site. `lifetime` is the most seconds a shared cache may keep the answer, at most 60; left out, it is 60. `query` names the query parameters that make a different answer, and must name every parameter in `when.query`. `varyAccept` keeps a different answer for each Accept header, and a cached route that matches on Accept must set it. Enabling or disabling the plugin clears what was kept. A route without `cache` is never kept.

Each route counts requests per client address, a minute at a time: 120 GET or 30 POST requests by default. `throttle` lowers or raises that, to at most 240 for GET and 60 for POST.

## The order addresses are answered in

Teme's own exact addresses come first: `robots.txt`, `ads.txt`, the sitemaps and `llms.txt`. Then the `.well-known` routes plugins declared, then the feeds and the Markdown copies of pages, then plugins' routes under their code, and last the public site: the front page, items, archives, and the 404 page. A plugin's conditional route at `/` or another public address is tried before the public site answers it.

## Addresses Teme answers itself

The public site answers these, so a plugin needs no route of its own for them:

- the main feed at `/feed/`, a type's archive feed at its archive address followed by `feed/`, and a category's, a tag's and an author's feed at their own address followed by `feed/`;
- WordPress's spellings of those feeds, the base followed by `feed`, `rdf`, `rss`, `rss2` or `atom`, or `feed/` and one of those names, which lead to the one canonical address; WordPress's comment feeds are gone;
- the sitemaps and `robots.txt`, `llms.txt` and the Markdown copy of each page.

Next: [Your plugin's own tables](tables.md), and [Jobs and schedules](jobs.md).
