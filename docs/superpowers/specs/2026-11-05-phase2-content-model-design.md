# Phase 2: the content model — design

**Status:** design, 2026-11-05. Phase 2 releases as `v0.2.0`.
**Marked in this document:** *decided* = a settled product decision; *adopted* = an earlier decision (I-number); *design call* = an implementation choice.

## 1. Intent

Phase 1 runs a site made of posts and pages. Phase 2 lets a site hold **its own kinds of content**: its own types, each with its own fields, categories shared across types, and **links between items, each link with an optional role**. Then a site built on WordPress custom post types and custom fields can move to Teme with its content intact.

**The test case** is a site built on a film-and-games database plugin (v7.35): Movies, TV Shows, Games, Books, People and Specs; fields such as ratings and release details; shared Genres, Studios, Platforms, Publishers and Status; and cast and crew kept in the plugin's own tables. Real sites are test cases, never the requirement.

## 2. Goals, non-goals, anti-goals

**Goals:**
1. An administrator makes a content type on a screen: names, address segment, whether it has an archive, which taxonomies and fields it uses, its permissions.
2. Fields of the phase 2 kinds on any type, drawn by the editor without code written for that type.
3. A **link field** (*decided*, 2026-11-05): an item points to other items, in order, each link with an optional role from a list the field sets ("Actor", "Director"; or none). The linked item can list what points at it (an actor's films).
4. Taxonomies made on a screen, shared by several types; a term's archive lists every public type that uses it.
5. The importer brings WordPress custom post types and ACF fields across, and **lets an extension bring a plugin's own real content** (an example plugin's cast and crew) (*decided*).

**Non-goals:**
- The example plugin's own features: fetching from TMDB and IGDB, ratings, sync, affiliate click tracking. They return later as a Teme plugin (*decided*, phase 5).
- A freeform page builder, and ACF's flexible content as a field (study E rejected a blocks field; flexible content becomes body cards where a mapping exists, otherwise it's reported).
- A public REST API (`app/Http/Api/` is empty; not in this phase's *Done when*).
- The phase 1 "later" and "soon" issues, unless one blocks a part (*decided*).

**Anti-goals** (what would make it a failure even if it works):
- A key-value meta table, or a table per type (rule 2): values stay in `contents.fields` JSONB.
- Editing a type rewriting stored data (study E: "a registry edit never rewrites stored data").
- The web process writing code or files other than the types' export file (I-211).

## 3. Already decided (adopted), which phase 2 builds as written

| | Decision | Where |
|---|---|---|
| I-411 | Types declared by code **and** the admin into one registry; admin types in the database with a versioned export file (`teme-types.json`, `teme:types:import`); code types locked in the admin; key clashes refused; a production lock (`TEME_TYPES_LOCKED`) | study Y §4.2 |
| I-412 | A type with items can't be deleted (trashed ones count); its key can't be renamed; labels and segment can, old addresses kept as redirects | §4.3 |
| I-413 | Per-type permissions optional: the global `content.*` six unless the type names its own `{type}.*` six; the builder creates and grants them | §4.4 |
| I-414 | Custom taxonomies are registry entries over `terms` and `content_terms`; one picker per taxonomy | §4.5 |
| I-415 | Single-instance content is a `single` flag on a type | §4.6 |
| I-416 | Only filterable fields filter or sort; their index built by `teme:fields:index`, never the web process | §4.7 |
| Study E | Repeaters one level deep, rows `{id, …}`; each repeater value `{"v": 1, "rows": [...]}` | §4.8 |
| I-328, I-349, I-353 | The importer brings content, not leftovers; custom types become *proposed* types the operator accepts, renames or skips; plugins import their own real content through the importer's registry | §4.9 |
| I-710, I-711 | Recipe, Event, Product and Review packs after 2.2; raw JSON-LD per item, administrators only, with the `json` field | study Z |

## 4. What phase 1 gives, and what's missing (from the code on `main`, 2026-11-05)

**Gives:** `ContentType` and `ContentTypeRegistry` (`app/Domain/Types/`), with `CoreTypes` the only place naming post, page, region, category and tag; `Taxonomy` and `TaxonomyRegistry`, already shareable; one `contents` table with `fields` JSONB and `UNIQUE(type, slug)`; `FieldRegistry` with ULID keys and 11 field types (`entry` among them); importer phases by container tag, which a plugin can add to; `NoTypeLiteralsTest` keeping application code free of type names.

**Missing:**
1. **Types from the database.** Admin routes (`routes/admin.php:288-301`) and settings groups (addresses, discussion) are built once at boot from the registries.
2. **A field contract big enough for a builder.** `FieldType` has 5 methods; study E's `edit`, `render`, `sqlType`, `references` and `text` aren't built.
3. **An editor that draws any field.** It draws built-in fields by handle (12 ULIDs in 3 JS files); there's no general renderer.
4. **Links between items as data.** `content_links` holds only links in the body; `entry` field values aren't projected anywhere; the spec's `content_relations` (spec :169) doesn't exist; nothing has a role.
5. **One archive type.** `ArchiveType` (21 calls in 12 files) assumes one type has the archive: term, author and feed listings show only it.
6. **Per-type permission rows.** `ContentType::ability()` maps names, but nothing creates `{type}.*` abilities or grants them.
7. **The importer's WordPress literals.** Posts, pages, categories and tags in `SiteAnalysis` (4), `WordPressAddresses` (6), `MenuTargets`, `ImportScreen`; about 30 `'post'` object kinds in `import_map`. No post meta or ACF is read. Teme Companion (0.2.0) lists no type definitions, ACF groups or meta.
8. **`{category}` in address patterns** means "the type's first hierarchical taxonomy" in 6 places.
9. **Contracts.** `teme/contracts` exposes nothing for types, fields or taxonomies (internal until phase 5, `content-types.md:7-9`).

## 5. The new parts of the design

### 5.1 The link field (*decided*; storage is a design call)

- **Field type `relation`.** Settings: which types it may point to (one or several), how many links (one, or a list with a maximum), and **the roles**: a list of names the field allows, or none. A role is optional per link unless the field says it's required.
- **A short note on each link** (*decided*, I-3297): optional free text up to 200 characters, such as "Tony Stark", "200 g" or "keynote". It's for every site, not only that plugin: recipes, books, events, teams, products.
- **Stored** in the item's `fields` JSONB like every other value: `{"v": 1, "links": [{"id": "<content id>", "role": "actor", "note": "Tony Stark"}, ...]}`, in order. Roles are stored by key; their labels live on the field, so renaming "Actor" never rewrites data. An import may add roles the field doesn't list yet (crew jobs), recorded in the import's report.
- **Projected** into a new table `content_relations(from_id, to_id, field_key, role, note, position, scope)` **as `content_links` and `media_usages` are today**: inside the operation's transaction, with a `working` and a `published` scope (`LinkProjection::sync()`, `MediaProjection::sync()`), so drafts' links count too. *Design call, correcting this draft's first wording ("after commit, from the published revision"), which the code doesn't do.* It answers "what points at this item" (an actor's films), keeps deletes honest (an item linked from others warns before trashing, as media do), and lets themes list links both ways.
- *Reference check:* Craft, Strapi, Payload and EmDash keep links in one shared table; **only Wagtail has a role on each link natively** (an inline through-model); the rest need a repeater row of {link, text}. Teme's role on the link is the simpler model for editors.
- The existing `entry` and `canonical` fields project into the same table (they hold content IDs today, unprojected).

### 5.2 Archives for shared taxonomies (design call)

`ArchiveType` becomes "the public types that use this taxonomy" for term archives and their feeds, and "the public types with an archive" for author and date archives. A Genres page lists movies, shows and games together, newest first. WordPress lists only `post` on term archives unless a theme changes the query; that's a 2004 leftover (rule 8), and editors expect a genre to show everything in it.

### 5.3 The field builder (design call, within study E)

Fields are added to a type on its builder screen: label, handle (unique per type), type and its settings, required, help text, filterable. Fields from code (core's SEO, excerpt; later plugins) show locked. Removing a field hides it and keeps its stored values (study E §5.3). Deleting a field's stored values follows study E §5.3 unchanged: a dry run, an NDJSON export first, a confirmation, and a forward write as a new revision, so past revisions keep the old values and the item can be restored. *Design call:* the admin may start it as a queued operation, but the dry run and the export stay; values in past revisions are not erased.

### 5.4 Plugin content in the importer (decided; seam by I-349)

The importer gains a registry for **import extensions**: an extension adds phases (orders not multiples of ten, `ImportPhase.php:19`), reads its own Companion routes, and maps rows to Teme items, fields and links, recording them in `import_map`. **Proof:** a test extension for an example plugin's cast and crew, kept in `tests/Fixtures`, never shipped; a real one belongs to that plugin's own Teme version. Teme Companion grows a matching hook so a WordPress plugin (or a small add-on) can publish a route of its own data.

### 5.5 How the proof runs (*decided*)

Not a large migration. A test site turns the plugin on and makes a few items of each type, with fields, shared genres and studios, and cast and crew links. Phase 2's run imports that sample into a test Teme and checks every type, field value, term and link with its role came across, and that an actor's page lists their films. A small disk and an evening, not days.

## 6. Slices

**2.1 Your own content types**
1. Types stored in the database, read when the registry is first asked, never at boot (boot reads no database, `docs/architecture/themes.md:60`; corrected 2026-11-05); routing and the settings groups that follow the registry (addresses, discussion, and search and social's `seo`) at once (§5.2b); the export file and `teme:types:import`; the lock, off by default.
2. The type builder screen: labels, segment, archive, single, taxonomies, icon; code types locked; the delete rule (I-412).
3. Per-type permissions (I-413): the six abilities created, granted, and shown on the roles screen.
4. Archives and lists for many types: `ArchiveType` replaced (§5.2); the home page and main feed setting (§5.2a); `{category}` generalised; feeds, sitemaps and search across types.

**2.2 Fields**
1. The full field contract (edit, render, sqlType, references, text) and **an editor that draws any field**; the built-in fields moved onto it.
2. The field builder on a type (§5.3).
3. Group, repeater (`{"v":1}`), multi-select, rich text, link and JSON (I-711).
4. The relation field with roles and notes, `content_relations`, and "what links here" (§5.1).
5. Filterable fields and `teme:fields:index` (I-416). (The I-710 packs follow 2.2 as their own small slice.)

**2.3 Your own taxonomies**
1. The taxonomy builder: labels, hierarchy, which types, public archives and base; term screens per taxonomy.
2. Pickers per taxonomy in the editor and lists, and the shared archives' addresses and feeds.

**2.4 Importing them**
1. Companion 0.3.0: lists of type and taxonomy definitions, ACF field groups and post meta values.
2. Custom post types and taxonomies become proposed types and taxonomies: accept, rename or skip on the choices step; their items imported.
3. ACF fields become proposed fields, mapped by ACF type (study Y §4.9); values imported.
4. Import extensions (§5.4) with the example cast and crew test extension; then the proof run (§5.5).

**Order:** 2.1 → 2.2 → 2.3 → 2.4, with parts in parallel where they don't share files (2.1·3 beside 2.1·4; 2.2·3 beside 2.2·4). Expected: 15 parts.

### 5.2a The home page and the main feed (*decided*, I-3298)

A setting, **"Types on the home page and in the main feed"**, listing every public type with an archive, **Posts ticked by default**, so every existing site behaves as today. Term archives always list every public type that uses the term (§5.2). *Reference check:* WordPress shows posts only on both (`class-wp-query.php:2610`); Ghost's feed carries posts only; Drupal shows content "promoted to the front page" (`views.view.promoted_content.yml`), a per-item tick, which Teme can add later if a site needs it. Trailing slashes keep following each type's own pattern, so no existing address moves.

### 5.2b A type made on a live site works at once (*decided*, I-3296)

A type, field or taxonomy saved in the builder is usable immediately, as with WordPress and CPT UI: its admin screens, lists, editor, public addresses and permissions, with no restart or deploy. *Design call for how:* admin and public routing resolve a type's segment against the registry on each request (reserved segments first, so `/admin/media` stays Media) instead of from route lists compiled at boot; settings groups read the registry when drawn; a registry version in the cache tells workers and other web processes to reload, as `queue:restart` does for code. `TEME_TYPES_LOCKED` stays available and is **off by default**; a site that wants changes only through `teme-types.json` switches it on.

### 5.6 The export file covers everything made on a screen (design call)

`teme-types.json` (version 1) holds admin-made **types, fields, taxonomies and per-type permission grants**, so a site built in staging moves to live whole. `teme:types:import` adds and updates what the file names; a type, field or taxonomy that exists only in the database is **left in place and reported**, never removed by an import (I-412 still governs removal). The lock, when on, covers all four.

## 7. Left to the implementation

Table and column names beyond `content_relations`; the builder screens' layout within the admin's existing patterns (designs are shown before building, as always); index names; the exact Companion route names.

## 8. Settled after review (2026-11-05)

1. **A type made on a live site works at once** (§5.2b): I-3296.
2. **A short note on each link, besides its role**, for every site (§5.1): I-3297.
3. **The home page and the main feed: a setting, Posts ticked by default** (§5.2a): I-3298.

**Design calls from the review:** links projected as today's projections are, in the transaction with draft and published scopes (§5.1); deleting a field's values keeps study E's dry run, export and forward write, and never erases past revisions (§5.3); the export file and the lock cover types, fields, taxonomies and permission grants, and an import never removes what only the database has (§5.6); per-request type routing and a cache version for workers (§5.2b).
