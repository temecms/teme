# Plugins

How plugins are built, installed, trusted and shown, how they store data and work with each other, and how they keep working across Teme versions. The decisions are I-036, I-200 and I-208 to I-214.

**Status.** This document is designed, not built. The plugin API arrives in phase 5, and nothing here is built before then, except that core already draws the public head, the three placements and the sitemaps through the registries that channels 1 and 2 and *Sitemaps* describe (*Plugins and themes*). What phase 1 must already respect is listed in [What phase 1 builds now](#what-phase-1-builds-now); everything else waits. When phase 5 builds it, this document becomes the description the code is checked against, like the other documents in this folder.

## The rules this follows

- **Teme is built to be extended, not to accommodate particular plugins.** Designs come from the reference systems. Existing plugins, whether first-party or third-party, are only a check that a kind of plugin *could* be built, and built better.
- **The name is *plugin*** (I-208). A plugin is a Composer package that adds to Teme. A theme is a separate thing (phase 4), though themes install the same way.
- **Declared before coded.** Anything a plugin adds that can be data is data in its manifest: content types, fields, cards, settings, permissions, jobs, routes, admin contributions. Core validates it, shows it, checks it and removes it exactly on uninstall.
- **Composition, never inheritance** (study C). A plugin implements Teme's interfaces and receives Teme's registrar and services. It never extends a Laravel class and never receives the container.
- **Every change goes through an Operation** (rule 1), for plugins too, which is why a plugin's Operation automatically gets a permission check, an API endpoint and an AI tool (spec §9).
- **The web process never writes code** (spec §3), including when someone installs a plugin from the admin.
- **Plugins are trusted code, and Teme says so.** PHP has no in-process isolation. Declared capabilities are a contract that core enforces at its boundaries, not a sandbox.
- **Core places plugin contributions; plugins do not grab space.**

## The package

A plugin is a Composer package of type `teme-plugin` with a `teme-plugin.json` manifest at its root. Core validates the manifest against its schema before loading any of the plugin's code, on every enable and every update. Teme starts no plugin code before an administrator enables it, and keeps every `teme-plugin` package out of Laravel's package discovery; but Composer includes any files a package (or one of its dependencies) declares under `autoload.files` on every request from the moment it is installed, and Teme cannot prevent that. Adding a plugin to the site's build is itself an act of trust (slice 5.1b, follow-up G2).

```json
{
  "schema": 1,
  "name": "acme/link-suggestions",
  "label": "Link suggestions",
  "version": "1.2.0",
  "teme": "^1.0",
  "adminApi": 1,
  "requires": { "acme/embeddings": "^2.0" },
  "capabilities": ["content.read", "content.write", "http"],
  "hosts": ["api.example.com"],
  "hooks": ["ContentSaved"],
  "provides": { "service": "Acme\\LinkSuggestions\\Contracts\\Suggestions" },
  "contributes": {
    "admin": [
      { "surface": "editor.pane", "id": "suggestions", "label": "Link suggestions", "icon": "link", "entry": "dist/pane.js" },
      { "surface": "list.column", "list": "content", "id": "links-out", "label": "Links out", "value": "fields.links_out", "sortable": true }
    ],
    "jobs": ["Acme\\LinkSuggestions\\Jobs\\IndexArticle"],
    "schedules": [{ "job": "Acme\\LinkSuggestions\\Jobs\\Reindex", "cron": "0 3 * * *" }]
  },
  "owns": { "tables": ["acme_link_index"] }
}
```

| Key | Meaning |
|---|---|
| `name` | The Composer package name, and the plugin's identity everywhere: ownership records, dependency references, hook ordering |
| `teme` | The Teme versions it supports. Outside them the plugin is `incompatible` (study C) and cannot be enabled |
| `adminApi` | The version of the admin interface contract its interface code was built against. A mismatch refuses enable |
| `requires` | Other plugins, each with a semver range. Composer resolves them at install; core checks them again at enable |
| `capabilities`, `hosts` | What it asks to reach ([Trust](#trust)) |
| `hooks` | Core hooks it subscribes to. Hooks carrying personal data require the matching capability, and a subscription without it is refused at registration (study C) |
| `provides` | A service interface it offers to other plugins ([Plugins together](#plugins-together)) |
| `contributes` | Entries for core's registries and admin surfaces; on the public site, region card types (with categories), overridable views (each with a `contract` version), assets keyed per output, and document fragments (capability-gated) — see *Plugins and themes* |
| `owns` | Tables it creates, so uninstall can list and remove them exactly |
| `imports` | What it brings across when Teme imports a WordPress site: its importer class and the WordPress options, post types with their meta keys, people's meta keys and terms' meta keys it reads, behind `import.wordpress` ([Bringing a plugin's data from WordPress](#bringing-a-plugins-data-from-wordpress)) |

The exact capability names and contribution kinds are fixed in phase 5. The manifest's shape is fixed now so that the admin screens built in phase 1 read entries of the same shape.

## Installing (I-036, I-211)

From the admin, without the web process ever writing code.

1. **Request.** An administrator chooses a plugin and version. The `RequestPluginInstall` Operation checks `plugins.install`, requires re-authentication (the elevation already used for sensitive settings), and records an **install request**: package, version, who asked, when. It writes no files. This is audited like every Operation.
2. **The installer** is a separate process running as a **different system user**, the one that owns the code. The web server's user can read the code but not write it. The installer picks up the request and:
   1. **stages** a copy of the release and runs `composer require` inside the copy (Drupal Package Manager's lifecycle);
   2. **verifies integrity**: Composer's lock hashes, and, once the repository exists, the checksum and provenance pinned in Teme's signed index;
   3. **validates**: manifest schema, Teme range, dependencies, admin API version, PHP extensions, disk space;
   4. **swaps releases atomically**: the new release directory becomes current, and the previous one is kept;
   5. **runs the lifecycle**: install migrations through study C's `installed` transition. It does **not** enable; enabling is the administrator's next step;
   6. **restarts the queue workers** and reports back to the request.
3. **Rollback** points `current` back to the kept release. Data is recovered separately, as study C and spec §11 already say.
4. **No installer configured.** The same screen shows the exact commands to run, as Statamic does, and the request waits until someone runs them.

Updates and removals use the same path. The repository (phase 6) is this path with a source: Teme publishes a **signed index** that pins every release's checksum and provenance, because Packagist signs nothing.

Hosts that cannot run a second process (shared hosting) use step 4. Shared hosting is already a non-goal.

## Trust

There is one tier: **every plugin runs in-process and is trusted code.** The consent screen says this in plain words before its list of capabilities.

Capabilities still matter, and core enforces them where it hands things out:

| Capability | What it unlocks |
|---|---|
| `content.read` / `content.write` | Content queries / content Operations |
| `media.read` / `media.write` | The media library / media Operations, including imports from outside |
| `people.read` | Users' and commenters' personal data, and the hooks that carry it |
| `mail.send` | The mailer |
| `http` | Core's HTTP client, limited to the manifest's `hosts` |
| `routes.public` | Routes reachable without signing in, including inbound webhooks |
| `site.output` | Adding scripts or markup to public pages |
| `ai` | Core's AI providers |

- A plugin receives **only the services its granted capabilities allow.** The rest are not handed to it (Emdash's model).
- **On every update** the new manifest is compared with what was consented. Anything wider (a new capability, a new host, a private route becoming public) blocks enable until an administrator consents again.
- This stops honest plugins from overreaching and keeps the consent screen truthful. **It does not stop a malicious plugin**, which can reach anything in the PHP process. The defences there are the installer's integrity checks, the future signed repository, and people choosing what to install.
- A **sandboxed tier** for untrusted authors is parked (I-213). The manifest is designed so a sandboxed plugin could use it unchanged.

## Lifecycle

Study C Decision 3 is the lifecycle: present → installed → enabled ⇄ disabled → uninstalled; `disabled` with a reason; `plugins` and `plugin_history`; safe routes; quarantine of failing listeners. This document adds only that **present** is reached through the installer, and that **consent is given when the install is requested, before anything is downloaded**. The agreement is recorded against that exact version, so enabling it does not ask again; an update whose access widens asks again before it is enabled (the 2026-09-21 prototype showed asking first is clearer).

**A plugin’s own tables run with enable and with “Run database changes”** (I-3442). Enabling runs the plugin’s waiting Laravel migrations inside that Operation’s transaction. When a new version of an already-enabled plugin brings new migrations, the plugin does not start until someone runs them — so its code never meets the old tables. The Plugins screen shows the reason and **Run database changes**; `php artisan teme:plugins:migrate` does the same for one plugin or every enabled plugin that is waiting. A deploy does not run them in this release. Removing the tables is offered only while the plugin is disabled or its code is gone (`Remove its data…`, `php artisan teme:plugins:remove-data`); the row and its consent stay.

**Console commands** that touch plugins today:

| Command | Help |
|---|---|
| `teme:plugins:cache` | Cache what Teme found in the installed plugins |
| `teme:plugins:clear` | Remove the plugin cache |
| `teme:plugins:enable` | Enable a plugin, agreeing to what it asks for |
| `teme:plugins:disable` | Disable a plugin |
| `teme:plugins:migrate` | Run waiting database changes for enabled plugins |
| `teme:plugins:remove-data` | Remove a disabled plugin’s database tables |

## Where plugins appear

Each task goes in the **smallest surface that fits it**. Small surfaces are data that core draws with its own components, so they look native and survive every upgrade. Larger ones are the plugin's own React, drawn inside core's frame.

| # | Surface | Drawn by | Budget |
|---|---|---|---|
| 1 | **Inline mark**: a list cell, a badge, an editor decoration | Core | Text, number, status or icon. Sortable only if backed by an indexed value the plugin declares |
| 2 | **Field input** | Core's field components, or the plugin's own | One field in a form |
| 3 | **Selection button** | Core | The toolbar holds core formats only. Plugin buttons go in the selection bubble's More group, and a person may pin one |
| 4 | **Menu entry**: row action, bulk action, Insert, More | Core | Two visible row actions per plugin; the rest under More |
| 5 | **Command** | Core | Unlimited, because the palette is searched |
| 6 | **Rail section** in the editor | Core, from fields and actions | Collapsed by default, and each person can hide it. It summarises and links out; it does not hold a workflow |
| 7 | **Pane** in the editor rail | The plugin | **One at a time.** It replaces the rail's sections, as the card inspector does. For work beside the text. Opened from the editor's top bar: up to three icons each person pins, the rest in its ⋮ menu (I-234, as Gutenberg) |
| 8 | **Dialog** | Core or the plugin | One task, one decision |
| 9 | **Workspace**: full screen, opened from the document, returning to it | The plugin | For work that needs the room. Opened from the editor's top bar like a pane (I-234). Its result comes back to the document as one undoable change (I-203) |
| 10 | **Page** | The plugin, or core from a schema | Reports, logs, tools, the plugin's settings. **In a hub by default** (Tools, Settings, Reports). A top-level menu entry only for a workspace people use daily, at most one per plugin, in a core section, within a site-wide cap (I-216, I-220) |

Other surfaces of the same kind: list filters and saved views; dashboard panels (in their own labelled section, I-210); pre-publish checks (the `PublishPreflight` gate, with its message drawn by core; core's own checks are one list in the editor, below); media-picker sources (I-201); settings groups (the settings registry).

**Core's checks in the editor are one list, `coreChecks()`** (`resources/js/components/editor/rail/checks.ts`; slice 1.9k, `docs/superpowers/plans/2026-10-08-phase1-slice09k-editor-search-and-social.md`, D1 to D17 are I-1193 to I-1209; D8 is I-1200). Each entry is a label and a plain fact, never a score, and carries `owner: 'core'`, the field every declared entry carries (I-200). The editor's Search and social section shows its search checks, and the rail's Checks and the confirmation before an item's first publication show all of them, in the same words; a warning never blocks (I-286). A plugin's checks join the list in phase 5, each with its own `owner`, and core draws them from the plugin's declared entry; the SEO plugin's panel, with its keyphrase and score, has its place in the section at a `// Later (I-286)` line.

**Rules for every surface**
- An entry declares its surface, label, icon, group, order, **when it applies** (content type, status, role, selection) and the permission it needs. Anything without a permission shows to nobody but administrators.
- Each surface shows a fixed number of entries and puts the rest under More. **The sidebar is the exception:** its overflow is the hubs, the command palette and each person's hiding and pinning, never a More section (I-220).
- People can hide and pin entries (preferences, slice 1.5f). Administrators set the defaults.
- Every plugin-drawn area is wrapped in its own error boundary. A failure draws a one-line notice naming the plugin, never a broken screen.

### The plugin's own interface code (I-212)

Surfaces 2, 7, 9 and 10 may use the plugin's own React:
- **Built with Teme's Vite preset**, which forces `react`, `react-dom`, `react/jsx-runtime`, `@inertiajs/react` and Teme's component library to be taken from the admin, never bundled. Two copies of React break hooks, and WordPress had to postpone React 19 because plugins bundled their own JSX runtime.
- **Shipped as built files** inside the package and published by `php artisan teme:plugins:publish`. There is no Node on the server and no rebuild of the admin.
- **Loaded on demand** from a list the server renders into the page.
- **Styles** go in a cascade layer below core's, with no reset, and read core's design tokens as CSS variables (Statamic's approach). This is why tokens stay CSS variables (`docs/architecture/admin-design.md`).
- **Versioning:** the React major is fixed for a Teme major. `adminApi` is checked at enable.

## Plugins and themes (I-228)

A plugin reaches the public site through exactly five channels, and never writes layout markup:

1. **Document metadata**: typed meta, link and JSON-LD entries (`DocumentMeta`), open to any plugin, with an allow-list of `rel` values. Duplicates resolve first-wins in the order plugin, site, core.
2. **Document fragments**: raw script or markup at three placements core owns (head, body start, body end), behind the `site.output` capability, deduplicated by key, `on*` attributes stripped. Core renders the three placements; a layout that omits them fails theme preflight.
3. **Cards** in content, placed by editors. A card is also drawn for feed readers, which run no scripts and load no styles: a plugin's card registers its feed version, a `FeedCardRenderer`, under the container tag `teme.feeds.cards` (`FeedCards::RENDERERS`) from its provider (I-920). A card with none gives its plain text as a paragraph, or nothing. A card may also describe itself to search engines by implementing `StructuredDataCard`, which gives nodes for its page's graph at `{page}#{card type}-{card id}` (`NodeIds::card()`, I-1137); no core card does yet.
4. **Region card types** in theme regions, placed by administrators. Regions accept categories, never a named list.
5. **Its own routes**, rendered with its own views.

**Channels 1 and 2 are built, internally** (`docs/architecture/themes.md`, *The head* and *Placements*), so the public head already has the shape a plugin will use and nothing about it changes when the plugin API arrives. Slice 1.9 built them in parts: 1.9a the entries, the contributors and the placements (`docs/superpowers/plans/2026-10-07-phase1-slice09a-foundation.md`, D1 to D19 are I-1010 to I-1028), 1.9d the feed versions of cards (`2026-10-07-phase1-slice09d-feeds.md`, D1 to D20 are I-1063 to I-1082), 1.9e site code (`2026-10-07-phase1-slice09e-site-code.md`, D1 to D11 are I-1083 to I-1093), 1.9f the sitemap providers (`2026-10-08-phase1-slice09f-sitemaps-and-robots.md`, D1 to D23 are I-1097 to I-1119), 1.9g the graph's filter and gate (`2026-10-08-phase1-slice09g-structured-data.md`, D1 to D20 are I-1120 to I-1139), 1.9i the verification tags (`2026-10-08-phase1-slice09i-search-and-social.md`, D1 to D17 are I-1156 to I-1172) and 1.9j the site's publisher (`2026-10-08-phase1-slice09j-who-publishes.md`, D1 to D20 are I-1173 to I-1192):

- **Channel 1** is `DocumentMeta` and its three typed entries, `MetaTag`, `LinkTag` and `JsonLdNode`, in `App\Domain\Site\Document`. A `HeadContributor` is registered by container tag from its provider's `register()` (I-1019) and says its `Precedence`: plugin, then site, then core, the first entry for a key winning, ties kept in registration order (I-1013). The `rel` allow-list is `LinkRelation`: `canonical`, `prev`, `next` and `alternate`, and never a relation that makes the browser load something (I-1014); `preconnect`, `dns-prefetch`, `me` and `license` wait for a consumer (I-890). JSON-LD nodes merge by `@id` into one graph written by one encoder (I-697, I-698, I-1015). Core's own head is drawn through it, so a plugin that wants another value for a key gives it at `Precedence::Plugin`. The site's own verification tags are a contributor of this kind at `Precedence::Site` (`VerificationTags`, on the home page alone): the screen refuses a name core writes itself, since the site's entry would beat core's.
- **The graph's filter** (I-697, I-1129): a `GraphFilter`, registered under `DocumentMeta::GRAPH_FILTERS` (`teme.structured-data.graph`), is given the merged graph and the `DocumentPage` and returns the graph it wants. Filters run once a page in `Precedence` order, plugin, then site, then core, each given what the one before returned, and what comes back is held to a node's rules, so the encoder can never fail on it. A filter is the only way to remove or rewrite another part's node; adding to one needs only an entry with the node's `@id`, and the fragments core's `@id`s end with are published as `Teme\Contracts\StructuredData\NodeIds` (`@since 0.1.0`, I-696), the first part of this channel to reach `teme/contracts`. Core has no filter of its own. After the filters, core tidies the graph and applies **the gate** (I-703, I-1127, I-1130): a node of a type Google gives a rich result, when it lacks a property Google requires, is left out with the references to it, and the reason is kept for the editor, whose check says it in words (1.9k). A pack for such a type adds its requirements with `RequiredProperties::add()`, each met by any one of the property paths it names; the vocabulary it writes it adds with `Vocabulary::register()` (I-1131).
- **The site's publisher** is core's node in this graph (I-708, I-1177; `PublisherNodes`, `docs/architecture/themes.md`, *The head*): an organization or a local business at `{home}#organization`, its logo at `{home}#logo`, the organization that owns it at `{the owner's website}#organization`, or a person at `{their author address}#person`, named as the WebSite's `publisher` and as every article's. A plugin that wants to add to one of them gives an entry with that `@id`, and one that wants to change it uses a `GraphFilter`. The kinds a site may choose from are core's closed list until the plugin API reads them from a registry (I-990). Two things stay a plugin's (I-1191):
  - **Several locations.** A plugin contributes each as a node of its own, `LocalBusiness` or a subtype, at an `@id` of its own on the site (`{home}#location-{slug}`), with `parentOrganization` naming `{home}#organization`, through a `HeadContributor` at `Precedence::Plugin` on the pages that show that location. Core's one business stays the site's publisher (I-991).
  - **An organization's policy links** (ownership and funding, masthead, ethics, corrections; I-726, parked). They are properties of the organization node, each a page chosen by ID with its address made at render (rule 9), so they need a setting type that names an item. Until there is one, a plugin adds them with a `GraphFilter`, or by merging into `{home}#organization`.
- **Channel 2** is `Placements`: a `PlacementContributor` gives keyed `Fragment`s at `Placement::Head`, `BodyStart` or `BodyEnd`, read with the same precedence, each written as given, after that placement's Blade stack (I-1018). Only an administrator's site code uses it today. A plugin's fragments will lose their `on*` attributes and JSON-LD scripts, behind `site.output`, at the `// Later (I-697)` line in `Placements::for()`; none is stripped before then, because no plugin can register one.

**Site code is the site's fragments, and core does nothing to them** (I-865). It reaches a page through channel 2 at `Precedence::Site`, and differs from a plugin's fragments in who writes it and what happens to it:

| | Site code | A plugin's fragment |
|---|---|---|
| Written by | An administrator, by hand, holding `site-code.manage` and confirming a password (I-288, I-864) | A plugin's code, behind `site.output`, granted when the plugin is enabled |
| Precedence | `Precedence::Site` | `Precedence::Plugin` |
| What core does to it | Nothing: kept exactly as written, `on*` attributes and JSON-LD scripts included. A JSON-LD script is flagged on the screen, never removed, since it would make a second graph | Strips `on*` attributes and JSON-LD scripts, once the plugin API is built |
| Why | Stripping breaks real analytics snippets and adds no safety: the administrator could paste any script | A plugin's output is reviewed by nobody on the site, so it is held to channel 1's typed rules where it can be |
| Audit | Each change in the security log with its before and after (`docs/architecture/security.md`) | The plugin's enable and its capability grant |
| The host's switch | `TEME_DISALLOW_SITE_CODE` draws none and refuses every change | Disabling the plugin |

**Sitemaps are a seam of the same kind, built internally** (I-1117). A `SitemapProvider`, registered under `SitemapProvider::TAG` (`teme.sitemaps.providers`) from a provider's `register()`, names its child sitemaps, finds where each page after a child's first starts, draws a page and says the page-cache tags the child carries. The index lists core's four (`HomeSitemap`, `ItemsSitemap`, `TermsSitemap` and `AuthorsSitemap`), then any other in registration order, and throws, naming it, when two children share a name or a name is not lower-case letters, digits and hyphens. A plugin's news or video sitemap, and `hreflang` alternates (I-306), come through it. Like `DocumentMeta` it is an internal interface until the plugin API publishes its contract.

Overrides run theme, then plugin, then core. Every overridable view declares a `contract` version; a theme override records the version it was written against, and a mismatch is flagged by preflight. Two plugins registering the same card or view are refused at enable. Plugin CSS loads only when its output renders, sits in the `plugins` cascade layer (`core < plugins < theme`) and uses `--teme-*` tokens; a theme may exclude a plugin's stylesheet by key and style that output itself. Themes carry no functionality: a theme that needs a feature declares `requires` on plugins, checked at activation and blocking. The full evidence is study P §8.

## Plugin data

- **Fields on content** are registered in the field registry and stored in the content row's JSONB (rule 2). Values are keyed by the field's ULID, so plugins never collide. A plugin's handles carry its prefix. A field whose plugin is disabled keeps its value and shows read-only.
- **Tables** (I-3442) come from Laravel migrations in the plugin’s `database/migrations` folder, each returning a migration as Laravel’s own do, read in name order. The plugin lists them under `owns.tables` in the manifest. The prefix is the plugin’s code with hyphens written as underscores, then `_` (`link-helper` → `link_helper_…`); every owned table name must start with that prefix and match `^[a-z][a-z0-9_]*$`, at most 63 characters. Each run records on the plugin’s row in `plugins.migrations` (a JSONB object: migration name → `{version, ran_at, tables}`), not in `plugin_history` — that broader, append-only history is parked with I-3442. Before and after each migration file, Teme lists the database’s tables: every new table must start with the prefix and be listed in `owns.tables`, and a dropped table must be one the plugin created; otherwise the run is undone. A migration that sets `$withinTransaction = false` is refused. `RemovePluginData` drops the recorded tables (without `CASCADE`), empties the record, and keeps the row and consent; it reads the tables from that record alone, so it still works when the plugin’s code is gone.
- **Ownership is recorded by the platform:** every registry entry (field, card, setting, permission, Operation, job, schedule, admin contribution) carries its owning plugin, or `core`.
- **Uninstall** lists exactly what goes, from those records, and classifies it as owned, contributed or derived (study C). It is refused while a card or field type the plugin provides is used in content, or while an enabled plugin requires it.
- **Secrets** use the `secret` setting type (`docs/architecture/settings.md`): encrypted, write-only, never cached, never sent to the browser.
- **Files a plugin writes** (exports, logs) go to private storage behind a permission check, never to a public folder.
- **Inbound webhooks** are a core route kind that requires a signature verifier. There is no way to declare one without it.

### Bringing a plugin's data from WordPress

A site moving from WordPress carries data that belongs to its plugins: a fediverse plugin's keys and followers, a form plugin's entries, a plugin's own settings. A Teme plugin brings its share across through the import, never on its own (`docs/proposals/plugin-import-socket.md`).

```json
"capabilities": ["import.wordpress"],
"imports": {
    "importer": "Acme\\Social\\Importer",
    "options": ["acme_actor_mode"],
    "postTypes": { "acme_actor": ["_acme_inbox", "_acme_following"] },
    "userMeta": ["acme_also_known_as"],
    "termMeta": []
}
```

- **Declared before read.** Only the names under `imports` are ever asked for: option names and meta keys of WordPress's shape (`^[A-Za-z0-9_\-:.]{1,191}$`), post types as WordPress names them, at most 50 names in all. `user_pass`, `session_tokens`, `_application_passwords`, `mailserver_pass`, and any name ending in `user_roles` or `capabilities` (core's roles and each person's, after any table prefix) are refused in any case by the validator, and again by the companion. `imports` needs `import.wordpress`, which the consent screen words with the declared names.
- **Core runs it; the plugin receives it.** The importer implements `Teme\Contracts\Import\WordPressImporter` and is built as the plugin's class is, with only what its granted capabilities offer. Three core phases do the work, for each enabled plugin holding the capability, in the order of their codes: `plugin-options` (540) hands the options over once a run; `plugin-data` (550), after core's people, terms and content and before the second pass, hands each record of the declared post types, people's meta and terms' meta to `record()` inside that record's own transaction with its map row (`plugin:<code>:<kind>:<type>:<id>`, I-332); `plugin-data-gone` (560) lists, on a catch-up, the records WordPress no longer has, and deletes nothing. `ImportScope` tells the importer what core's import made of a WordPress post or person.
- **Like core's lists:** a catch-up hands over only a record whose hash changed; a record the importer fails, or that throws, is reported with its sentence and the run goes on; the run's report has a row for each plugin. A serialized value arrives decoded, and one holding a PHP object as null, reported (rule 4).
- **Secrets it brings stay secret:** a plugin stores imported keys through the `secret` setting type, never in a plain column.
- The companion's side is `docs/specs/companion-extension-route.md`; it needs the companion's export API 2, and an older companion is reported and skipped. A plugin's own WordPress tables are not read in this version.

## Plugins together

- **Dependencies** carry semver ranges. A plugin cannot be disabled while an enabled plugin requires it. Losing a dependency disables its dependents with the reason `dependency_missing`.
- **Offering an API:** a plugin publishes a PHP interface in its own contracts package and registers an implementation under `provides`. Others request it by interface after declaring the dependency.
- **Plugin hooks:** a plugin may declare its own hooks as classes on the same dispatcher with the same five verbs (study C).
- **Chain, don't replace.** Transform and collect hooks chain in declared order. Where exactly one answer is needed (the SEO provider, the image generator, the spam classifier), it is a **provider slot**, and the administrator chooses among the installed providers. A lone provider is chosen automatically, and a choice that disappears is cleared and reported.
- **Shared registries instead of private conventions.** Content statuses, AI providers, mail providers, media sources and importers are core registries. A plugin adds to them or reads from them; it never depends on another plugin's option names or strings.

## Background work

- Jobs and schedules are declared in the manifest and run on Laravel's queue and scheduler (spec §5.7). Each is owned by its plugin, stops when the plugin is disabled, and is removed on uninstall.
- **Long work reports progress** through a core primitive: a batch with a total, a count done, a message and a cancel. It is shown on one core activity screen, so no plugin builds its own progress poller.
- Jobs change content only through Operations, so every change is an ordinary revision (I-203).

## Stability

- `teme/contracts` is the whole public surface (rule 5) and follows semver.
- **Interfaces never change within a major** (I-214). A new ability arrives as a new interface a plugin may also implement.
- **Deprecations name the version that removes them.** Each use is logged against the plugin that made it and shown on that plugin's admin page.
- **Experimental APIs are marked** and cannot be used by a plugin that declares itself stable.
- **Recovery** follows study C: safe routes that load no plugins, quarantine of failing listeners, and `degraded` shown in the admin.

## What phase 1 builds now

Only the shapes that would be expensive to change later. No plugin API, no manifest loader, no installer.

1. **Every admin surface is drawn from a list of entries** (I-200). Each entry has the fields a manifest entry will have: surface, id, label, icon, group, order, when, permission, and **owner** (always `core` in phase 1). This covers the editor's Insert menu, slash menu, selection bubble, rail sections and command palette (1.5c, 1.5f), and the list screens' columns, row actions and bulk actions.
2. **The rail shows its sections, or one pane.** The card inspector is the first pane and the AI panel (1.10) the second. The rail is built so a pane is a kind of occupant, not a special case (1.5c).
3. **Budgets and More are built in** where a surface has more entries than it shows.
4. **Card manifest entries carry `owner`** (1.5b).
5. **Design tokens stay CSS variables** (1.5a), because plugin interface code will read them.
6. **The public head, the placements, the graph's filters, the sitemaps and the feed versions of cards are registries that any part fills by container tag** (I-1019, I-1129, I-1117, I-920): `DocumentMeta::CONTRIBUTORS`, `Placements::CONTRIBUTORS`, `DocumentMeta::GRAPH_FILTERS`, `SitemapProvider::TAG`, `FeedCards::RENDERERS` and, for Search and social's sections, `SearchAndSocial::SECTIONS`. They are internal classes in `App\Domain`, not yet a published contract (see *Not built yet*).

Each carries a `// Later (I-200)`, `// Later (I-212)` or `// Later (I-697)` comment naming what phase 5 adds.

## Not built yet

Everything else, in phase 5, and the repository in phase 6:
- the manifest schema and loader;
- the installer and install requests;
- capabilities and consent;
- the Vite preset and `teme:plugins:publish`;
- provider slots;
- the activity screen for progress;
- the head's published contract in `teme/contracts` (`HeadContributor`, `MetaTag`, `LinkTag`, `JsonLdNode`, `GraphFilter`, `Placement`, `PlacementContributor`, `Fragment`, `FeedCardRenderer`, `StructuredDataCard`, `SitemapProvider`), which rule 5 keeps free of `App\` and `Illuminate` types; until it exists they are internal and may change in any release. Only `NodeIds`, the `@id` fragments of I-696, is published (1.9g);
- the stripping of a plugin's fragments, and their `site.output` check;
- the deprecation log;
- `owner` on the registries that exist before phase 5 (fields, settings, Operations), added then with `core` as the default so no existing registration changes;
- a sandboxed tier, parked (I-213).

Parked with a plugin’s own tables (I-3442), each with what brings it back:
- **Fuller safe mode** (the basic safe mode stays as it is). Back when a plugin locks someone out in a way the safe pages don’t cover.
- **Undo scripts** (a migration’s `down()`), and rolling a plugin back to an earlier version. Back when a plugin needs to go back a version without losing its data.
- **Deeper checks before removal**, such as refusing while content uses a card or field type the plugin provides, or while another plugin needs it. Back with the first plugin that provides a card or field type, or depends on another plugin.
- **A broader, append-only history** of every install, upgrade and removal (`plugin_history`). Back when someone needs to see a plugin’s past beyond what its row records.
- **Removing a plugin’s settings and field values** with its tables. Back with the deeper checks above.
- **Running plugins’ changes during `teme:deploy`.** Back when an updated plugin waiting for its changes after a deploy becomes a real nuisance on a live site.
- **Migrations outside a transaction.** Back with the first plugin that needs one.
