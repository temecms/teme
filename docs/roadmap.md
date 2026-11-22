# Teme roadmap

The single tracker for phases and slices. Every idea starts in the ideas register (kept outside this repository) and reaches this file only through triage. Task-level progress lives in the checkboxes of each slice's plan; this file tracks everything above that.

**Rule for adding scope:** anything proposed for a phase must name which part of that phase's *Done when* requires it. If nothing does, it is recorded in `docs/ideas.md` as *Parked* until something does. Being a good idea is not enough — phase 1 went from five slices to thirteen in a day, each addition justified on its own and none checked against the sum.

**Rule:** this file changes when a slice changes status or scope. Scope changes are recorded in the [change log](#change-log) with a date and a reason — never as a silent edit. The reasoning behind a move is the part worth keeping.

## How the work is divided

| Unit | What it is | Identified as |
|---|---|---|
| **Phase** | A commitment boundary. Each phase converts something private into a public promise (spec §4) | `Phase 1` … `Phase 6` |
| **Slice** | A vertical piece that leaves a working product and is small enough for one implementation plan, roughly ten tasks | `1.1`, `1.2` … |
| **Task** | One reviewable unit inside a plan, with its own test cycle | `Task 3` within a plan |

Plan files carry the slice in their name, zero-padded so they sort correctly past nine:

```
docs/superpowers/plans/2026-09-17-phase1-slice01-foundation.md
docs/superpowers/plans/<date>-phase1-slice02-admin-shell.md
```

A slice's plan is written **when the previous slice lands**, not in advance. Planning slice 1.9 now would mean planning against code that does not exist yet, and the plan would be wrong by the time anyone used it.

**A slice too large for one plan is delivered in parts** — `1.2a`, `1.2b` … — with plan files named `…-slice02a-…`, `…-slice02b-…`. The parts of one slice may be planned together, because each part's interfaces are fixed before its code exists; the rule above still holds between slices. Each part:

- opens with a **Task 0 that checks the previous part landed as planned**, and stops if it did not;
- has its own branch, and is merged to `main` when done, so `main` stays deployable between parts;
- carries no tag. The slice is tagged once, after its last part.

### A slice is done when

- It leaves a working product, and `composer check` is green
- Its acceptance criteria from spec §7 pass, where it owns any
- `docs/architecture/` reflects what was built, and a user-guide page exists if the slice is user-facing
- This file's status column is updated, and `CHANGELOG.md` has an entry

## Status

| Status | Meaning |
|---|---|
| ⬜ Not planned | Scoped here; no plan written yet |
| 📝 Planned | Plan written, not started |
| 🔨 In progress | Being built |
| ✅ Done | Meets the definition above |
| ⏸ Blocked | Waiting on a decision or an input — the reason is noted |

---

## Phase 1 — Core publishing loop

**Done when:** Teme can migrate a WordPress publication and serve it, proven end to end by a full test migration. Moving a real publication onto Teme happens later, when the maintainers are satisfied with development, and is itself a test.
**Version at completion:** `v0.1.0`

| Slice | Name | Delivers | Depends on | Status |
|---|---|---|---|---|
| **1.1** | Foundation | App skeleton, standards tooling, content schema, Operation layer, architecture tests, roles | — | ✅ Done — plan |
| **1.2** | Admin shell | Laravel's React starter kit, adopted by overlay (I-107), under `/admin` (I-105), on TypeScript 7 (I-108). Invitation-only accounts; users and roles screens; `DeleteUser` with content reassignment (I-102). The security baseline (I-113, I-114), with optional two-factor and per-role enforcement off by default (I-115), and a minimal security event log (I-118). The settings registry (I-109) with a shared core of form field types (I-112). The architecture-test fix, the model-write rule, query classes and the Fortify allow-list (I-097 to I-100) — studies F, G, H. **Four parts:** 1.2a guardrails and shell · 1.2b settings registry · 1.2c secure sign-in · 1.2d users and security log | 1.1 | ✅ Done — v0.1.0-alpha.2 |
| **1.3** | Content management | Posts and Pages lists, filters, quick edit, bulk actions, trash; categories and tags; the field registry, grown from 1.2's shared core of field types (I-112); maintained status counters; the **scale benchmark** — a million articles and media items — which every later slice runs before its tag (spec §5.12). Study I; decisions I-140 to I-148. **Four parts:** 1.3a content lifecycle · 1.3b categories and tags · 1.3c Posts and Pages screens · 1.3d field registry and benchmark | 1.2 | ✅ Done — v0.1.0-alpha.3 |
| **1.4** | Media | Library, uploads, derivative generation on the queue, alt text, queue workers | 1.2 | ✅ Done — v0.1.0-alpha.4 |
| **1.5** | Editor | Card manifest, Tiptap prose + cards, autosave, revision compare and restore, preview, scheduling, submit-for-review, `search_tsv`, derived projections; the command palette, as content search (I-103); per-user preferences (I-110). The admin design system, set from study M's reading of nine admin interfaces (I-187 to I-191). Study L; decisions I-181 to I-184. **Nine parts:** **1.5·0 types and taxonomies (I-410, study Y)** · 1.5a design system · 1.5b the document · 1.5c the editor screen · 1.5d history and preview · 1.5e publishing, scheduling and review · 1.5f palette, preferences and benchmark · 1.5g the dashboard (I-161, I-210) · 1.5h the list screens (I-204) | 1.3, 1.4 | ✅ Done — v0.1.0-alpha.5 (1.5·0, 1.5a, 1.5b, 1.5c, 1.5d, 1.5e, 1.5f, 1.5g and 1.5h; benchmark in `docs/benchmarks/v0.1.0-alpha.5.md`, provisional under I-154, four misses accepted under I-153) |
| **1.6** | Public site | Blade theme layer, view composers and view models, default theme, template resolution with debug header, navigation, regions, `Settings › Design` with Preview; sticky (pinned) posts as a built-in field (I-156) | 1.5 | ✅ Done — v0.1.0-beta.6 (1.6a addresses, 1.6b the theme engine, 1.6c the page, 1.6d navigation, 1.6e regions, 1.6f Settings › Design, 1.6g the default theme and 1.6h search; benchmark in `docs/benchmarks/v0.1.0-beta.6.md`, provisional under I-154, every plan rule passing but one check-4 pair accepted under #30, time misses provisional under I-153 and I-154, B-322; #29 and #30 accepted for the betas) |
| **1.7** | Cache | Full-page cache, tag-based invalidation from the publish pipeline, surrogate keys, purge API, driver interface, stampede protection. Decides the static-file driver and Laravel Octane (spec §5.6). **Carry-ins (the 1.6 audit, 2026-09-27):** `s` on the cache key's query allow-list (study R §3.2) and search's `X-RateLimit-*` headers never cached, both in 1.7a; I-087's promised architecture test against static mutable state, in 1.7a, with 1.7e asking whether singletons that hold request state are forbidden too (today `Actors` is one, made safe only by every caller clearing it in a `finally`); per-card lookups batched (#30), in 1.7e. **Five parts:** 1.7a the cache core (the lookup ahead of any session and of maintenance mode, the key, what is never stored, the file store and `page_cache_tags`, the headers, the `teme_auth` marker, I-087's test; off by default, a page kept a fixed minute; I-460 to I-466) · 1.7b dependencies and invalidation (tags recorded while pages render, before-and-after tags from Operations after the outermost commit, the race guard, `CacheKeysFor`, purges; on by default) · 1.7c stampedes, staleness and `/_teme/session` (a lock per page, stale-while-rebuild, hard purges for removals, time caps, the "Edit this post" link) · 1.7d scale-out (Redis, CDN purges after regeneration, warming, the static-file driver; split on 2026-09-28, I-511: 1.7d the Redis store, its locks and the race guard by numbers, then 1.7d′ CDN purges with Cloudflare first (I-514), warming and the shared folder, then 1.7d″ the static-file driver (split again, I-530)) · 1.7e proof and the tag (I-087's singleton question, #30, the benchmark with the cache) | 1.6 | ✅ Done, tagged `v0.1.0-beta.7` on 2026-09-30 (1.7a the cache core, 1.7b dependencies and invalidation, 1.7c stampedes, staleness and the session endpoint, 1.7d the Redis store, 1.7d′ in front of Teme, 1.7d″ the static-file driver and 1.7e proof; benchmark in `docs/benchmarks/v0.1.0-beta.7.md`, provisional under I-154, every hit reading nothing, within 10 ms, and the file store's `STALE` answer and wait over it, B-345, B-346; check 4's article pair and the Task 5 table the plan's faults, B-343, B-344) |
| **1.8** | Comments | Storage, display, submission with rate limiting and honeypot, moderation queue, spam-check interface. **Eight parts** (I-569; three would put one part over 3,000 lines, the design pass split 1.8b in two, I-599, 1.8c's plan split 1.8c in two, I-654, 1.8c′'s split off the public thread's Edit link as 1.8c″, I-728, and 1.8d's split off the item's Discussion and the benchmark as 1.8d′, I-773): 1.8a storage, submission and moderation (`comments` and `comment_stats`, each item Open, Closed or Hidden with each type's start, the statuses registry, the `modules` switch and the Discussion settings it reads, `comments.moderate` through an add-only migration, `SubmitComment` with the moderator contract, moderation and tombstones; nothing people see) · 1.8b the thread and the form, with no JavaScript (the thread's view model, null while off or Hidden; conversations counted and pages found by position, I-600; tombstones, pings, the author's mark and "In reply to"; later pages and a comment's own address; core's form, its honeypot and forgery guard, I-605; one answer page per outcome; the reply page; the page-cache tags, I-603; the default theme's templates; drawn in `theme-v1` first, I-592 to I-597) · 1.8b′ core's script for comments (saved details, the signed-in name, your own waiting comments, the moving reply form, posting without leaving the page) · 1.8c the screen and its moderation (the Comments screen as v9 draws it, authors' own items, Undo, the details drawer and its address, the menu badge, Home's figure, the Comments column) · 1.8c′ editing, replying, emptying and the keys (Quick edit, the inline reply, Empty spam and Empty trash, the trash purge, the keys) · 1.8c″ the thread's Edit link and its hooks (`data-teme-actions`, `data-teme-empty`, `data-teme-count`) · 1.8d Settings › Discussion with the switch on top, the moderator and author emails with their opt-outs, erasing IP addresses · 1.8d′ the Publish panel's Discussion row and `SetDiscussion`, Quick and Bulk Edit, the erasure's index, the benchmark, `v0.1.0-beta.8`. The decisions for 1.8a: I-570 to I-574 | 1.6 | ✅ `v0.1.0-beta.8`, 2026-10-08: all eight parts built and merged (1.8a, 1.8b, 1.8b′, 1.8c, 1.8c′, 1.8c″, 1.8d, 1.8d′); tagged after an unbroken local Browser run (69 passed), with two fixes made alone first (issues 59 and 60). 1.8c′ Tasks 4 to 7, 1.8c″, 1.8d and 1.8d′ were built in parallel (I-752); the benchmark is `docs/benchmarks/v0.1.0-beta.8.md` |
| **1.9** | SEO | Metadata, canonicals, sitemaps, redirects, feeds, managed script slots. **Study Z adds (2026-10-01):** the structured-data graph's `@id`s, merge, encoder and validation (I-696 to I-707); the site's representation with Organization subtypes and LocalBusiness (I-708), and "Owned by" with the parent's profile links (I-725); schema types per content type and per item (I-709); per-item `nosnippet`, `max-snippet` and `noarchive` (I-714); the AI training switch naming training crawlers only (I-707). **Also 1.9's:** Tools › Page cache with its permission (I-467), and what a trashed or unpublished item's address answers (I-142). **Twelve parts, 1.9a to 1.9l,** collected on a `slice/1.9` branch and merged to `main` together after `v0.1.0-beta.8` (part map) | 1.6 | ✅ Done — `v0.1.0-beta.9`, tagged 2026-10-10 after an unbroken local Browser run (107 passed) and the Rich Results Test by hand (no errors): 1.9a the foundation, 1.9b the head, 1.9b′ share images, 1.9c redirects, 1.9c′ counts and the 404 log, 1.9d feeds, 1.9e site code, 1.9f sitemaps and robots.txt, 1.9g structured data, 1.9h the Tools screens, 1.9i Search and social's screen, 1.9j who publishes, 1.9k the editor's Search and social, 1.9l proof; built in seven waves on `slice/1.9` (I-752); the benchmark is `docs/benchmarks/v0.1.0-beta.9.md`, provisional under I-154 |
| **1.10** | AI | Provider adapter, selection rewrite, alt text, metadata suggestions, AI activity record. **Last part: the admin parity sweep against v9** (I-440; the list is in `architecture/admin-design.md`) | 1.4, 1.5, 1.9 | ✅ Done — v0.1.0-beta.10 (part map): 1.10a the seam, 1.10b services and keys, 1.10c asking and using, 1.10d AI activity, 1.10e Settings › AI, 1.10f rewrite, 1.10g alt text, 1.10h metadata, 1.10p1 to 1.10p10 the parity sweep, 1.10z the full pass and proof; benchmark in docs/benchmarks/v0.1.0-beta.10.md, provisional under I-154 |
| **1.11** | Import | Companion WordPress plugin (own repo, GPL), importer, URL preservation and redirect generation, comments, media, import report; the jobs indicator (I-104); WordPress password hashes verified once and upgraded to Argon2id (I-119), through an `UpgradePasswordHash` Operation called after sign-in, never through Laravel's vendor rehash (I-124). **The secure media downloader** it needs is also the editor's "Import images" prompt for pasted articles (I-453, same slice); **Audio and Video cards** (I-446) land before it, because the importer maps `[audio]`, `[video]` and `[playlist]` to them; queued operator work carries the operator actor (I-392). **Before it:** the editor issues in the GitHub milestone "Before the importer (1.11)" (#2 scheme-less and copied links, #3 heading and list values, #7 own-site links, #10 pasted h1/h5/h6), whose fixes were rechecked against `main` on 2026-10-11 into `docs/superpowers/plans/2026-10-11-phase1-fixes-before-the-importer.md` (I-452, I-455, with I-1669 and I-1670) | 1.8, 1.9 | ✅ Done — v0.1.0-rc.11 (part map, answers I-1700 to I-1719): 1.11a the seam, 1.11b passwords, 1.11c audio and video cards, 1.11d the downloader, 1.11e people and terms, 1.11f the converter (two parts), 1.11g jobs and the inbox, 1.11h files, 1.11i the Imported content card, 1.11j posts and pages, 1.11k comments, 1.11l menus, 1.11m addresses and the check, 1.11n the second pass and catch-up, 1.11o Tools › Import, 1.11p the header's jobs and Select all matching, 1.11q Uploaded for, 1.11r private and protected, 1.11s links by ID, 1.11t menu headings, 1.11z the proof; proved by a full import of an example site (3,578 posts and pages), followed by ten fixes (#139 to #161); the second import and the run on a copy of a live site moved to later live testing (I-3168); benchmarks in docs/benchmarks/v0.1.0-rc.11.md and v0.1.0-rc.11-import.md, provisional under I-154. The companion plugin's part 3 is still to build |
| **1.12** | Backup and restore | Backup engine capturing database and media together; encryption with an offline recovery key; destinations local, S3-compatible and SFTP; retention; scheduled verification; CLI restore with preflight; NDJSON export; `Tools › Backups` status panel; two-factor secrets re-encrypted on restore (I-119) — spec §5.11 | 1.8, 1.9, 1.10; 1.12i on 1.11a and 1.11g | ✅ Done — v0.1.0-rc.12 (part map, answers I-2400 to I-2411): 1.12a the seam (two parts), 1.12b re-encrypting secrets, 1.12c keys and places (two parts), 1.12d the backup run (two parts), 1.12e verification and alerts, 1.12f restore (two parts), 1.12g the export (two parts), 1.12h Tools › Backups (two parts), 1.12i Back up now, 1.12z the proof; built beside 1.11 on slice/1.12, 1.12i after rc.11; a site of 40,000 images backed up and restored onto an empty server under a new APP_KEY and address, the restore onto a second server left to 1.13z; benchmarks in docs/benchmarks/v0.1.0-rc.12.md and v0.1.0-rc.12-backup.md, provisional under I-154 |
| **1.13** | Deployment and full test migration | Docker image, VPS documentation, a migration runbook, and a full test migration run end to end on a production-like server — import, publish, back up, restore onto a second server. Production connects as a **non-superuser** database role that owns the schema, verified by a health check — the official PostgreSQL image makes `POSTGRES_USER` a superuser, which is acceptable in development only (SECURITY.md commitment 8). HSTS, the trusted-proxy recipe, and Argon2id re-tuned on the target image (I-119) | 1.11, 1.12 | 🏷️ v0.1.0-rc.13; the full test migration under way (1.13z) |

### Open inputs

Phase 1's scope rests on assumptions about the test migration that nobody had verified. Each one below could cut or add scope, so they should be answered **before slices 1.2–1.10 are planned**, not discovered in 1.11:

| Question | Answer, 2026-09-18 | Effect |
|---|---|---|
| Which publication goes first? | A test site | Phase 1 is proven on a test migration |
| Custom post types? | None on the test site | The phase 1 assumption is confirmed; the type builder stays in phase 2 |
| Which plugins are active? | A list of 42 from one installation — study E | Read as an **idea pool**, triaged by general capability. Ideas I-058 to I-075. It sets no requirements |
| Comments? | Many, with an active moderation queue | Native comments confirmed (I-059); spam defences matter (study E, finding 4) |
| Permalinks and table prefix | `/%postname%/`, default prefix | The companion plugin reads both, so neither needs configuring. Root-level permalinks need a path table (I-061) |
| Content counts | About 10,600 articles and 20,000 images on the largest site | Not used to set targets — those describe the general case (I-076) |

**Performance target** (I-076): set on 2026-09-18 — a million articles and a million media items (spec §5.12).

### Milestones

| Milestone | Reached after | Meaning |
|---|---|---|
| **It publishes** | 1.6 | An article can be written and published to a real public site. Internal only |
| **Feature-complete** | 1.10 | Everything a publication needs day to day exists |
| **Proven** | 1.13 | A full test migration passes end to end. Tag `v0.1.0` |

### Order and parallel work

```
1.1 → 1.2 ─┬→ 1.3 ─┐
           └→ 1.4 ─┴→ 1.5 → 1.6 ─┬→ 1.7
                        │        ├→ 1.8 ─┬→ 1.11 ──┐
                        │        └→ 1.9 ─┤         ├→ 1.13
                        └──────────────→ 1.10 → 1.12 ┘
```

1.3 and 1.4 can run in parallel; so can 1.7, 1.8 and 1.9; and so can 1.11 and 1.12. Everything else is sequential because each slice consumes the previous one's interfaces.

### The review track (I-3134)

What a review of an imported example site found, beyond the two fixes rc.11 needs (#157, #161). **Built after `v0.1.0-rc.11` reaches `main`, on its own branch from `main`, and merged when it passes the gate; it never holds up 1.12 or 1.13.** Its bugs wait for a free slot unless one blocks a feature or later work (I-3169). The wording pass is the one item with a due point: before 1.13f, so the editors' guide describes the final words.

| Item | Issues | Plan | Status |
|---|---|---|---|
| The editor's layout: the writing area and Search and social (I-3121, I-3124) | #152 | `docs/superpowers/plans/2026-10-28-phase1-editor-layout.md` (on `slice/1.11` at `7c3f197d`; rebased onto `main` before it is built) | ✅ Built and merged into `main` 2026-11-03 (`91e8b489`) |
| The list header, row links and month counts (I-3129 to I-3131, I-3135) | #119, #120, #154, #155, #173 | `docs/superpowers/plans/2026-10-29-phase1-list-header-and-settings.md`, Parts 1 and 2; needs #163 merged and `@radix-ui/react-popover` added first | ✅ Built and merged into `main` 2026-11-03: Part 1 (`7839843a`), Part 2 (`52647736`) |
| Settings pages as titled panels (I-3128) | #149 | the same file, Part 3 | ✅ Built and merged into `main` 2026-11-03 (`47e865a7`) |
| A Full import button beside Catch up (I-3133) | #180 | drawn first | ⬜ Not planned |
| The admin-wide wording pass, **due before 1.13f** | #176, #175 | — | ✅ Built, in `v0.1.0-rc.12` (#176) |
| Editor bugs | #168, #170, #172 | — | ⬜ Not planned |
| Media bugs | #165, #166 | — | ⬜ Not planned |
| The import's address-clash words | #179 | — | ⬜ Not planned |
| Linked images (I-3126), if it proves cheap after #152; otherwise phase 2 | #177 | — | ⬜ Not planned |
| Bugs that block nothing, left for later (I-3169) | #156, #158, #181 | — | ⬜ Not planned |

---

## Later phases

Slice lists here are **estimates**. Each phase is re-sliced properly when it is reached, against the code that exists then.

### Phase 2 — Content model · `v0.2.0`

**First release (`v0.2.0`, I-3323):** a site makes its own content types, taxonomies and fields on screen and uses them at once: 2.1, 2.2a and 2.2b, 2.3a and 2.3b. **Updates later**, each small, planned only when its turn comes, in priority order and possibly between other phases' work: linked items with roles, more field types, filtering lists by a field, and importing custom types and ACF fields.

| Slice | Name | Status |
|---|---|---|
| 2.1 | Custom content types, with the admin builder over the phase 1 field registry | ✅ Done, to be tagged `v0.2.0-alpha.1` (2.1a types in the database, 2.1b the type builder at Site › Types, 2.1c per-type permissions, 2.1d archives across types and `reading.home_types`, 2.1e each type's listing page, 2.1f pages' own permissions, breaking for authors and contributors) |
| 2.2 | Your own fields: 2.2a the field contract and the editor's general controls; 2.2b the Fields page, Details in the editor, date only and *Show on the site* (I-3320 to I-3322) | 📝 Building (2.2a: plan) |
| 2.3 | Your own taxonomies: 2.3a stored taxonomies, 2.3b Site › Taxonomies (I-3319) | 📝 Building (2.3a and 2.3b: plan) |

**Updates later (after `v0.2.0`, I-3323).** Each is planned when its turn comes; the order is a starting point, not a promise.

| Update | What | Status |
|---|---|---|
| Linked items with roles | The relation field with roles and notes, `content_relations`, "what links here" (spec §5.1); cast and crew | ⬜ Later |
| More field types | Group, repeater, multi-select, rich text, link, JSON (I-711), each when a site needs it; then the structured-data packs (I-710) | ⬜ Later |
| Filtering by a field | Filterable fields and `teme:fields:index` (I-416) | ⬜ Later |
| Importing custom types and ACF | Companion 0.3.0, proposed types, taxonomies and fields, import extensions, proven by a small test migration (spec §5.4, §5.5); an ACF field type Teme lacks is reported, never lost | ⬜ Later |

### Phase 3 — Public surfaces · `v0.3.0`

**Done when:** the API and MCP server are consumed by something other than Teme's own admin. **Promise made:** the HTTP API shape, versioned by path from `/api/v1`.

| Slice | Name |
|---|---|
| 3.1 | Delivery API — read-only, cached; beside it, `llms.txt` and `.md` versions of pages as an opt-in switch (I-713) |
| 3.2 | Management API with scoped tokens |
| 3.3 | MCP server |
| 3.4 | Wider AI operations |

### Phase 4 — Theme contract · `v0.4.0`

**Done when:** a second theme is built against the documented interface. **Promise made:** the theme interface.

| Slice | Name |
|---|---|
| 4.1 | Publish the theme contract — `teme-theme` package type, manifest validation, theme checker |
| 4.2 | Appearance section — Themes, scoped theme settings, template resolution inspector |
| 4.3 | Second theme, proving the abstraction |
| 4.4 | Theme handbook, generated theme reference, `wordpress-theme-to-teme` skill |

### Phase 5 — Plugin API · `v0.5.0`

**Done when:** someone other than us extends Teme. **Promise made:** hook stability. This is the one-way door.

| Slice | Name |
|---|---|
| 5.1 | Hook dispatcher — five semantics, fail-soft, auto-disable after repeated failure |
| 5.2 | Plugin lifecycle — versioned migrations, append-only history, safe mode, activation preflight |
| 5.3 | Public registries — fields, cards, admin screens, AI tools, backup destination drivers; `owner` on every registry entry |
| 5.3a | The manifest (`teme-plugin.json`) and its loader; capabilities, consent and the update-escalation check; dependencies and provider slots (`docs/architecture/plugins.md`) |
| 5.3b | Plugin interface code — the Vite preset, `teme:plugins:publish`, the admin API version; the surface ladder open to plugins; the activity screen for long work |
| 5.3c | The installer — install requests from the admin, a separate installer process, staged and atomic releases with rollback (I-211) |
| 5.4 | Plugin handbook, generated hook reference, `wordpress-plugin-to-teme` skill |

### Phase 6 — Distribution · `v1.0.0`

**Done when:** a stranger installs Teme. **Promise made:** upgradeability. First public release.

✅ **Released as `v1.0.0` on 2026-11-17.** What it holds is in [`CHANGELOG.md`](../CHANGELOG.md).

| Slice | Name |
|---|---|
| 6.1 | Browser installer, with an install token, and browser restore from the installer and the admin — data only, under spec §5.11's controls |
| 6.2 | Signed release artefacts, update channel, pre-update snapshot and rollback; the plugin repository — the 5.3c installer with a source and a signed index |
| 6.3 | Documentation site |
| 6.4 | Security go-live — monitored disclosure mailbox, advisory channel, dependency scanning in CI; passkeys (I-116); session listing and revoking, new-device notices, an admin IP allow-list, and the security log viewer (I-119) |
| 6.5 | WordPress export (WXR) — the exit path, generated from the NDJSON export |

### Versions

`0.x` throughout phases 1–5: Teme runs only on our own infrastructure, so the product version marks internal milestones rather than promises. Public promises made before 1.0 carry their own versioning — the HTTP API by URL path, `teme/contracts` by its own semver — so they can be stable while the product is still `0.x`.

`1.0.0` is the first release a stranger can install, and it is the point at which the product version starts to mean something to anyone outside.

#### The scheme

| Part | Tracks |
|---|---|
| Minor, before 1.0 | The phase. Phase 1 builds toward `0.1.0`, phase 2 toward `0.2.0` |
| Pre-release label | The milestone reached inside the phase — `alpha`, `beta`, `rc` |
| Pre-release number | **The slice that just landed** — not a counter |
| Patch | Fixes to a released phase, while the next phase is built |

#### Within phase 1

| Slices | Tag on completion | Means |
|---|---|---|
| 1.1 – 1.5 | `v0.1.0-alpha.1` … `v0.1.0-alpha.5` | Cannot yet publish a public site |
| 1.6 – 1.10 | `v0.1.0-beta.6` … `v0.1.0-beta.10` | Publishes; features still landing |
| 1.11 – 1.13 | `v0.1.0-rc.11` … `v0.1.0-rc.13` | Feature-complete; proving the migration |
| Phase complete | **`v0.1.0`** | A full test migration passes end to end |

The pre-release number is the slice number so that a tag identifies its slice on sight — `v0.1.0-beta.8` is slice 1.8. Semver permits the gaps this leaves, and orders `alpha < beta < rc < release` correctly, so tooling sorts these the right way.

Later phases follow the same pattern, with stage boundaries set at that phase's own milestones when it is sliced.

#### After a phase releases

Fixes ship as patches — `0.1.1`, `0.1.2` — while the next phase builds toward `0.2.0` through `v0.2.0-alpha.N` tags.

This works without release branches because of one rule already in `AGENTS.md`: **every slice leaves a working product.** A slice is built on its own branch and merged to `main` only when done, so `main` is always deployable and a patch can be tagged from it at any time. The rule stops being a nicety the moment any site runs on Teme, even a test site: an unfinished slice sitting on `main` would ship in the next fix.

#### Every tag gets

- A `CHANGELOG.md` entry under that version
- The slice's status in this file set to ✅
- For a phase release, an upgrade note if anything internal to our own deployment changed shape

---

## Not scheduled

Parked, rejected and open ideas live in the ideas register, kept outside this repository, with the reasoning for each. That file is the intake for everything proposed; this one holds only what has been placed in a phase.

---

## Change log

What moved, when, and why. Most recent first.

### 2026-09-27

| Change | Was | Now | Why |
|---|---|---|---|
| Planning rule (I-468) | A plan built a screen as its prototype drew it | **Everything people see gets a design pass when its slice is planned, before it's built:** admin screens redrawn in v9, the default theme's templates in `theme-v1`, and emails and one-off pages designed in the plan itself; a parity sweep item gets the same pass first | On v9's Backups screen: drawn as an inventory of study W's decisions, it would have been built that way and then enforced by the parity sweep (I-440) |
| Planning rule (I-749) | A request to go through WordPress file by file | **Every plan lists the WordPress files behind its features** (`Reference/wordpress-develop-trunk/src`, `file:line`) and marks each edge case they handle as kept, improved or dropped, with rule 8's reason. Behaviour is ported; code is never translated | Study J maps behaviour by subsystem; a file-level pass per slice catches the edge cases a subsystem view can miss |

### 2026-09-25

| Change | Was | Now | Why |
|---|---|---|---|
| The gate's test run | One test at a time: 1,186 s | In parallel on 12 processes: 244–264 s, the same 1,037 tests | A measured trial (I-441, B-203): three parallel runs green, and no test that failed only in parallel |

### 2026-09-22

- **Slice 1.5 gains a first part, 1.5·0 — types and taxonomies** (I-410, study Y, adopting the recommendation). A content-type and a taxonomy registry replace every post/page and category/tag literal while five seams are still cheap to open: per-type addresses, per-type field handles, the search contract's default, per-type preference keys and per-(term, type) counts. Numbered 1.5·0 so the eight existing part letters don't move. Built after 1.4d, before 1.5a.

| Change | Was | Now | Why |
|---|---|---|---|
| Slice 1.5 | Six parts | Eight: adds 1.5g the dashboard and 1.5h the list screens | The dashboard is built straight after the editor (I-161), because everything its first panels show is created by 1.5b–1.5e; the list screens' Bulk Edit, columns and rows per page (I-204) need 1.5f's preferences |
| Prototype | v3 current | v8 adopted (I-229) | Review, 2026-09-21 and 22 |
| Featured image | May be marked decorative | Never decorative (I-237) | The option confused; a featured image is the article's picture and its social image |
| Slice 1.4 delivered | Planned as one slice | Delivered in four parts, 1.4a–1.4d; the benchmark's result is in `docs/benchmarks/v0.1.0-alpha.4.md` | Kept each part reviewable and `main` deployable between them. The benchmark run is provisional (I-154): it was made on a loaded development machine, every query-plan rule passed — including check 4, built in 1.4d, and the four tab-and-sort scenarios B-081 found were not yet checked — and five time misses are accepted under I-153 |

### 2026-09-21

| Change | Was | Now | Why |
|---|---|---|---|
| Slice 1.5 | Six plans written, ready to start | On hold, then revised to the plugin architecture; the hold lifts after the plugin prototype is reviewed | Plugins had not been designed, and building the editor first would decide it by accident (I-207) |
| Plugin architecture | Deferred whole to phase 5 | Designed now (study O, `docs/architecture/plugins.md`); built in phase 5 (5.3a–5.3c); phase 1 builds only the shapes | Admin surfaces built in 1.5 must already take entries of the shape plugins will contribute |
| Extension name (I-208) | *Plugins* and *extensions* both used | **Plugins** | Decided on a recommendation |
| Part 1.5a | Tokens, controls, list screen, shell | Adds Task 5, the navigation registry (I-220) | Study P: the menu was hand-coded while settings navigation was already a registry; moving it now costs seven entries |

### 2026-09-19

| Change | Was | Now | Why |
|---|---|---|---|
| Slice 1.2 delivered | Planned as one slice | Delivered in four parts, 1.2a–1.2d, each merged separately; one tag at the end | Kept each part reviewable and `main` deployable between them |
| Part 1.2e, after the tag | — | The documentation standard (I-132 to I-134) and three backlog follow-ups (I-135 to I-137), merged without a tag, shipping in the next tag | The documentation questions were raised once 1.2 was built, and the backlog showed three gaps worth closing before 1.3 builds on them |
| Slice 1.2 delivery | One plan | Four part plans, 1.2a–1.2d, each on its own branch and merged separately; one tag, `v0.1.0-alpha.2`, after 1.2d | About thirty-five tasks — three times a plan's size. Parts keep each plan reviewable and `main` deployable in between |
| Planning rule | Every slice's plan written only when the previous slice lands | Unchanged between slices; the parts of one slice may be planned together, each opening with a precondition check | A part's interfaces are fixed in advance, so all four can be built without waiting |
| Per-user preferences (I-110) | Slice 1.2 | Slice 1.5 | The editor is the first real preference; building the storage before anything uses it would break the slice rule |
| Account page name (I-122) | *Interface* | *Preferences* | `interface` is a reserved word in TypeScript, and Wayfinder names generated code after the page |
| Password rehash on sign-in (I-124) | Laravel's default, on | Off, pending a decision before 1.11 | Laravel rewrites the `password` column from vendor code, which the I-100 exception does not cover |
| Slice 1.3 delivered | Planned as one slice | Delivered in four parts, 1.3a–1.3d; the benchmark's first result is in `docs/benchmarks/v0.1.0-alpha.3.md` | Kept each part reviewable and `main` deployable between them. The benchmark run is provisional (I-154): it was made on a busy shared machine, every query-plan rule passed, and three time misses are accepted under I-153 |
| Domain namespace (I-149) | `App\Cms` | `App\Domain` | `App\Cms` renamed `App\Domain` (I-149), part 1.3e, merged after `v0.1.0-alpha.3`. The whole application is a CMS, so `Cms` said nothing; `Domain` is the standard term for an application's own business logic. A mechanical rename, no tag, shipping in the next one |
| Sticky posts (I-156) | Not in any slice | Slice 1.6, as a built-in field; the importer carries them in 1.11 | Study J, the WordPress disposition map, found the importer must carry WordPress's sticky posts, so phase 1's *Done when* — migrate a publication and serve it — needs somewhere to put them |

### 2026-09-18

| Change | Was | Now | Why |
|---|---|---|---|
| Phase 1 slicing | Five slices (spec §6, first draft) | Twelve | The five were each too large for one plan. Comments, navigation and AI were added to phase 1 after that estimate was made |
| Plan file naming | `2026-09-17-phase1-foundation.md` | `2026-09-17-phase1-slice01-foundation.md` | A plan should say which slice it implements |
| Slice 1.2 scope | Shell, sign-in, users and roles, settings registry, command palette, jobs indicator | The starter kit by overlay; a security baseline; the settings registry with shared field types; a security event log; per-role two-factor enforcement, off by default. The command palette moved to 1.5, the jobs indicator to 1.11 | Studies F, G and H, and decisions D1 to D9 — ideas I-098 to I-122 |
| Performance target | 10,000 articles / 20,000 media, never set deliberately | 1,000,000 / 1,000,000, proven by a scale benchmark built in 1.3 and run before every tag | The target for a CMS serving any publication. The design rules that make it achievable are spec §5.12 |
| Phase goals | Defined by real publications going live | Defined by what Teme can do, proven by full test migrations. Slice 1.13 ends in an end-to-end test migration, not a go-live | Direction: migrating a real publication happens later, when the maintainers are satisfied with development, and is for testing |
| **Drift, corrected** | One site's plugin list had been read as its requirements: a proposal to reorder phases around its integrations, a performance target sized to it, and deadlines tied to its migration | Withdrawn (I-062, I-063); reframed as general ideas (I-058, I-064); study E rewritten as an idea pool; spec §1 now states Teme is built for any publication | Teme is built for any site. Real sites are test cases and a source of ideas, never the specification |
| Open inputs | Unanswered | Answered; study E read the plugin list as an idea pool | — |
| Ideas intake | Ideas went straight into a phase as they were raised | Every idea is captured in the ideas register with an ID and a status, then triaged; the parked and rejected lists moved there | Capturing an idea should never commit anyone to building it. Phase 1 grew from five slices to thirteen in a day without that step |
| Browser restore | "CLI-only", stated as permanent | CLI in phase 1; browser restore of data in phase 6 (6.1) under explicit controls | The first draft overstated the risk. The real dangers — writing executable code, and executing an uploaded SQL dump — are both designable out |
| WordPress export (WXR) | Absent | Phase 6 (6.5), as an exit path | Being able to leave is part of what makes it safe to arrive. Lossy by design, so never the backup |
| Backup and restore | One line in slice 1.12 | Its own slice, 1.12; deployment and cutover become 1.13. Phase 1 is now **thirteen** slices | Backup is a guarantee, so it is core, with pluggable destinations (spec §5.11). Encryption, verification, CLI restore with preflight and three destinations are too much to share a slice with go-live |
| Site URLs in content | Unspecified | Content stores IDs; URLs are generated at render. Agent rule 9 | Makes a domain change a setting rather than a search-and-replace, and makes a restore onto a new domain just work |
| Agent instructions | Hand-written `AGENTS.md` and `CLAUDE.md` | Source in `.ai/guidelines/teme.md` and path-scoped `.ai/rules/`; Boost composes them into generated `AGENTS.md` / `CLAUDE.md`, which are committed | `boost:install` generates those two files itself and would have overwritten the hand-written ones. One source, several generated outputs |
| Laravel Boost | Required by spec §10, absent from the slice 1.1 plan | Slice 1.1, Task 2 — with a human step for the interactive installer | A gap in the plan |
| Skeleton install safety | Slice 1.1 Task 1 copied Laravel's skeleton over the root | `.gitignore` files are merged, existing files are never overwritten, and ignore rules are verified before any `git add` | **It happened for real on 2026-09-18**: a skeleton install replaced Teme's `.gitignore`, un-ignoring the file holding payment card details. Caught and repaired before any commit existed |
| Versioning within a phase | Only `v0.1.0` at phase completion was defined | Each slice tags `v0.N.0-{alpha,beta,rc}.<slice>`; patches `0.N.x` after release | A tag should identify its slice and milestone on sight, and the live site needs a patch line once phase 1 ships |
| Field registry | Phase 2 | **Internal registry in 1.3**; the admin builder remains phase 2 (2.1) | Built-in fields — SEO title, featured image, excerpt — need it in phase 1. What waits is letting users define their own |

### 2026-09-17

Moves relative to `CMS-INTERFACE-DESIGN.md` §16, which defined the original initial release.

| Change | Was | Now | Why |
|---|---|---|---|
| Custom content types | Initial release | Phase 2 | The first publication needs only posts and pages |
| Browser installer | Initial release | Phase 6 (6.1) | We install our own sites; an installer only matters to strangers |
| Plugin installation lifecycle | Initial release | Phase 5 (5.2), and deploy-time only | Deployed code is read-only at runtime (spec §3), and the API must stop moving before anyone builds on it |
| Installing plugins from the admin (I-036, I-211) | Deploy-time only | Phase 5 (5.3c): the admin requests, a separate installer performs; the repository in 6.2 | Requested on 2026-09-21; study O found a way that keeps the web process from ever writing code |
| Comments | Absent from the initial release; first spec draft placed them in phase 3 | **Phase 1** (1.8) | A migrated site arrives with its comments. Anything the importer must carry cannot be deferred |
| Navigation | Under Appearance | Under Content, phase 1 (1.6) | A menu is a tree of pointers to content; only its binding to a theme slot is a design concern (study B) |
| Appearance section | Phase 1 | Phase 4 (4.2) | With one theme and nothing to switch to, it has no content (study B) |
| Live-preview Customizer | Interface doc §10 | Rejected | Spec §3 |
| Multisite | Later enhancement | Rejected | Spec §2 |
| Editor model | Block list | Prose with embedded cards | Gutenberg's writing-flow problem comes from making every paragraph a block |
| Outline pane | Interface doc §6 | Parked | Earns its place only in very long structured documents |
| Reusable patterns | Interface doc | Parked | |
| AI scope | Draft operations, activity history, provider configuration | Three operations in 1.10; wider operations in 3.4 | Phase 1 AI exists to prove the Operation layer, not to ship an agent |
| Import approach | WXR or direct database read | Companion plugin, and an uploaded export file (1.4.0); direct database read as fallback | Code inside WordPress can call `get_permalink()` and hand us the true URL (spec §5.10) |
| Plugin upload through the admin | Interface doc §11 | Withdrawn | Contradicts read-only code at runtime (spec §3) |
