# Plan: public routes, for plugins and core features (v1 wave 2)

**One part, one branch: `plugins/public-routes`, from `origin/main`.** Proposal: `docs/proposals/plugin-public-routes.md` (its Settled section is binding).

**Goal.** A plugin granted `routes.public` answers its declared public addresses (GET and POST), including query- and `Accept`-matched ones such as `/?author=0` and `/.well-known/{name}`, with verified inbound POSTs, rate limits, correct page caching and contained failures, through one registry core features use too.

**Bound by:** `docs/architecture/plugins.md` (Trust, `routes.public`, inbound webhooks need a verifier, Recovery), `docs/architecture/page-cache.md` (The key, the public group), I-262 (public group without session/cookie/CSRF), I-058 (core ActivityPub will register here), I-3353, I-3396, rule 5 (contracts free of framework types), I-569 (lighter plan).

## Owns

- `app/Domain/PublicRoutes/**` (new): `DeclaredRoute`, `PublicRouteRegistry`, `RouteCollisions`, `Verifiers/{SecretVerifier,HmacVerifier,CustomVerifier}`, `RouteDispatcher`.
- `packages/teme-contracts/src/Routes/**` (new): `PublicRoute`, `PublicRequest`, `PublicResponse`, `InboundVerifier`.
- `app/Http/Site/DeclaredRouteController.php`, `app/Http/Site/Middleware/MatchDeclaredRoutes.php` (new).
- `routes/public.php`: one block registering declared path routes before the feed and fallback routes; the `MatchDeclaredRoutes` middleware added to the `public` group's home and fallback routes.
- `app/Domain/Cache/PageKey.php`: query names and the `Accept` variant from the registry (D4).
- Lines only (shared with wave-1 parts A and B, who add their own): the `Capability` enum (`RoutesPublic = 'routes.public'`), `ManifestValidator` (`routes.public` out of `LATER`; validate `routes`).
- Tests: `tests/Feature/Plugins/PublicRoutesTest.php`, `tests/Feature/Plugins/PublicRouteCollisionsTest.php`, `tests/Feature/Plugins/PublicRouteVerifiersTest.php`, `tests/Feature/Cache/DeclaredRouteCacheTest.php`, `tests/Unit/PublicRoutes/*`; fixture plugin `tests/Fixtures/plugins/inbound/`.

Not owned (other parts): the handbook, the plugin import socket, the management API, insertion points, content write, jobs.

## Decisions

**D1. The manifest entry.**

```json
"routes": [
  { "id": "callback", "method": "POST", "path": "/{code}/callback", "handler": "Vendor\\Plugin\\Callback",
    "verify": { "kind": "secret", "from": "header:X-Plugin-Token", "secret": "connection_token" },
    "maxBody": 1048576, "throttle": 30 },
  { "id": "actor", "method": "GET", "path": "/", "when": { "query": { "author": "^[0-9]+$" }, "accept": ["application/activity+json"] },
    "handler": "Vendor\\Plugin\\Actor", "cache": { "query": ["author"], "varyAccept": true, "lifetime": 3600 } }
]
```

`{code}` is replaced by the plugin's code. `path` may carry `{name}` segments constrained by `where` patterns. `verify.secret` names one of the plugin's declared `secret` settings. `from` is `header:<name>`, `field:<name>` (form or JSON body) or `query:<name>`. Kinds: `secret`, `hmac` (`header`, `secret`), `custom` (`class` implementing `InboundVerifier`).

**D2. One registry.** `PublicRouteRegistry` holds `DeclaredRoute` values with an `owner` (`core` or a plugin code), built at boot from core's own registrations plus every enabled plugin's manifest (not in safe mode). Path entries without conditions become Laravel routes in `routes/public.php`'s new block, served by `DeclaredRouteController`. Conditional entries (query or `Accept`) on paths core also answers are checked by `MatchDeclaredRoutes` in front of the home and fallback routes: a match is answered by the entry's handler; no match passes through unchanged.

**D3. Collisions** (`RouteCollisions`, checked when found and when enabled): refused when a path is under a reserved prefix (`admin`, `_teme`, `api`, `up`, `build`, `storage`) or equals a core public name (robots.txt, sitemaps, llms.txt, the feed pattern), when an unconditional path is outside `/{code}/` or `/.well-known/`, when a `.well-known` name is core's (`webfinger`, `nodeinfo`, `host-meta`, `security.txt`, `change-password`) or already another plugin's, or when two entries can match one request (same method, same path pattern, overlapping query keys and `Accept` types). The refusal names the other owner. Core entries are never refused; a plugin entry overlapping one is.

**D4. The page cache (delicate; write it as here).** Today `PageKey::QUERY` keeps only `s`, so `/?author=0` would be served the stored home page before any route runs. `PageKey` takes the registry's compiled list of cache query names (`PublicRouteRegistry::cacheQueryNames()`, every name any enabled cacheable entry declares, plus `s`) and an `Accept` variant: when an enabled entry with `varyAccept` exists for the path, the key gains `accept:{type}` for the request's first `Accept` type that any entry on that path declares, else no suffix. A request whose query names a conditional entry's parameter but whose entry isn't cacheable is never served from the cache. Stored answers carry `url:` first and `plugin:{code}`; `EnablePlugin`, `DisablePlugin`, `MigratePlugin` and `RemovePluginData` purge `plugin:{code}` after commit. The compiled list is cached with the plugin registry and rebuilt when a plugin's state changes.

**D5. Verifying POSTs.** The verifier runs before the handler and before the body is parsed for the handler; `secret` uses `hash_equals`; `hmac` computes `hash_hmac('sha256', rawBody, secret)` and compares with `hash_equals`; a missing or wrong value answers 401 with no body detail. Bodies over `maxBody` answer 413 before verification. POSTs are never cached and need no CSRF token (the public group has none).

**D6. Rate limits.** A named limiter `declared-routes` keyed by entry id and client address; defaults 120 GET and 30 POST a minute, an entry may declare less, never more than 240 and 60.

**D7. Failure.** A disabled or code-gone plugin's entries are absent from the registry (404). A throw inside a handler answers 500 with Teme's own error page and records the fault against the plugin (`SpooledFaults`). Handlers get a 30-second limit.

**D8. Consent.** `Capability::RoutesPublic`. The consent screen lists `METHOD path` per entry; the update comparison treats a new entry, a GET→POST change or a new `.well-known` name as widening (existing rule).

**D9. The contract** (`Teme\Contracts\Routes`, `@since` the next release): `PublicRoute::handle(PublicRequest $request): PublicResponse`; `PublicRequest` (method, path, path parameters, query, the request's headers lower-cased, raw body string for POST, client address); `PublicResponse` (status, content type, body string, extra headers from an allow-list: `Cache-Control` is Teme's own, never the plugin's); `InboundVerifier::verify(PublicRequest $request, string $secret): bool`.

## Tasks (tests first; each test seen red)

- [ ] **T0.** Read the files above; confirm `PageKey::QUERY` and the public group as described, with lines. Record differences as B-?? (draft).
- [ ] **T1. Manifest and capability.** Tests (`ManifestTest`, `PublicRouteCollisionsTest`): a valid `routes` passes; each D3 refusal by its sentence; a POST without `verify` refused; `verify.secret` naming no declared secret refused; `routes.public` accepted as a capability.
- [ ] **T2. Registry and dispatch.** Tests (`PublicRoutesTest`, fixture `inbound`): an enabled granted plugin's `GET /inbound/ping` answers its body; not granted, or disabled, answers 404; `/?author=0` with `Accept: application/activity+json` reaches the fixture's actor handler while `/` and `/?author=0` in a browser still get the home page; a core-owned entry registered in a test wins over an overlapping plugin entry; in safe mode no entry answers.
- [ ] **T3. Verifiers.** Tests (`PublicRouteVerifiersTest`): `secret` from header, field and query (right 200, wrong 401, missing 401, handler never called on 401); `hmac` right/wrong; `custom` calls the plugin's class; 413 over `maxBody` before verification.
- [ ] **T4. Page cache.** Tests (`DeclaredRouteCacheTest`): with the cache on, `/` stored, then `/?author=0` with the activity `Accept` answers the plugin's JSON (not the stored home); the plugin's cacheable answer is stored under its own key and served on the next request without PHP running the handler; `/?author=0` in a browser still gets the home page; disabling the plugin purges `plugin:{code}`; a POST is never stored.
- [ ] **T5. Limits and failure.** Tests: the 121st GET in a minute answers 429; a throwing handler answers 500 with no plugin detail and records a fault; the consent screen lists the routes; an update adding a route asks for consent again.
- [ ] **T6. Records.** Fill Records; the full check once.

**Quick check:** `php artisan test --compact tests/Feature/Plugins/PublicRoutesTest.php tests/Feature/Plugins/PublicRouteCollisionsTest.php tests/Feature/Plugins/PublicRouteVerifiersTest.php tests/Feature/Cache/DeclaredRouteCacheTest.php tests/Architecture` and `vendor/bin/phpstan analyse app/Domain/PublicRoutes packages/teme-contracts/src/Routes`.

## Records

(The builder fills this.)
