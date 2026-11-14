# Content management

How editors find, filter, change and remove content, and how that stays fast at a million items. The decisions are ideas I-140 to I-148. This document describes the system as built in slice 1.3, and the code is checked against it.

**Types (1.5·0):** posts and pages, categories and tags are registry entries, not names in the code. What a type and a taxonomy declare, and how the rules below read them, is in `docs/architecture/content-types.md`. Where this document says "posts and pages" or "categories and tags", read "each content type" and "each taxonomy"; since I-417, pages use no taxonomy.

## Lifecycle

A content item is **live** or **trashed**. A live item has one of three statuses:

| Status | Meaning |
|---|---|
| `draft` | Never published, or reverted to a draft |
| `scheduled` | Waiting to publish at `scheduled_for` (scheduling itself arrives in 1.5) |
| `published` | The public site renders its published revision |

**Trash is not a status.** Trashing sets `deleted_at` and leaves `status` alone, so restoring puts the item back exactly as it was. WordPress restores to *draft*, which surprises editors.

A `CHECK` constraint limits `status` to the three values.

**Visibility sits beside the status, never in it** (I-1700, I-2770). `contents.visibility` is `public`, `private` or `protected`, Public by default. A draft can carry it ahead, a schedule keeps it, and a trash and a restore keep it, so no Operation that changes the status needs to know it. **Private:** a published private item is read only in the admin, by whoever may edit it; on the site it has no address and appears nowhere (`docs/architecture/addresses.md`). **Password protected:** it keeps its address and its place in lists, and readers give its password to read it (`docs/architecture/security.md`, *Password-protected items*). The password is kept in `contents.reader_password`, encrypted with the application key, only while the item is protected; a check ties the two columns together. WordPress made `private` a status; Teme closes it at the source instead: no address, no published term rows, a count bucket of its own, and every public query saying `visibility <> 'private'`.

## The Operations

Every change is an Operation, one item at a time (rule 1).

| Operation | Does | Authorised by | Slice |
|---|---|---|---|
| `CreateDraft` | New draft with its first revision. A type with one item only refuses a second, one in the trash included (I-415) | the type's `create` ability: `content.create`, `page.create` for pages, or a stored type's own (`docs/architecture/content-types.md`) | 1.1, extended in 1.3 and 2.1 |
| `SaveDraft` | New working revision | `update` policy | 1.1, extended in 1.3 |
| `PublishRevision` | Moves the published pointer | `publish` policy | 1.1, extended in 1.3 |
| `UnpublishContent` | Published → draft; the public site stops rendering it | `publish` policy | 1.3a |
| `TrashContent` | Sets `deleted_at` and frees the slug | `delete` policy | 1.3a |
| `RestoreContent` | Clears `deleted_at`. If the slug has been taken since, it gets a numeric suffix, and the result says so | `delete` policy | 1.3a |
| `DeleteContentPermanently` | Removes a **trashed** item and its revisions | `delete` policy, or the operator | 1.3a |
| `CreateTerm`, `UpdateTerm`, `DeleteTerm` | Categories and tags | `terms.manage` | 1.3b |
| `UpdateContentDetails` | Quick edit and bulk edit: title, slug, author, categories and tags, and the published date | `update` policy; changing the author needs the type's `edit.any` (`content.edit.any`, or `page.edit.any` for pages) | 1.3c |
| `ChangeVisibility` | Public, Private or Password protected, with the password, at once from the Publish panel, outside any revision and never an edit (`updated_at` stays). Refused for the home page and the posts page, for a type with no public pages, and for a private item whose address another now holds | `publish` policy | 1.11r |

The `delete` policy passes for the type's `delete.any`, or for its `delete.own` on one's own item; for posts those are `content.delete.any` and `content.delete.own`, and authors and contributors hold `delete.own` from 1.3. Pages ask `page.delete.any` and `page.delete.own` (I-3313), held by administrators, and editors for `any`; authors and contributors have no page permission, so they delete none, those they wrote included.

**Quick edit on a published item.** When the working revision *is* the published revision, meaning nothing is waiting to publish, a detail change writes a new revision and publishes it, as WordPress's quick edit does. When unpublished changes are waiting, the change goes into the working revision only, and the editor is told that the live page is unchanged. So a quick edit never publishes someone's half-finished draft.

## Two projections, one code path

Every content Operation ends by calling two helpers, inside its transaction.

### `ContentCounts::apply($before, $after)` — the status counts

`content_counts(type, bucket, author_id, count)`:

- `bucket` is `draft`, `review`, `scheduled`, `published` or `trash`, and `private` for a published private item (I-2780), so the Published tab, author archives and the authors' sitemap count only what readers see.
- `author_id = 0` is the all-authors row, and the other rows serve the *Mine* tab.
- Primary key: `(type, bucket, author_id)`.

A state is `(type, bucket, author_id)`, or null for an item that does not exist. `apply()` subtracts one from the *before* state's rows and adds one to the *after* state's rows, as an `INSERT … ON CONFLICT DO UPDATE`. Creating is `(null → draft)`; deleting permanently is `(trash → null)`; an author change is `(bucket, A) → (bucket, B)`.

`php artisan teme:counts:rebuild` recomputes every row in one pass (seconds at a million items) and reports any drift. It runs weekly, and can be run at any time. `DeleteUser` moves the per-author rows when it reassigns content.

### `TermProjection::sync($content)` — category and tag assignments (1.3b)

Assignments are versioned with the post (I-144). Each revision holds `terms`, an ordered list of term IDs. `content_terms(content_id, term_id, scope, position)` projects them:

| Scope | Rows present when | Built from |
|---|---|---|
| `working` | The item is live | The working revision's `terms` |
| `published` | The item is live **and** published, and not private (I-2781) | The published revision's `terms` |

`sync()` computes the rows the item should have, compares them with the rows it has, and writes the difference. It adjusts `terms.published_count` by the change in `published` rows. So:

- trashing or unpublishing removes the `published` rows, and the count drops;
- restoring or republishing adds them back;
- no `COUNT(*)` ever runs on publish.

Term IDs in a revision that no longer exist are skipped: a deleted term simply drops out.

`teme:counts:rebuild` also rebuilds term counts.

## Terms (1.3b)

`terms`:

- **Columns:** `id` ULID, `taxonomy` (`category` or `tag`), `name`, `slug` (C collation), `parent_id` (categories only), `description`, `fields` JSONB, `published_count`, timestamps.
- **Unique:** `(taxonomy, slug)`.
- **Hierarchy:** an adjacency list. "In this category or its children" gathers the descendant IDs with one recursive query.
- **Tag picker:** searches by name prefix, and returns at most 20 results.
- **Order:** the order of terms in a revision is the editor's order. The first category is the primary one, which `%category%` permalinks need (study D).

Deleting a category moves its children to its parent. The deleted term drops out of every projection. Revisions keep the ID, but it is skipped.

## Lists (1.3c)

Posts and Pages are separate screens over one table.

- **Paging is keyset:** *Newer* and *Older*, never page numbers (I-140). A cursor is the sort value plus `id`, encoded opaquely. Fifty rows per page, and 51 are fetched to learn whether an *Older* page exists.
- **Tabs:** All · Mine · Published · Private · Drafts · In review · Scheduled · Trash. Each shows its exact count from `content_counts`, and every tab is shown, zero included (I-440). **The Private tab** (1.11r, I-2780) lists published private items; the Published tab leaves them out, so its rows match its count. A published row's suffix after its title says Private or Password protected.
- **Sorts:**
  - updated, by default, on every tab;
  - title;
  - published date, on the Published tab only, where it is never null;
  - on the Trash tab, trashed date only.

  Every sort ends with `id`.
- **Filters:** author, category (with its children), tag, and a date range. **No totals are shown under a filter or a search.**
- **Collation:** titles and term names sort with the ICU collation `und-x-icu`, set on the column (I-150). The order is part of the schema, the same on every image and host: `apple, Banana, Cherry, éclair`. Slugs keep the `C` collation, which the slug prefix search relies on.
- **Category and tag filters choose their plan by density** (I-152). `terms.working_count`, maintained like `published_count`, says how many live items carry each term. At or above 0.5% of the type's live items, the list query runs with sorting and sequential scans turned off for that one query, so it walks the list's own index: PostgreSQL cannot estimate how many items a *set* of terms matches, and at a million rows a wrong guess meant scanning and sorting the whole table. Below 0.5%, PostgreSQL's own plan, starting from the term's index, is already right. Both plans return the same rows. Trashed items have no projection rows, so a term filter on the Trash tab finds nothing.
- **Search:** by title, using a trigram index (`pg_trgm`) on `contents.title`. The title is projected from the working revision by `CreateDraft`, `SaveDraft` and `UpdateContentDetails`.
- **Rows never load documents.**

### Indexes

All partial on `deleted_at IS NULL` unless noted:

| Index | Serves |
|---|---|
| `(type, updated_at, id)` | All, by updated |
| `(type, status, updated_at, id)` | Status tabs, by updated |
| `(type, title, id)` | All, by title |
| `(type, status, title, id)` | Status tabs, by title |
| `(type, published_at, id) WHERE status = 'published'` | Published, by date |
| `(type, author_id, updated_at, id)` | Mine, and the author filter |
| `(type, deleted_at, id) WHERE deleted_at IS NOT NULL` | Trash |
| `GIN (title gin_trgm_ops)` | Title search |
| unique `(type, slug)` | Live slugs only, so trash frees a slug (I-142) |
| `content_terms (term_id, scope, content_id)` | Category and tag filters |

## Bulk actions (1.3c)

At most one page of selected items; nothing larger (I-143). For each item, the controller calls that item's Operation, which runs in its own transaction and is authorized on its own. The answer counts successes and lists every skip with its reason. A "select all *n* matching" action arrives with the jobs indicator in 1.11.

The actions:

- move to trash, restore, delete permanently (Trash tab only);
- publish, and revert to draft;
- change author;
- add or remove a category or tag.

**Bulk Edit** (I-204) applies its changes item by item through `BulkEditItem`: the terms and author through one `UpdateContentDetails`, then the status through `PublishRevision` or `UnpublishContent`, then, since 1.8d′, the Discussion through `SetDiscussion`, offered only while the list takes comments and the person holds the type's publish ability. Each step reports in the item's line; a refused step is reported there when something else on the item changed, and otherwise the item is skipped with the reason (`docs/architecture/comments.md`, *One item's Discussion*).

## Trash retention (1.3a)

- **Retention:** `content.trash_days`, in the settings group `content` (1–365, default 30, ability `settings.content.manage`).
- **Purging:** `php artisan teme:trash:purge` acts as the operator and runs daily. It deletes items trashed longer ago than the setting, 1,000 per chunk, each through `DeleteContentPermanently`.

## The scale benchmark (1.3d)

**Commands and data:**

- `php artisan teme:bench:seed` fills a separate database, `teme_bench`, with the study I data set: 1,000,000 items, three revisions each, 2,000 categories, 50,000 tags and 200 authors. Since 1.5h it adds the editor's data: deeper histories (43 revisions on one item in 100, 113 on item 1), autosaves, pending schedules, reviews, varied body text with its search columns, and one long article. Since 1.9l, `--redirects=100000` and `--not-found=100000` (the defaults) add 100,000 redirects, 100 patterns and the rest exact with every kind of target, a 404 log at its largest cap, and item 247, an article whose head takes every fallback. Every value is written by the statement that inserts its row, never by a later UPDATE over the whole table (B-189, B-198). Posts are written Open and pages Closed, as their types start (1.8d′). Since 1.8d′, `--comments` (40,000 by default, study S §3.11) adds comments in one `INSERT`: every eighth on one post, `THREAD_ITEM`, in blocks of 41 conversations and a chain of nine replies, so 5,000 make 4,100 conversations ten deep, 82 pages at 50; the rest round the other published posts *n* % 100 = 17 below 100,000, 24 in 35 approved, 1 pending, 8 spam and 2 in the trash; arriving over 720 days, with an address and a browser only on those within `keep_ip_days`, as an import leaves them; then `comment_stats` through `RebuildCounts`. A data set seeded without comments runs no comment scenario, and the report says why.
- `php artisan teme:bench:run` replays every list, tab, sort, filter and search query, and every quick-edit save. It writes `docs/benchmarks/<version>.md`.

**The checks:**

1. **The time budgets (I-148).** Each is the 95th percentile over 20 runs, measured on the server:

   | Query | Budget |
   |---|---|
   | List page | 150 ms |
   | Filtered or sorted list | 200 ms |
   | Title search | 300 ms |
   | Term screens | 100 ms |
   | Quick-edit save | 150 ms |
   | Opening the editor, or a page of its history (1.5h) | 150 ms, the list budget, under its own name, `editor` |
   | One scheduler pass, up to 50 rows (1.5h) | 500 ms, `scheduler`, the one budget not from I-148 (2026-09-23) |
   | A public page drawn (1.6h, I-400): an article; the front page or an archive; a deep archive page; the site search | 100 ms, `public-item`; 150 ms, `public-list`; 200 ms, `public-deep`; 300 ms, `public-search`: server time only, from the request reaching the kernel to the HTML leaving it |
   | A page answered from the page cache (1.7e) | 10 ms, `public-hit`, either store (1.7e's question 2) |

   The comment scenarios (1.8d′, `CommentScenarios`) join existing classes: the long thread's article and the link to its last comment, answered 302, are `public-item`; its page 2 is `public-list`, and page 80, held to page 2's statements since page 1 is the article, is `public-deep`; the Comments screen's Pending view, its deep page, the views' counts and the menu badge are **list pages**; what Empty spam covers is a **filtered list**; one chunk of the trash purge and the daily erasure of addresses are **scheduler** passes; one permanent deletion inside Empty spam and one Bulk Edit Discussion step are **quick-edit saves**, timed per item. "public: article" leaves out the posts that carry comments, so the thread adds nothing of its own to the pair it makes with "public: article, 20 tags".

   The other scenarios 1.5h added joined an existing class: the dashboard and a contributor's All tab are **list pages**; the author sort, its deep page and the month filter are **filtered or sorted lists**; body search, the palette and comparing two long revisions are **searches**; an autosave, a checkpoint and one Bulk Edit item (timed per item, over a batch of 25) are **quick-edit saves**.

2. **No sequential scan** on a table larger than 10,000 rows, in any query.
3. **No sort of more than one page of rows** in the queries that serve a tab in each of its sorts. Those are the screens editors land on, and each has an index made for it. Until 1.4d this was only checked for each tab's default sort, by updated; since 1.4d it is checked for the other tab-and-sort combinations too — `all tab, by title` (both pages), `published tab, by published date`, and `trash tab` — closing the gap B-081 found (docs/superpowers/plans/2026-09-19-phase1-slice04d-media-benchmark.md, Task 2).

   **Filtered lists are exempt from this check but not from their budget.** A sort within one author's or one category's rows is sometimes cheaper than an index made for every combination of filter and sort, and the time budget decides. This narrows the wording of study I's Decision 9. The reason is recorded here.
4. **A fixed number of queries per request**, whatever the page. Built in 1.4d: a scenario may name another it must cost the same as (`queriesLike`), and a mismatch fails it.
5. **A hit reads nothing from the database** (1.7e): a scenario marked `readsNothing` fails on any statement, whatever its time.
6. **How many items fit a time** (1.8d′): a per-item scenario may name `within`, how many items must fit how many seconds, and its p95 fails it when they wouldn't: one deletion inside Empty spam is held to 1,000 in 10 s, what an Empty press deletes in its own request, and to 5,000 in 30 s, one queued part in half the worker's 60.
7. **Writes planned too** (1.8d′): a scenario marked `planWrites` has its `INSERT`, `UPDATE` and `DELETE` planned and held to the rules above, never counted as queries (`EXPLAIN` without `ANALYZE` runs nothing); the erasure's `UPDATE` is.

8. **Writes nothing, and reads these** (1.9l): a scenario marked `writesNothing` fails on any statement but a SELECT, as a 404 a reader asks for must; one that names `reads` fails unless each index named is among those its plans read. Both are checked in `ScenarioChecks` with `readsNothing` and `within`.

The SEO scenarios (1.9l, `SeoScenarios`, `docs/superpowers/plans/2026-10-08-phase1-slice09l-proof-and-the-tag.md`, D1 to D18 are I-1210 to I-1227) join existing classes: a redirect lookup is `address`, 2 ms a lookup; a reader's request a redirect answers, a 404 and an article with every fallback are `public-item`; the sitemap index and its first pages are `public-list`, its deepest page `public-deep`, held to page 2's statements; Tools › Redirects and the 404 log's screen are **list pages** and **searches**; the minute's run that writes a flood into the log is `scheduler`, and `within` 100,000 in 30 s. `redirects` is among the tables the scale rule watches; the capped `not_found_log` isn't, and its screen names its index with `reads` instead.

The AI scenarios (1.10z, `AiScenarios`, `docs/superpowers/plans/2026-10-15-phase1-slice10z-full-pass-and-tag.md`, D11 to D14 are I-2382 to I-2385) run against `teme:bench:seed --ai-activity=10000000`: ten million AI requests over thirteen months, and their monthly totals. They join existing classes: Tools › AI activity's page one, its deep page and the month's spend are **list pages**; a month's filter, a deep page inside it, and a person's or a task's filter are **filtered lists**. Each page reads `ai_activity_created_index` in index order, the deep pages are held to their page one's statements (`queriesLike`), and the month's spend reads no `ai_activity` row (the totals table answers). Two scenarios the sweep handed over join too: the term screens' tabs' counts are **term screens**, where a sequential scan of `terms` is by design (1.10p7's live counts, B-794), and the media library's months are a **list page**. beta.10's run read page one in 10.9 ms and halfway down in 10.2 ms, four statements each (`docs/benchmarks/v0.1.0-beta.10.md`).

**The indexes read** (1.8d′): each scenario keeps the indexes its plans read (`PlanInspector::indexes()`), and the report lists them under "Indexes read", so an index a design relies on is checked, not assumed.

**What it counts** (1.7e): each public request starts in a fresh scope, as a production request does, so nothing an earlier run read is kept. Queries are SELECTs; Writes, every other statement sent, a failed one too, counted as it is sent (a refused lock's `INSERT` never reaches the query log); Valkey, the commands sent to the page store's. The page cache's own scenarios are *The benchmark* in `docs/architecture/page-cache.md`.

It runs before every tag. It is not part of `composer check`.

## The document: what the editor writes and the server keeps (I-452)

**The guard.** `EditorSchemaTest` builds the editor's own schema in Node: `tests/Fixtures/editor-schema.mjs` calls `temeExtensions()`, the function the editor calls, through `tests/Fixtures/editor-hooks.mjs`, which loads the editor's TypeScript as Vite does. Every node, mark and attribute the editor declares must be allowed on the server, and every document the editor can make must pass `DocumentNormaliser` and then `DocumentValidator`: each node with its defaults, each value its parse rules can give, and each card through the clipboard. What the guard still finds is listed in the test by name with its exact message, under the GitHub issue that fixes it (#2, #3 and #4 today), so every commit stays green and a listed document that starts failing for a new reason fails it. Each fix deletes its entries.

**Tables.** A cell's `align` (left, center, right or none, from Tiptap 3.31) is allowed, and drawn on the site after the spans as the editor draws it (`TableCellNode`, `TableHeaderNode`). A cell's span is repaired, never refused: 1 when it isn't a positive whole number, and no more than browsers allow, 1,000 columns and 65,534 rows. The normaliser and the paste cleaner both apply it; the cleaner also keeps a pasted cell's alignment, from its `align` or its `text-align` style.

Before upgrading any `@tiptap/*` package, or adding an extension to `temeExtensions()`, run `EditorSchemaTest`. A new attribute fails it, as the table cell's `align` would have in 1.5c, until the server allows or normalises it and the script lists the values its parse rule can give.

## Not built yet

- Scheduling (1.5).
- The editor, and field editing beyond quick edit (1.5).
- The public site and category archives (1.6).
- "Select all *n* matching" (1.11).
- Page hierarchy, meaning `parent_id` on pages and nested page URLs, which arrive with the public URL work in 1.6.
