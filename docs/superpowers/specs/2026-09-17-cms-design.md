# Teme — Architecture & Phase 1 Design

**Status:** Draft for review
**Date:** 17 September 2026
**Supersedes:** parts of `CMS-INTERFACE-DESIGN.md` (see §11)

---

## 1. What we are building

**Teme** — Ijaw for *create*.

A complete, standalone, installable CMS that replaces WordPress.

It owns the content, editor, media, themes, plugins, users, permissions, publishing, and the public website. Installation ends with a working site.

It keeps WordPress's product model — Posts, Pages, Media, Appearance, Plugins, Settings, custom post types, taxonomies, actions and filters — because twenty years and a large share of the web have validated those nouns. It discards WordPress's implementation entirely.

AI is architecture, not a feature. Humans, the API, and AI all reach the content through one set of permission-checked operations.

### Guiding reframe

> **WordPress is a requirements document, not an implementation reference.**

Read WordPress core to learn *what* to build and *what to avoid*. Never to learn *how*. Nearly every structural decision in WP core is an accumulated compromise from 2004 constraints — shared hosting with no persistent processes, MySQL 4 without JSON, PHP 4 without namespaces, and an absolute rule against breaking backwards compatibility. All of those are gone. Copying the structure inherits the constraints without the reasons.

**Corollary:** before copying any WordPress behaviour, state why it exists. If the answer is a dead constraint, drop it. If the answer is "editors genuinely need this", keep it.

### Built for any publication

Teme is designed for any publication, of any size, on any topic. Real sites used for testing are **test cases and a source of ideas**. They never set requirements, targets or sequencing. When an idea comes from one, it is judged by whether a CMS for any site should have it.

### Development context

Built solo, by directing AI coding agents through prompts. Developers may be hired later, so the codebase must stay conventional enough that a competent Laravel developer recognises it immediately.

### Trajectory

Proven on test migrations of real publications → self-hostable release → possibly managed hosting. Each step earned by the previous one, not assumed. Moving a real publication onto Teme for good happens only when the maintainers are satisfied with development, and is itself a test.

---

## 2. Settled decisions

| Decision | Value | Rationale |
|---|---|---|
| Backend | **PHP + Laravel** | Conventions reduce entropy across hundreds of independent agent sessions. Not a claim about developer cost |
| Database | **PostgreSQL only** | JSONB + GIN, partial indexes, `tsvector` FTS, advisory locks, pgvector. Above all **transactional DDL** — a failed migration rolls back cleanly on machines we do not control. A second database would double migration and query testing forever |
| Admin | **Inertia + React + TypeScript** | Keeps Laravel routing, controllers, validation and auth; removes the need to build an API for our own admin |
| Public rendering | **Blade**, Alpine only where a theme needs interactivity | |
| Editor | **Tiptap**, prose-first with embedded cards | See §5.4 |
| Multisite | **Out of scope** | One install = one site, as WordPress single-site and Ghost. Not deferred-with-preparation — out |
| Shared hosting | **Not supported** | VPS / container baseline. This is what permits persistent queue workers |
| Distribution | Docker image primary, documented bare-VPS path | |
| Theme starter | **Not forking Sage.** Teme's theme layer takes from Sage and Acorn the composer API (`$views`, `with()`), Blade layouts and anonymous components, and a per-theme Vite build; it leaves Sage's WordPress glue, composer auto-discovery, header-scanned page templates and parent-theme paths. Typed per-route view models come from Statamic, not Sage | Sage exists to bring Laravel to WordPress, which is why Teme does not fork it; what is worth taking is small and named. Verified from Sage 11.2.1 and Acorn 6.2.0 source in study Q (2026-09-22) |
| Product name | **Teme** | Ijaw for *create*. Public namespace `Teme\Contracts`; Composer packages `teme/*`; extension types `teme-theme`, `teme-plugin`; console namespace `teme:` |

The application's internal domain namespace is `App\Domain\*` (it was `App\Cms\*` until slice 1.3e, I-149). Inside Teme's own repository a `Teme\` prefix on every internal class is redundant, and `App\Domain\Contents\Operations\PublishRevision` reads better than the alternative. Only the published surface carries the product name, because that is what extension authors type.

### Deliberately deferred

Multilingual (phase 3+, no schema preparation in v1), ecommerce (never core), collaborative editing, marketplace, managed hosting.

**Comments are core and in phase 1.** Not for engagement reasons — for migration. A WordPress site arriving with 40,000 comments has nowhere to put them otherwise, and those comments are indexed, linked content. Anything the importer must carry cannot be deferred.

---

## 3. What we reject from WordPress

Every item below looks like "how a CMS is built" and is a trap. This table is normative: an implementation that violates it is wrong, even if it works.

| WordPress does | Why it is wrong | We do |
|---|---|---|
| `wp_postmeta` EAV table | Unindexable `longtext` values; N rows per item; joins on every query. Grows as posts × fields | One row per content item. Typed fields in a single JSONB column |
| Everything in `wp_posts` | Posts, pages, attachments, revisions **and nav menu items** share one table. Revisions alone triple row count | Separate tables. Content, revisions, media and navigation are different shapes |
| `wp_options` with `autoload` | The whole autoload set loads on every request; plugins dump megabytes there | Explicit typed settings registry. Load what the request needs |
| PHP `serialize()` in the database | Unqueryable; byte-length prefixes break on search-replace. The sole reason WP migrations need special tooling | JSONB |
| Shortcodes | A regex macro language inside content. Remove the plugin and the content is permanently broken | Cards. Imported shortcodes become a fallback card, never originated |
| Blocks stored as annotated HTML | Content must be re-parsed and validated on every load; drift produces "this block contains unexpected or invalid content" | Tiptap JSON in a revisions table. This failure mode cannot occur |
| Global mutable state (`$post`, `global $wpdb`, the Loop) | Untestable; the reason WP code cannot be meaningfully unit tested | Explicit dependencies. View composers bind data to templates |
| Untyped hooks | Accept anything, return anything, no contract, no error handling | Typed contracts, declared payloads, `Throwable` caught per listener |
| Plugins `echo` their admin UI | Why WP admin looks incoherent | Schema-declared forms rendered by core components |
| Web-writable, web-executable code directory | One plugin vulnerability equals full site takeover | Code read-only at runtime. Uploads non-executable, ideally separate storage |
| Theme File Editor | Editing live PHP from a browser. A standing security hazard kept for legacy reasons | Themes are code, deployed with the application |
| Widgets | A 2007 sidebar concept superseded by blocks and template regions, kept alive by backwards compatibility | Theme-defined regions filled with content |
| The Customizer | A cramped panel over an iframe with a settings API parallel to the options API. WordPress is itself moving away from it | Theme settings are ordinary settings in the one registry, plus a Preview button |
| The site's own absolute URLs stored in content | Moving to a new domain needs a search-and-replace, and PHP-serialized data breaks under one — the reason WordPress migrations need special tools | Content stores IDs — internal links store the target content ID, media is referenced by ID — and URLs are generated at render time from the current site address (§5.11) |
| wp-cron | Fires on page requests. No traffic means scheduled posts never publish | Real queue workers plus system cron |
| Caching left to plugins | Plugins cannot see the dependency graph, so they guess. Result: stale pages and a paid plugin required for speed | Cache policy in core, storage pluggable (§5.6) |
| Backwards compatibility forever | The single reason WP cannot modernise | Semver on a narrow public contract, with migration guides |

---

## 4. Phases

Phases are **commitment boundaries**, not feature buckets. Each converts something private into a public promise that cannot be retracted.

| Phase | Done when | Promise made |
|---|---|---|
| **1. Core publishing loop** | A full test migration passes end to end | None — everything internal and freely changeable |
| **2. Content model** | Custom types, fields and taxonomies work end to end, proven by a test migration of a site that uses them | None |
| **3. Public surfaces** | API and MCP consumed externally | API shape |
| **4. Theme contract** | A second theme built against a documented interface | Theme interface |
| **5. Plugin API** | Someone other than us extends it | Hook stability |
| **6. Distribution** | A stranger installs it | Upgradeability |

Phase 5 is the real gate. Until it ships, the core can be restructured freely. After it ships, it cannot. This is the principal advantage of proving Teme on test migrations before anyone depends on it.

> **Amended 2026-09-18.** Phase goals originally read as real publications going live — "one publication fully migrated and live", "remaining publications migrated". They now describe what Teme can do, proven by test migrations. Moving a real publication onto Teme happens later, when the maintainers are satisfied with development, and is for testing.

---

## 5. Architecture

### 5.1 Application structure

A modular monolith in one Laravel application.

```
app/Domain/             Contents/  Media/  Taxonomies/  Cards/
                        Comments/  Themes/  Settings/  Search/
                        Cache/  Ai/  Hooks/
                          - each with Operations/  Models/  Policies/
app/Http/               Admin/ (Inertia)   Api/   Site/ (public render)
packages/teme-contracts/ public interfaces, semver'd independently
resources/js/           admin/   editor/
themes/default/
```

> **Amended 2026-09-19 (I-149).** The domain namespace was `App\Cms` until slice 1.3e; it is now `App\Domain`.

`packages/teme-contracts` exists from day one even though it is not published until phases 4–5. Its purpose is to make the boundary visible while it is still cheap to move.

### 5.2 The Operation rule

> **Controllers, jobs, console commands, API endpoints and AI tools never touch models. They call an Operation.**

**One named exception (I-100, 2026-09-18).** Laravel Fortify writes its own credential state directly: two-factor secrets and recovery codes, email verification, remember tokens and passkeys. Only the account's owner, signed in through a browser, ever changes those values, so no second path can drift from them. The exception is listed exactly, by package, table and column, and enforced by the static-analysis rule as an allow-list; the security event log records Fortify's events. Nothing else is exempt.

An Operation is one class performing one thing in a fixed order:

```
authorize -> validate -> mutate -> record revision -> fire hooks
          -> invalidate cache -> return result
```

`PublishRevision` is the same code whether triggered by a button, a scheduled job, an API call or an AI agent. This is the enforceable form of "code sharing is optional; behaviour sharing is mandatory".

**This is verified by architecture tests, not by convention.** A Pest architecture test fails the build if a controller imports an Eloquent model. Agents read test failures reliably and documentation inconsistently; the constraint must be executable.

### 5.3 Content storage

Source of truth is a JSONB document per revision, with small derived tables projected from it for the relationships we actually query.

```
contents            id, type, slug, status, author_id,
                    published_revision_id, working_revision_id,
                    published_at, scheduled_for,
                    fields JSONB (projection of the published revision),
                    search_tsv

content_revisions   id, content_id, document JSONB, fields JSONB,
                    title, excerpt, author_id,
                    origin (human|ai|import), created_at

media_usages        content_id, media_id, node_id        <- derived
content_links       from_content_id, to_content_id       <- derived
card_usages         content_id, card_type, node_id       <- derived
content_relations   from_content_id, to_content_id, field_key  <- derived

comments            id, content_id, parent_id, author_name, author_email,
                    author_url, author_user_id, body, status
                    (pending|approved|spam|trash), ip, user_agent,
                    source (native|imported), created_at
```

Comments are a real table with a real foreign key, not content rows. WordPress stores them separately too — this is one of the few places its schema is sound.

> **Amended 2026-09-22 (study S).** The `comments` table gains `path` (a sortable thread path of ULIDs), `depth`, `kind`, `legacy_id`, `commenter_token_hash` and `moderation`; counts live in a `comment_stats` row per item; `contents` gains `comment_status` (open, closed, hidden). The body is a restricted Tiptap document (I-267), and IP addresses are kept 60 days (I-270). See `docs/studies/comments.md`.

> **Amended 2026-09-30 (slice 1.8a, I-591).** No `source` column: an imported comment is one with a `legacy_id`, and a second column saying the same could disagree with it.

**Why not normalised block rows.** One row per block per revision means 10k articles × ~50 nodes × ~20 revisions ≈ 10 million rows to store what this holds in 200,000. Every save becomes a diff-and-upsert reconciliation — precisely the kind of code that silently corrupts data.

**Publishing falls out of the schema.** `published_revision_id` and `working_revision_id` are separate pointers on one row. The public site renders the published pointer and never sees autosaves. "Update…" is one pointer assignment. "Restore as draft" writes a working revision and does not touch the published pointer.

**Derived tables are projections, not schema.** Rebuildable from the documents at any time, so they never need careful migration. Get the shape wrong, truncate and re-derive.

**`fields JSONB` is the anti-postmeta.** Adding a field to a content type changes a registry entry and the JSONB shape; row count never moves. For a field that is sorted or filtered heavily, add an **expression index** built with `CREATE INDEX CONCURRENTLY`. Not a generated column — Postgres generated columns are `STORED`, so adding one rewrites the whole table under `ACCESS EXCLUSIVE`.

**Field values are versioned with the body.** `fields` lives on `content_revisions`; `contents.fields` is the projection of the published revision. Versioning the prose without the fields would restore an article's text while silently keeping today's SEO title and featured image — §7.5 would be unsatisfiable. Statamic, Craft and Payload all version custom field values alongside the body.

**JSONB keys are immutable ULIDs, not field handles.** Craft stores field UIDs, which is why renaming a field there costs nothing; Statamic keys by handle, so a rename orphans the data. Labels and handles are registry metadata and may change freely.

**Media and entries are referenced by ID, never by path.** Statamic rewrites every entry, term, global and user on a single asset rename. An ID reference makes that a no-op.

**Registry edits never rewrite data.** Adding a field is a no-op — defaults resolve at read time. `required` applies at the next save, never retroactively. Removing a field soft-removes the registry entry and leaves orphan keys in place; a separate, confirmed, batched `cms:fields:prune` is the only thing that ever deletes. Type changes are refused in place: add new, migrate, soft-remove old.

**Phase 1 field types:** text, textarea, number, boolean, datetime, select, media, entry, url. **Phase 2 adds:** group, repeater (one level), multi_select, rich_text, link, json. There is deliberately **no blocks/replicator field type** — it would duplicate the card system and leave two renderers, two text extractors and two reference extractors doing one job.

**Search.** `search_tsv` on `contents`, maintained by a trigger extracting plain text from the published document, GIN indexed. Handles admin and site search at this scale without a second datastore. Plain-text extraction is therefore a first-class function of the card registry, not an afterthought.

**Revisions.** Store full documents, not deltas — storage is cheap, correctness is not. Ship a retention policy from day one: keep all for 30 days, thin to one per day, keep published revisions permanently. WordPress's revision bloat was real, but its actual error was storing them in `wp_posts` beside live content.

### 5.4 Editor

**Prose-first with embedded cards.** The body is one continuous Tiptap document. Paragraphs, headings, lists and quotes are ordinary Tiptap nodes, so text flows and selects across them normally. Non-text content — image, gallery, embed, table, button, callout, code — is a card node with a React NodeView.

Not a list of blocks. Gutenberg's writing-flow problem is a direct consequence of making every paragraph a block: typing means traversing dozens of boundaries, each with focus state and a toolbar. For a publisher, writing feel is the product.

Consequences of this choice:

- Cross-node text selection works natively.
- Undo is Tiptap's single history stack. No document-level history manager needed.
- Complex cards cost more — React NodeViews rather than standalone components. Accepted.
- Heavy multi-column page layout is awkward. Handled by **page templates** with defined regions, not a freeform builder.

**Storage is Tiptap JSON, never HTML.** Structured, diffable, sanitisation enforced by schema rather than filtering. HTML in the database is the road to shortcodes.

**On terminology.** Tiptap is the editor, and this document calls it Tiptap throughout. Tiptap is built on ProseMirror, and the JSON it produces is ProseMirror's document format — that is the only reason the name ever matters here. It means the stored documents outlive the editor: if Tiptap were ever replaced, any ProseMirror-based editor reads the same data, so an editor swap costs a frontend, not a content migration. Filament 5 made the same choice for its rich editor, including server rendering through `ueberdosis/tiptap-php` (see `docs/references.md`).

**Editor chrome:** two inspector tabs — Document and AI. A card's settings belong on the card, not in a permanent sidebar tab. No outline pane in v1.

### 5.5 The card manifest

A card type is declared once and generates four things:

```
cards/gallery.json  ->  React NodeView            (editor)
                    ->  Blade/PHP renderer        (public)
                    ->  plain-text extractor      (search_tsv)
                    ->  reference extractor       (media_usages)
```

If the React registry and the PHP renderer are maintained separately, preview and published output drift and readers find out first. Server-side rendering starts from `ueberdosis/tiptap-php`, extended rather than rewritten.

Nodes carry **stable IDs**, because AI proposes targeted changes to specific nodes, revision diffing compares node trees, and the projections key off node IDs.

### 5.6 Caching

**Cache policy is core. Cache storage is a driver.**

> **Designed 2026-09-22 (study R, `docs/studies/page-cache.md`, I-262 to I-266).** Anonymous pages only, with no session on the public side and personal parts from one uncached endpoint; dependencies recorded while a page renders and tags emitted from before and after each change, after the outermost commit; a guard against re-caching a page rendered before a purge; Redis tag sets or files with a PostgreSQL tag table; hard purges for changed articles and removals, stale listings, CDN purged only after Teme regenerates. The static-file driver and Octane are decided in study R §4.

Core owns the full-page cache and its tag-based invalidation, driven by the publish pipeline; object/query caching; HTTP cache headers and surrogate keys; and a public purge API.

Drivers are pluggable: file, Redis, Varnish, Cloudflare, Fastly, Bunny.

Invalidation is a graph problem, and only the component owning the content model can build the graph. Publishing content X invalidates X's URL, its term archives, the author archive, the homepage, the feed and the sitemap — known exactly, never guessed. This is unfixable from outside core, which is why it cannot be a plugin.

Serving the public read path from cache is also the plugin-crash insulation: a fatal in an extension cannot take down pages that never execute it.

**The layers**, from the outside in:

| Layer | Holds | Handled by |
|---|---|---|
| CDN, optional | Pages at the network edge | A driver — Cloudflare, Bunny, Fastly — purged by the same tags |
| Page cache | Finished HTML for anonymous visitors | Core. Default driver Redis-compatible; a static-file driver served directly by the web server, and Laravel Octane, are decided in slice 1.7 |
| Object cache | Expensive computed values — menus, settings, term counts | Core, and **targeted**. Not WordPress's cache-every-query object cache, which exists because WordPress re-reads the same options on every request |
| OPcache; Laravel's config, route and view caches | Compiled PHP and framework metadata | Server configuration and the deploy step |
| Fingerprinted assets | CSS and JavaScript | Vite; cached permanently by browsers |

Logged-in visitors bypass the page cache, and a commenter's cookie must never cause a personalised page to be cached for everyone. Stampedes — many requests rebuilding the same expired page at once — are prevented with a lock and by serving the stale copy while one request rebuilds it.

**Redis-compatible, not Memcached.** The page cache's tag index needs sets, which Memcached lacks; and one Redis-compatible server also carries queues, atomic locks, sessions, rate limiting and Horizon. Redis or Valkey — the open-source fork created after Redis changed its licence. Supporting Memcached as well would double the testing for nothing, the same reasoning as PostgreSQL-only.

**Teme runs on PostgreSQL alone.** A small site uses the database queue, the database cache and the file page cache, with nothing else to install. A large one adds a Redis-compatible server and gets faster. Redis is recommended, never required — that is how one CMS serves a personal blog and a million-article publication.

### 5.7 Background work

Laravel queue with the `database` driver initially — zero extra dependencies, one Postgres. Redis when throughput demands it.

- Migrations run behind a `pg_advisory_lock`, or as a one-shot step. Replicas racing `migrate` corrupts schema.
- **The updater must run `queue:restart`.** Workers hold bootstrapped code in memory; skipping this runs old code against a new schema.
- Job batches supply progress and partial-failure reporting for bulk publish and import.

### 5.8 AI

AI calls the same Operations as everything else, through the same policies. It has no privileged path.

Phase 1 surface is deliberately narrow — selection rewrite, alt text, metadata suggestions — because its purpose is to prove the operations layer, not to ship an agent.

Behaviour contract (carried from `CMS-INTERFACE-DESIGN.md` §7): scope shown before execution; context limited to what the current user can access; suggestions produce reviewable changes, never a hidden replacement document; applying writes a draft and an attributed revision; staleness checked before apply; provider failure leaves the draft intact; AI unavailability never blocks manual editing or publishing.

Provider access sits behind our own adapter. The Laravel AI SDK is a candidate implementation, not an interface.

> **Amended 2026-09-22 (study U).** The adapter, the three tasks with versioned prompts, `ApplyAiSuggestion` (a staleness check, then a working revision with `origin = ai`), the `ai_activity` record, keys as `secret` settings and the limits are designed in `docs/studies/ai.md`. The library behind the adapter is `laravel/ai`, stateless API only (I-311).

### 5.9 Themes, navigation and Appearance

**There is no Appearance section in phase 1.** WordPress's Appearance is not a design menu — it is a join-table editor binding theme-declared slots to theme-independent user content. WordPress has already dismantled it: only Themes is unconditional, Widgets is appended by a separate function, and the Theme File Editor moves to Tools on block themes. No other reference system has the concept; October and Winter file Themes under Settings.

- **Navigation is a top-level item under Content, permanently.** A menu is a tree of pointers to content, terms and URLs. Only the *binding* to a theme slot is a design concern.
- **The theme declares named slots** in its manifest. The slot-to-navigation assignment is a theme setting. **An unbound slot is a loud error**, never silence.
- **Theme settings live in `Settings › Design`**, in the one settings registry, scoped `theme.<code>.*`, with a Preview button.
- **Regions replace Widgets** as ordinary content rows, inheriting revisions and cache invalidation rather than being option blobs.
- Appearance returns in **phase 4** holding exactly Themes, scoped theme settings, and a read-only template resolution inspector — when a second theme makes it earn its place.

**Theme contract:** `themes/<code>/` containing `teme-theme.json` (renamed from `theme.json`, I-243), `views/{layouts,templates,partials,cards}`, `composers/`, and `public/` as the only served path. Distributed as a Composer package of type `teme-theme`. **Never written by the web process** — see §3, code is read-only at runtime. *Amended 2026-09-21 (I-211):* themes and plugins may be installed from the admin, but the admin only records the request; a separate installer running as the code's owner performs it (`docs/architecture/plugins.md`).

Data reaches templates through **view composers plus one typed view model per route**. Templates never receive Eloquent models.

**Template resolution is a declared first-match list, capped at three candidates per kind, with no parent/child cascade** and `templates.index` as the floor. Debuggability is contractual: an `X-Teme-Template` response header, the full candidate list in the debug panel, and an artisan command. This is precisely where WordPress fails — `template_include` reports the winner and never the losers.

### 5.10 WordPress import

**The primary import path is a companion WordPress plugin exposing a REST API on the source site.** We ship a small GPL plugin the user installs on the WordPress site being migrated; our importer authenticates with an Application Password and pulls content over HTTPS. `Reference/emdash-exporter` is a working example of the pattern.

This is better than reading the database directly, for one decisive reason: **code running inside WordPress can call `get_permalink()` itself.** That returns the true, live URL for every item with all plugin filters applied — so permalink derivation, the highest-stakes part of the migration, stops being a reimplementation we have to validate and becomes a value we are simply handed. The same applies to SEO metadata (ask Yoast or Rank Math through their own APIs rather than guessing at meta keys), ACF field types, and taxonomy hierarchy.

It also removes the three per-install unknowns entirely: the table prefix becomes irrelevant, and options and the active plugin list are endpoints.

**Fetch both raw and rendered content for every item.** Raw `post_content` is what we convert from; the output of `apply_filters('the_content', …)` on the source site is the fidelity oracle — what readers actually see today. Where our converter understands the raw form we use it; where it does not, the rendered HTML is the fallback, and the difference between the two is the import report.

Minimum endpoint set: probe (unauthenticated capability check), analyze (counts, post types, taxonomies, active plugins, detected SEO and field plugins), content, media, taxonomies, comments, options, redirects. All paginated and resumable — the source host has PHP timeouts and memory limits we do not control.

**Direct database read is the fallback**, for sites where a plugin cannot be installed, and optionally for bulk content on very large libraries where HTTP round trips dominate. **The companion is the full path; an uploaded export file is the second**, for a site's content where the plugin can't be installed, or the site is gone. The export file (WXR, from WordPress's Tools › Export) brings posts, pages and custom types with their categories, tags and authors, comments, menus, featured images and each item's own address. It can't bring what `export_wp()` leaves out, and the file's plan says so: the `options` table (site settings, widgets, where each menu sits, the category and tag bases), passwords and roles, redirects kept by plugins, spam comments, `comment_agent`, and revisions. The media files themselves aren't in it: they are downloaded from the old site while it answers, or kept there as links. How the file is read is in `docs/architecture/import.md`, *A file as the source*.

Three traps that must be handled explicitly:

- **Permalink dates use local `post_date`, not GMT.** Normalising to UTC before deriving URLs shifts posts across day boundaries and 404s them.
- **`_wp_old_slug` postmeta is already the site's redirect table.** WordPress has been recording slug changes for years. Import it.
- **Attachment pages become redirects** (I-244). WordPress gives every upload its own public page (`/post-slug/image-name/`, `?attachment_id=N`); Teme has none, because media are not content. The companion plugin reports each attachment's permalink and parent, and the importer writes a permanent redirect for each: to the parent article, or to the image's largest variant when it had no parent. This is what WordPress SEO plugins do by hand, done once at migration.
- **Stored `post_content` is not renderable HTML.** It passes through `do_blocks` → `wpautop` → `do_shortcode` on output; classic content has no paragraph tags at all. And `get_shortcode_regex()` builds its pattern from *registered* shortcodes, so it matches nothing once the plugins are gone — we need our own generic scanner.

Study D's findings still hold and remain the working reference for conversion: permalink dates use local `post_date`, `_wp_old_slug` is the existing redirect table, and `get_shortcode_regex()` matches nothing once the plugins are gone. What the companion plugin removes is the need to *derive* URLs, not the need to convert content correctly.

**Internal links are converted to ID references on import.** WordPress content holds absolute URLs to its own site. The importer resolves each one to the imported content or media it points at and stores the ID, so the migrated site never carries its old domain inside its content (§5.11). A link the importer cannot resolve is kept as a plain URL and listed in the import report.

The companion plugin is itself a phase 1 deliverable, in its own repository, GPL-licensed.

> **Amended 2026-09-22 (study V).** The run model (an import map keyed by WordPress ID, resuming by re-running, per-item commits, an import mode that holds hooks until the end), the second pass for references, the plugin's endpoints, block and classic conversion, the fidelity check, media, address verification before cut-over, the catch-up run and the import report are designed in `docs/studies/import.md`. The companion plugin's first part, a read-only site report, was built and run on five sites on 2026-09-22; its second part, the export API (version 0.2.0), was built the same day.

### 5.11 Backup, restore and moving servers

Three different jobs are all commonly called "backup". They have different formats because they have different purposes:

| Job | Format | Restores into | For |
|---|---|---|---|
| **Backup** | Database dump, media, and a manifest — encrypted | The same or a newer Teme version | Disaster recovery, and moving servers |
| **Export** | NDJSON with a manifest — a public, documented format | Any version, any system | Portability, data ownership, large version jumps |
| **Deployment** | The code — git and `composer.lock` | — | Teme itself, themes, extensions |

**WordPress export (WXR) — an exit, not a format.** Alongside the NDJSON export, Teme exports WordPress's WXR format. Not because WXR is good — study D documents how much it omits — but because WordPress and many other platforms can import it, which makes it the most widely readable way to *leave* Teme. "You can always leave" is part of what makes it safe to arrive. It is generated from the NDJSON export by a format adapter: cards render to HTML through the same server renderer the public site uses, custom fields become post meta, and anything with no WXR equivalent is listed in an export report. Whether cards export as plain HTML or as Gutenberg block markup is decided when it is built. WXR is a compatibility output — never the backup, never the canonical export. Phase 6.

> **Amended 2026-11-01 (slice 1.12; I-354, I-359, I-364, I-2404, I-2405).** The engine is **restic**, a pinned program Teme drives through its JSON output (I-354). It speaks S3 and SFTP itself, so places to keep backups don't go through Laravel's filesystem layer, and `spatie/laravel-backup` isn't used. `BackupSite` is an engine over two short Operations, `StartBackupRun` and `EndBackupRun`, because a backup outlasts any transaction. **Secrets:** the backup carries the site's `APP_KEY` inside the encrypted repository, and a restore writes every value Teme encrypts again under the new server's key (`ReencryptSecrets`, I-359). **The image variants are backed up** beside the originals, so a restore remakes none (I-2404). **No code travels in a backup, a local theme's folder included:** the manifest names the active theme, its version and every package's version, and a restore onto a server without them refuses before writing anything (I-2405); the *code recipe* paragraph below no longer holds. **`Tools › Backups` isn't read-only:** it sets backups up, keeps the places and the recovery keys, says when backups run and what's kept, and offers Back up now (I-364); restoring stays at the command line in phase 1. The design as built is `docs/architecture/backups.md`.

**Backup policy is core; the destination is a driver** — the same split as caching (§5.6). Core owns everything that makes a backup trustworthy: capturing the database and the media it references *together*, so the two are consistent; encrypting before anything leaves the server; retention; scheduling on the queue; failure alerts; verification; and restore. A backup is a guarantee, and a guarantee cannot live in an optional plugin. WordPress leaves backup to plugins, and restore is where they fail.

**Core destinations:** local disk, S3-compatible storage — one driver covers AWS S3, Cloudflare R2, Backblaze B2, DigitalOcean Spaces, Wasabi and MinIO — and SFTP, all through Laravel's filesystem layer. Destinations that need OAuth flows and vendor SDKs — Google Drive, Dropbox, OneDrive — are extension drivers once the driver registry is public (phase 5).

**Engine.** `spatie/laravel-backup` is the candidate for capture, retention and notifications: conventional, well maintained, familiar to any Laravel developer. **It does not restore** — restore is ours to build, with `wnx/laravel-backup-restore` as a reference. Whatever the engine, it sits behind our own Operations (`BackupSite`, `RestoreSite`) and our own manifest, so the destination-driver interface extensions will implement is Teme's, not a vendor's. Decided when slice 1.12 is planned.

**Encryption.** Backups hold user accounts, password hashes, and commenters' emails and IP addresses. They are encrypted before leaving the server, with a key generated at installation and shown once as a **recovery key** for the operator to store offline. A backup whose key exists only on the server that died is not a backup.

**Secrets.** `.env` is never included in plaintext. Values Teme encrypts in the database, such as AI provider keys, depend on `APP_KEY`; the backup carries them re-encrypted under the backup key, so a restore onto a new server with a new `APP_KEY` can still read them.

**Verification.** `teme:backup:verify` restores the latest backup into a scratch database on a schedule and checks row counts and checksums against the manifest. An unverified backup is a hope.

**Moving to a new server is a restore, not a separate feature:**

1. Deploy Teme on the new server, at the same or a newer version. Code, themes and extensions arrive with the deployment.
2. Run `php artisan teme:backup:restore <backup>`. In phase 1 restore is a CLI operation; restoring through the browser arrives in phase 6, under the controls below.
3. Preflight reads the manifest and refuses — before writing anything — if the target Teme is older than the source, or if the active theme or any extension is missing or at an incompatible version. It names the exact packages to install.
4. Migrations run forward to the target version.

**Restoring through the browser.**

> **Amended 2026-09-18.** The first draft said restore must be CLI-only, because "a web endpoint that accepts an archive and writes files is the exact vulnerability §3 forbids". That overstated it. The danger is narrower — **writing executable code** through a web request, and **executing an uploaded SQL dump** — and both can be designed out. A browser restore of *data* is safe under these controls:

- **Data only.** Rows, media and settings. Never code: theme and extension code still arrives by deployment, and preflight still refuses a backup whose code is missing.
- **Never executes an uploaded dump.** Replaying an uploaded `pg_dump` runs arbitrary SQL, and with an over-privileged database role, `COPY … FROM PROGRAM` turns that into a shell on the server. The browser path restores from the structured export format, parsed and inserted by Teme's own code. Raw dumps are restored only by an operator, on the CLI.
- **Preferably no upload at all.** The admin lists backups at the destinations already configured and restores from one of those. An uploaded archive is accepted only if it decrypts and authenticates under the site's recovery key — so it can only be a backup Teme itself made, never an arbitrary file.
- **Traversal-safe extraction.** Paths are normalised; `..`, absolute paths and symlinks are rejected; media lands only in non-executable storage.
- **Administrator only, with password re-confirmation.** It runs as a queued job behind maintenance mode, with progress reporting and the same preflight as the CLI.
- **On a fresh install**, restore through the phase 6 installer is guarded by a one-time install token shown on the server, so a not-yet-configured site cannot be claimed by whoever reaches its URL first.

The database role Teme runs as is never a superuser, so even a mistake in this path cannot reach `COPY … PROGRAM`.

**Changing domain is one configuration change, not a search-and-replace**, because content never stores the site's own absolute URLs (§3). The site address changes and every link and image follows. *(Amended 2026-09-18: first written as "a setting". The site address is deploy configuration, `APP_URL`, because a wrong value entered in an admin form would lock everyone out — I-111.)*

**How themes and designs move.** A theme has two halves, and they travel differently:

| Half | What it is | Moves by |
|---|---|---|
| **Code** | Templates, view composers, assets — `themes/<code>/` or a `teme-theme` package | The deployment: git and `composer.lock` |
| **State** | `Settings › Design` values, navigation, regions, the logo and other media | The backup, as ordinary data |

The manifest records the active theme and its version, so a restore can say *this backup needs `acme/teme-theme-editorial` 2.3* instead of restoring into a broken site. Because code never travels inside a restore, a restore cannot carry executable files onto a server. WordPress migrations copy `wp-content` wholesale, which is how malware moves between hosts.

A site whose theme is local and unpublished — a directory under `themes/` rather than a package — gets that directory and `composer.json` / `composer.lock` included in the backup as a *code recipe*. The operator restores it at deploy time, through the CLI, never through a web request.

**Placement.** Slice 1.12: the engine, encryption, the three core destinations, retention, scheduled verification, CLI restore with preflight, NDJSON export, and a read-only `Tools › Backups` status panel showing the last success, the next run and destination health. Phase 5: extension destination drivers. Phase 6: restoring through the browser — from the admin and from the installer — under the controls above, and the WXR export.

### 5.12 Scale

**Design target: 1,000,000 articles and 1,000,000 media items**, with every screen and every public page staying fast. At that size, what fails is not PostgreSQL but query *shapes* that are harmless at 10,000 rows. These rules hold from slice 1.3 onward:

- **Keyset pagination, never `OFFSET`.** `OFFSET 500000` reads and discards half a million rows. "The 50 after this one" costs the same on page 1 and page 20,000. *Amended 2026-09-27 (I-459):* one exception, the **public** archives, which keep WordPress-style `/page/N/` addresses (imported sites keep their indexed URLs) with an OFFSET capped by rows skipped (#37); every admin list stays keyset.
- **No exact counts on large tables.** PostgreSQL's `COUNT(*)` reads the whole table. Status counts come from **maintained counters**, updated by the Operations that change them. That is reliable only because of the Operation rule (§5.2): no write reaches content without passing through an Operation, so nothing can skip the counter. Where a count is only indicative, the planner's estimate is used.
- **Indexes are designed with the queries that use them**, in the slice that introduces the query — not guessed in advance.
- **No unbounded queries.** Every list is paginated; every batch job — reindexing, projection rebuilds, imports, exports — works in chunks and can resume.
- **Search stays in PostgreSQL** (`tsvector` with GIN). If a future scale outgrows it, search moves behind the same interface; nothing calls it directly.
- **Sitemaps are split** — search engines accept 50,000 URLs per file — and generated incrementally, never per request.

> **Amended 2026-09-22 (study T, I-296).** Sitemap pages hold 2,000 URLs each, ordered by ID, and are served through the page cache: a change marks them stale and the next request rebuilds one small page. Nothing rebuilds the whole sitemap. The head, redirects, the 404 log, feeds and code slots are designed in `docs/studies/seo.md`.
- **Cache invalidation is by tag.** Publishing one post shifts every page of its category archive; those pages are purged by one tag, never by listing their URLs (§5.6).
- **Revisions are bounded** by the retention policy (§5.3). A million articles with twenty revisions each is twenty million documents otherwise.
- **Media lives on object storage** — S3-compatible — not the web server's disk, once a site is past a small size.

**Proving it.** The everyday test suite runs at a small size so it stays fast. A separate **scale benchmark** seeds a million articles and a million media items, checks response times against budgets, and inspects query plans for full-table scans on large tables. It is built in slice 1.3 and runs before every tag.

---

## 6. Phase 1 scope

**Done when:** Teme can migrate a WordPress publication and serve it, proven end to end by a full test migration. *(Amended 2026-09-18 from "one Krafty Sprouts publication is fully migrated and serving live traffic" — see §4.)*

**In** — the complete list; the roadmap assigns each item to a slice:

- Admin shell on Laravel's React starter kit, under `/admin`: invitation-only sign-in with study G's security baseline, users and roles, the settings registry, and a shared core of form field types (studies F, G, H)
- Posts, Pages, categories, tags
- Internal field registry, for built-in fields such as SEO title, featured image and excerpt
- Media library with derivative generation via queue
- Editor: prose + cards, autosave, revisions, preview, scheduling
- Publishing: working vs published revisions, conflict detection
- Roles: Administrator, Editor, Author, Contributor
- Blade theme layer, view composers, one real theme, page templates
- Theme settings in `Settings › Design`, plus a Preview button
- Navigation, as content (§5.9), and theme regions
- Comments: storage, WordPress import, public display, submission with rate limiting and honeypot, moderation queue (approve / spam / trash)
- Full-page cache with tag invalidation
- SEO core: metadata, canonicals, sitemaps, **redirects**, managed script slots
- WordPress companion plugin (its own repository) and importer: URL preservation, redirect generation, internal links converted to IDs, media, authors, taxonomy, comments, shortcode fallback, resumable, no duplicates on retry (§5.10)
- Export: NDJSON archive with manifest
- Backup and restore per §5.11: database and media captured together, encrypted with an offline recovery key, offsite storage, scheduled verification, CLI restore with preflight
- Queue workers, Docker compose, bare-VPS documentation
- Narrow AI: selection rewrite, alt text, metadata suggestions

**Out:** custom content types, plugin API, browser installer, update channel, multilingual, multisite, reusable patterns, marketplace. Within comments, deferred: reply notifications to commenters (moderator and author emails moved into 1.8, I-271), thread subscriptions, third-party spam services (the spam check is an interface with a naive implementation).

**Assumption:** Posts, Pages, categories and tags cover the first publication migrated. If it needs a custom content type on day one, the admin builder for custom types (phase 2) moves into phase 1. **Confirmed 2026-09-18:** the test site used for the first migration has no custom post types.

**Phase 1 is larger than one implementation plan.** It decomposes into roughly five, each leaving a working product: (1) foundation — app skeleton, schema, Operation layer, architecture tests; (2) content and editor; (3) public rendering, theme layer and cache; (4) SEO, redirects and script slots; (5) WordPress import, export, backup and restore. AI attaches to (2). Each gets its own plan, written when the previous one lands.

> **Amended 2026-09-18.** Phase 1 is now **twelve slices**, not five. Each of the five above was too large for one plan, and comments, navigation and AI joined phase 1 after that estimate was made. The field registry moves to phase 1 as an internal registry (slice 1.3); only the admin builder for user-defined types stays in phase 2. The slice list, dependencies and status are tracked in `docs/roadmap.md`, which is now the source of truth for sequencing — this section keeps the reasoning, the roadmap keeps the state.

> **Amended again 2026-09-18.** Thirteen slices. Backup and restore grew into a slice of its own (§5.11), separate from deployment and cutover.
>
> **Corrected 2026-09-18.** The *In* list, the assumption above and §9 had not been updated as §5.9–5.11 and the roadmap changed, and contradicted them. Brought into line; no scope added.

---

## 7. Phase 1 acceptance criteria

Adapted from `CMS-INTERFACE-DESIGN.md` §17. These become the test suite.

1. A WordPress user finds Posts, writes an article, adds an image, previews and publishes it without instructions.
2. Editing a published article autosaves without changing the live page until an authorised update.
3. A concurrent save is detected and never silently overwrites.
4. A failed AI job erases no text and blocks no publication.
5. AI proposes a rewrite; the author compares, applies to a draft, and restores the prior revision.
6. A WordPress import preserves every URL or creates a redirect, and reports unsupported content accurately.
7. A WordPress import carries comments with threading, status and authorship intact, and they render on the migrated article.
8. Publishing invalidates exactly the affected cached pages.
9. A full backup restores to a working site on a clean machine.
10. Keyboard and mobile users complete the writing and publishing flows.
11. **At 1,000,000 articles and 1,000,000 media items**, the scale benchmark keeps list, search, filter and public page responses within their budgets, and no query's cost grows with total content count (§5.12). *(Amended 2026-09-18. The original 10,000 / 20,000 came from the interface document and was never set deliberately; a same-day amendment sizing it to one particular site was withdrawn. The general target is a million.)*
12. A backup restores onto a new server **under a different domain** with no search-and-replace, and every internal link and image resolves.
13. A restore onto a server missing the backup's active theme, or running an older Teme, is refused before any data is written, naming what is missing.
14. A backup cannot be read without its recovery key, and a restore on a new server with a new `APP_KEY` still reads the secrets Teme encrypts in the database.

---

## 8. Non-goals

Not building: WordPress plugin or theme compatibility; shared-hosting support; a freeform visual page builder; multisite; ecommerce; a marketplace; collaborative editing in v1.

---

## 9. Later phases

- **2 — Content model.** Custom content types and the admin builder over the phase 1 field registry, the phase 2 field types, custom taxonomies. Proven by a test migration of a site that uses them.
- **3 — Public surfaces.** Delivery API (read, cached), management API (scoped tokens), MCP server, wider AI operations.
- **4 — Theme contract.** Theme manifest, design settings, template hierarchy as public API, a second theme proving the abstraction.
- **5 — Plugin API.** Versioned contracts, safe mode, fail-soft hook dispatch, activation preflight, lifecycle. **Ship thirty well-chosen extension points, not three thousand.** Hook counts across the references: WordPress 2,222, LaraDashboard 632, October ~132, Winter ~86, emdash 24, Payload 18. The small ones are not less extensible — **most extension is contribution to a registry, not interception of a hook**. Every proposed hook must first be tested as a registry entry. Five dispatch semantics are named up front: notify, transform, gate, resolve, collect. Plugin contracts are composition, never inheritance — Winter's `PluginBase extends ServiceProvider` puts the whole Laravel API into its public contract permanently. Safe mode is concretely Winter's `$noInit`: skip all plugin registration on installer, updater and admin-login routes. Lifecycle follows October's `version.yaml` plus an append-only history table, so uninstall reverses what was *actually applied*. Backup destination drivers become extensible here (§5.11). *Added 2026-09-21:* the full design — manifest, installer, capabilities, the surface ladder, data ownership, provider slots — is `docs/architecture/plugins.md` (study O, I-207 to I-214).
- **6 — Distribution.** Browser installer with an install token, browser restore of data (§5.11), signed release artifacts, update channel with pre-update snapshot and expand/contract migrations, documentation site, WordPress export (WXR). The plugin repository is the installer of I-211 with a source and a signed index that pins each release's checksum and provenance.

Extension surfaces, when opened: hooks; content types and fields; taxonomies; cards; admin screens and settings as schema; themes; routes; jobs and scheduled tasks; storage/cache/queue drivers; CLI commands; and **AI tools**. A plugin registering an Operation automatically receives a permission check, an API endpoint and an AI tool — extensibility and AI capability are one registry.

---

## 10. Development approach

Solo, directing AI coding agents. Work proceeds in complete vertical slices that leave a working product.

Required from the start:

- A project `CLAUDE.md` carrying §3 (what we reject) and §5.2 (the Operation rule). This is higher leverage than any tool.
- **Architecture tests** enforcing the Operation rule and module boundaries. Executable constraints beat documented ones.
- Static analysis (PHPStan) and integration tests for publishing, authorisation, revisions and migrations.
- **Laravel Boost** (dev dependency) for version-matched documentation and schema introspection. Distinct from the Laravel AI SDK, which is runtime.

> **Amended 2026-09-18.** Boost generates `AGENTS.md` and `CLAUDE.md` itself, so the "project `CLAUDE.md`" above is no longer hand-written. Teme's rules live in `.ai/guidelines/teme.md` (always loaded) and `.ai/rules/` (loaded by path), and Boost composes them with Laravel's version-matched guidelines into the generated files. The generated files are committed, against Boost's own suggestion, because a builder in a fresh worktree gets no gitignored files and would otherwise start without the project's rules.
- Reproducible setup, deployment, backup and restore in version control.

---

## 11. Relationship to `CMS-INTERFACE-DESIGN.md`

That document remains the interface reference. Changed by this spec:

- §1, §15 — Laravel only; the Node.js option is removed
- §6 — prose + cards; no outline pane; two inspector tabs
- §10 — no Customizer at all. Theme settings are ordinary settings; Preview opens the site with draft settings applied
- §13 — WP import is phase 1, browser installer is phase 6
- §16 — replaced by the six phases in §4
- §18 — decisions resolved in §2

Deferred but carried: §9 content types, §11 plugins and hooks. Carried unchanged: §2 visual direction, §5 content lists, §8 media, §12 settings and users, §14 responsive and accessibility, §17 acceptance scenarios.

**Resolved since that document:** comments are core and in phase 1 (§2). There is no Customizer (§3). There is no Appearance section in phase 1 and Navigation is content (§5.9). §11's packaged plugin upload is withdrawn — code is read-only at runtime (§3), so extensions arrive at deploy time and the admin never writes executable code. *Amended 2026-09-21 (I-211):* the admin may now request an install, which a separate installer carries out; the web process still writes no code.

---

## 12. Reference studies

Four studies read the reference codebases in `Reference/` and produced the decisions folded in above. They are normative companions to this spec — read the relevant one before implementing that area.

| Study | Covers | File |
|---|---|---|
| A | Content and field modelling; field types; what happens when a field changes | `docs/studies/content-and-fields.md` |
| B | Theme contract, template resolution, navigation, whether Appearance exists | `docs/studies/theme-layer-and-appearance.md` |
| C | Hook dispatcher, the minimum hook set, plugin lifecycle | `docs/studies/hooks-and-extensions.md` |
| O | Plugins: manifest, installing from the admin, trust, interface code, surfaces, data, dependencies, stability (2026-09-21) | `docs/studies/extensions.md`, normative in `docs/architecture/plugins.md` |
| Q | The default theme for a publication, what a theme gets from core (Core Web Vitals), and what Teme takes from Sage (2026-09-22) | `docs/studies/default-theme.md` |
| R | The page cache: storage, exact invalidation, personalisation, stampedes, CDN; MilliCache assessed (2026-09-22) | `docs/studies/page-cache.md` |
| S | Reader comments: threads, counts, submission and trust, cached pages, pings, removal; WordPress, Drupal, Ghost and Emdash (2026-09-22) | `docs/studies/comments.md` |
| T | Search, social, sitemaps, redirects, the 404 log, feeds, code slots and the Yoast/Rank Math import; Yoast 28.5 and Rank Math 1.0.278 read for ideas only (GPL) (2026-09-22) | `docs/studies/seo.md` |
| U | AI in the editor: adapter, providers and keys, tasks and prompts, suggestion to revision, the activity record, images, limits; WordPress 7.0, Emdash, Laradashboard, Payload; laravel/ai and Prism checked outside (2026-09-22) | `docs/studies/ai.md` |
| V | Importing a WordPress site: runs and the map, conversion, fidelity, media, addresses, users, comments, cut-over and the report; Emdash, Ghost, Drupal migrate, Filament, Payload, Wagtail, and five real site reports (2026-09-22) | `docs/studies/import.md` |
| W | Backup and restore: what a backup holds, database and media in step, keys and the recovery key, destinations, verification, restore, the NDJSON export; Craft, Ghost, Laradashboard, BackWPup, restic and PostgreSQL 18 (2026-09-22) | `docs/studies/backup.md` |
| X | Deployment and the full test migration: the image, one image in several roles, teme:deploy, health, the database role, proxies and HSTS, media storage, updates, platform notes and the runbook; Ghost, Payload, Emdash, Craft, Wagtail, Laravel 13, serversideup, PostgreSQL 18, Dokploy and Coolify (2026-09-22) | `docs/studies/deployment.md` |
| Y | Content types, taxonomies and the phase 1 seams: who declares a type, portability, deletion, per-type permissions, custom taxonomies, singles, filterable fields, the CPT and ACF import, and where phase 1 hard-codes post, page, category and tag; Craft, Statamic, Tailor, WordPress, Drupal, Wagtail, Payload, Emdash and Twill (2026-09-22) | `docs/studies/content-types.md` |
| P | The rest of the admin (plugin screens, tools, dashboard, logs, health, account, people, comments, terms, settings), how the admin grows, and the theme–plugin boundary (2026-09-21) | `docs/studies/admin-growth.md` |
| D | WordPress schema mapping, the cannot-map list, URL and redirect strategy | `docs/studies/wordpress-data-model.md` |

Two corrections to note: `Reference/cms-5.x` is **Craft CMS 5**, not Statamic 5 — and it is the strongest single piece of evidence for our storage model, having migrated from one-column-per-field to a single JSON column. And LaraDashboard, which this spec originally treated as the model for WordPress-style hooks in Laravel, is better read as a cautionary example; only its `BackedEnum` hook naming is adopted.

---

## 13. Documentation and process

Full policy in `docs/architecture/documentation.md`. The decisions that constrain implementation:

**Reference is generated; guides are written.** Hooks, field types, card types, settings keys, permissions, CLI commands and API endpoints are generated from their registries. Hand-written reference drifts within one release — WordPress's hook documentation is generated from source, and it is the most reliable part of its handbook for exactly that reason.

**This constrains phase 1.** Every registry entry carries its documentation as data from the first registration: a description, an example where the shape is non-obvious, and a `since` version on the published surface. Retrofitting doc metadata means revisiting every call site; requiring it at registration costs one line.

**The public surface is deliberately narrow** — `teme/contracts`, documented hooks, the HTTP API, the theme contract, and the export format. Everything else, including the schema itself, is internal and may change in any release. WordPress's inability to modernise comes from having implicitly promised that everything is public forever.

**Deprecation:** announced in a minor with the replacement and removal version named, kept working for at least one full minor, removed only in a major. A deprecation without a removal version is not a deprecation.

**Changelog is written by hand, not generated from commits.** Commit messages are written for developers reading a diff; a changelog is written for someone deciding whether to upgrade. Generating one from the other produces something useful to neither.

**Settled decisions change by dated amendment, never silent edit.** The reasoning that produced a decision is the most valuable thing in this document; deleting it means relitigating the same question in a year with none of the evidence.

---

## 14. Open decisions

**Browser-installable extensions.** Studies B and C independently concluded that themes and plugins should be Composer packages installed at deploy time rather than uploaded through the admin. That is the security-correct answer and it matches Statamic, but it is a genuine departure from the WordPress model and it decides what the phase 4 and 5 admin screens are. Needs a product call before phase 4.

*Corrected 2026-09-21 (study O).* This paragraph first said the answer "matches Craft and Statamic". Reading the source showed that only Statamic is deploy-time only — it removed control-panel installs in 4.0. **Craft 5 installs plugins from its control panel by default, in production too**: the web request runs Composer as a child process and writes `vendor/`, `composer.json` and `composer.lock` (`src/services/Composer.php:114-209`); `allowAdminChanges` and `allowUpdates` both default to true, and deploy-time installation is only Craft's *recommended* setup. Installation from the admin, and a repository later, was then decided (2026-09-21, I-036).

**Decided 2026-09-21 (I-036, I-211).** Install from the admin, without the web process writing code: the admin records an audited install request, and a separate installer running as the code's owner stages, verifies, validates and swaps the release atomically, keeping the previous one for rollback. Where no installer is configured, the screen shows the commands instead. The name is **plugins** (I-208). See `docs/architecture/plugins.md`.
