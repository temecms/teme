# Content types and taxonomies

What a kind of content is, what a kind of term is, and how the rest of the code asks about them instead of naming them. The decisions are I-410 to I-417. This document describes the system as built in part 1.5·0, with the `schema` entry slice 1.9g added (*What a type's items are as structured data*) and the types a site makes for itself that slice 2.1 added (*Types made on the site*), and the code is checked against it.

## The rules this follows

- **`post`, `page`, `category` and `tag` are declared once, in `App\Domain\Types\CoreTypes`.** Nothing else in `app/` decides behaviour by naming them, and nothing in the admin's TypeScript does either. `tests/Architecture/NoTypeLiteralsTest.php` enforces it; it skips only `CoreTypes`, the benchmark (which seeds sample data) and generated code.
- **Phase 2 adds rows, not code.** A new type or taxonomy is a registry entry, and it gets content rules, term rules, abilities, built-in fields and admin screens from its declaration. Since slice 2.1 a type can be a row in the database instead of a line of code (*Types made on the site*); a taxonomy is still code's.
- **The registries are internal and unversioned until the plugin API (phase 5)**, following the field registry's precedent. Nothing is in `teme/contracts` yet.
- **Nothing a person sees changed, with one exception: pages have no categories or tags (I-417)**, as in WordPress.

## The two registries

`ContentTypeRegistry` and `TaxonomyRegistry`, in `App\Domain\Types`. `TypesServiceProvider` binds a fresh pair and registers `CoreTypes` into it; it runs directly before `ContentServiceProvider`, whose field registry reads the types. `ContentTypeRegistry` holds code's types first and the site's own after them, read from the database when first asked for (*Types made on the site*).

**Consumers resolve a registry when they need it** (`app(ContentTypeRegistry::class)`), never in a constructor. That keeps a type registered late — by a plugin, in phase 5 — visible everywhere, and lets a test bind fresh registries with `typesFresh()`.

Both registries answer `get()` (which throws `UnknownContentType` or `UnknownTaxonomy`), `has()`, `all()` in registration order, `keys()`, `bySegment()` and `segments()`. `TaxonomyRegistry` also answers `forType()` — a type's taxonomies, in the order the type names them — and `typesUsing()`. `ContentTypeRegistry::ability($type, $action)` gives the ability governing an action on a type, or the global default for a type nobody registered. `fromCode()` answers the types code registered without reading the stored ones, `refused()` the stored ones left out and why, and `stamp()` a value that changes whenever the registered types do.

### What a content type declares

| Property | Meaning | Default |
|---|---|---|
| `key` | Stored on every row; `^[a-z][a-z0-9_]{0,31}$` | — |
| `singular`, `plural` | Labels | — |
| `segment` | Its admin list URL, `/admin/{segment}` | — |
| `public`, `archive`, `hierarchical`, `single`, `searchable` | Whether it has public pages; whether it has an archive page of its own and its items join author and date archives (*Archives across types*, *A type's archive page*); whether its items nest (unused so far); whether it holds one item only (*One item only*); whether search finds its items | `true`, `false`, `false`, `false`, `true` |
| `taxonomies` | The taxonomy keys it may use, in the order the editor shows them | none |
| `supports` | Features, which decide its built-in fields: `seo`, `excerpt`, `featured_image` | none |
| `abilities` | Action ⇒ ability name, for any of `ContentType::ACTIONS` it overrides (I-413) | the global `content.*` set |
| `listed` | Whether it has the standard admin list screen and a sidebar entry | `true` |
| `icon` | A Lucide icon name for the sidebar | `file` |
| `pattern` | Its default public address shape, which 1.6a turns into a setting | `/{segment}/{slug}/` |
| `permissions` | `shared` or `own`, for a type made on the site: whether it uses the global `content.*` set or has six of its own (*A type's own permissions*). A type code declares names its six through `abilities` instead | `shared` |
| `comments` | Whether its items take comments, and how a new one starts: `Discussion::Open` or `Discussion::Closed`; null, none (I-274, 1.8a's D7). Each item's own is `contents.comment_status` (`docs/architecture/comments.md`) | `null` |
| `schema` | What its items are as structured data says it: a `TypeSchema` (below), checked when it is made (I-709, I-1128) | `null`: a web page with no main entity |

### What a taxonomy declares

| Property | Meaning | Default |
|---|---|---|
| `key` | Stored on every term; `^[a-z][a-z0-9_]{0,31}$` | — |
| `singular`, `plural`, `segment` | Labels and its admin screen, `/admin/{segment}` | — |
| `hierarchical` | Whether its terms nest (I-414) | `false` |
| `public` | Whether it has public archives (1.6) | `true` |
| `ability` | The ability that manages its terms | `terms.manage` |
| `icon` | A Lucide icon name for the sidebar | `folder` |
| `base` | Its default public address base, which 1.6a turns into a setting | its key |

### What core registers

| Type | Segment | Taxonomies | Supports | Comments | Icon |
|---|---|---|---|---|---|
| `post` | `posts` | `category`, `tag` | `seo`, `excerpt`, `featured_image` | Open | `file-text` |
| `page` | `pages` | none (I-417) | `seo`, `excerpt`, `featured_image` | Closed | `files` |

Pages answer to their own six permissions, `page.create` to `page.delete.any`, which `CoreTypes` names through `abilities` (`TypePermissions::names('page')`); posts keep the global `content.*` set (I-3313).

| Taxonomy | Segment | Hierarchical | Icon |
|---|---|---|---|
| `category` | `categories` | yes | `folder` |
| `tag` | `tags` | no | `tag` |

1.6e will register `region`, unlisted: regions have their own screen, Content › Regions.

### What the registries refuse

`register()` throws, naming the problem, for:

- a key that doesn't match the pattern above, or one already registered;
- a segment that isn't lower-case letters, digits and hyphens, starting with a letter, at most 40 characters;
- **a reserved segment**, for a listed type or a taxonomy — the ones that get admin routes. `ContentTypeRegistry::RESERVED` holds the fixed admin paths (`dashboard`, `media`, `users`, `settings`, `account`, `terms`, `content`, `preview`, `search`, `quick-draft`, `navigation`, `regions`, `comments`, `tools`, `themes`, `plugins`, `ai`, `inbox`, `my-jobs`), the builders' (`types`, `taxonomies`), Fortify's (`login`, `logout`, `user`, `two-factor`, `two-factor-challenge`, `email`, `forgot-password`, `reset-password`), and `api`, `up`, `invitation`, `setup` and `register`. A part that adds a fixed admin screen adds its segment here, and a test holds every fixed route's first segment to this list;
- a segment another type or a taxonomy already has, so no two can claim one URL;
- an ability for an action that isn't in `ContentType::ACTIONS`;
- comments on a type that isn't public, since comments are shown on an item's own page;
- a key that starts a permission's name, such as `settings` or `content`, since the type's own six would then be named like a permission that exists (`TypeRules`, *A type's own permissions*);
- a type whose new items start Hidden (I-572): on an item with no comments yet Hidden looks the same as Closed, so Hidden is chosen item by item.
- a listed type's or a taxonomy's plural that is an admin screen's name, compared without case (`AdminScreenNames`): the fixed screens, every entry the admin menu registers, and the other listed types' and taxonomies' plurals. The words name the screen and suggest a name, the singular with "directory" after it. `TypeRules` and `TaxonomyRules` refuse it the same way for a type or taxonomy made in Settings › Types, imported or brought by a plugin, only when the plural is new or changed, so one already stored with such a name keeps working, and Settings › Types shows it a warning asking to rename it.

**`TypesServiceProvider::verify()` runs at boot, in every environment**, once everything is registered: every taxonomy a type names exists, every feature a type supports is one some field provides, and no type has two fields with one handle. A misconfigured type is a deploy error, not a warning. **`TypeSchemas::verify()` runs beside it,** from `JsonLdServiceProvider::boot()`, and refuses a type whose `schema` names a term off the structured-data vocabulary or maps a property from a field the type doesn't have (*What a type's items are as structured data*).

## Content and term rules

- **`CreateDraft` accepts any registered type** and refuses an unknown one. Authorization runs before validation, so `ContentPolicy::create($user, $type)` judges an unknown type by the global `content.create` and validation then refuses it.
- **`ContentPolicy` asks the item's type for every ability** — create, edit own and any, delete own and any, publish. `UpdateContentDetails` asks the same way before an author change. `CreateDraft` asks the type's `create`, so a page is started with `page.create` and a post with `content.create`.
- **A term an item is given must be in a taxonomy its type uses** (`TermRules::usableBy()`). `SaveDraft` and quick edit apply it only to newly added terms, as they already did for their existing-term check, so a page whose old revision still lists a tag can still be saved.
- **`TermProjection` projects only terms of a taxonomy the type uses.** A page's old revisions may still list tags; they stay as written, and are never projected again.
- **The term rules come from the taxonomy registry**: the taxonomy must be registered, only a hierarchical taxonomy's terms have parents, and a parent must be of the same taxonomy. Each term Operation authorizes with the taxonomy's `ability`. The two `CHECK` constraints that used to hold these rules (`terms_taxonomy_check`, `terms_parent_categories_only`) are gone: a `CHECK` can't know which taxonomies a plugin will register.
- **Pages' term links were removed when 1.5·0 migrated** (`2026_09_29_100000_open_taxonomies`), and the term counts recomputed. The links can't be restored by rolling the migration back.

## Features and fields

A field applies to a type when the type is in the field's `contentTypes`, **or** the type supports the field's `feature`. The built-in fields name no type:

| Feature | Fields |
|---|---|
| `seo` | SEO title, meta description, canonical address (an item or another site's address, I-1039), hide from search engines, social title, social description, social image, don't show a snippet, snippet length, don't keep a cached copy, primary category (a term, I-1040), page type and article type (what search engines are told the item is, I-1132) |
| `excerpt` | Excerpt |
| `featured_image` | Featured image |

**Field keys are unique across the site; handles only per type**, so two types may each have a field called `price`. `FieldRegistry::register()` still refuses a duplicate key; `FieldRegistry::verify()` refuses two fields with one handle on the same type. `teme:docs:fields` writes one section per type, in key order.

## What a type's items are as structured data

A type's `schema` entry (`TypeSchema`, in `App\Domain\StructuredData`) says what search engines are told its items are (slice 1.9g, `docs/superpowers/plans/2026-10-08-phase1-slice09g-structured-data.md`, D1 to D20 are I-1120 to I-1139; the entry is D9, I-1128, from study Z, I-709). It holds:

- **`pageType`:** the kind of page each item is drawn on, one of `WebPage`, `AboutPage`, `ContactPage` and `CollectionPage` (`TypeSchema::PAGE_TYPES`);
- **`mainEntity`:** what the page is about, or null for nothing, such as a `BlogPosting` (`TypeSchema::ARTICLES` are `Article`, `NewsArticle` and `BlogPosting`, which core describes as articles);
- **`mainEntities`:** the main entities an item of the type may choose instead;
- **`properties`:** a map from a schema.org property to an ordered list of field keys (I-1133, below).

`CoreTypes` declares posts as a `WebPage` whose main entity is a `BlogPosting` and which may choose any of the three article types, and pages as a `WebPage` with no main entity that may choose the same three. A public type that declares none is a `WebPage` with no main entity (`TypeSchemas::for()`), so its items still describe themselves. The entry is checked when it is made: a page type that is not on the list, a main entity not among `mainEntities`, or a name that is not a schema.org name throws.

**Each item may choose** (I-1132): two built-in `select` fields with the `seo` feature, so whoever may edit an item sets them: `page_type` (the four page types) and `article_type` (the three article types, or `none`, "Not an article"). Empty is the type's default. An `article_type` outside the type's own `mainEntities` is ignored, so a type's narrower list holds; a `page_type` is held to the four page types, and no type narrows them. They are stored in the item's revision with the other fields (rule 2). `TypeSchemas::pageType()` and `mainEntity()` apply them.

**The site may choose for each type** (slice 1.9j, `docs/superpowers/plans/2026-10-08-phase1-slice09j-who-publishes.md`, D1 to D20 are I-1173 to I-1192; this is D14, I-1186): Settings › Search and social has a section, Page and article types, with a page type and, where the type offers main entities, an article type for each public type, set to what the type declares (`docs/architecture/settings.md`). `TypeSchemas::for()` puts that choice before the type's own entry, so a news site makes its posts `NewsArticle` without code. A stored choice the type doesn't offer, as an import could leave, keeps the type's. An item's own choice still comes first, and what the editor's Advanced fold calls "Default for its type" is this answer (`EditorSearchAndSocial`, slice 1.9k).

**The property map** (I-1133) fills the main entity, or the page node of a type with none: the first field whose value converts to something replaces core's value for that property, and when every field is empty core's stands. Each field type converts its own value, through `FieldType::structuredData()`: text and textarea as trimmed text; a number as a number; a boolean as itself; a URL trimmed; a datetime with the site's offset; a select as its value; an entry as its target's live address; a media field as an ImageObject; a term as its name; and a canonical choice as nothing, being where search engines go rather than a fact about the item. Fields are named by their keys, never by template strings.

**`TypeSchemas::verify()` runs at boot** and refuses a type whose entry names a term off the vocabulary or maps a property from a field the type doesn't have, since a typo there would otherwise fail silently, page after page. The vocabulary is `Vocabulary`, a committed list of the schema.org types and properties Teme may write (`VERSION` 30.1, I-1131), a test fails if a page core draws uses a term off it, and `teme:structured-data:check {release}` compares the list with schema.org's release file, named by the path it was saved at, naming each term the release lacks or has superseded. A pack adds its own terms with `Vocabulary::register()`. The publisher's terms (the twelve kinds, `PostalAddress`, `GeoCoordinates`, `OpeningHoursSpecification` and the properties they use) are on the list too, and the check was run against release 30.1 (I-1192).

## Term counts per type

`term_counts(term_id, type, published, working)`, primary key `(term_id, type)`, both counts `>= 0`. `TermProjection` moves it by the same deltas it applies to `terms.published_count` and `working_count`, in the same fixed term order, so two Operations can't deadlock. **`terms.published_count` stays the total across types**, so nothing that read it changed. `TermCounts` reads a term's counts per type; `teme:counts:rebuild` checks and fixes them with the rest.

A count that goes down is applied with a plain `UPDATE`, never through the upsert: PostgreSQL checks the `CHECK` on the row an upsert would insert before it finds the conflict, so a negative delta fails there even when the row exists.

## The admin

- **Taxonomies' routes are compiled from the registry's segments at boot**, as an explicit list: a catch-all `{taxonomy}` would capture `/admin/media`. So a taxonomy registered inside a test has no admin route.
- **A type's routes are found on each request** (`routes/admin.php`): one group matches `{type}` against `ContentTypeRegistry::segmentPattern()`, which is any segment a type could have except the admin's own `RESERVED` ones, built from constants so a cached route file keeps it. A type made a moment ago has its screens at once, with no route cache to rebuild (I-3296). The group asks the gate `TypeAccess::OPEN_LIST` with the URL's segment, which answers the type's own `create` ability, or `content.create` for a segment no type has, whose screens then answer 404.
- **The list screen resolves its type with `bySegment()`**, filters on each taxonomy the type uses under the taxonomy's key (so `?category=…&tag=…` works as before), and passes `typeTaxonomies` and `labels` to the page. Its pickers, columns and bulk term actions loop over `typeTaxonomies`; a type with none, such as `page`, shows none.
- **An item acted on through another type's address answers 404**, and a bulk action skips it item by item as "Not a {singular}".
- **Every signed-in page shares `contentTypes`** (the listed types) **and `taxonomies`**, which the sidebar draws in registry order. A type's or taxonomy's `icon` is looked up in `resources/js/lib/nav-icons.ts`, with a generic icon for an unknown name.

## Types made on the site

Slice 2.1 (`docs/superpowers/plans/2026-11-05-phase2-slice21a-types-in-the-database.md` and the five parts after it; I-411 to I-415, I-3296 to I-3313) lets a site define its own content types without code. Code's types stay as they were; a stored type is registered after them, carries `stored` on its `ContentType`, and is changed only through its Operations.

**The table.** `content_type_definitions` (`database/migrations/2027_01_27_100000_create_content_type_definitions_table.php`): `key` (the primary key, held to the same pattern as a code type's), `definition` as JSONB with the other properties, and timestamps. A `CHECK` holds the definition to an object with a text `segment`, and a unique index on the segment keeps two types off one admin address. It is one row per type with typed values in one JSONB column (rule 2). `TypeDefinition` (`app/Domain/Types/TypeDefinition.php`) is the version 1 shape of that value: the labels, segment, icon, `single`, `public`, `archive`, `searchable`, `taxonomies`, `supports`, `comments`, `pattern` and `permissions`. Fields and taxonomies can't be stored yet; they wait for 2.2 and 2.3.

**How it is read.** `StoredTypes` (`app/Domain/Types/StoredTypes.php`) keeps the table's rows in the cache store as one copy with a version, which every process reads instead of the table. A change puts a new copy after it commits, under the types' advisory lock, so two changes' copies land in the order the changes committed; a copy lost or stale mends itself within five minutes. `ContentTypeRegistry` reads it when first asked, and again whenever the copy's version differs (`refreshStored()` before each queued job). What is built from the registry follows its `stamp()`: the settings groups derived from the types (`SettingsRegistry::derive()`, `docs/architecture/settings.md`) are built again, so a type made a moment ago has its address, discussion and title settings at once. The preference definitions for a list's columns are not rebuilt this way, so a worker started before a type was made lacks that type's `lists.{type}.columns` definition until it restarts.

**What it refuses.** `TypeRules` checks a stored type as `register()` checks code's: key, labels, segment (not an admin screen's, not another type's), icon, taxonomies and features that exist, comments, archive and search only on a public type, and the address pattern. A stored type whose key or segment clashes with a code type's, or whose taxonomy or feature is gone, is left out of the registry and listed by `refused()`; it is never thrown at boot. Status shows the `StoredTypesRefused` banner (error, priority 80) with a link to Site › Types (`app/Domain/Status/Checks/StoredTypesRefused.php`).

**Four Operations** (`app/Domain/Types/Operations`) change them: `CreateType`, `UpdateType`, `DeleteType` and `ImportTypes`. Each takes the types' lock first. They authorize `types.manage` (administrators; it reached existing sites through `2027_01_27_100100_add_types_manage_permission.php`) or the operator at the server, and refuse everyone while `TEME_TYPES_LOCKED` is on (`TypeAccess::locked()`). They follow I-412: a type with items can't be deleted, items in the trash counted; its key can't change; whether it is public can't change once it has items; and a taxonomy can't be taken off a type while its items use that taxonomy's terms. Each writes nothing when authorization fails. After the commit a change refreshes the stored copy and purges every stored page. `CreateDraft` and `SaveDraft` take no lock on the types, so a draft made as its type is deleted can outlive it for a moment; Status would list such items.

**The file.** `teme-types.json` (`TypesFile`): `{"format": "teme-types", "version": 1, "types": [...], "fields": [], "taxonomies": [], "grants": [...]}`. `php artisan teme:types:export {--output=}` prints the site's own types as that file, or writes it, and `php artisan teme:types:import {file}` adds and updates what it names through `ImportTypes`, as the operator. It never removes a type, keeps a site's own address pattern for a type already there, and reports what it left in place. A file that holds fields or taxonomies, or a version this Teme doesn't read, is refused whole. Code's types are never in the file.

**The lock.** `TEME_TYPES_LOCKED` (`config('teme.types_locked')`, false by default) makes the builder read-only for every person: a site that changes its types only by file sets it and brings them with `teme:types:import` (`docs/architecture/security.md`).

**What an export doesn't carry.** `teme:export` writes content, people and media, not type definitions, so a site rebuilt from it needs `teme-types.json` beside it. Backups keep the table.

## Site › Types

`/admin/types` (`TypesController`, `TypesScreen`, `resources/js/pages/types/index.tsx` and `show.tsx`; I-3302) lists code's types, marked as built into Teme and read-only, and the site's own, with any refused one named and why. It sits under Site in the menu and needs `types.manage`. A type's page shows its labels, admin address, icon, what it supports, its taxonomies, its public and search switches, **Has an archive page**, one item only and, for a type that takes comments, whether its new items start open or closed. A stored type's comment choice lives on the type itself and no longer in Settings › Discussion. The type's public address is changed in Settings › Addresses, which the page links to. The page saves through `CreateType` and `UpdateType`, deletes through `DeleteType` with the count of items that block it, and offers the file as a download (`/admin/types/teme-types.json`). While the lock is on every control is read-only and a notice says why.

**The icon picker** offers every Lucide icon. The list of names is a lazy chunk (`iconNames` from `lucide-react/dynamic`), loaded with the picker and with the first icon the sidebar can't find, so the app's entry chunk doesn't carry it. The sidebar draws any stored name through `DynamicIcon`; a name Lucide no longer has draws a circle.

**A mended type.** A stored row that no longer parses can still be opened: `UpdateType` reads it as far as it can (`StoredTypes::findLeniently()`), and saving writes it right again.

## One item only

`single: true` (I-415) lets a type hold exactly one item. `CreateDraft` refuses a second, one in the trash included, naming the first ("There can be only one Film, and it already exists."). For a stored type it reads the type's row under the types' lock taken shared, so a change to `single` is seen at once, and a single type's drafts queue on a lock of their own. The type's list (`ContentListController`) opens the one item in the editor for someone who may edit it, and `canAdd` hides **Add new** once the item exists.

## A type's own permissions

A type made on the site has `permissions: shared` (the global `content.*` set) or `own`. **Own** gives it six permissions named from its key by `TypePermissions::names()`: `{key}.create`, `{key}.edit.own`, `{key}.edit.any`, `{key}.publish`, `{key}.delete.own` and `{key}.delete.any` (I-413). Saving a type as own creates them and gives each role what it holds of `content.*` now, so nothing changes until someone changes it; saving it as shared, or deleting it, removes them. `TypePermissions` writes with the query builder inside the Operation's transaction and forgets Spatie's cached list after the commit. A process that cached the old list before the forget can lag, up to 24 hours (`config/permission.php`), until it reads again; saving the type again mends it. Keys that start a permission's name (`settings`, `content`) are refused, so a type's six never collide with an existing permission.

`ContentPolicy` and the `{type}` admin routes ask `ContentType::ability()`, so nothing reads `content.*` for a type that has its own. The type's page lists the six and which roles hold each. `teme-types.json` carries each own type's grants, a role with the abilities it holds, and an import applies them while keeping a site's own grants for a type already there. Roles can't be edited in the admin yet, so grants change only by the file. The comment emails ask only for the permissions Spatie can find (`CommentRecipients`).

**Pages are the first code type with its own six** (I-3313): `CoreTypes` names `page.create` to `page.delete.any` through `abilities`. Administrators hold all six and editors all but `page.delete.own`. `RoleSeeder` lists them for a new site, and `2027_01_31_100000_add_page_permissions.php` adds them to an existing one, never by re-running the seeder. Authors and contributors no longer start, edit, publish or delete pages, those they wrote before included, as in WordPress; such pages keep their author's name and editors look after them. Posts keep `content.*`. A page's author picker still lists everyone who may write (#270).

## Archives across types

`ListingTypes` (`app/Domain/Addresses/ListingTypes.php`; I-3298) says which types each listing holds, asked of the registry each time. A term's archive and feed hold every public type that uses its taxonomy, ticked or not; an author's and a date's archive hold the public types that have an `archive`; the home page, the posts page and the main feed hold the types Settings › Reading ticks among those. That setting is `reading.home_types` ("Types on the home page and in the main feed", a multiselect, posts ticked by default), in a `reading` group that is derived from the types (`ReadingSettingsGroup`). Unticking a type's `archive` takes it off the home page at once; its stored tick drops at Reading's next save. Feeds and the sitemaps (`TermsSitemap`, `AuthorsSitemap`, `HomeSitemap`) read the same lists. `{category}` comes from each type's first hierarchical taxonomy (`PrimaryCategory::taxonomyFor()`), and only that taxonomy's terms move items' addresses.

## A type's archive page

A public type with `archive`, other than the first, has a listing page of its own (I-3304), at one word set in Settings › Addresses as **{Plural} archive page** (`archive_{type}` in the `addresses` group, defaulting to the first fixed word of the type's pattern, else its admin segment). The first such type, posts on every site so far, has none: its list is the home page or the posts page. `docs/architecture/addresses.md` has the resolution order, the checks and the old addresses; `docs/architecture/themes.md` the template (`archive-{type}`); `docs/architecture/page-cache.md` what a page and its feed depend on. Each page has a feed at its address followed by `feed/`, an entry in the sitemap's `archives` child, and a title template and a hide box in Settings › Search and social (`title_archive_{type}`, `hide_archive_{type}`).

## What phase 2 adds

From study Y §4.2 to §4.9, on these seams (the first four are built; the rest are 2.2 to 2.4):

- **Who declares a type (I-411):** code and the admin builder, into one registry; a code-declared type is locked in the admin; admin types export as one versioned file; a production lock makes the builder read-only. *Built in 2.1.*
- **Changing and removing a type (I-412):** a type with items can't be deleted; its key can't be renamed, its labels and segment can. *Built in 2.1.*
- **Per-type permissions (I-413):** a type names its own six abilities through `abilities`; one that names none keeps the global set. *Built in 2.1.*
- **Custom taxonomies (I-414):** a registry entry, not new storage; the editor draws one picker per taxonomy the type uses.
- **Single-instance types (I-415):** `single: true` allows exactly one item. *Built in 2.1.*
- **Filtering and sorting on a field (I-416):** only a field marked filterable, with its expression index built by `teme:fields:index`.
- **Nested data:** repeaters one level deep, their stored shape versioned.
- **The WordPress import of custom types and ACF (2.4):** proposed types and fields for the operator to accept.

## Not built yet

- Registering `region` (1.6e).
