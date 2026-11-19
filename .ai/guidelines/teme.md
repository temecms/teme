# Teme project guidelines

Teme is a content management system built on Laravel and PostgreSQL. It replaces WordPress: it owns the content, editor, media, themes, plugins, users, permissions, publishing and the public website.

It keeps WordPress's product model — Posts, Pages, Media, Settings, taxonomies, actions and filters — because twenty years have validated those nouns. It discards WordPress's implementation entirely.

## Read before changing anything

| Document | What it is |
|---|---|
| `docs/superpowers/specs/2026-09-17-cms-design.md` | The architecture. **§3 is normative.** |
| `docs/architecture/operations.md` | How every state mutation works |
| `docs/architecture/coding-standards.md` | PHPDoc, comments, naming |
| `docs/architecture/documentation.md` | What gets documented, where, and what is generated |
| `docs/references.md` | External documentation at the versions we use — Laravel 13, PostgreSQL 18, Pest 5, Inertia 3, Tiptap 3 — and how to look things up |
| `docs/roadmap.md` | Phases, slices, status, and what moved between them — **the tracker** |

## Non-negotiable rules

1. **Every state mutation goes through an Operation.** Controllers, API endpoints, queue jobs, console commands and AI tools call operations; none of them touch Eloquent models directly. Enforced by `tests/Architecture/LayeringTest.php` and a static-analysis rule. **One named exception:** Laravel Fortify's own credential state — two-factor secrets, recovery codes, email verification, remember tokens, passkeys — listed exactly in that rule's allow-list (idea I-100). Nothing else is exempt, and the allow-list is never widened without the maintainer's decision.

2. **Never create a key-value or EAV meta table.** One row per content item, with typed values in a single JSONB column governed by the field registry. This is the most consequential decision in the project — `wp_postmeta` is the reason WordPress is slow at scale, because it grows as posts × fields and its `meta_value` column cannot be usefully indexed.

3. **PostgreSQL only.** No MySQL or SQLite compatibility anywhere, including tests. Tests run against PostgreSQL, because SQLite would hide every JSONB and index behaviour the design depends on.

4. **No PHP `serialize()` in the database.** JSONB for all structured values. Serialized strings carry byte-length prefixes that break on search-replace, which is the entire reason WordPress migrations need special tooling. **Two named exceptions:** Laravel's `cache` table, which is disposable — never exported, migrated or restored, and rebuilt whenever it is cleared — with `serializable_classes` kept `false` in `config/cache.php` (I-261, the maintainer's decision); and Laravel's queue tables (`jobs`, `failed_jobs`, `job_batches`), whose payloads Laravel serializes itself — short-lived working rows, never exported or migrated, and backed up as structure only (I-534, the maintainer's decision). Nothing else is exempt, and page-cache entries are never serialized.

5. **`packages/teme-contracts` imports nothing from `app/` and nothing from `Illuminate`.** It is the published surface extension authors code against. A framework type there means one Laravel upgrade breaks every extension at once.

6. **Hooks and cache invalidation fire after commit**, never inside the transaction. Invalidating cache inside a transaction that then rolls back leaves the cache purged for a change that never happened.

7. **Content is stored as Tiptap JSON, never HTML.** Tiptap is the editor; the JSON it produces is the stored document. HTML in the database is the road that leads to shortcodes.

8. **Before copying any WordPress behaviour, state why it exists.** If the reason is a dead 2004 constraint — shared hosting, MySQL 4, PHP 4 without namespaces, permanent backwards compatibility — drop it. If the reason is that editors genuinely need it, keep it. This test is the difference between rebuilding WordPress and rebuilding its mistakes.

9. **Never store the site's own absolute URLs in content.** Internal links store the target content's ID, media is referenced by ID, and URLs are generated at render time from the current site address. This is what makes moving to a new domain a single configuration change instead of a search-and-replace. Tiptap's link mark stores an `href` by default — for internal links, store the ID and resolve the `href` on render.

## What is deliberately not here

Multisite. Shared-hosting support. A freeform visual page builder. Ecommerce. WordPress plugin or theme compatibility. A web process that writes code — code is read-only at runtime. Themes and plugins are Composer packages; they may be installed from the admin, but the admin only records an install request and a separate installer process carries it out (I-211, `docs/architecture/plugins.md`).

If you believe one of these is needed, it is a product decision, not an implementation detail. Raise it; do not build it.

## Commands

```bash
composer check          # Pint, PHPStan and the full test suite
php artisan test        # Pest
./vendor/bin/pint       # format
docker compose up -d    # PostgreSQL, and Valkey for the Redis page store's tests (valkey_test)
```

`composer check` is the gate before anything is merged. While building, commit after the quick check: Pint, PHPStan, the type and lint checks, the Architecture suite and the tests the change touches; run the full `composer check` once the part is done.

## Workflow

- Work in complete vertical slices that leave a working product. A slice that adds a table but nothing that uses it is not a slice.
- Build each slice on its own branch; merge to `main` only when the slice is done. `main` must always be deployable, because once any site runs on Teme — even a test site — patches are tagged from it.
- Tag each finished slice per the scheme in `docs/roadmap.md` — `v0.1.0-alpha.1` for slice 1.1.
- Write the failing test first, and run it to confirm it fails for the reason you expect.
- Every operation needs a test proving it writes nothing when authorization fails.
- Commit after every task, Conventional Commits format.
- If the plan and the code disagree, stop and say so. Do not quietly implement something different.
- When you start sub-agents, name the model every time, because leaving it out inherits yours. Use Sonnet (or Haiku) for routine work: research, reading the references, docs, drawing designs, mechanical edits, applying a review's fixes. Use Opus only for delicate plans and code (permissions, migrations, the updater, security) and for reviews before merging.
- Every class in `app/` opens with a one-to-three-sentence docblock saying what it is for, and every public query method says what it returns. Every PHP file opens with the short file header (what it is for, `@package`, `@since`, `@license`), before `declare(strict_types=1)`; a new file or public member says `@since next`, which the release step replaces with the version. See `docs/architecture/coding-standards.md`.

## Naming

- Operations are imperatives: `CreateDraft`, `PublishRevision` — never `DraftCreator` or `PublishingService`.
- Public surface carries the product name (`Teme\Contracts`, `teme/*` packages, `teme:` console namespace). Internal domain code stays `App\Domain\*`; inside Teme's own repository a `Teme\` prefix on every internal class is redundant.

## Versions Boost does not cover yet

Boost's Pest guidelines stop at Pest 4.x. This project runs **Pest 5**. Where Boost's Pest guidance and the Pest 5 documentation disagree, Pest 5 wins.
