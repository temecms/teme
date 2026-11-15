# Proposal: public routes, for plugins and core features (v1 wave 2)

**Status:** draft, 2026-11-15. Part of v1 (I-3445), wave 2. Plan: `docs/plans/plugin-public-routes.md`.

## Why

A plugin that talks to the outside world needs addresses of its own: an AI writing service calling back with a post, a webhook from a mail service, a fediverse server looking an account up. `docs/architecture/plugins.md` names the capability (`routes.public`) and says an inbound webhook can't be declared without a verifier; nothing is built. Core's own ActivityPub (I-058) needs the same thing for `/?author=0` and `/.well-known/webfinger`, so core features and plugins share one mechanism.

## Settled

1. **Declared, not coded.** A plugin lists its public routes in its manifest under `routes`: method (GET or POST), path, optional query and `Accept` conditions, its handler class, cache rules (GET) and verifier (POST). Teme checks every entry when the plugin is found and when it is enabled.
2. **One registry for core and plugins.** Core features register their public routes in the same registry, with owner `core`. Core always wins: no plugin entry can take a core entry's place.
3. **Where a plugin may answer:**
   - any path under `/{plugin-code}/…`;
   - `/.well-known/{name}`, one owner per name, first come first served;
   - any path at all **only with a condition**: a query parameter (`/?author=0`) or an `Accept` type (`application/activity+json`). Without the condition the request falls through to whatever answers it today.
   Paths under `admin`, `_teme`, `api`, `up`, `build`, `storage` and core's named public addresses (robots.txt, sitemaps, llms.txt, feeds) are refused.
4. **Collisions are refused, never resolved at random.** Two entries that could match the same request (same method and path, overlapping conditions) cannot both be enabled; the second plugin's enable is refused with a sentence naming the first.
5. **Inbound POSTs must be verified.** Every POST names a verifier: `secret` (a value in a named header, field or query compared, in constant time, with one of the plugin's `secret` settings), `hmac` (a SHA-256 signature of the raw body in a named header), or `custom` (the plugin's own verifier class, for schemes such as HTTP Signatures). A request that fails is answered 401 and never reaches the handler. Bodies are capped (1 MB unless the entry asks for more, up to 10 MB).
6. **The page cache stays correct.** GET entries are drawn in the public group (no session, cookie or CSRF). Nothing a plugin route answers is stored unless the entry says so. A cacheable entry names the query parameters and the `Accept` variants its answer depends on; the page key includes them, so `/?author=0` asking for activity JSON is never served the stored home page, and the reverse. Every stored answer carries the tag `plugin:{code}`, purged when the plugin is disabled, updated or removed. POSTs are never stored.
7. **Rate limits.** Every entry is throttled per address: 120 a minute for GET and 30 for POST unless the entry declares less (never more than twice those).
8. **Consent.** `routes.public` becomes a working capability. The consent screen lists every route (method and path); an update that adds a route, turns a GET into a POST or claims a new `.well-known` name asks again.
9. **Failure stays contained.** A disabled plugin's routes answer 404. A handler that throws answers 500 with no plugin detail shown, and the failure is recorded against the plugin. Safe mode loads no plugin routes.
10. **The contract has no framework types** (rule 5): `PublicRoute::handle(PublicRequest): PublicResponse` in `teme/contracts`, with plain values in and out.

## Provisional (built as written unless the maintainers say otherwise)

- **P1.** A plugin's HTML answers are plain (its own markup). Answers drawn inside the active theme's layout come later.
- **P2.** The limits above (120 and 30 a minute; 1 MB and 10 MB bodies).
- **P3.** `.well-known` names are first come, first served among plugins; core's reserved names (`webfinger`, `nodeinfo`, `host-meta`, `security.txt`, `change-password`) belong to core.

## Out of this part

Core's ActivityPub itself (it will register entries here); a plugin's admin pages (built); theme-wrapped plugin pages (P1); the management API (its own part).
