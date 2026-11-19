# Teme

This file, and `CLAUDE.md`, are generated: Laravel Boost builds both from one source, `.ai/guidelines/teme.md`. Edit that source, never the generated files.

===

<laravel-boost-guidelines>
=== .ai/teme rules ===

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

=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
