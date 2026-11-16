# Proposal: plugin sockets, round 1

**Status:** draft (2026-11-16). **Decides:** the sockets for the walls W1 to W12 and the CI wiring C3 found while building the first first-party plugin, Pings, from the developer handbook alone (`plugins/pings/FINDINGS.md`, branch `plugins/pings`), and the fix for issue #334. **Plan:** `docs/plans/plugin-sockets-1.md`. **Branches:** `sockets/outbound-feeds` (A), `sockets/plugin-admin` (B), `sockets/root-files` (C), built side by side from `main` after the v1 join.

## What it is

Twelve small sockets in core, so a plugin like Pings (IndexNow and WebSub pings) can be built without workarounds: a form POST, links in the feeds, writing its own settings, a verification file at the site's root, an indexable flag on items, a button that starts its job, a small table on its settings page, outbound hosts taken from a setting, the site's own facts, a notice when feeds change, delayed and once-only jobs, and schedules that work when a plugin is enabled from the console. Plus a CI workflow that runs first-party plugins' tests and analysis only when plugin or socket code changes.

## Why

A plugin built from the handbook alone hit each of these as a wall (I-3396: a wall is a missing socket, fixed in core, never hacked around in the plugin). Pings works today only through workarounds a real site would notice: it sends WebSub as JSON (Google's hub is not expected to accept it), keeps its key in its own table where nobody can see or rotate it, answers its key at `/?pings-indexnow-key=1`, pings items marked noindex, guesses the site's address from an item's, guesses which feeds changed, and races its own jobs to batch. Issue #334 is worse: a plugin enabled the way the handbook shows never runs its schedules, and nothing says why.

| Wall | Socket | Part |
|---|---|---|
| W1 form POST | `OutboundHttp::postForm()` | A |
| W2 links in feeds | `contributes.feedLinks`, capability `feeds.links` | A |
| W3 keep a value in its settings | `PluginSettingsWriter` through `WritePluginSettings` | B |
| W4 a file at the site's root | manifest `rootFiles`, served by core | C |
| W5 is the item noindex | `ItemRef::$indexable` | C |
| W6 start its job from the admin | `contributes.actions` | B |
| W7 show its log | `contributes.lists` with `Cell` rows | B |
| W8 hosts from a setting | manifest `hostsFrom`, agreed at enable and on save | A |
| W9 the site's facts | `SiteInfo` | A |
| W10 feeds changed | `FeedsChanged` notification | A |
| W11 delay and once-only | `QueueOptions` on `Jobs::queue()` | B |
| W12 / #334 console schedules | `--as`, and a visible reason when schedules can't run | B |
| C3 plugin tests in CI | `plugins.yml` with path filters, a `Plugins` suite, `phpstan-plugins.neon` | C |

## Settled

1. **A form POST, same guard as JSON.** `postForm($url, $fields, $headers, $timeoutSeconds)` sends `application/x-www-form-urlencoded`, a list value repeating its name (`hub.url=a&hub.url=b`, as WebSub needs). Every check of `postJson` holds: https, port 443, agreed hosts, a pinned public address, no redirects, the time limit, 1 MB each way.
2. **Feed links are values; core writes the XML.** A plugin granted the new `feeds.links` declares `contributes.feedLinks` (at most 2): `rel` from a closed list, `hub` alone in this round, and `href` a literal https address or one of its own URL settings (a list gives one link each, at most 5). Core writes `<atom:link rel="hub" …/>` after `rel="self"` in every feed, escaped. Feeds carry a new page-cache tag, purged when such a plugin is enabled, disabled or its settings saved.
3. **A plugin writes only its own settings, only for the person running its work.** `PluginSettingsWriter::write($values, $actingPersonId)`, through the Operation `WritePluginSettings`: the person must be the one the work runs for (the person of the running job, or the signed-in person; never the operator), must hold `plugins.manage`, and the plugin must be enabled. Only non-secret, non-URL types; values go through the declared settings' own rules and checks; History records the person and "through" the plugin. Scheduled work runs as the administrator who enabled the plugin, so a key generated on schedule is kept.
4. **Root files: declared, narrow, consented, served by core.** `rootFiles` (needs `routes.public`, at most 2): a name `[A-Za-z0-9-]{8,128}.txt`, literal or filled from the plugin's own text setting, and a body of at most 1 KB, literal or from such a setting. Core answers plain text with `noindex`, `nosniff` and `no-store`, runs no plugin code, and never stores it. No name may equal a core address or another plugin's; a setting value that would collide is refused when saved. The consent lists each file.
5. **Indexable is the page's own answer.** `ItemRef` gains `indexable` (last, default `true`), worked out by the same code that writes the item page's `robots`, so the two never disagree.
6. **Actions start declared jobs, as the person who clicks.** `contributes.actions` (at most 3, needs `jobs`) draws buttons on the plugin's settings page; a click runs `RunPluginAction`, which queues the declared job as that person, once while one waits.
7. **Lists show plain rows.** `contributes.lists` (at most 2, up to 6 columns and 50 rows) draws tables on the plugin's settings page from `Cell` values, the list-columns part's text, number and badge, cleaned and cut by the same rules. 100 ms a list and 250 ms a page; over it, or a throw, the list says it couldn't be shown and the fault is recorded.
8. **Hosts from a setting are agreed by a person, never by the plugin.** `hostsFrom` names up to 3 of the plugin's URL settings. Enabling lists their current hosts in the consent; saving a new host asks for a tick and the fresh password; a plugin's own write cannot set a URL setting at all. A host removed from the setting stops being reachable.
9. **The site's facts.** `SiteInfo` gives every plugin the site's address, name, default language, home address and main feed per language, the same in a request, a job or the console.
10. **Feeds changed, from the page cache's own graph.** `FeedsChanged` lists the absolute addresses of the feeds whose cached copies a change purged (main, type, term, author), after commit, never during an import, only when someone listens; a site-wide change says `all`. No capability: public addresses only.
11. **Delay and once-only.** `Jobs::queue()` takes `QueueOptions`: a delay up to an hour, and a unique key that returns the waiting run instead of queuing another. Held by a lock and a partial unique index on `tracked_jobs`.
12. **Schedules never fail in silence.** `teme:plugins:enable` refuses a plugin with schedules unless `--as=<email>` names someone who may manage plugins, whose permissions the schedules then run with. The Plugins screen, `teme:plugins` and the scheduler share one answer for why a plugin's schedules can't run (enabled without a person, that person gone, or no longer allowed), with what to do.
13. **Plugin CI only when it matters.** A separate workflow runs `plugins/*/tests` and PHPStan over `plugins/` only when plugins, the contracts or socket code change. `tests.yml` and `composer check` are untouched.
14. **New contracts follow the contracts rule.** Each carries `@since` the next release (0.9.0 while it is untagged, otherwise 0.10.0), imports nothing from `App\` or `Illuminate\`, and value classes grow only at the end. Every write is an Operation with a test that a refusal writes nothing. Every word goes through `__()`, in American English.

## Provisional (built as written unless the maintainers say otherwise)

- P1. The numbers: 2 feed links, 5 links from a list setting, 3 `hostsFrom` settings, 2 root files of 1 KB, 3 actions, 2 lists of 6 columns and 50 rows, 100 and 250 ms, a one-hour delay.
- P2. The words quoted in the plan, among them `Saving lets :plugin connect to :hosts.`, `:label queued. Follow it in Tools › Jobs.` and the three "Its schedules don’t run" sentences.
- P3. `consented_by` holds the person named with `--as`; the security log says the operator enabled it naming them.

## Open questions for the maintainers

1. **Ship order.** Recommended: these parts after the v1 join, in the same release if 0.9.0 is still untagged. (Unverified when 0.9.0 will be tagged.)
2. **Growing two interfaces.** Recommended: add `postForm()` to `OutboundHttp` (released in 0.5.0) and a last `?QueueOptions` parameter to `Jobs::queue()` (0.9.0). Core is their only real implementer; a plugin's hand-made test double of `OutboundHttp` would need the new method. The other way, a second interface for each (`OutboundForms`, `JobsWithOptions`), keeps I-214's letter before 1.0 at the cost of two doors a developer must find.
3. **Root files beyond `.txt`.** Recommended: `.txt` only now. Google's and Yandex's HTML verification files and Bing's `BingSiteAuth.xml` are already covered by core's verification meta tags; serving plugin-supplied `.html` at the root is the riskier shape.
4. **Agreeing to a setting's new host on save.** Recommended: on the settings page, with a tick and the fresh password (Settled 8). The other way is to save the value but keep the host blocked until someone agrees on the Plugins screen, which is one more place to look.
5. **Secrets written by a plugin.** Recommended: not in this round (Settled 3). An OAuth plugin that receives a token in a callback will need it; that is a later socket with the person present.
6. **A capability for feed links.** Recommended: the new `feeds.links`, so the consent says it. The other way, no capability, treats a hub link as harmless; it still changes what every feed says.
7. **The console without `--as`.** Recommended: refuse a plugin that declares schedules (Settled 12). The other way, enable it and only show the warning, is what made #334 silent.
8. **The drawing for the settings page's panels.** The Actions and lists panels are new on a plugin's settings page. v9 has no plugin settings page with either; the drawing will extend Teme's settings page (a `SettingsPanel` of buttons, then one `SettingsPanel` per list holding a plain table) and is shown to the maintainers before part B builds it (B's Task 6 waits).
9. **Composer scripts (C1, and a local plugin check).** `composer require` runs `boost:update` from `post-update-cmd`, which rewrites `AGENTS.md`, `CLAUDE.md`, `boost.json` and the agents' skills. Recommended, as the maintainers' own one-line change (builders never edit `composer.json`): move `@php artisan boost:update --ansi` out of `post-update-cmd` into a named script, `"ai:update"`, run by hand; and add `"check:plugins"` running the `Plugins` suite and `phpstan-plugins.neon`. Separately: should `plugins.yml` become a required status? Recommended: no, because a required workflow that path filters skip never reports.
10. **Schedules whose administrator lost `plugins.manage`.** Recommended: count as "can't run" (Settled 12). The other way runs them with whatever that person may still do.

## The screens, for the drawing

- **A plugin's settings page.** Below the settings form: an **Actions** panel (one button per action, its description beside it; after a click, a toast with a link to Tools › Jobs) and one panel per **list** (its label and description, a table, "Nothing to show yet." when empty, one line when it couldn't be shown). Phone width: buttons stack; the table scrolls inside its panel.
- **Under a `hostsFrom` field**, only when a value names a host not agreed yet: one line `Saving lets Pings connect to hub.example.net.` and a checkbox.
- **The Plugins screen.** A warning line in the row of a plugin whose schedules can't run, with the reason.
- **The consent dialog.** Feed links, setting hosts and root files listed in the plain words of the plan.

## Not in this part (Later, each with what brings it back)

1. **`rel` values other than `hub` in feeds**, and links in the HTML head. Back when a plugin needs another relation.
2. **A `generated` setting type** that core fills with a random value at enable. Back if more than one plugin needs a key made once; Settled 3 covers Pings.
3. **Writing secrets and URL settings from a plugin.** Back with the first OAuth-style plugin.
4. **Root files that are not `.txt`.** Back with a verification method core's meta tags can't serve.
5. **A time cell** (relative time, as Emdash's table has) for lists and columns. Back when a second plugin shows times.
6. **Paging in a plugin's list.** Back when 50 rows aren't enough.
7. **Actions outside the settings page** (Tools links, row actions). Back with a plugin whose action belongs to an item.
8. **A public testing kit for plugins** (H9) and the handbook gaps H1 to H16. Separate work; each part here updates only the handbook pages of its own sockets.
9. **A version in `packages/teme-contracts/composer.json`** (C2). Separate, one line, the maintainers'.

## References checked

`Reference/` holds 40 folders. Those below were read for these sockets (WordPress, Rank Math, ArtisanPack SEO, Yoast, Emdash, Pursae, Craft, Statamic, Drupal, Ghost) or searched by name for IndexNow and WebSub (all 40). Outside the folder: nothing was needed.

- **WordPress** (`wordpress-develop-trunk`): `wp_remote_post()` takes a body array that Requests encodes with `http_build_query()` (`Requests/src/Transport/Curl.php` line 398), the form POST of W1, though its `name[0]=` arrays are why Teme writes repeated names itself; `do_action( 'rss2_head' )` and `'atom_head'` (`feed-rss2.php` line 82, `feed-atom.php` line 53), the hook WebSub plugins add `rel="hub"` through, with any markup (W2: Teme takes values instead); `update_option()`, any option from anywhere (W3: Teme limits it to the plugin's own group and a person); `admin-post.php`'s `admin_post_{action}` (W6, with a nonce and capability check); `get_bloginfo()` `url`, `name`, `language`, `rss2_url` (W9); `wp_schedule_single_event()` refusing a duplicate within ten minutes (`cron.php` line 135, W11's once-only); `wp_robots` (W5). It has no feeds-changed hook.
- **Rank Math 1.0.278** (`includes/modules/instant-indexing/`): generates the IndexNow key into its own option (`class-api.php` line 377, W3), serves `/{key}.txt` as `text/plain` with `X-Robots-Tag: noindex` (`class-instant-indexing.php` lines 372 to 386, W4's headers), keeps a log in an option and shows it, and offers manual submission (W6, W7).
- **ArtisanPack UI SEO** (`routes/web.php` lines 132 to 135, `src/Http/Controllers/IndexNowKeyController.php`): a Laravel route `{key}.txt` constrained to `[A-Za-z0-9]{8,128}`, comparing the asked name with the key; the shape of W4's pattern.
- **Yoast SEO 28.5**: indexables carry `is_robots_noindex`, one stored answer to "is this indexable" (W5's single source).
- **Emdash** (`packages/core/src/plugins/`): `allowedHosts` checked on every plugin fetch (`context.ts` line 1150), fixed at install (W8 goes further, with setting hosts agreed by a person); plugin settings written through `ctx.kv` with a `settings:` prefix and compare-and-set (W3); `SiteInfo` with `name`, `url`, `locale` (`types.ts` line 961, W9's model); cron tasks upserted by plugin and name, one-shot or repeating (`cron.ts` line 270, W11); Block Kit admin blocks with `table` columns of `text`, `badge`, `number`, `relative_time` and `actions` buttons with `action_id` (`packages/blocks/src/types.ts`), the closest model for W6 and W7, though Teme keeps declared entries in the manifest and core's own drawing.
- **Pursae** (WordPress in Go, `internal/plugins/permissions.go`, `sdk/go/wpgo/api_ext.go`): `options:write` as its own permission and `http:outbound` as GET only; `ScheduleSingleEvent` and a repeat schedule that keeps its time when asked again (W11).
- **Craft CMS 5** (`cms-5.x/src`): `Plugins::savePluginSettings()` validates through the settings model before saving (`services/Plugins.php` line 735, W3); `Queue::push($job, $priority, $delay)` (`helpers/Queue.php` line 41, W11); Utilities registered by plugins (W6's home, Teme keeps it on the settings page).
- **Statamic** (`src/Addons/Settings.php`): an addon's `settings()->set()->save()` (W3); CP utilities (W6).
- **Drupal** (`drupal-main/core`): `ConfigFactory::getEditable()` for a module's own config (W3); `DelayableQueueInterface` (`lib/Drupal/Core/Queue`, W11's delay).
- **Ghost**: a `site.changed` event for any public change, with no addresses (W10: Teme says which feeds).
- **Teme's own v9 demo** (`docs/prototypes/2026-09-22-admin-v9.source.html`): plugin settings groups under "Plugin settings" with their owner mark; no actions or tables on them (open question 8).

Not read for this part beyond that name search (unverified whether they hold more): Joomla, October, Winter, Wagtail, Payload, Strapi, Sharp, Twill, Filament, TypiCMS, Capell, Pubvana, the other ArtisanPack packages, Polylang, MultilingualPress, TranslatePress, Sage, Acorn, MilliCache, Laradashboard, Kaleta, BaseHim, WebBlocks, the React starter kit and Emdash's exporter. Only ArtisanPack SEO and Rank Math mention IndexNow, and only WordPress's bundled SimplePie mentions WebSub. WebSub's `rel="hub"` comes from the W3C WebSub recommendation (outside the folder, not reread for this proposal).

## Decided (provisionally by the architect while the maintainers were away, 2026-11-16; to be confirmed)

All open questions as recommended:

- Q1 ship order: these parts join after the v1 parts already on main; the release that carries them is the one their @since names (0.9.0 while v0.9.0 is untagged).
- Q2: `postForm()` on OutboundHttp and an options parameter on `Jobs::queue()`, added at the end as the contracts rule requires; no new interfaces.
- Q3: root files `.txt` only for now.
- Q4: a new host from a URL setting is agreed on the settings save (tick plus fresh password), as recommended.
- Q5: no secret writes by plugins in this round.
- Q6: feed links need the new `feeds.links` capability.
- Q7: a console enable of a plugin with schedules is refused without `--as`.
- Q8: the settings-page panels (actions and lists) are drawn from v10 and approved before Part B's screen task; the rest goes ahead.
- Q9: the maintainers change composer.json before the builders start (step M-0): `boost:update` moves out of `post-update-cmd` into its own named script, and `check:plugins` is added; `plugins.yml` is not a required check yet.
- Q10: an administrator who lost `plugins.manage` counts as "schedules can't run", with the same reason everywhere.
- Added: A-W13 (route handlers get the plugin's doors) and A-W14 (a plugin's lang files load), found by the handbook planner.
