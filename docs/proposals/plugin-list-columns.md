# Proposal: plugin columns in the content lists

**Status:** draft for wave 3 of v1 (2026-11-16), scope item A6. **Decides:** the list-column surface of I-200 (adopted 2026-09-21: declared entries core draws, in fixed regions, with a budget; list columns sortable only when backed by an indexed value the plugin declares) and the last open line of I-204 ("columns from extensions"). **Plan:** `docs/plans/plugin-list-columns.md`. **Branch:** `plugins/list-columns`, from `main` after the v1 join merges.

## What it is

A socket in core: a plugin adds its own column to the admin's content lists (Posts, Pages and every other listed type), such as an SEO score, a translation status, a view count or an ad-placement flag. The plugin declares the column in its manifest and supplies plain values for one page of rows at a time; core draws the heading, the cells, the plug mark, the empty and failed states, the phone layout and each person's show and hide choice, so the column looks native and survives upgrades.

## Why

All five sites moving to Teme in v1 rely on a WordPress plugin that adds a list column today (SEO scores, link counts, image counts, ad flags). Without a socket, each of those plugins would have to build its own list page, a wall in I-3396's sense. Today's lists already carry an `owner` on every column and a `Later (I-200)` line where this socket goes.

## Settled

1. **Declared in the manifest.** `contributes.columns`: `id`, `label`, `kind` (`text`, `number` or `badge`), optional `width` (`narrow` 100, `medium` 128, `wide` 168 pixels) and optional `types` (absent means every listed type). At most 3 columns per plugin.
2. **`content.read` is required.** A manifest with columns and without `content.read` is refused when it is checked; at run time only a plugin whose consent grants it is asked.
3. **Values, never markup.** The contract (`Teme\Contracts\Lists`) is `HasListColumns::listColumns()` giving a `ListColumn` per declared id, and `ListColumn::cells(ListRows): array<rowId, Cell>`. A `Cell` is text, a number, or a badge with one of the Status pill's tones. Core removes control and direction-override characters, cuts text to 80 characters and badges to 24, and React draws everything as text.
4. **Asked once per page, only when shown.** Each column the person shows is asked once with every row of the page (at most 100), in the person's left-to-right order. A hidden column is never asked.
5. **A budget, and failure stays small.** A call over 100 ms, or one that throws, leaves its column empty and is recorded under the plugin with Teme's existing fault recording; once 300 ms are spent on a page, the remaining plugin columns are not asked. The list itself always renders, and one line above it says which plugins' columns couldn't be shown this time.
6. **Off until a person turns it on.** A plugin column is offered in Columns, with its plug mark, and is shown only once someone ticks it, the rule Featured image and Languages already follow (I-3120). The choice is saved through the existing preferences Operation. A disabled plugin's column drops out of everyone's list and the rest of their choice is kept.
7. **No sorting by a plugin column in v1.** Lists sort in the database by indexed columns of the content table, so a deep page costs the same as the first (I-140). A plugin's values live in its own tables or are worked out on the fly; sorting by them is WordPress's `wp_postmeta` `orderby`, the cost rule 2 exists to avoid. The manifest refuses `sortable` with a clear message.
8. **Accessible and phone-ready.** The heading carries the plug mark with "From {plugin}" for screen readers; numbers are right-aligned and tabular; an empty cell reads "No value"; badges carry their words, never color alone. On a phone, up to two shown plugin columns join the card's facts after the core facts.
9. **No core cache across requests.** The values belong to the plugin, and core cannot see when they change. Each column is asked once per request, and `ListRow::$updatedAt` lets a plugin key its own cache on each item's version.

## Provisional (built as written unless the maintainers say otherwise)

- P1. The budget numbers: 100 ms per call, 300 ms per page.
- P2. Three columns per plugin.
- P3. The fault line's words: "Some plugin columns could not be shown this time: {plugins}. The rest of the list is unaffected."

## Open questions for the maintainers

1. **Shown by default?** Recommended: no, in v1 (Settled 6). WordPress shows a plugin's column at once, and Yoast hides some of its own by default to reduce clutter. With this rule, a site owner who installs an SEO plugin has to tick its column once. The other option is a manifest flag `shown`, at most one per plugin, which would need the preference's default to know which plugins run.
2. **`@since` version.** The plan uses `0.9.0` if `v0.9.0` is not tagged when the builder starts, and `0.10.0` if it is. The v1 scope table names release 0.11.0 for wave 3, but the join of waves 1 and 2 is being released as 0.9.0, so that table's numbers seem to have shifted. (Unverified which release this part will ship in.) Separately: the insertion-point contracts carry `@since 0.8.0` although they ship with the join; this part does not change them.
3. **The budget numbers (P1).** They come from the view that a list page should not wait more than about a third of a second for plugins. No benchmark has measured them (unverified).
4. **With the page, or deferred?** Recommended: with the page. The 300 ms budget limits the wait, and deferring the values (Inertia's deferred props, used on the import screen) would run the list query a second time and flash a skeleton in every plugin column.
5. **Who sees a plugin column.** Recommended: anyone who may open the list. Plugins can't declare permissions yet, so a per-column permission would need its own socket.

## Not in this part (Later, each with what brings it back)

1. **Sorting by a plugin column.** Back when a plugin can declare an indexed value core may join, for example a typed, indexed column in the plugin's own table that a list can order by, and a site needs to sort by it.
2. **Plugin filters and the "From plugins" filter** (DS 4.26). Back with the first plugin that needs to narrow a list by its own value.
3. **Row actions and bulk actions from plugins** (DS 6.1). Their places in the "…" menu and the bulk bar are kept.
4. **Richer cells:** a link, a hint, an icon, or DS 6.1's "row of marks" (a link plugin's counts as pills). Back with the first plugin whose column can't be said in text, a number or a badge.
5. **Columns on Categories, Tags, Comments and Media.** Back when a plugin needs one there.
6. **A plugin's faults on a screen.** Faults go to the log today, as every plugin fault does. Back with the plugin fault screen.

## References checked

Every system in the reference folder where an extension adds list or table columns was read; where a system has columns but no way for an extension to add one, that is said.

- **WordPress** (`wordpress-develop-trunk/src/wp-admin/includes/class-wp-posts-list-table.php`, line 738, `manage_posts_columns`; `class-wp-list-table.php` lines 1344 and 1357, `get_hidden_columns()` and `manage_{screen}_sortable_columns`; `screen.php` `get_hidden_columns()`): a filter adds a column as an id and a heading (HTML), an action echoes each cell's HTML **once per row**, and sorting is a separate filter mapped to a query var. Teaches: per-person hidden columns (Screen Options) are worth keeping. Not copied: HTML headings and cells, and one call per row.
- **Yoast SEO 28.5** (`wordpress-seo/admin/class-meta-columns.php`; `src/integrations/admin/admin-columns-cache-integration.php`; `src/integrations/admin/link-count-columns-integration.php`): SEO score and readability columns, sortable through a meta query (`column_sort_orderby()`), some hidden by default (`default_hidden_columns`), and a cache filled from **the page's IDs** before the first cell, because WordPress asks per row. Teaches: the one-call-per-page batch (Settled 4), and why sorting by plugin data is costly (Settled 7).
- **Rank Math 1.0.278** (`seo-by-rank-math/includes/admin/class-post-columns.php`): SEO details, title and description columns; loads all of the page's SEO data on the first cell (`get_post_seo_data()`), and puts editable inputs in cells for its bulk-edit mode. Teaches: the batch again; editable cells are a separate feature, left out.
- **Polylang** (`polylang-master/src/admin/admin-filters-columns.php`, lines 53 to 64): one column per language, each cell a flag linking to the translation, on posts, media and terms. Teaches: a translation status column; Teme's core Languages column already covers it.
- **Craft CMS 5** (`cms-5.x/src/base/Element.php`: `EVENT_REGISTER_TABLE_ATTRIBUTES`, `EVENT_REGISTER_DEFAULT_TABLE_ATTRIBUTES`, `EVENT_PREP_QUERY_FOR_TABLE_ATTRIBUTE`, `EVENT_DEFINE_ATTRIBUTE_HTML`, `EVENT_REGISTER_SORT_OPTIONS`): attributes are registered apart from the defaults, a "prep the query" step lets an extension eager-load for the whole index, and sort options are registered separately and must map to a query column. Teaches: offered versus shown by default (Settled 6), the batch (Settled 4), sorting only on a queryable value (Settled 7).
- **Winter CMS** (`winter-develop/modules/backend/widgets/Lists.php`, `backend.list.extendColumns` at line 1048, `backend.list.overrideColumnValue` at line 1375, `invisible` columns): any plugin adds columns to any list and overrides a value per record; `invisible` columns are offered and off. Teaches: offered and off; per-record overrides are what the batch replaces.
- **Statamic** (`src/CP/Column.php`, `src/Http/Resources/CP/Entries/ListedEntry.php`, `src/Data/StoresComputedFieldCallbacks.php`): a column has `defaultVisibility`, `sortable` and `numeric`; an addon's computed field becomes a listable column, worked out per entry. Teaches: visibility and numeric alignment as column facts.
- **Filament 5** (`packages/tables/src/Columns/Concerns/CanBeToggled.php`, `toggleable(isToggledHiddenByDefault)`; `Columns/TextColumn.php`, `badge()` and `e()` escaping at line 216): text and badge columns, escaped by default, hideable and hidden by default. Teaches: text, number and badge are enough for most cells, escaped. A Filament plugin changes another resource's table only through global configuration, which is not a socket.
- **Strapi** (`packages/plugins/i18n/admin/src/contentManagerHooks/listView.tsx`, registered in `packages/plugins/i18n/admin/src/index.ts` through `Admin/CM/pages/ListView/inject-column-in-table`): the i18n plugin injects an "Available in" column, `sortable: false`, drawn by the plugin's own React cell. Teaches: an injected column is not sortable; the cell being the plugin's own code is the step Teme does not take (Settled 3).
- **Payload** (`packages/payload/src/collections/config/types.ts`, `defaultColumns`; `packages/payload/src/fields/config/types.ts`, `admin.components.Cell`): columns come from fields, with custom cell components; a plugin adds columns by changing the collection's config. Teaches: default columns as a separate list.
- **Sharp 10** (`src/EntityList/Fields/HasCommonEntityListFieldAttributes.php`) and **Twill 3** (`src/Services/Listings/TableColumns.php`): each list's own code defines its columns; no way for an extension to add one.
- **Emdash** (`packages/core/src/plugins/manifest-schema.ts`, the `admin` block): plugins declare admin pages, dashboard widgets, field widgets and editor panels in a manifest; **no list columns**. Teaches: declared admin surfaces, the model Teme's `contributes` follows.
- **Teme's own v9 demo** (`docs/prototypes/2026-09-22-admin-v9.source.html`, `COLUMNS` and `openScreenOptions()`): plugin columns (Images from a media counter, Links from a link plugin) marked with their plugin, grouped under "Columns from plugins", sortable when indexed, and a failing column shown as a dash with one line saying so. This proposal keeps the mark and the failure line, keeps the chooser's single ordered list from the content-lists part, and leaves sorting for later.

The other folders (Ghost, Drupal, Joomla, Wagtail, TypiCMS, October and the rest) were not read for this part beyond a search for a column-adding hook; October's list widget has the same `backend.list.extendColumns` event as Winter's (not read separately; unverified).

## Decided (the maintainers, 2026-11-16)

All open questions as recommended:

- Q1 a plugin column shown by default: no; each person turns it on.
- Q2 @since: the version the other new contracts carry when the part merges (the maintainers set it at the join).
- Q3 the 100 ms / 300 ms budget: measured by the builder and recorded; changed only with a reason.
- Q4 values with the page: yes.
- Q5 no per-column permission in v1.
