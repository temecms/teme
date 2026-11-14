# Addresses

Every published item, term and author has one public address, and every address it had before keeps answering with a permanent redirect. Addresses are settings, as WordPress's Permalinks are (I-393), and they live in one table, `paths`, that the public site reads and the Operations write. The design is in slice 1.6a's plan (`docs/superpowers/plans/2026-09-22-phase1-slice06a-addresses.md`); this document describes the system as built and is what the code is checked against.

## The `paths` table

```
paths   id           bigint PRIMARY KEY
        path         text COLLATE "C"      -- no leading or trailing slash; '' is the home page
        target_type  varchar(16)           -- content | term | user | type
        target_id    varchar(32)           -- the ULID, a user's ID as text, or a type's key
        is_current   boolean
        created_at   timestamptz
        replaced_at  timestamptz NULL      -- when a current address became history

        UNIQUE (path)                                              -- one owner per address, current or old
        INDEX (target_type, target_id)                             -- every row of one target
        UNIQUE (target_type, target_id) WHERE is_current           -- paths_one_current: one live address each
```

- **A path is stored in the form the request is compared in:** URL-decoded, NFC-normalised, lower-cased, without its outer slashes (`PublicRequestPath::normalise()`). `C` collation makes the comparison byte-wise, so the unique index is also the lookup index.
- **The trailing slash is not stored.** It belongs to the pattern, and is added when a URL is built (`Addresses::toUrl()`).
- **One row per address, not per field or per item version.** A million published items give a million current rows plus their history, and every lookup is one read of the unique index.

## Current and history

`PathProjection` keeps the rows in step, called by the Operations inside their transaction, as `TermProjection` and `MediaProjection` are. It never checks for clashes: by the time it runs, the Operation has validated.

| Operation | Calls |
|---|---|
| `PublishRevision`, `UnpublishContent`, `RestoreContent`, `TrashContent` | `syncContent` |
| `UpdateContentDetails`, only when the slug, author, categories or published date changed | `syncContent` |
| `DeleteContentPermanently` | `forget('content', $id)` |
| `CreateTerm`, `UpdateTerm` | `syncTerm`, with every descendant |
| `DeleteTerm` | `forget('term', $id)`, then `syncTerm` for each child it moved up |
| `InviteUser`, `UpdateProfile` | `syncUser` |
| `DeleteUser` | `forget('user', $id)` |

Scheduled publishing, approval and Bulk Edit publish through `PublishRevision` and edit through `UpdateContentDetails`, so they need no call of their own.

A sync compares the address the target should have (`Addresses::forContent()`, `forTerm()`, `forUser()`) with its current row:

- **Unchanged:** nothing is written.
- **Moved:** the current row becomes history (`is_current = false`, `replaced_at = now()`), and the new address is inserted as current.
- **Back to an old address of its own:** that history row becomes current again, so no row is duplicated.
- **Onto another target's history:** the other target's old row is deleted. A live address comes first; a redirect is a courtesy.
- **Onto another target's current address:** refused loudly by the unique index on `path`. It can only happen when an Operation skipped its check.
- **No address:** a draft, an item in the trash, a private item, or an item of a type that isn't public has none. Its current row becomes history, and since the target has no current address, that history answers 404. Trashing therefore frees the address (I-142). `RestoreContent` suffixes the slug (`-2`, `-3` …) while its would-be address is taken. A private item is read only in the admin, by whoever may edit it, and answers 404 on the site to everyone, signed in or not (I-1700, I-2771). When it is made public or protected again it takes back its address, unless another target holds it now: `ChangeVisibility` then refuses, with `Paths::conflict()`'s words.

**What an address is made of.** An item's comes from its type's pattern and the published revision's details. The home page, when the site shows a page, is `''`. A term's is its taxonomy's base, then its ancestors' slugs and its own; with an empty base, just the slugs. An author's is the author base, then `users.slug`, which is made unique from their name when they are created.

**Clashes are refused before anything is saved,** in each Operation's `validate()`: the would-be address goes to `Paths::conflict()`, which names what holds it, for example "The address /about/ is used by the page "About"." A path whose first segment the router already answers (`Paths::RESERVED`: `admin`, `preview`, `up`, `storage`, `build`, and `_teme`, core's own endpoints such as `/_teme/session`) is refused as used by the site itself, and so is one that starts with a segment the public site's own routes answer: `Paths::SITE_ROUTES`, which holds `feed`, `sitemap.xml` and `robots.txt` (I-1025). `Paths::taken()` reads both lists, and `Paths::conflict()` and `ChangeAddresses`' three checks (a pattern's first fixed segment, a taxonomy base, the author base) use it. The two lists are apart on purpose: `RESERVED` also makes the page cache pass an address by and the static-file driver give it no file, which would stop it storing feeds and sitemaps. Only the first segment is held, so an item may be called `feed` deeper in its address; whether such an address is a feed's is decided in *Feeds*, below. The sitemaps' child addresses (`/sitemap-{name}.xml`) need no entry of their own: no slug, base or pattern may hold a dot (I-1105).

The admin's first segments are reserved a second way, for content types and taxonomies that get admin routes: `ContentTypeRegistry::RESERVED` holds them (`docs/architecture/content-types.md`), and it gained `tools` with the Tools section (1.9h, I-1143), so no listed type or taxonomy can take `/admin/tools`.

**Slugs** may hold Unicode letters and numbers (I-397), so an imported `café` keeps its address. Titles still make ASCII slugs.

**An import keeps the old site's addresses** (I-339). Every address that changes on the way becomes a history row pointing at its target, so it answers 301 to wherever the target is now, follows later renames, and never chains: an item's are written by the posts and pages phase (1.11j), a category's, tag's or author's by the import's `old-addresses` step (1.11m, `KeepOldAddresses`). A path the table already holds is left as it is: when it is another target's current address the run reports an error, since old links would reach the wrong page, and when it is another's history, a warning.

## The settings

The `addresses` settings group is built from the type and taxonomy registries (`AddressSettingsGroup`), so a public type or taxonomy registered in code gets its own setting with the default it declares. It has its own screen, **Settings › Addresses** (`settings/addresses`), and is written only by `ChangeAddresses`; `UpdateSettings` refuses it (`docs/architecture/settings.md`).

| Key | What it holds | Default |
|---|---|---|
| `pattern_{type}` | One per public type: fixed text and tokens | The type's declared pattern, or `/{segment}/{slug}/` |
| `base_{taxonomy}` | One per public taxonomy; may be empty | The taxonomy's declared base, or its key |
| `archive_{type}` | One per public type with an archive, except the first: the one word its archive page is at | The first fixed word of the type's pattern, else its admin segment |
| `author_base` | The word before an author's address | `author` |
| `front_shows` | `latest` or `page` | `latest` |
| `front_page_id`, `posts_page_id` | The home page and the page that lists posts | empty |

**Patterns** (`AddressPattern`) are fixed segments and tokens: `{slug}`, `{year}`, `{month}`, `{day}`, `{id}`, `{category}`, `{author}`. A pattern needs `{slug}` or `{id}`, so it names one item. The screen offers WordPress's presets: *Post name* `/{slug}/`, *Day and name* `/{year}/{month}/{day}/{slug}/`, *Month and name* `/{year}/{month}/{slug}/`, *Numeric* `/archives/{id}/`, and *Custom*.

- Dates are on the site's clock (`general.timezone`), as the admin shows them, never the server's.
- `{category}` is the path of the item's **primary category**, parents included (I-869). `PrimaryCategory::pick()` chooses it: the term the writer chose, kept in the built-in field `primary_category`, while it is still one of the item's terms in its type's first hierarchical taxonomy, else the first of those terms in the item's order, else none. The same choice is the kicker above a title (`ArticleViews`, `ListingViews`), so a listing's card and the article agree, the category in its breadcrumb trail (`docs/architecture/themes.md`, *The trail*) and the `articleSection` of its structured data. **Changing it on a published item moves its address:** the old row becomes history and answers 301, whether the change goes live through `PublishRevision` or through Quick edit's `UpdateContentDetails`, and each checks the new address for a clash first; `RestoreContent` reads it too when it brings an item back. `Addresses::wouldHave()`'s sixth parameter is the value an importer passes (1.11). Slice 1.9g's second part built it (`docs/superpowers/plans/2026-10-08-phase1-slice09g-structured-data.md`, D15 is I-1134).
- **The trailing slash follows the pattern.** A term's, an author's and a listing's follow the first archive type's pattern (`ListingTypes::first()`).

`AddressSettings::current()` reads the group once per request, as a scoped binding. It reads only the keys the group declares; the group is derived from the types and built again when they change, so a type made on the site has its pattern and archive page at once (`docs/architecture/settings.md`, *Groups that follow the content types*). `ChangeAddresses` refuses a pattern, base or home page that would put two things at one address, found by a few set queries (`AddressConflicts`) rather than by rendering every item, and lists up to twenty clashes.

**The home page and the posts page stay on the site** while they're chosen (#36): `TrashContent`, `UnpublishContent`, a published date moved ahead in `UpdateContentDetails`, and `ChangeVisibility` to Private or Password protected (I-2778) refuse them, through `SitePages::refuseTakingOff()`, with "This is your home page. Choose another in Settings › Reading first." (or "posts page"), and write nothing; choosing a page that isn't public as either is refused too, so `/` is always a page the cache may store; Bulk Edit and the list's bulk actions report it as the item's reason. Permanent deletion needs the trash first, so it can't reach them. Teme refuses instead of switching the site back to the latest posts, so the home page never changes without being asked.

**An import sets these too** (I-393). Before it writes anything else, `ImportAddresses` sets each public type's pattern, the bases and the author base from the old site's permalink structure, with `general.timezone` and `general.language`, through the same checks as `ChangeAddresses` (`Concerns\ChecksAddressSettings`). WordPress's tags become Teme's tokens; what Teme can't write, such as `%post_id%` or a segment mixing tags, is dropped, and every address that changes becomes an old address. A later run keeps any value a person changed since, and says so. The home page and the posts page are set near the end of a run, once the pages are there.

## A type's archive page

A public type with an archive, other than the first, has a listing page at its own address (slice 2.1e, I-3304; `TypeArchives`, `app/Domain/Addresses/TypeArchives.php`). The first such type, posts on every site so far, is the latest list: its page is the home page or the posts page, so it has no archive page and its pattern's trailing slash is the one terms', authors' and listings' addresses take (`ListingTypes::first()`).

- **The address is a setting, not a row.** `archive_{type}` is one word (no slash; `maxLength` 60), set in Settings › Addresses under *Other addresses* as "{Plural} archive page", and read from `AddressSettings::$archives`. There is no `paths` row for the live page.
- **Its old addresses are rows.** Moving the page makes its old address a `paths` row of target type `type` and target ID the type's key (`TypeArchives::TARGET`, `PathProjection`), which answers 301 to where the setting puts the page now. `target_id` was widened to 32 characters for the key (`2027_01_30_100000_widen_paths_target_id.php`). Unticking `archive` makes the page answer 404.
- **Resolution order.** In `PathResolver::resolve()` a live address keeps its place; then a type's archive page, the first in `TypeArchives::types()` when two are at one word; then an old address, which an archive page may take over. A non-canonical spelling answers 301 to the canonical form, and a page past the last answers 404. The page lists the type's published items newest first through `ListingViews::forType()`, titled by its own row in Search and social, under the heading of the type's plural, in the `archive` view kind (`archive-{type}`, `docs/architecture/themes.md`).
- **Checks.** `AddressConflicts::check()` is given the pages a change moves and checks only those: another archive page at the word, a live address already there, an item whose `{slug}` pattern would put it there, a top-level term whose base is empty. A save that doesn't move a page never fails over one. A page whose word a live address holds stays hidden, and the screen names the holder (`TypeArchives::holder()`). `Paths::conflict()` refuses an item or term an address an archive page uses.
- **Its feed and sitemap.** The page's feed is its address followed by `feed/` (`FeedResolver`, subject kind `type`), and its old address's feed leads to the current one. `ArchivesSitemap` lists the pages that are shown, not hidden by their row in Search and social and holding a live item, dated by the type's newest item.

Not built: deleting a type does not yet forget its archive page's old addresses or its `archive_{key}` value, so a type made later with the same key inherits them; a menu item or an editor link to an archive page (links store an address, not the type); the redirect list reports an address under an archive page as redirecting although the page answers first; the page-cache warmer does not warm archive pages.

## Answering a request

Before any route runs, the page cache may answer an anonymous GET from a stored page (`docs/architecture/page-cache.md`; off until 1.7b). Public requests take the `public` middleware group: `SubstituteBindings` only. **There is no session, cookie, CSRF token or shared error bag**, so a public response sets no cookies, and 1.7's page cache can store it. `routes/public.php` is loaded last, through `withRouting()`'s `then:` closure: `/` is the route `home`, and the fallback route `public.path` takes everything no other route matched. `PublicController` touches no model. It turns the answer of `PathResolver::resolve()` (`app/Domain/Site`, 1.6a's `PublicAddress` moved in 1.6b) into a response: a redirect, or a page the theme renders (`docs/architecture/themes.md`). Outside production it adds `X-Teme-Address: current|history|date|front|notfound`, and `redirect` when a redirect answered. Three routes come first: the feeds' (*Feeds*, below), and `robots.txt` and the sitemaps' (*Sitemaps and robots.txt*, below).

`resolve()` works in this order:

1. **Normalise** the path (`PublicRequestPath`).
2. **A trailing `page/{n}`** is split off. `page/1` answers 301 to the address itself.
3. **`''` is the home page:** the latest posts, paged, or the chosen page, which has no page 2.
4. **`Paths::find()`**, one index read, current or old:
   - **Current:** the home page's own address answers 301 to `/`, so the home page has one address, even before the recompute has moved its row. A non-canonical form answers 301 (below). Then the item, rendered from its published revision; the posts page, which lists the latest posts; a term's listing of the items filed under it; or an author's listing.
   - **No live row:** a type's archive page, if one is at the path (*A type's archive page*, above), before an old address is tried.
   - **Old:** 301 to the target's current address, with the page and the query string kept, or 404 when it has none.
5. **A date archive:** `YYYY`, `YYYY/MM` or `YYYY/MM/DD`, on the site's clock, listing the posts published in it. Its pages are counted no further than the deepest page served, 1,000, so a busy year isn't counted to the end (1.6h).
6. **Otherwise** `PublicController` asks `RedirectLookup` whether a redirect answers the address (*Redirects*, below), and, when none does, **answers 404, in place.** Never a redirect to the home page. The 404 page is stored for ten seconds at most and carries the page-cache tag `not-found` (`docs/architecture/page-cache.md`), and the address is counted for the 404 log.

**A protected item is locked at the resolver** (I-1700, I-2777). `resolve()` takes the reader's keys as a third parameter, none by default. At a protected item's address, or a later page of its comments, it answers a `ProtectedView` of kind `protected`, core's password form, unless a key opens the item (`docs/architecture/themes.md`). Only `PublicController` passes keys, read from the `teme_unlock` cookie by `ReaderKeys`; `SiteAnswers::at()` and every other caller get the form. Every answer for a protected item, locked or open, carries `private`, which `PublicController` turns into `Cache-Control: no-store, private` (`docs/architecture/page-cache.md`, *Protected items*).

**`POST /_teme/unlock`** (`UnlockController`, route `teme.unlock`) takes the form's `path` and `password`. It sits under `_teme` with no middleware of its own, so it keeps no session and takes no CSRF token, and it is the one public endpoint that sets a cookie. The right password answers 303 to the address, built from the site's root, with the item's key added to the cookie; a wrong one draws the form again with 422; too many tries draw it with 429. An address that isn't a protected item now answers 303 to it, with no cookie. The count of tries and the cross-site refusal are in `docs/architecture/security.md`, *Password-protected items*.

Listings show ten items a page, until the theme's own number (1.6c), and stop at page 1,000.

### The canonical form

An address has exactly one form: lower case, NFC, single slashes, the trailing slash its pattern gives, and no percent-encoded letter, digit or hyphen. Any other form of an existing address, such as `/Hello-World`, `/hello-world` without its slash, or `/hello%2Dworld/`, answers **301 to the canonical form, never 404**, keeping the query string. The `Location` header percent-encodes each segment, so a Unicode slug travels safely.

The redirect is built as a `RedirectResponse` from `rtrim(url('/'), '/')` and the path, never with `redirect()` or `url($path)`, because Laravel's URL generator trims a trailing slash, and the canonical address would then redirect to itself (B-212). For the same reason, a test of a public path sends it exactly as written with `addrGet()` and reads the literal `Location` with `addrLocation()`; the test client's `get()` trims the slash too.

## Redirects

An address the site no longer answers can be sent on by a **redirect** an editor makes. The path table does most of this alone (*Current and history*): an item's old address is history and leads to where the item is now, in one step, and can never chain. Redirects are for what the path table cannot know: an old site's addresses, a section that moved, a trashed item's address, a file. Slice 1.9c built them in two parts: the table, the lookup and the Operations (`docs/superpowers/plans/2026-10-07-phase1-slice09c-redirects-and-404-log.md`, Part 1), then the in-process resolver, import and export, the counts and the 404 log (Part 2); D1 to D20 are I-1043 to I-1062. Slice 1.9h built their screens (*Tools › Redirects*, below).

**The lookup runs only where routing missed** (I-1045, I-858). The order for any address: (1) a live path; (2) a path's history, which answers 301 to the target's current address; (3) an enabled exact redirect, one indexed statement; (4) the first enabled pattern that matches; (5) 404. A redirect therefore never hides a page the site still answers, and one made from such an address is kept and shown as waiting, taking effect once the page goes. A history row beats a manual redirect at the same address for the same reason: it points at the item, so it is always right. `PublicController` asks `RedirectLookup` only for `PathResolver`'s "not found". **A trashed or unpublished item's address** has only a history row and no current address, so it answers 404 (I-142, I-856); the lookup then runs, so a redirect made from it works at once.

`redirects` holds manual and imported rules, one row each, with a lower-case ULID. `hits` and `last_hit_at` are written a minute at a time (*Counting*, below).

| Column | Holds |
|---|---|
| `source`, `kind` | the path, and `exact` or `pattern` |
| `pattern` | for a pattern, the compiled regular expression PHP and PostgreSQL both read the same way |
| `status` | 301, 302, 307 or 308; or 410 or 451, which have no target |
| `target_kind`, `target`, `target_path` | where it sends readers (below); null for 410 and 451; `target_path` an address target's path, for the chain check |
| `origin`, `enabled`, `note` | `manual` or `import`; on or off; why it is there (200 characters) |
| `hits`, `last_hit_at` | how many readers it has sent on, and when it last did |

- **A source is a path** (I-1046), kept in the path table's form (`PublicRequestPath::normalise()`: decoded, NFC, lower case, no slash at either end), so `/Old-Page`, `/old-page/` and `/old-page?utm_source=x` all match `old-page`. A query or fragment, a control character, text that isn't UTF-8, `/` alone (the home page always answers), a first segment `Paths::taken()` names and more than 2,000 characters are refused. The request's query is passed on when the target has none of its own, as history passes it on.
- **Patterns** (I-287, I-1047): `[name]` is one segment and `[...rest]` the rest of the path, only as the last segment. A placeholder is a whole segment; names are `[a-z][a-z0-9_]*`, each once. The pattern is compiled once, when saved. Patterns are tried longest fixed text first, then one without `[...rest]` before one with, then the oldest, and are kept compiled in `Cache::memo()` under `RedirectLookup::PATTERNS_KEY`, forgotten after any change commits. A placeholder's value goes into the target percent-encoded.
- **Four kinds of target** (I-1048, I-860, rule 9): `address`, a path on this site, as it goes in a `Location` header; `outside`, a full `http` or `https` address on another site, which the list marks "Another site"; `content`, an item by ID, sent to its current address (`/` for the home page); `media`, a file by ID, sent to its public file (the widest variant of an image, the original of anything else). The site's own scheme and host are taken off an address typed with them, and an address that is an item's, current or old, with no placeholder, query or fragment, is kept as the item, so it follows the item when it moves.
- **Loops and chains are refused when a redirect is saved,** both ways, naming the redirect to change first (I-859, I-1049). Every saved redirect is checked, so no chain longer than one step exists. The checks cover every other redirect, on or off, so switching one on needs none: its source is another's; its target leads back to its source; its target is another's source that leads back, or onward; another's target is its source. Patterns are checked with sample values, both ways, and against every exact row by PostgreSQL's `~`. Every change takes `pg_advisory_xact_lock(hashtext('teme.redirects'))` first, so two saves at once cannot together make a chain.
- **Waiting is computed, never stored** (I-1050, I-857, I-858). A redirect whose item isn't published, or whose file is in the trash or has no public file yet, waits: its source answers 404, and the first match decides, so no later pattern is tried for it, and a waiting redirect counts no hit. `RedirectState` names what each does now (on, off, waiting for a page, for an old address, for its item or file, or its item or file deleted for good); `RedirectStates::of()` computes it in four statements for any number of rows. No foreign key ties a target to `contents` or `media`, so a deleted item's redirect stays, marked, until someone edits or deletes it.
- **410 and 451** (I-1051) are drawn by the theme's error templates, as any public HTTP error is, and never stored.
- **Five Operations,** `CreateRedirect`, `EditRedirect`, `SwitchRedirects` and `DeleteRedirects` (the last two for 1 to 500 redirects at once) and `ImportRedirects`, each for `redirects.manage` (editors and administrators), each recorded in the security log (`docs/architecture/security.md`, *Redirects*), each with a "writes nothing when authorization fails" test. After the outermost commit each purges the stored 404s it could replace: an exact redirect its source's `url:` tag, a pattern the tag `not-found`, which every stored 404 carries (I-1052), told to the page cache as `CacheKeysFor::SITE` with the ID `redirects` (I-1059).

**Counting** (I-1054, I-1055, I-1057; `docs/studies/seo.md` §3.15). A reader's request writes no row. A redirect that sent a reader on, and an address nothing answered, each append one line to a file, `counts.open`, in the `counts` folder of the page cache's file store, which the web server, the queue worker and the scheduler already share (`AddressCounts`; `docs/architecture/page-cache.md`, *The folder every process shares*). Not Laravel's cache, whose default store is the database (I-261): that would be a statement per request. Every minute `teme:redirects:count` runs `RecordAddressCounts` as the operator (`docs/architecture/operations.md`, *The operator*): it reads the file closed a run ago line by line, adds each redirect's hits and each address's row in statements of 500 rows, applies the 404 log's cap and ignore list, and closes the open file for the next run. A line is dropped once the open file passes 64 MiB, so a stopped scheduler cannot fill the disk, and a write that fails is dropped without a report, since it would repeat on every 404 of a flood. **The scheduler must run every minute** for the counts to appear, and with the Redis page store on several servers only the scheduler's own server is counted (I-911). A 404 that the page cache serves from storage is counted too, on its hit path, with one append and no database statement (I-1055).

**The 404 log** (I-298, I-1056): `not_found_log`, one row per address in the path table's form, so `/Old/` and `/old` are one: how many times it was asked for, when first and last, and the host of the `Referer` alone. It keeps at most `not_found_log_size` addresses, 10,000 by default, the least recently seen making room when a run adds more. It ignores, before it writes anything, the patterns of `not_found_log_ignore`, `*.php`, `.*` and `*/.*` by default, matched with `fnmatch()` where `*` also matches `/`, and any address that is not UTF-8, holds a control character or passes 2,000 characters. Both settings are Search and social's, applied by the minute's run and never on a reader's request (`docs/architecture/settings.md`). An address a redirect now answers is marked "Redirected" when the log is read, and stops being counted, since readers no longer get a 404 there.

**Other readers of the same rules.** `SiteAnswers::at()` (I-1058) gives the status and `Location` the site would answer at an address, in process, with no HTTP, nothing drawn and nothing counted: `PathResolver::resolve()`, then `RedirectLookup::answer()`. 1.11's importer checks every old address with it. `TrashedAddress::of()` (I-1061) gives the address a trashed or unpublished item last had and the exact redirect from it, if any, for the offer after trashing. `RedirectExport` writes every redirect as CSV, to edit and import again, or as nginx rules, reading 500 at a time; `ImportRedirects` reads the CSV back (`from`, `to`, `status`, `note`, and `on`; at most 5,000 lines and 2 MB), checks each line as one added by hand and reports the lines it left out (I-1060).

**Old file addresses** (I-1851). After the path table and every redirect, `RedirectLookup::oldFile()` answers a path below `wp-content/uploads/`, in any case, from the files an import brought: every size and edited copy the old site had leads 301 to the file's public address now (an image's largest variant, any other file's original), and a file no longer here answers nothing. A person's own redirect from such an address wins, and nothing is stored, since the page cache never keeps a 301.

**Tools › Redirects** (1.9h, `docs/superpowers/plans/2026-10-08-phase1-slice09h-tools-screens.md`, D1 to D16 are I-1140 to I-1155) is the screen for all of this, under Tools, for whoever holds `redirects.manage`. It lists redirects newest first, a page at a time, found by address, typed target or note, each with where it sends readers in words and what it does now (I-1146); adds and edits in one drawer, whose To is a post or page found by a title search, a file in the media library, or an address (I-1147); switches and deletes by IDs, one to 500 at a time, with Undo for a switch (I-1148); imports and exports (I-1150); and beside the list, as a second tab, shows the 404 log, the most asked for first, with *Create redirect* filling the drawer and *Mark as gone* making a 410 in one click (I-1149). Just after a published item is trashed from its row or the editor, its toast offers "Add a redirect for its address" to whoever holds the permission (I-1151). The 404 log reads its size from `NotFoundLogSection::size()` and links "Change the log’s size and what it ignores" to Settings › Search and social for whoever holds `settings.seo.manage`; that section links "Open the 404 log" for whoever holds `redirects.manage` (I-970).

**Not built yet:** a redirect source with a query (I-910); counting hits in Valkey when the page store is Redis (I-911); a lighter `SiteAnswers` for a very large site (I-913); deleting a row of the 404 log or clearing it (I-953); the offer after a bulk trash (I-952). An importer's redirects (1.11) go through `ImportRedirect` (1.11h, `docs/superpowers/plans/2026-10-15-phase1-slice11h-media.md`): the same `RedirectProposal` and `RedirectRules` as a person's, `origin` `import`, never written over a person's row, and with `overImport` only over another import's. `CreateRedirect` stays a person's. Anything that writes the table another way forgets `RedirectLookup::PATTERNS_KEY` after its commit.

## Feeds

RSS 2.0 lives at WordPress's addresses, so a migrated site's subscribers keep working (I-299, I-1065, I-1066). Slice 1.9d built it (`docs/superpowers/plans/2026-10-07-phase1-slice09d-feeds.md`, D1 to D20 are I-1063 to I-1082). **One route, and the controller decides:** `FeedController` answers every public path whose last segment, or last two, is a feed name (`feed`, `rdf`, `rss`, `rss2`, `atom`, in any case; `FeedPath::ROUTE`), and gives back to `PublicController`, unchanged, anything that is not a feed's, so redirects, the 404 log and the theme's not-found page still apply. The route is a fallback route (`->fallback()`), so a route a plugin, a theme or a test names itself is matched first and the feed route only sees what no ordinary route did (I-928). It carries `throttle:site-search`, as the public fallback does, and is marked cacheable.

`{base}` is the stored path of a term, an author or an item, or empty for the site:

| Request | Answer |
|---|---|
| `/feed/` | 200, the site's feed |
| `/{term}/feed/`, `/{author}/feed/`, at the term's or author's current address | 200, that feed |
| Any other spelling of those: no trailing slash, other case, `…/feed/{name}/`, `…/{name}/`, a percent-encoding the canonical form doesn't use | **301** to the canonical `…/feed/`, query dropped |
| A term's or an author's *old* address, then `/feed/` or any other spelling | **301** to the target's current `…/feed/` |
| `/{item}/feed/` and its spellings, at an item's current or old address, the posts page's included (WordPress's feed of one item's comments) | **410**, plain text: "Gone. This site has no feed of comments." |
| `/comments/feed/` and its spellings, when no address exists at `comments` | **410**, the same |
| An address that exists, or existed, at the whole path: an item called `rss`, a category called `feed` under a base | **Not a feed's:** `PublicController` answers it |
| Anything else: an unknown base, a date archive, a base starting with a first segment the router answers itself | **Not a feed's:** `PublicController`, a 404 in place |

**Why a 301 and not RSS at every spelling.** WordPress serves five formats at those names; Teme serves RSS 2.0. A reader subscribed to `/feed/atom/` follows a 301 to `/feed/`, updates its subscription, and sniffs the format from the document, as every current reader does. **Why an address that exists wins.** WordPress refuses `feed`, `rdf`, `rss`, `rss2` and `atom` as slugs at every level because its rewrite rules would collide. Teme sends only unknown addresses to the feed route, so there is nothing to collide with, and an imported site never has such a slug. Refusing them as slugs is parked (I-921). `Paths::SITE_ROUTES` holds `feed` as a first segment only, so an item deeper in an address may still be called `feed`. The feed itself, its settings and what it holds are in `docs/architecture/settings.md` (the `seo` group's Feeds section) and `docs/architecture/themes.md` (*The head*, for the discovery links).

**What a feed holds** (I-1067, I-1068, I-3298, I-3304): the published items of the types it covers, live (the main feed, the types Settings › Reading ticks; a term's, every public type that uses its taxonomy; an author's, the public types with an archive; a type's archive page's, that type), in their **published revisions**, never a draft's, a trashed item's or a scheduled one's; newest first by `published_at` then ID, at most `feed_items` of them. A term's feed holds the items filed directly under the term, as its archive does; an author's the items they wrote, and an author's name is the only thing a feed says of them. An item with no current address is left out; a subject with nothing yet is a valid empty feed. `description` is the excerpt (the writer's, or the first 55 words with an ellipsis); `content:encoded` is the body in full when `feed_content` is `full`, with every card drawn as its plain version (I-1069) and every link and picture address absolute; `media:content` is the featured image. A feed's `guid` is `teme:content/{id}`, `isPermaLink="false"`, because a reader remembers what it has shown by `guid` and the address changes with a slug, a pattern or a domain (I-1072). The statements a feed costs are the same whatever its length: every image, author, category and address is read in one statement.

**Not built yet:** Atom and JSON Feed (I-308; the format is a seam, `FeedFormat`, I-1077); comment feeds, comment counts and links (I-305, I-924); IndexNow and WebSub (I-862); WordPress's `?feed=` query addresses (I-925); a feed `guid` carried over from WordPress by the importer (I-922).

## Sitemaps and robots.txt

`robots.txt` and the sitemaps are the public site's own routes too, stored by the page cache like pages (`docs/architecture/page-cache.md`). Slice 1.9f built them (`docs/superpowers/plans/2026-10-08-phase1-slice09f-sitemaps-and-robots.md`, D1 to D23 are I-1097 to I-1119; `2026-10-08-phase1-slice09f-robots-txt-carried.md` is the robots.txt work it took over from 1.9e). `/robots.txt` is written by Teme (`RobotsTxt::text()`, I-300) and is no longer a file in `public/`. The sitemaps are all at the site's root, since a sitemap may list only addresses at or below its own folder (I-1105): the index `/sitemap.xml`; the stylesheet a browser shows them through, `/sitemap.xsl` (I-868); a child's first page `/sitemap-{name}.xml`; and each later page `/sitemap-{name}.{first key}.xml`, whose key is the ID of the first live item in the page's range, so no page is found by `OFFSET` (I-1106). Core's children are `home`, `items-{type}`, `terms-{taxonomy}` and `authors`. A page answers only for a key the index gave, so there are as many addresses as pages (I-1107). WordPress's `/wp-sitemap.xml` and Yoast's `/sitemap_index.xml` answer 301 to `/sitemap.xml` (I-1116). While the site discourages search engines the index, every page, the stylesheet and the two old addresses answer the site's own 404, and robots.txt loses its `Sitemap:` line (I-1115). What a sitemap lists, and how it is built, is in `docs/studies/seo.md` §3.7.

## Links and the editor

Internal links store the target's ID (`teme:content/{id}`, rule 9). `PathLinkResolver`, the renderer's `LinkResolver`, reads each target's current path once per render and gives its site-relative URL, or leaves the text unlinked when the target has none. The editor's rail shows the address an item would have (`EditorSession::$addressPattern`), and **View on site** opens its live address (`EditorSession::$url`, from `Addresses::urlFor()`).

## Moving every address: the recompute

When settings change, addresses move in the background, and the old ones stay as history, so every link that was ever shared keeps working.

`RecomputeAddresses` is a queued job carrying the person who asked (I-392). Each run takes up to 500 targets (`RebuildPaths::MOST`) after the last ID it reached, in the order content, then terms, then authors. It hands them to the `RebuildPaths` Operation, which authorizes that person (`settings.addresses.manage`, `terms.manage` or `users.manage`), locks the items, and syncs each. The run then queues the next. `RebuildPaths` is idempotent, so a batch run twice writes nothing the second time.

- **One run at a time:** each run holds the lock `addresses-recompute`, and releases it before it queues the next, so a synchronous queue is not refused by its own lock. A run that can't take the lock is released back to the queue for ten seconds.
- **Progress** is kept in the cache under `addresses.recompute` (`done`, `total`, `running`), and Settings › Addresses polls it every two seconds: "Updating addresses: 1,200 of 4,000".
- **Who queues it,** always after commit:

| Operation | Recompute |
|---|---|
| `ChangeAddresses` | All, when a pattern, a base, the author base or the home page changed |
| `UpdateTerm` | That term's items and its descendants' items, when a pattern uses `{category}` |
| `DeleteTerm` | All content, when a pattern uses `{category}`, since its items can no longer be named |
| `DeleteUser` | All content, when their items were reassigned and a pattern uses `{author}`; not for the operator, who has no user to carry (B-209) |

Between the change and the recompute reaching an item, the item still answers at its old address, which is still its current row. Nothing is ever unreachable.

## Scale

The benchmark (`teme:bench:run`, `docs/benchmarks/`) seeds one current address per published item and an old one for every tenth, and times three scenarios at a million items. The first run, `docs/benchmarks/1.6a.md`, was on a busy development machine and is provisional (I-154):

| Scenario | What it times | p95 | Budget |
|---|---|---|---|
| `address.lookup` | `Paths::find()` on 1,000 current and 1,000 old addresses, per lookup | 4.4 ms | 2 ms; judged by its plan and statement count until 1.13 (B-218) |
| `public.item` | `PathResolver::resolve()` (then `PublicAddress::resolve()`) for a random post: find, check, render | 29.3 ms | 150 ms |
| `address.recompute` | One `RebuildPaths` batch of 500 posts moving to `/{year}/{slug}/`, per post | 25.1 ms | 150 ms |

- **A lookup is one index read and one statement:** an index scan on `paths_path_unique`, 4 buffers, 0.088 ms in the database, whatever the table's size. The rest of the 4.4 ms is the round trip, which on this host is 2.1 ms even for `SELECT 1`, and Eloquent's model, about another 1.2 ms. The scenario times the full round trip; its millisecond verdict comes on 1.13's production-like server (I-154), and until then it is judged by its plan and its statement count (B-218).
- **The recompute moves about 40 items a second here,** three reads and two writes each, so **a million items take about 7 hours** on this machine. That is an upper bound: drafts and items in the trash have no address to move and cost one read each. Every item keeps answering at its old address meanwhile, so the time is how long the move takes to finish, never an outage.
