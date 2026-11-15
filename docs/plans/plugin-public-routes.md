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

- [x] **T0.** Read the files above; confirm `PageKey::QUERY` and the public group as described, with lines. Record differences as B-?? (draft).
- [x] **T1. Manifest and capability.** Tests (`ManifestTest`, `PublicRouteCollisionsTest`): a valid `routes` passes; each D3 refusal by its sentence; a POST without `verify` refused; `verify.secret` naming no declared secret refused; `routes.public` accepted as a capability.
- [x] **T2. Registry and dispatch.** Tests (`PublicRoutesTest`, fixture `inbound`): an enabled granted plugin's `GET /inbound/ping` answers its body; not granted, or disabled, answers 404; `/?author=0` with `Accept: application/activity+json` reaches the fixture's actor handler while `/` and `/?author=0` in a browser still get the home page; a core-owned entry registered in a test wins over an overlapping plugin entry; in safe mode no entry answers.
- [x] **T3. Verifiers.** Tests (`PublicRouteVerifiersTest`): `secret` from header, field and query (right 200, wrong 401, missing 401, handler never called on 401); `hmac` right/wrong; `custom` calls the plugin's class; 413 over `maxBody` before verification.
- [x] **T4. Page cache.** Tests (`DeclaredRouteCacheTest`): with the cache on, `/` stored, then `/?author=0` with the activity `Accept` answers the plugin's JSON (not the stored home); the plugin's cacheable answer is stored under its own key and served on the next request without PHP running the handler; `/?author=0` in a browser still gets the home page; disabling the plugin purges `plugin:{code}`; a POST is never stored.
- [x] **T5. Limits and failure.** Tests: the 121st GET in a minute answers 429; a throwing handler answers 500 with no plugin detail and records a fault; the consent screen lists the routes; an update adding a route asks for consent again.
- [x] **T6. Records.** Fill Records; the full check once.

**Quick check:** `php artisan test --compact tests/Feature/Plugins/PublicRoutesTest.php tests/Feature/Plugins/PublicRouteCollisionsTest.php tests/Feature/Plugins/PublicRouteVerifiersTest.php tests/Feature/Cache/DeclaredRouteCacheTest.php tests/Architecture` and `vendor/bin/phpstan analyse app/Domain/PublicRoutes packages/teme-contracts/src/Routes`.

## Records

### T0

- `PageKey::QUERY` is `['s']` only (`app/Domain/Cache/PageKey.php` line 21), as the plan describes.
- The `public` middleware group has no session, cookie or CSRF (`bootstrap/app.php` lines 78–84: `HoldUntilSetUp`, `DesignPreviewLink`, `SubstituteBindings` only).
- **B-01 (draft):** `StoreRules::MARKED_TYPES` already stores `application/json` when a route is marked cacheable, but not `application/activity+json`. A cacheable ActivityPub-style answer needs that type added (or the fixture to answer `application/json`). Built: add `application/activity+json` to `MARKED_TYPES`.
- **B-02 (draft):** Registering one Laravel route per manifest entry would freeze the router until the next boot, so an enable in a test (and `route:cache`) would miss new paths. Built: fixed shapes `/{code}/{path?}` and `/.well-known/{name}` that look up `PublicRouteRegistry`, plus `MatchDeclaredRoutes` on home and fallback for conditional entries.

Draft numbers: B-01 upward (plan gave no block).

### T1

- First failure: `Failed asserting that two arrays are identical.` (acceptance still refused with `Teme doesn't offer routes.public to plugins yet.`).
- Built: `Capability::RoutesPublic`, `routes.public` out of `LATER`, `RouteCollisions`, manifest `routes` validation.

### T2

- First failure while wiring: `PHP Fatal error:  Readonly property App\Domain\PublicRoutes\RouteDispatcher::$autoloaded cannot have default value`.
- Process slip: feature tests were green on first full run after that fix because the registry and dispatch were already in place; the fatal above is the recorded red.
- **B-03 (draft):** `NoStaticStateTest` refuses a singleton that holds request-derived state. Built: `PublicRouteRegistry` is scoped, not a singleton.
- Built: contracts under `Teme\Contracts\Routes`, registry, dispatcher, controller, `MatchDeclaredRoutes`, fixture `tests/Fixtures/plugins/inbound/`, fixed shapes in `routes/public.php` (B-02).

### T3

- First failure: `Expected response status code [200] but received 401.` (secret from a form field).
- **B-04 (draft):** Laravel's form POST fills the request bag without leaving `getContent()` as the body string. Built: `RouteDispatcher` rebuilds `rawBody` from the bag when the string is empty.

### T4

- First failure: `Header [Content-Type] was found, but value [text/html; charset=utf-8] does not match [application/activity+json].`
- Built: `PageKey` takes registry query names and Accept variants; `StoreRules` allows `application/activity+json` (B-01); enable/disable/migrate/remove purge `plugin:{code}`; cacheable declared answers tagged and stored.

### T5

- First failures that still needed code: `Failed asserting that an array contains 'GET /inbound/ping'.` and `Failed asserting that true is false.` (consent still covered after an added route).
- Rate-limit and throwing-handler cases were already green from the T2 limiter and dispatcher catch; recorded as built earlier, not re-broken.
- Built: consent stores and compares `METHOD path` lines; Plugins screen lists them; `asksMore` / Review access when a route is added.

### T6

- Commits on `plugins/public-routes` (`git log origin/main..HEAD --oneline`):
  - `docs(plans): confirm PageKey and public group for public routes`
  - `feat(plugins): validate declared public routes and routes.public`
  - `feat(plugins): dispatch declared public routes from the registry`
  - `test(plugins): cover secret, hmac and custom public route verifiers`
  - `feat(cache): honour declared-route Accept and query names`
  - `feat(plugins): rate-limit declared routes and widen consent for them`
  - `fix(plugins): satisfy phpstan on declared-routes query names`
  - `fix(plugins): fall through fixed-shape declared routes to the public site`
  - `fix(plugins): order public fallbacks so feeds and markdown win`
  - `docs(plans): Plugin public routes' records` (this commit)
- Architecture suite: **139** passed (1309 assertions) before and after (no baseline numbers raised).
- Full `composer check`: Pint passed; PHPStan OK; types/lint/theme build passed; Unit+Feature **10630** passed, **2** skipped (54822 assertions).
- Contracts added: `Teme\Contracts\Routes\{PublicRoute,PublicRequest,PublicResponse,InboundVerifier}` (`@since 0.8.0`).
- B-?? (draft): B-01, B-02, B-03, B-04, B-05.
- **B-05 (draft):** The fixed shape `{code}/{path?}` also matched ordinary public paths and `/api/…`, so misses 404'd the site and stole the API fallback. Built: exact public routes first (robots, sitemaps, llms, well-known); fallbacks in order `feeds.show`, `markdown.show`, `declared.plugin`, then `public.path` so HoldUntilSetUp keeps feed/md route names, late probes like `_probe.md` still win, and language `.md` beats the declared catch-all; miss falls through to `FeedController` (then `PublicController`); reserved first segments `admin|api|up|build|storage` are excluded from `{code}`; fallthrough still counts `?s=` toward the search limit; `PageKey` / `PageTags` / `RedisPageStoreTest` use `RefreshDatabase` because `PageKey` now reads the registry.
- STOP: none.
- Changelog lines for the maintainer (do not edit `CHANGELOG.md` here):

```
### Added
- Plugins granted `routes.public` may declare public GET and POST addresses, answered through one registry core features share.
- Inbound POSTs support secret, HMAC and custom verifiers; declared routes are rate-limited and contained on failure.
- The page cache keys declared Accept and query variants, and purges `plugin:{code}` when a plugin's state changes.
- The Plugins consent screen lists each declared `METHOD path`, and an update that adds a route asks again.
```

Try it: enable the inbound fixture with `routes.public`, `GET /inbound/ping`, `GET /?author=0` with `Accept: application/activity+json`, and a signed `POST /inbound/callback`.
