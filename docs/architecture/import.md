# Importing a WordPress site

How Teme brings a whole WordPress site across: what a run is, the steps it goes through and in what order, how it stops and carries on, how it proves every old address still answers, and what it reports. The evidence and the rejected alternatives are in `docs/studies/import.md` (study V), §4; the parts that built it are slice 1.11's plans, `docs/superpowers/plans/2026-10-11-phase1-slice11-part-map-and-questions.md` and the 1.11a to 1.11z plans beside it. This document describes the system as built. Code is cited as `path:line` at `slice/1.11`'s `d9c37444`.

## The pieces, and where each lives

| Concern | Where |
|---|---|
| The run's engine | `App\Domain\Import\ImportEngine` |
| The phases, in order | `App\Domain\Import\Phases\ImportPhases`, reading whatever is tagged `teme.import.phases` |
| The jobs | `ImportListPage` (a page of a list) and `RunImportStep` (one stretch of a step), in `App\Domain\Import\Jobs` |
| The run's Operations | `StartImportRun`, `AdvanceImportRun`, `StopImportRun`, `ContinueImportRun`, `FinishImportRun`, `ImportObject`, `RecordImportFailure` and the recorders, in `App\Domain\Import\Operations` |
| Import mode | `App\Domain\Import\ImportMode`, bound scoped |
| The map, the runs, the screen | `ImportMap`, `ImportRuns`, `ImportScreen`, in `App\Domain\Import\Queries` |
| The companion plugin's client | `CompanionClient` and `CompanionClients`, in `App\Domain\Import\Source` |
| The public-address check | `App\Domain\Support\Network\PublicAddresses` |
| Who a job acts for | `App\Domain\Support\Security\JobActor` |
| The operator's commands | `teme:import` (`app/Console/Commands/ImportSite.php`) and `teme:import:walk` (`app/Console/Commands/WalkImportAddresses.php`) |
| The screen | Tools › Import, drawn from `ImportScreen` |

`App\Providers\ImportServiceProvider` wires all of it, and registers core's phases one tag line each (`app/Providers/ImportServiceProvider.php:52-82`).

## The tables, and the import map (I-332)

Four tables, as study V §4.1 designed them, with one change: the application password lives on the source's row, encrypted, not in a `secret` setting (1.11a; `app/Domain/Import/Models/ImportSource.php:41`).

| Table | What it holds |
|---|---|
| `import_sources` | A WordPress site Teme imports from, keyed by its home address: the API root, the user name, the application password (encrypted), the companion's plugin and API versions, and when it was probed or forgotten |
| `import_runs` | Each run: its kind, its state, the phase it is in and that attempt's token, its options and choices, its counts, its high-water mark, why it stopped, who started it, and when it started and ended |
| `import_map` | **One row per source object**, unique on (source, object type, object ID) (`database/migrations/2026_11_21_100200_create_import_map_table.php:48`): what it became in Teme, its status, the last run's outcome, the hash of the source's item, the run that last touched it, and its `facts` |
| `import_messages` | Each warning or error, tied to a map row, or to the run alone |

**The key is the WordPress ID, never the slug.** A post whose slug changed between runs is still the same row.

**A map row's status** is what the next run does with it (`app/Domain/Import/ImportStatus.php`):

| Status | Meaning |
|---|---|
| `imported` | It became something in Teme; a `CHECK` holds that it has a Teme ID (`database/migrations/2026_11_21_100200_create_import_map_table.php:43`) |
| `needs_update` | Marked for the next run to meet again |
| `skipped` | Brought across as nothing, on purpose, and said why |
| `failed` | It couldn't be imported; its one error is on the row |

**The outcome** is what became of the object in the run that last touched it: `created`, `updated`, `skipped` or `failed` (`app/Domain/Import/ImportOutcome.php`). Created and updated leave the row `imported` (`:253-260`). The report counts outcomes, never statuses.

**The work list needs no cursor.** An object is on it when it has no map row, when its row is `needs_update`, or when the hash of its source item differs from the row's (`ImportMap::wants()`, `app/Domain/Import/Queries/ImportMap.php:37-40`). The hash is the SHA-256 of the item's JSON, its keys sorted, less the keys a list says mean nothing (`:26-31`); a post's password is keyed with the site's key first, so the map holds no unkeyed hash of it (`app/Domain/Import/Jobs/ImportListPage.php:234-241`). So running again is how a run resumes: what is done is skipped, what changed is done again.

**`facts`** is what a phase keeps for an object that Teme has no column for: the fingerprint of what the import last wrote (`written`), the references and links the second pass will finish, the revision the fidelity check last compared. A person's save in Teme changes the item's fingerprint, and every later step that would write the item first compares it with `written`: **a person's change here is never undone.**

## A run

**Starting.** `StartImportRun` (`app/Domain/Import/Operations/StartImportRun.php`) starts a **full** run or a **catch-up** (`ImportRunKind`). It refuses a second run of the same site (`:89-91`, and a partial unique index on running runs, `database/migrations/2026_11_21_100100_create_import_runs_table.php:58`), a catch-up before any finished run (`:93-95`), and a site whose password Teme has forgotten. Its options are the pause between pages, `pause_ms`, 1,000 by default and 0 to 60,000, to spare the old site, and, for an export file, `media_lanes`, the media files fetched at once (below). It marks every failure the site has `needs_update` (`:111-113`), records the run's high-water mark, and after commit hands the run to the engine.

**The engine** (`app/Domain/Import/ImportEngine.php`) runs the phases in their order, **each phase as one `Bus::batch`** of queued jobs (`:132-138`):

- **A list phase** reads one of the companion's lists a page at a time, in WordPress's ID order. Its batch starts with each list's first page; each `ImportListPage` imports its page's objects, then adds the next page to the batch (`ImportListPage.php:127-131`). A page job starts no new object once 45 seconds have gone (`ImportListPage::SECONDS`), so the slowest, an attachment's 25, still ends inside its 75: the next page then starts after the last object done, so nothing is skipped or done twice, and the media list can be read twenty at a time (#254).
- **A step** is one job that works from a cursor and, when the step names a next cursor, adds the next job (`RunImportStep.php:107-111`). Steps that read much give a job fifty seconds, then carry on where they were.
- Every job fails after three errors, waiting 30 then 120 seconds between them (`$maxExceptions`), and is timed out at 75, under the database queue's `retry_after` of 90. **The old site not answering** (a retryable `SourceRefused`: unreachable, or busy) isn't one of them: the job goes back on the queue to try again after 30, 60, 120, 300, 300, 600, 600, 900 and 900 seconds, about an hour in all, then fails (`Jobs/Concerns/WaitsForTheSource.php`, #255). Each next page or stretch waits the run's pause.
- When a phase's batch ends, the engine moves the run on through `AdvanceImportRun` under the run's lock, or **stops it if any job failed for good** (`ImportEngine.php:88-93`), or finishes it after the last phase (`:97-101`).

**Each object is committed on its own.** `ImportObject` runs the phase's `import()`, then writes the map row and its messages, in one transaction, so a failure leaves neither (`app/Domain/Import/Operations/ImportObject.php`). An object's old messages are cleared when it is imported again (`:76`). An object that fails is recorded by `RecordImportFailure` with its one error, keeping the Teme ID and hash an earlier run gave it, and the page goes on: one bad post never rolls back a thousand good ones (`ImportListPage.php:192-217`).

**Tokens.** Each attempt at a phase has a token, held on the run's row and carried by every job. A job whose token is no longer the run's does nothing (`ImportListPage.php:78-80`, `RunImportStep.php:60-62`), and `ImportObject` reads the run's row `FOR SHARE` and checks the token before it writes (`ImportObject.php:46-51`). So a stopped or superseded attempt's leftovers write nothing, and a stop waits for the objects in flight.

**Import mode** (`app/Domain/Import/ImportMode.php`). Each job does its work inside `ImportMode::during()`, which only a running run may enter (`:35-46`). While it is on, every Operation's purge of the page cache is held (`app/Domain/Support/Operations/BaseOperation.php:67-77`), and the run ends with **one purge of everything**, after its last commit, whether it finished or stopped (`app/Domain/Import/Operations/Concerns/TalliesRuns.php:37-40`). The mode is bound scoped, so it ends with the job (`app/Providers/ImportServiceProvider.php:49`). Hooks are not held yet: there is no hook dispatcher until phase 5, and the signpost for gathering them is at `BaseOperation.php:70`.

**Counts** are tallied from the map and the messages when a phase or the run ends, never one object at a time, so parallel workers never queue on the run's row (`TalliesRuns.php:21-32`).

**Media in parallel (#701).** An export file's media list is read in **lanes**: `MediaPhase::lists()` gives one `ImportList` per lane, and lane k of N reads the attachments whose ID leaves k over N (`FileReader::rows()`, `mod(object_id, N) = k`), so the engine's one batch runs N chains side by side with no change to the engine or the page job, and no two lanes share an attachment. Each lane pages in ID order as a single list does, so stopping and carrying on are unchanged: a carried-on run starts every lane again from the start, and the map's hash skips each attachment already brought before any download. N is the media setting *Files fetched at once in an import* (`media.import_downloads`, 4 by default, 1 to 8), frozen into the run's `options.media_lanes` when it starts so a change never moves a running import's lists; a run may ask for fewer, and a run started before 1.5.0 has none and reads one lane. Lanes run at once only when as many queue workers take the default queue: the image starts `TEME_QUEUE_WORKERS` of them (`docker/teme/lib/queue-workers`), one by default, so the lanes go as fast as the fewer of the two allows. Each lane waits the run's pause between its pages, so the old site gets up to N requests in each pause. Identical bytes in two lanes still make one item (`ImportMedia`'s lock on the bytes' hash), and `RecordMediaFiles` inserts an attachment's file addresses sorted, so two lanes never wait on each other's rows. **A live import reads one lane** until the companion can page by ranges (`before_id`, below); the one condition to change then is `$context->readsFile()` in `MediaPhase::lists()`.

**Articles first, pictures after (#716).** The plan's third media choice, `media: later`, for a file and a live site alike, brings the posts without downloading a file: `MediaPhase` lists nothing, and where the converter finds an own image Teme has no file for, it writes an **outside picture**, an Image card that holds the old site's address (`source`, with `sourceWidth` and `sourceHeight`) in place of a `mediaId` (`BlockContext::outsideCard()`). An export file's featured image does the same from the file's attachment row, `{source, width, height, alt}` in place of `{id}` (`ContentPhase::outsideFeatured()`); a live post names only its featured image's ID, so it has none until the file comes. Each post's map row keeps what its pictures wait for in `facts.waiting_media`, `a:{attachment ID}` and `f:{original file below uploads}` (`ImageTrace::waitingKeys()`), and the featured image's attachment in `facts.waiting_featured`, under a GIN index of only the rows still waiting (`import_map_waiting_media`). The site, the editor's preview, feeds and Markdown draw an outside picture from its address with no referrer; a featured one reaches a theme as an image ID of its own, `outside:` and its address encoded (`OutsideImage`), which `ImageMarkup::render()` draws, so themes change nothing. Only an import writes one: `DraftChecks` and `CreateDraft` refuse a save that adds an outside picture the item didn't hold (`OutsidePictures`). When the import finishes, the engine starts a **picture pass** (`StartMediaPass`), a run of kind `media` that `follows_run_id` the import, with its choices, pause and lanes, and runs only the phases that implement `RunsInMediaPass`. For an export file, `media` reads the attachments posts wait for by ID (`FileReader`'s `waited: only`) and `media-rest` the others; a live pass reads every attachment in `media`. After each page of files, the page job queues `SwapPictures`, which passes the posts waiting for those files through the second pass, 25 a job, one save each through `RecordSecondPass` and `CompleteImportedContent`: `PictureSwap` turns each outside picture whose file is now in the library into that item, keeping its words, and the featured image likewise. A post changed here since the import keeps its outside pictures and gets `second-pass.pictures-changed-here` on the report. The swaps' purges are held in import mode and purged after each job, only those posts' pages (`ImportMode::hold()`, `takeHeld()`). The second pass swaps too, from the converted words each time, so it never undoes a swap. `pictures-left` ends the pass with a count of the posts still waiting; *Bring the pictures again* on the import's report starts another pass, which tries the files the old site refused (failed or skipped) again.

## The step table

Every phase has a key, an order and a label. **Core's phases sit at the hundreds, a part's follow-up step at a ten inside its hundred, and a plugin's at a number that isn't a multiple of ten** (I-349; `app/Domain/Import/Phases/ImportPhase.php:19`). The registry refuses a malformed key, an order below 1, two phases with one key or one order, and a class that is both a list phase and a step (`app/Domain/Import/Phases/ImportPhases.php:28-70`).

| Order | Key | What people read | Kind | Runs in | What it does | Where |
|---|---|---|---|---|---|---|
| 50 | `address-clash` | Addresses both sites claim | step | both | Lists each old address of a post, page, category or tag that Teme already gives something the import didn't make (I-1706). As WordPress's importer does, the run carries on: a category or tag of the same taxonomy at the same address is the one the imported posts are filed under (the terms phase says so, `term.shared`, and never changes it), and a post, page or person whose address is taken gets the next free one (-2, -3); a published post with the same type, title and publication time as one here is that post (`post.already-here`). A category or tag whose address something else holds (a page, with no category base) still stops the run, since its address follows its base and the rebuild of addresses can't move it aside | `Phases/Addresses/AddressClashStep.php` |
| 100 | `address-settings` | Address settings | step | both | The old site's address settings, time zone and language, so everything made takes the address it had (I-393) | `Phases/Addresses/AddressSettingsStep.php:44` |
| 200 | `users` | People | list | both | Each account becomes a Teme person with their role and WordPress hash, or is matched by email (I-340) | `Phases/Users/UsersPhase.php:54` |
| 210 | `authors-waiting` | Posts waiting for their author | step | both | Marks the posts credited to the fallback owner for work again when this run made or matched a person (I-2998) | `Phases/Users/AuthorsWaitingStep.php:31` |
| 300 | `terms` | Categories and tags | list | both | Each category and tag at its own address (I-397) | `Phases/Terms/TermsPhase.php:44` |
| 310 | `term-parents` | Category parents | step | both | Places each category WordPress listed before its parent | `Phases/Terms/TermParentsStep.php:38` |
| 350 | `old-addresses` | Old addresses | step | both | Keeps every old address of a category, tag or author that now has another address, as an old address leading to it by ID (I-339) | `Phases/Addresses/OldAddressesStep.php:48` |
| 400 | `media` | Media | list | both, and pictures | Each attachment's original, downloaded through the safe downloader before its transaction, stored once by its bytes (I-338) | `Phases/Media/MediaPhase.php:76` |
| 420 | `media-rest` | Other media | list | pictures | In a picture pass of an export file, the attachments no post waits for, after `media` brought those posts wait for (#716); delegates each to `MediaPhase` | `Phases/Media/MediaRestPhase.php` |
| 450 | `outside-images` | Images from other sites | step | both, and pictures | Images on the hosts the person ticked, and the old site's own images that the media list didn't bring when downloads are wanted, brought into the library before posts convert (I-1703) | `Phases/Media/OutsideImagesStep.php:56` |
| 480 | `content-visibility` | Private and protected posts brought again | step | full | Marks for work again the posts and pages imported before Teme kept items private or protected (I-2643) | `Phases/Content/ContentVisibilityStep.php:30` |
| 490 | `content-waiting` | Posts waiting for their media | step | full | When this run brought media, marks for work again the posts whose media was missing | `Phases/Content/ContentWaitingStep.php:30` |
| 500 | `content` | Posts and pages | list | both | Each post, page and reusable block, converted before its transaction and written as WordPress had it, or brought up to date while nobody changed it here (I-335) | `Phases/Content/ContentPhase.php:100` |
| 590 | `media-parents` | Media’s posts and pages | step | both, and pictures | Records each attachment's Uploaded for, and sends its attachment page on with a 301 (I-553) | `Phases/Media/MediaParentsStep.php:42` |
| 600 | `second-pass` | Reusable blocks and links | step | both, and pictures | The second pass (I-333), below | `Phases/SecondPass/SecondPassStep.php:62` |
| 700 | `comments` | Comments | list | both | Each comment, pingback and trackback whose item came across, in ID order so a parent comes before its replies (I-341) | `Phases/Comments/CommentsPhase.php:68` |
| 780 | `comment-counts` | Comment counts | step | both | Rebuilds every item's comment counts once, never per comment (I-273) | `Phases/Comments/CommentCountsStep.php:28` |
| 790 | `comment-pages` | Comment pages | step | both | Redirects WordPress's `/slug/comment-page-N/` to where those comments are now (I-610) | `Phases/Comments/CommentPagesStep.php:47` |
| 800 | `menus` | Menus | list | both | Each classic menu and published navigation post, its items leading by ID (I-343) | `Phases/Menus/MenusPhase.php:55` |
| 820 | `unplaced-menus` | Unplaced menus | step | both | Says once how many menus this run made (`menus.unplaced`): they come across unplaced, for the person to place under Content › Navigation; writes nothing | `Phases/Menus/UnplacedMenusStep.php` |
| 900 | `redirects` | Redirects | step | both | The old site's redirect rules, a rule at a time through `ImportOldRedirect` (I-297) | `Phases/Redirects/RedirectsStep.php:44` |
| 1000 | `settings` | Settings | step | both | Title, tagline, feeds, the search engines' switch, Discussion, the comments module and its page, and the SEO plugin's sitemaps sent on to `/sitemap.xml` (I-342, I-1717) | `Phases/Settings/SiteSettingsStep.php:50` |
| 1100 | `check` | Address check | step | both | The check, below (I-339) | `Phases/Check/CheckStep.php:51` |
| 1200 | `fidelity` | Compared with WordPress | step | both | The fidelity check, below (I-337) | `Phases/Finish/FidelityStep.php:55` |
| 1210 | `recount` | Counts | step | both | Checks once the counts the import kept item by item, and fixes where they drifted (I-141) | `Phases/Finish/RecountStep.php:30` |
| 1250 | `deleted` | Deleted on WordPress | step | catch-up | Lists what WordPress deleted since it was imported, and counts the failures the catch-up didn't meet (I-345), below | `Phases/Finish/DeletedStep.php:77` |
| 1260 | `file-missing` | Missing from the file | step | full | On a run of an export file only: lists each item an earlier import of the same site brought that the newer file leaves out, and deletes nothing; *A file as the source*, below | `Phases/Finish/FileMissingStep.php` |
| 1270 | `pictures-left` | Pictures still on the old site | step | pictures | Says once how many posts still show pictures from the old site because their files couldn't be copied, so a person brings them again while it's up (#716) | `Phases/Media/PicturesLeftStep.php` |

**The new site's structure is the theme's.** The import brings content, not how the old theme was put together: no widgets (`SiteAnalysis` lists them under *Not imported* as "Widgets and theme settings"), no question of which template a WordPress page template becomes (every page takes the active theme's default), and no question of where each menu goes (the menus and their items come across unplaced, and `unplaced-menus` says so once). Choices an earlier release kept for these, `menu_locations` and `templates`, in a run's stored choices or a site's remembered ones, are ignored, never refused. A run that was at a step this release retired (`ImportPhases::RETIRED`: `menu-places` at 810, `widgets` at 890) carries on with the next step after that order (`ImportPhases::resume()`), never from the start.

Paths are under `app/Domain/Import/`. A full run goes through 25 steps; a catch-up through 23. 1.11a's own table had a single `finish` at 1200; 1.11n made it `fidelity` at 1200 and `recount` at 1210 (`docs/superpowers/plans/2026-10-18-phase1-slice11n-second-pass-and-catch-up.md:3880`).

The probe, the site report and the person's choices come **before** a run, when Tools › Import connects the site and plans the import (`app/Domain/Import/Planning/`), so they are not phases. An item's SEO values come with the item, in the content phase (`Phases/Content/WordPressSeo.php`), so there is no SEO phase.

## Who may import, and who a run acts for

**Who.** The operator at the server's shell, or a person who holds `imports.run` (`app/Domain/Import/Operations/Concerns/AuthorizesImports.php:20-29`). Administrators hold it (`database/migrations/2026_11_21_100400_add_import_and_jobs_permissions.php:21`). **Every** import Operation asks this first, including each object's `ImportObject`. Starting and carrying on a run also ask a person for their password again (`RequiresElevation`, `StartImportRun.php:46-49`, `ContinueImportRun.php:35-38`); the operator isn't asked.

**A run acts for whoever started it** (I-392). `StartImportRun` records `started_by` (`:107`), and after commit gives the engine a `JobActor` for that person, or for the operator (`:126-130`). Every job and every batch's end carries that actor, and calls every Operation in its name:

- `JobActor` is a person's ID, or the operator. The operator can be made **only while the operator is present at the shell** (`app/Domain/Support/Security/JobActor.php:42-49`), so a job queued from a web request can never act as the operator. On the worker, `bind()` gives the operator's elevation and nothing else does (`:70-75`); elevation is bound scoped, so it ends with the job.
- Because each Operation checks `imports.run` again, a run whose person has lost the permission writes nothing more: the job fails with no retry (`ImportListPage.php:111-114`, `RunImportStep.php:92-97`), its phase's batch ends with a failed job, and the engine stops the run.
- The run is also a tracked job in Tools › Jobs (I-1714, `app/Domain/Import/Tracking/ImportRunJobs.php:37`), each step it reaches told there. Tracking never stops an import.

**The security log** records four events: `import.source_connected`, `import.started` (with `continued` true when a run is carried on), `import.ended` (finished or stopped, with its counts, and a stop's reason) and `import.source_forgotten` (`app/Domain/Security/SecurityEventType.php:35-38`). None is written per object.

**The operator's command.** `teme:import {address}` (`app/Console/Commands/ImportSite.php:40-48`) binds the operator's elevation and acts with a null actor, as every `teme:` command does (`docs/architecture/operations.md`, *The operator*). It connects a site, asking for the application password with a hidden prompt (`:122`), and starts a full import; with `--catch-up`, `--continue`, `--stop`, `--forget` or `--status`, it does that instead; `--pause` sets the pause between pages. A queue worker does the work; the command never waits for it.

## Stopping and carrying on

**A run stops** through `StopImportRun` (`app/Domain/Import/Operations/StopImportRun.php`): by a person's word, on Tools › Import or with `teme:import --stop`, or by a job's. It sets the run `stopped`, clears its token so every job still queued does nothing, keeps a reason and the words people read (`:64-68`), tallies the counts, logs `import.ended`, and purges the page cache once (`:79`). A job's stop names its attempt's token, and a stale token stops nothing (`:56-59`). A job stops the run when:

- the old site refuses in a way no retry would help (`SourceRefused`, not retryable), or its address now resolves somewhere Teme won't go (`AddressRefused`); the words end "Then run the import again: it carries on from where it stopped." (`ImportEngine.php:35`);
- a phase asks for a stop (`ImportStopped`), as the address clash, the address settings and the languages do;
- a job of the phase failed for good: three errors, or the old site not answering it for about an hour (`page-failed`, `ImportEngine.php:88-93`).

**A run carries on** through `ContinueImportRun` (`app/Domain/Import/Operations/ContinueImportRun.php`), with `teme:import --continue` or from Tools › Import. Only a stopped run carries on, and only while no other run of the site is running (`:60-78`). It gets a new token, marks every failure `needs_update` again (`:90-91`), and the engine starts **the phase it stopped in**, or the first phase when that phase is no longer registered (`ImportEngine.php:54`). Inside the phase it starts over: a list phase from its first page, a step from no cursor. **Starting over costs reads, never duplicates** (`ImportPhases.php:100-104`): the map skips what is done.

The import benchmark, `teme:bench:import` (`app/Console/Commands/BenchImport.php:24`), stops a run of 50,000 posts and pages halfway and carries it on, and times every step (`docs/superpowers/plans/2026-10-18-phase1-slice11z-proof-and-tag.md`, D4 to D6).

## The check and the walk

Criterion 6 of the spec asks that every URL be preserved or redirected. Two things prove it, each asking the same addresses: the companion's `urls`, every public address of the old site with its kind (`OldSiteUrls::KINDS`: items, terms, authors, attachment pages, attachment files, and the home page and archives; `app/Domain/Import/Phases/Addresses/OldSiteUrls.php:20`).

| | The check | The walk |
|---|---|---|
| What | Step `check`, at 1100, in every run | `teme:import:walk {address}`, a console command |
| Asks through | `SiteAnswers::at()`, in process: the path table and the redirects, the view model built, nothing drawn, no middleware, no template (`docs/architecture/addresses.md`, *Other readers of the same rules*) | The application's HTTP kernel, as a reader's GET with no cookies, each address in a fresh scope (`app/Domain/Import/Queries/AddressWalk.php:138-155`) |
| Catches | An address that answers nothing, or a redirect that leads to nothing | That too, and a page whose middleware or theme template fails |
| Writes | The run's messages | Nothing itself; its requests write what a reader's do |
| Says | An error per address, in the report | A line per address, and exit 1 when any doesn't answer (`app/Console/Commands/WalkImportAddresses.php:79-89`) |

**Both** follow a redirect on this site at most two more steps (`CheckStep::HOPS`, `app/Domain/Import/Phases/Check/CheckStep.php:37`): its canonical form, then an old address's own. An address passes when it ends on a page (200), or on a redirect to another site or to the site's own files. An address on another host is left alone and said once. An address that differs from another only by what follows the `?` fails: Teme doesn't tell those apart, so readers reach the home page.

**The check's errors** (`CheckStep.php:151-196`) each hold up "Ready to switch":

| Code | When |
|---|---|
| `check.not-found` | Something the import brings answers "not found": bring it, or add a redirect |
| `check.not-brought` | Something the import never brings, such as a custom type's item, answers "not found": add a redirect, and the message suggests one pattern for every address under that part |
| `check.redirect-not-found` | A redirect sends it on to an address that answers "not found" |
| `check.query` | It differs only by its query string |

One more error holds it up, from the settings step (1000) of a live run: **`setting.front-page-empty`**, when the page the old site showed as its front page shows nothing here, because all it held was layout the content phase left out (`block.dynamic` and `block.contents-list`, such as a post list, a query loop or a theme's sections). The message names those blocks, at most three and then "and others", and the report lists it first (`SiteSettingsStep::emptyFront()`, `ImportScreen::report()`). A page with content of its own, or one that held nothing left out, says nothing. An export file sets no front page, so a file's run never says it.

**The old site's file addresses** (`/wp-content/uploads/…`) are answered by one rule for the whole folder, not a redirect row per file: `RedirectLookup::oldFile()` (`app/Domain/Redirects/RedirectLookup.php`) reads the import map's `file` rows, which the media phase writes for every attachment's original, main file, edited copies and sizes, for a live import and a file's alike. The public fallback asks it after the path table and after every redirect a person made, so neither a live page nor a person's own redirect is hidden; `wp-content` is kept from items and people (`Paths::OLD_FILES`) so none can take those addresses. It answers a permanent redirect: a resized copy (`-{w}x{h}`) to the item's narrowest variant at least as wide, else its widest; any other file of the item to its widest variant (an image's original is private), or a file that isn't an image to its original. While an image's variants are still being made it answers 503 with `Retry-After` and no copy kept, so no page cache holds a "not found". The check counts each such address as handled, in one warning-level line (`check.old-files`, "N old file addresses now redirect to the media library"), counted across its jobs in its cursor; a file the import didn't bring stays `check.not-found`.

**A file's media kept as links** (the `links` choice) are the person's choice, not a problem: the check leaves out the old site's attachment pages and files, and any address below its uploads folder or asking for `?attachment_id=`, and says once, as a warning, how many media files stay on the old host and how many brought posts' featured images with them (`check.media-kept`); the content phase writes no `post.featured-missing` for each post, and an image in a post's text from the old site's uploads that wasn't among the media files stays a link to that address (`image.outside`) instead of being left out. No error is made, so "Ready to switch" doesn't count them, and since no `file` row is written, the uploads rule doesn't answer for them.

`check.other-status` (another status) and `check.other-host` are warnings. The check works fifty seconds a job (`:34`), and started again from the beginning it clears its earlier messages first (`:66-68`).

**Why the walk exists** (11z's D3): the check never runs the middleware or the theme's template, so an item whose template fails would pass the check and fail a reader. The walk uses the application password Teme already keeps. **It isn't free of effects:** each address that answers 404 becomes a row of the 404 log, each redirect it follows counts a hit, and with the page cache on each page it draws is stored. So run it before the site is public, or expect its 404s and hits in Tools › Redirects; and turn the page cache off for it, or expect a warm cache (`docs/superpowers/plans/2026-10-18-phase1-slice11z-proof-and-tag.md`, D3). 1.13's runbook runs the same command after the cut-over (I-385).

## The second pass (I-333)

During conversion every reference to something that may not exist yet (a reusable block, a link to another post, a canonical address) is kept as a placeholder in the map row's `facts`. Once everything the run brings exists, `second-pass` at 600 finishes them (`app/Domain/Import/Phases/SecondPass/SecondPassStep.php`):

- **What it reads:** each post and page whose row holds references, pending links or a pending canonical (`:109-122`), a hundred rows at a time, fifty seconds a job.
- **What it writes:** the item's finished document, rebuilt from the converted one, written by `CompleteImportedContent` as **one new revision of origin `import`** (`app/Domain/Contents/Operations/CompleteImportedContent.php:31-36`, `:99`), under the object's lock, through `RecordSecondPass` (`app/Domain/Import/Operations/RecordSecondPass.php`). A row an earlier run wrote is counted as `updated` in this run when the pass changes it (`:111-116`).
- **A person's change is never undone:** an item changed here since the import wrote it is left as it is, and said (`second-pass.changed-here`, `SecondPassStep.php:183-185`).
- **What can't be finished is said, never dropped:** a link nothing the import brought answers stays an address on this site (`second-pass.links-unresolved`); a canonical that leads nowhere sends search engines to the item itself (`second-pass.canonical-unresolved`); a missing or looping reusable block is said. Each is a warning, and **every later run tries it again**.
- A reusable block changed on WordPress reaches every item that uses it, at any depth, on the next run.

## The fidelity check (I-337)

`fidelity` at 1200 compares each imported post and page with how WordPress renders it today (`app/Domain/Import/Phases/Finish/FidelityStep.php`):

- **Which items:** those whose working revision isn't the one last compared (`:103`), and that are as the import left them, since a person's save is theirs and never compared (`:124-128`).
- **How:** WordPress's rendered page, read from the companion's `content` list with `include=rendered`, twenty items a page, only the pages that hold an item to check; Teme's document drawn to HTML; the words a reader reads taken from each, the same way (`FidelityText`, which leaves out scripts, styles, players and forms, lower-cases and straightens quotes); and the words WordPress has that Teme's copy lacks counted (`app/Domain/Import/Phases/Finish/Fidelity.php:37-53`).
- **The flag:** more than 2% of WordPress's words lost (`Fidelity::MOST_LOST`, `:16`) is a **warning**, `fidelity.lost`, on the item: the share, the counts, and where up to three missing passages begin, eight words each (`:19-22`; `FidelityStep.php:215-231`). It is the one place a message holds the old site's words (1.11n's exception), because a person can't find a loss in a long article without them.
- Each comparison is recorded on the map row by `RecordFidelity`, so an unchanged item is never compared twice.

A fidelity warning doesn't hold up "Ready to switch": the import still brought the item, and the warning says where to look.

## Catch-up (I-345)

A site can be imported days or weeks before the switch. A **catch-up** brings only what changed since, so writers lose nothing and the old site is frozen only for minutes.

- **It needs a finished run.** Its options and choices are the last finished run's (`ImportScreen::catchUpWith()`, `app/Domain/Import/Queries/ImportScreen.php:179-184`).
- **Since when:** an hour before the last finished run's high-water mark, an hour for clocks that differ, which costs reads, never writes (`app/Domain/Import/Queries/ImportRuns.php:55-72`).
- **What it fetches by date:** posts and pages, media and outside images ask the companion with `modified_after` (`Phases/Content/ContentPhase.php:125-127`, `Phases/Media/MediaPhase.php:91`, `Phases/Media/OutsideImagesStep.php:79`). People, terms, comments, menus and redirects are read whole, and the hash skips what didn't change.
- **What it skips:** `content-visibility` and `content-waiting`, which run in full imports only.
- **What it adds:** `deleted` at 1250 compares WordPress's lists with the map and lists each person, category, tag, file, post, page, comment and menu WordPress no longer has but Teme still does (`catch-up.deleted`, up to 500 of a kind, then a count). **Nothing is deleted or changed** (`app/Domain/Import/Phases/Finish/DeletedStep.php:20-27`).

**What a catch-up doesn't retry** (#157). Every run, full or catch-up, starts by marking every earlier failure `needs_update` (`StartImportRun.php:111-113`). A **full import** reads every object, so it meets and retries them all. A **catch-up** reads only what changed on WordPress, so a failure whose item didn't change is never met. At its end `deleted` counts them (`ImportMap::failedBefore()`, `app/Domain/Import/Queries/ImportMap.php:88-96`) and says so as an **error**, `catch-up.not-retried`: "N items that failed before weren’t tried again, since a catch-up only fetches what changed on WordPress. Run a full import to retry them." (`DeletedStep.php:370-388`). Being an error, it holds up "Ready to switch" for that run; a full import retries them.

## The report and its verdict

Tools › Import shows each run's report from `ImportScreen::report()` (`app/Domain/Import/Queries/ImportScreen.php:219-281`):

- **One outcome per source object**, counted by kind and outcome: created, updated, skipped, failed (study V §4.13). No silent skips: a skipped object has a row and its reason.
- **Its messages grouped by code**, errors first, then by how often, each with the first one's words; at most 50 codes on the screen (`:44`), every one in the files.
- **Problems:** the run's errors, of any kind: a failed object, an address the check found, a catch-up's untried failures.
- **The verdict**, `ready`: for a finished run whose kinds include the check, **true when it has no errors**, false otherwise; null while it runs, when it stopped, or with no check registered (`:279`). The screen reads "Ready to switch: nothing failed and every old address answers on Teme.", or "Not ready to switch yet: N problems need attention. Fix them on WordPress or here, then catch up." (`resources/js/components/import/run-report.tsx:136-142`). Warnings never hold it up.
- **Two files** (`:288-332`): the full report, a CSV line for each object the run touched (`type,wordpress_id,outcome,teme_type,teme_id,messages`) then the run's own messages; and the **attention** file, only the objects that failed or have a message (Ghost's errors file). Each is named `import-{host}-{date}.csv` or `…-attention.csv` (`:337-346`), and a cell a spreadsheet would run as a formula starts with `'` (`:367-379`), as the redirects' export does.

`teme:import {address} --status` prints the latest run's kind, state, times and counts, the step it is on, and why it stopped (`app/Console/Commands/ImportSite.php:140-167`).

## The companion plugin

Teme reads a WordPress site through the **Teme Companion** plugin, in its own repository (GPL, PHP 7.4). Its versions:

| Version | What it is |
|---|---|
| Part 1 | The site report, run on five sites (study V §3) |
| **0.2.0**, part 2 | The export API Teme reads, merged on 2026-09-22: `probe`, `analyze`, `settings`, `users`, `terms`, `media`, `content`, `comments`, `menus`, `redirects` and `urls`, under `teme/v1`, behind application passwords and `manage_options`, keyset pages (`after_id`, `limit`) and `modified_after` (part map, `docs/superpowers/plans/2026-10-11-phase1-slice11-part-map-and-questions.md:37`) |
| 0.3.0, part 3 | Not built: the gaps below, the smoke check and the README (part map `:95-101`) |

**Teme expects 0.2.0 or later** (`CompanionPlugin::VERSION`, `app/Domain/Import/Planning/CompanionPlugin.php:13`), and Tools › Import says so; it has no download link until I-1711's public repository has a release (`RELEASES = null`, `:16`). **The probe** asks the plugin without signing in for its name and API version, refuses anything not `teme-companion`, an API below 1 (`CompanionClient::MIN_API`), or application passwords switched off; then reads `settings` signed in, which proves the user name, the password and the administrator's rights at once (`app/Domain/Import/Source/CompanionClient.php:41-64`). Teme finds the API at `/wp-json/`, then at `?rest_route=/` for a site on plain permalinks (`app/Domain/Import/Source/CompanionClients.php:28-47`).

**What 0.2.0 doesn't send, and so what imports lack until part 3** (11z's D13, `docs/superpowers/plans/2026-10-18-phase1-slice11z-proof-and-tag.md:101`):

| Not sent | What follows |
|---|---|
| Each item's `guid` (I-922) | Teme stores none, so a feed reader sees each imported post once as new after the cut-over. `WordPressPost::guid()` takes it when it comes |
| A trashed item's trash time (Q10) | Today's time instead, so it leaves the trash 30 days after the import, not after its own trashing |
| The privacy page, the comments' default page and the theme's logo (I-1880) | Not imported |
| Yoast's hold and block lists (I-1881) | Not imported |
| A `before_id` for downloads in parallel (I-1850) | A live import's media can't be downloaded in parallel; an export file's can, in lanes (#701, above) |
| Each shortcode's own output (B-841) | Teme has the shortcode, not what WordPress drew from it. The registered shortcodes come from the site report (`app/Domain/Import/Phases/Content/ContentSite.php:85-96`) |

Every address criterion 6 asks about comes from 0.2.0's `urls`, so the proof stands on 0.2.0.

## A file as the source

The companion is the full path. **An uploaded export file is the second**, for a site's content where the plugin can't be installed, or the site is gone: the `.xml` file from WordPress's Tools › Export (WXR). Spec §5.10 says why it is not the first. The run, its steps, the check and the report are the companion's; only the reader differs.

**Reading.** The upload goes up in pieces, and `ReadImportFile` (`app/Domain/Import/File/Jobs/ReadImportFile.php`) streams it once into `import_file_rows`: one row for each item, term, author, comment and menu, as `(file_id, list, object_id, payload)`, the payload in the shape the companion's route of that list answers. The file is read, never trusted: one that isn't an export or carries a DOCTYPE is refused with a sentence (`app/Domain/Import/File/WxrRefused.php`), and one larger than `teme.import.max_file_mb` (`config/teme.php`) is refused at the upload.

**The reader.** `SourceReaders` hands a run the reader its source needs: `CompanionClient` for a site, `FileReader` (`app/Domain/Import/Source/FileReader.php`) for a file. `FileReader` answers `page()` and `whole()` from the file's rows, so the phases can't tell the two apart. The lists a file can't hold answer as a site without them would: empty, or with the plan's choices in their place. `ImportContext::readsFile()` tells a step which reader it has, and these steps pass over a file's run, saying so on the report: site settings, widgets, redirects, languages and their words, plugin data, the fediverse steps, the fidelity check (there is no rendered page to compare with) and `deleted`.

**What a file can't bring, and what stands in for it.** Each is said on the file's plan, under *Not in an export file*:

| Not in the file | What the run does |
|---|---|
| Passwords and roles | Each author is matched by email to a person on this site, credited to a person chosen on the plan, or added with the role chosen there and no password anyone knows; they set one with *Forgotten your password?* (`UsersPhase`). An author with no email in the file is not added, and the report says so |
| The media files | Downloaded from the old site's addresses in the file, through the same downloader and public-address check as the companion's, while the old site answers; the plan asks it first (`FileDownloader::answers()`). Or kept at the old site as links, and no file is downloaded (`MediaPhase`). Or brought after the articles, in a picture pass, the posts showing them from the old site meanwhile (#716, above) |
| Where each menu sits | The menus come across; the plan places each in a location of this site's theme |
| The category and tag bases | Read from the terms' links in the file where it can, and shown on the plan to change (`FileAddresses`). Each post's and page's own address is in the file, so it keeps it |
| Site settings, widgets and theme settings, plugins' redirects, spam comments, revisions | Not imported |
| WordPress's own records (custom CSS, Customizer changesets, fetched embeds, privacy requests, a block theme's styles, templates, template parts and fonts, revisions, and unsaved drafts) | Never kept from the file, never listed as a custom type, never checked for an address and never brought: one list, `WordPressRecords` (`app/Domain/Import/WordPressRecords.php`), read by the file's reader, its report and addresses, `SiteAnalysis` and the check, for a live import too. Menu items, navigation and reusable blocks come across their own way |

**A newer file of the same site.** A file names its site by its address, so a newer file is a second import of the same source, and the map carries over: what is new or edited since comes across, and what was changed here is left alone, as catch-up does. `file-missing` (1260) lists what the earlier import brought that the newer file leaves out, and deletes nothing. A file's terms carry WordPress's numbers, which may differ from the numbers the companion gave the same terms: an earlier term is matched by taxonomy and number, then by slug (`TermsPhase`, `AdoptImportedTerm`), and a file's number another term already holds in the map is moved apart (`FileReader::apart()`), so the two never meet in one row. Authors and terms a file names only on its items, with no row of their own, take negative numbers.

**Keeping the file.** The file holds drafts, private posts and people's email addresses. The report offers to delete it, and `teme:import:prune-files`, run daily, deletes each file 30 days after the last import from it ended (`app/Console/Commands/PruneImportFiles.php`). Its rows go with it; what was imported, and the report, stay.

## Where Teme connects: the public-address check

The importer connects only to addresses an administrator gave it, and it checks each one **every time**, since a name can move (`app/Domain/Import/Source/CompanionClients.php:49-58`). `PublicAddresses::check()` (`app/Domain/Support/Network/PublicAddresses.php:48-104`):

- **http or https only**, no user name or password in the address, a host that is a name or a plain IP address, no control character and no trailing dot;
- **every address the name resolves to must be public**, by the IETF's special-purpose registries, IPv6 and IPv4-mapped included; private addresses are refused unless `teme.import.local_sources` is on, which it is only where `APP_ENV` is `local` (`config/teme.php:27`);
- **plain http to a public host is refused**, unless the caller sends no credential and allows it, as the downloader does for files;
- **the first resolved address is pinned** for the request, so a second lookup can't move it.

The companion's client then follows **no redirect**, uses **no proxy from the environment**, and waits 10 seconds to connect and 45 for an answer (`CompanionClient.php:120-134`). The downloader that brings media and outside images uses the same check at every hop (`app/Domain/Media/Downloads/FileDownloader.php:199`). The application password is encrypted on the source's row and never serialized (`app/Domain/Import/Models/ImportSource.php:12-15`). `ForgetImportSource` forgets it; `teme:import --forget` then reminds the operator to revoke it in WordPress too (`app/Console/Commands/ImportSite.php:213`).

## Where the build differs from study V

Study V §4 was written before the parts. These are the places the system as built does something else, each decided in a part's plan:

- **The order** (§4.2). Address settings come first (100), and the old site's address clash before that (50); old addresses for terms and authors at 350; redirects (900) and settings (1000) before the check (1100). The probe, the analysis and the choices are before the run, and SEO comes with each item, not as a phase.
- **The work list** (§4.1) also takes an object whose source changed, by its hash.
- **Import mode** (§4.1) holds the page cache's purge only; hooks are gathered from phase 5.
- **The credential** (§4.1) is on the source's row, encrypted, not a `secret` setting.
- **The check** (§4.6) accepts 200 or any redirect status after up to two hops on this site, and passes a redirect to another site or to the site's files; and the walk asks the same addresses through the HTTP kernel.
- **Catch-up** (§4.13) reaches an hour before the high-water mark, fetches only posts, pages and media by date and the rest whole, lists deletions without acting on them, and counts the failures it left untried.
- **HTTPS** (§4.3) is required to public hosts; plain http only to a private one where `local_sources` is on.

## Not built yet

- **The companion's part 3** (0.3.0), and its publication with a release per version (I-1711), after the tag is published; until then, the gaps in *The companion plugin*.
- **Hooks gathered during a run** and fired once at its end (phase 5; `BaseOperation.php:70`).
- **A database reader** beside the companion, chosen by the source's kind (I-1718; `CompanionClients.php:56`).
- **A walk the counters skip** (I-1951, draft), parked until a real cut-over shows the counts matter.
