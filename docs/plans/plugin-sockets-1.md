# Plan: plugin sockets, round 1

**Three parts, three branches, built side by side, each from `origin/main` after the v1 join is merged:** A `sockets/outbound-feeds`, B `sockets/plugin-admin` (also after `plugins/list-columns` is merged; see B, Task 0), C `sockets/root-files`. Proposal: `docs/proposals/plugin-sockets-1.md` (its Settled section is binding). Evidence: the walls W1 to W12 and C1 to C3 in `plugins/pings/FINDINGS.md` (branch `plugins/pings`), and issue #334.

**Bound by:** I-3396 (a wall an outside developer hits is a missing socket, fixed in core), I-3353 (a plugin is given only what its granted capabilities open; a plugin's failure is recorded under the plugin), I-3445 (plugin jobs), I-392 (whom work runs as), the maintainers' rule for doors that write (a plugin acts only for the person who started the work; scheduled work runs as the administrator who enabled the plugin; never with the operator's elevation), I-200 (declared entries core draws, with a budget), I-214 and `.ai/rules/contracts.md` (contracts grow only at the end, `@since` set once), rule 1 (every write through an Operation), rule 4 (no `serialize()`), rule 5 (contracts import nothing from `App\` or `Illuminate\`), rule 6 (purges and hooks after commit), rule 9 (no stored absolute site URLs), I-087 (no new singleton), I-569 (lighter plan), I-3439 (American English), I-3373 (every word through `__()`), I-797 (comments cite one I-number or path), I-3317 (quick check per task, full gate once per part).

This plan is lighter (I-569): decisions, names, JSON shapes, contracts, permissions, tests and the delicate code are written out; every other class is described by its file, its job and its public signature, and is the builder's to write in the style of its siblings.

## What exists (read on `public/main` at `964c7ea4`, 2026-11-16)

- **Doors.** `App\Domain\Plugins\Doors\PluginDoors::for(name, code, capabilities, hosts, httpSeconds)` returns what a plugin's constructor may ask for; `App\Domain\Plugins\Seams\PluginServices::OFFERED` lists those contracts. Today: `SettingsReader`, `SecretReader`, `ContentWriter`, `MediaWriter`, `OutboundHttp`, `Jobs`, `InsertionsChanged`, `FediverseFollowers`, `FediverseSender`.
- **Outbound.** `PluginHttp` (`get`, `postJson`): https to port 443, hosts covered by consent (`HostPattern`), resolved and pinned, no private addresses, no redirects, 10 s (300 inside a `long` job), 1 MB each way. Its host list is fixed when the door is built.
- **Consent.** `App\Domain\Plugins\Consent` compares a record's `consent` JSON (`capabilities`, `hosts`, `routes`, `imports`) with the manifest; `covers()` decides whether an update waits for another yes. `PluginWords` (screen) and `EnablePluginCommand` (console) word each capability.
- **Settings.** A plugin's `contributes.settings` becomes the group `plugin.{code}` (`DeclaredSettings::group()`, ability `plugins.manage`, `cacheTags` `[PageTags::INSERTIONS]` only for a plugin that inserts). Saved only by `UpdateSettings` (ability check, elevation for a sensitive group, `SettingRules`, tagged `SettingsCheck`s, `SettingsWriter` with `origin`). The plugin's page is `settings/group.tsx` (route `settings.show`, group `plugin.{code}`).
- **Jobs.** `Teme\Contracts\Jobs\Jobs::queue(key, payload, label)` (`@since 0.9.0`, not yet released) → `PluginJobs` → `StartJob` (table `tracked_jobs`, state `waiting` until it runs) → `RunPluginJob`. `DueSchedules` runs schedules as `plugins.consented_by` and **skips a plugin silently when it is null**, which is what `teme:plugins:enable --agree` leaves (issue #334). `EnablePluginInput` has `actorId` null for the operator.
- **Feeds.** `App\Domain\Feeds\Rss2::render(Feed)` writes `<atom:link rel="self">` in the channel; `FeedBuilder` tags a feed with `PageTags::listOf(type)`, `filedUnder(...)`, `term(...)`, `author(...)`, `person(...)`; `FeedLinks` puts the site's feed (one per language prefix) and an archive's feed in the HTML head; `FeedUrls::absolute()` makes addresses at render time.
- **After commit.** `BaseOperation::execute()` closes `PublicChanges` (the page-cache tags) and `PendingNotifications`, then after the outermost commit runs `afterCommit`, `invalidateCache`, `$changes->purge($tags)`, then `HookDispatcher::deliver($pending)`; nothing during an import run.
- **Public routes.** `RouteCollisions`: an unconditional plugin path must be under `/{code}/` or `/.well-known/`. The fallback routes carry `MatchDeclaredRoutes` and `MarkCacheable`; `StoreRules` stores 200 **and 404** answers. `Paths::SITE_ROUTES` holds `feed`, `sitemap.xml`, `robots.txt`, `ads.txt`, `api`, `llms.txt`, `ap`, `wp-json`.
- **Items in hooks.** `Teme\Contracts\Content\ItemRef(id, type, title, status, url, language)`, built in `App\Domain\Hooks\Payloads::item()`. Noindex lives in the built-in field `BuiltInFields::NOINDEX`, the `seo.hide_type_{type}` settings and `WholeSite::discouraged()`.
- **List cells.** `plugins/list-columns` (built, not yet on `main`) adds `Teme\Contracts\Lists\Cell` (`text`, `number`, `badge` with `TONES`) and `PluginColumns`' cleaning (control and direction characters out, text cut to 80, badges to 24).
- **CI.** `.github/workflows/tests.yml`; `phpunit.xml` suites `Unit`, `Feature`, `Architecture`, `Browser`; `phpstan.neon` paths `app`, `packages`. Nothing runs `plugins/*/tests` or analyses `plugins/`.

## V, the release in every new `@since` (all parts)

In Task 0 each builder runs `git tag -l 'v0.9.0*'`. **None:** `V = 0.9.0`, as the join's other new contracts. **Any:** `V = 0.10.0`. Written once, never edited. When V is `0.9.0`, `Jobs::queue()` (B) and `OutboundHttp` (A) may grow as below because they have no released implementer outside core; when V is `0.10.0`, follow the same design and record it as a B-?? (draft) for the maintainers (proposal, open question 2).

## Shared files (additions only; never edit or remove another part's lines)

| File | A adds | B adds | C adds |
|---|---|---|---|
| `packages/teme-contracts/src/Plugins/Capability.php` | case `FeedsLinks = 'feeds.links'` (last), its sentence in the docblock | none | none |
| `packages/teme-contracts/schema/teme-plugin.schema.json` | `hostsFrom`, `contributes.feedLinks` | `contributes.actions`, `contributes.lists` | `rootFiles` |
| `app/Domain/Plugins/ManifestValidator.php` | `hostsFrom()` | none | `rootFiles()` |
| `app/Domain/Plugins/Contributions/ContributionsValidator.php` and `Contributions.php` | `feedLinks` | `actions`, `lists` | none |
| `app/Domain/Plugins/Doors/PluginDoors.php`, `Seams/PluginServices.php` (`OFFERED`) | `SiteInfo` | `PluginSettingsWriter` | none |
| `app/Domain/Plugins/Consent.php` | `hostsFrom` keys | none | root-file lines in `routeLines()` |
| `app/Domain/Plugins/Operations/EnablePlugin.php`, `Input/EnablePluginInput.php` | last param `array $settingHosts = []` | last param `?string $schedulesAs = null` | none |
| `app/Console/Commands/EnablePluginCommand.php`, `app/Domain/Plugins/PluginWords.php` | `feeds.links` words, setting hosts line | `--as` | root-file lines |
| `app/Domain/Plugins/Contributions/DeclaredSettings.php` (`cacheTags`) | `PageTags::FEED_LINKS` when the plugin declares feed links | none | `PageTags::ROOT_FILES` when it declares root files |
| `app/Domain/Cache/PageTags.php` | `FEED_LINKS = 'feed-links'` | none | `ROOT_FILES = 'root-files'` |
| `resources/js/pages/settings/group.tsx` | the hosts agreement under a `hostsFrom` field | the Actions and lists panels under the form | none |
| `resources/js/pages/plugins/index.tsx` | setting hosts in the consent dialog | the schedules line | root files in the consent dialog |
| `tests/Unit/Contracts/PluginContractsTest.php` | dataset rows | dataset rows | dataset rows |

Where two parts add a last constructor parameter to the same class, the maintainer orders them at the join; every caller passes them by name. **Do not regenerate** `docs/reference/plugin-manifest.md` or `docs/reference/hooks.md`; the join does, once.

---

## Part A: outbound, feeds and the site (`sockets/outbound-feeds`)

Walls W1, W2, W8, W9, W10.

### Owns

- **New:** `packages/teme-contracts/src/Site/SiteInfo.php`; `packages/teme-contracts/src/Feeds/FeedsChanged.php`; `app/Domain/Plugins/SettingHosts.php`; `app/Domain/Plugins/Operations/AgreePluginHosts.php` and `Input/AgreePluginHostsInput.php`; `app/Domain/Site/CoreSiteInfo.php`; `app/Domain/Feeds/PluginFeedLinks.php`; `app/Domain/Feeds/ChangedFeeds.php`; the fixture plugin `tests/Fixtures/plugins/hub/` (feed links, `hostsFrom`); tests `tests/Feature/Plugins/Sockets/OutboundFormTest.php`, `HostsFromSettingsTest.php`, `FeedLinksTest.php`, `SiteInfoTest.php`, `FeedsChangedTest.php`; `tests/Browser/PluginHostsAgreementTest.php`.
- **Changed:** `packages/teme-contracts/src/Http/OutboundHttp.php` (one method); `app/Domain/Plugins/Doors/PluginHttp.php`; `app/Domain/Settings/Operations/UpdateSettings.php` and `Input/UpdateSettingsInput.php` (last param `array $agreedHosts = []`); `app/Http/Admin/Settings/SettingsController.php` (one prop); `app/Domain/Feeds/Feed.php` (last param), `Rss2.php`, `FeedBuilder.php` (the new tag); `app/Domain/Support/Operations/BaseOperation.php` (one block, A4); `docs/guides/developers/plugins/settings.md`, a new `docs/guides/developers/plugins/feeds-and-site.md`, and the handbook's outbound section where `OutboundHttp` is described; the shared files above.
- **Not owned:** everything in B and C; the Pings plugin.

### Decisions

**A1. A form POST (W1).** `OutboundHttp::postForm(string $url, array $fields, array $headers = [], int $timeoutSeconds = 5): HttpAnswer`, `@param array<string, string|list<string>> $fields`, `@since V`, the same `@throws` as `postJson`. Every check of `postJson` applies unchanged (https, port 443, consented hosts, pinned public address, no redirects, the time limit, 1 MB each way). The body is `application/x-www-form-urlencoded`; a list value repeats its name (`hub.url=a&hub.url=b`, as WebSub needs), never `name[0]=`. A value that is neither a string nor a list of strings is refused with PHP's `InvalidArgumentException` before any lookup. A `Content-Type` header the plugin passes is dropped, as `Host` already is.

**A2. Hosts from a declared setting (W8).** Manifest key `hostsFrom`: a list of keys of the plugin's own `url` or `urls` settings (at most 3), allowed only with `http`. Refused otherwise with `hostsFrom names :key, which is not one of its url or urls settings.` or `It takes hosts from its settings, but the plugin doesn’t ask for http.`
- **At enable**, the consent says, under `http`: `Connect to services outside this site: :hosts, and the hosts you enter in :settings` (the settings' labels, quoted, joined with "and"), then lists the hosts in those settings' current values (defaults included). Agreeing records `consent.hostsFrom` (the keys) and `consent.settingHosts` (those hosts). An update that adds a key to `hostsFrom` widens access (`Consent::covers()` false).
- **Reachable hosts** = the consented manifest hosts, plus each host in the **current** values of the `hostsFrom` settings that is in `consent.settingHosts`. A host removed from the setting stops being reachable; put back, it needs no new yes. Only exact host names (`HostPattern::PATTERN`, no wildcard, no IP literal). `PluginDoors` builds `PluginHttp` with a `Closure(): list<string>` for these extra hosts, read at each call (settings are cached), never a fixed list.
- **Saving a new host.** On the plugin's settings page, a value with a host not yet agreed shows, under the field, `Saving lets :plugin connect to :hosts.` and a checkbox `Let :plugin connect to these hosts`. `UpdateSettings` refuses the save unless `agreedHosts` names every new host (`Agree to let :plugin connect to :hosts, or remove them.`); with it, the save **requires elevation** (the fresh password enabling asks for) and, inside its `perform()`, runs `AgreePluginHosts` (authorize: the person holds `plugins.manage`; lock the plugin row; add the hosts to `consent.settingHosts`; append `{hosts, by, at}` to `consent.settingHostsAgreed`).
- A plugin can never agree for itself: B's `WritePluginSettings` refuses `url` and `urls` settings outright (B1).

**A3. Links in the feeds (W2).** Capability `feeds.links` (`Capability::FeedsLinks`), consent words `Add links to your site’s feeds, such as the hub feed readers subscribe through`. Manifest `contributes.feedLinks`, at most 2 entries, each `{ "rel": "hub", "href": "…" }`:
- `rel` comes from a closed list: `hub` alone in this round (`self` is core's and already written).
- `href` is one literal `https://` address (at most 2,048 characters), or exactly `{setting_key}` naming the plugin's own `url` or `urls` setting; a `urls` setting gives one link per value, at most 5.
- Refused without `feeds.links`: `It adds links to the feeds, but the plugin doesn’t ask for feeds.links.`
- **Core writes the XML**, every value through `Xml::attribute()`: in RSS 2.0, `<atom:link rel="hub" href="…"/>` straight after `rel="self"`, in every feed (site, language, type, term, author). Plugins in code order; a duplicate `rel` and `href` once; a value that is not an absolute https address is skipped. `PluginFeedLinks::for(): list<array{rel: string, href: string}>` reads the started plugins from `LoadedPlugins::running()` and their consent from `PluginRecords::enabled()`, as `InsertionPoints` does (no singleton); a plugin whose read throws is recorded through `ListenerFaults` and skipped.
- **Cache.** Every feed carries `PageTags::FEED_LINKS`. A plugin declaring feed links gets it in its settings group's `cacheTags`; `EnablePlugin` and `DisablePlugin` purge it after commit when the plugin declares feed links.

**A4. Feeds changed (W10).** `Teme\Contracts\Feeds\FeedsChanged` (`#[HookContract]`, `Notification`, `@since V`): `__construct(public array $urls, public bool $all = false)`, `@param list<string> $urls` absolute feed addresses, at most 200. No capability: it carries only public addresses. Built by `ChangedFeeds::from(list<string> $tags): ?FeedsChanged` from **the same tags the Operation gave the page cache**, in `BaseOperation`'s after-commit closure, between the purge and `deliver()`, only when a plugin listens (`HookDispatcher::wants()`). Mapping, each to absolute addresses through `FeedUrls` (rule 9):

| Tag | Feeds |
|---|---|
| `listOf(type)` for a type the main feed lists | the main feed of every site language |
| `listOf(type)` for a type with an archive feed | that type's feed |
| `filedUnder(taxonomy, term)` | that term's feed, from its stored path |
| `author(id)` | that author's feed, from their stored path |
| `PageTags::EVERYTHING` or `PageTags::SETTINGS` | the main feeds, and `all: true` |

No tag that maps: no notification. Nothing during an import run (as today). Duplicates once; beyond 200, `all: true` and the main feeds.

**A5. The site (W9).** `Teme\Contracts\Site\SiteInfo` (`@since V`), offered to **every** plugin (public facts, no capability):

```php
interface SiteInfo
{
    /** The site's address, absolute, with no trailing slash. @since V */
    public function address(): string;

    /** The site's name (Settings › General). @since V */
    public function name(): string;

    /** The site's default language as a BCP 47 tag. @since V */
    public function language(): string;

    /** The home page's address, absolute. @since V */
    public function home(): string;

    /**
     * The main feed's address for each site language, absolute, the default language's first.
     *
     * @return list<string>
     *
     * @since V
     */
    public function feeds(): array;
}
```

`CoreSiteInfo` reads the address from the source `Addresses::urlFor()` uses (Task 0 names it), so a queued job or the console gives the same answer as a request.

### The delicate code: the form body

```php
private static function formBody(array $fields, string $host, int $maxBytes): string
{
    $pairs = [];
    foreach ($fields as $name => $value) {
        if (! is_string($name) || (is_array($value) && ! array_is_list($value))) {
            throw new InvalidArgumentException('A form’s fields are names with a string or a list of strings.');
        }
        foreach (is_array($value) ? $value : [$value] as $one) {
            if (! is_string($one)) {
                throw new InvalidArgumentException('A form’s fields are names with a string or a list of strings.');
            }
            $pairs[] = urlencode($name).'='.urlencode($one);   // form encoding: a space is +
        }
    }
    $body = implode('&', $pairs);
    if (strlen($body) > $maxBytes) {
        throw new RequestFailed(/* the words postJson uses for a body over the limit */);
    }

    return $body;
}
```

`send()` gains the form path beside the JSON one: `$request->withBody($body, 'application/x-www-form-urlencoded')->post(...)`; everything before and after is shared.

### Tasks (tests first; each seen red for the reason given)

0. **Checks.** Record: V; `git log --oneline -1`; the source of the site's address that `Addresses::urlFor()` uses; what `PublicChanges::close()` returns and that tags are gathered when the page cache is off; how `FeedBuilder` chooses the main feed's types; that `Contributions::add()` passes a flag into `DeclaredSettings::group()` (the `$inserts` precedent). A difference that changes a decision is a STOP.
1. **Form POST.** `OutboundFormTest`, with the fake transport `PluginHttp`'s tests already use: the body and `Content-Type` sent; repeated names for a list; a host not agreed, http, another port, a private address and a body over 1 MB each refused as `postJson` refuses them; a nested array refused before any lookup. Red: method missing. Then the contract method.
2. **Hosts from settings.** `HostsFromSettingsTest`: the manifest refusals (A2); enable records `hostsFrom` and `settingHosts`; the door reaches an agreed setting host and refuses an unagreed one (`HostRefused`); a host removed stops being reachable; `UpdateSettings` refuses a new host without agreement **and writes nothing** (settings and consent unchanged); with agreement it asks for elevation, then saves and records the host; `AgreePluginHosts` refused for a person without `plugins.manage` writes nothing; an update adding a `hostsFrom` key waits for consent. Red.
3. **The screen.** `SettingsController` gives `pluginHosts` (`{plugin, settings: list<string>, agreed: list<string>, declared: list<string>}` or null); `group.tsx` shows the line and checkbox under a `hostsFrom` field when a value names a new host, and posts `agreedHosts`; the server stays the judge. `PluginHostsAgreementTest` (browser, 1280 and 375): the line appears; a save without the box shows the refusal; with it, the save succeeds after the password; axe in light and dark.
4. **Feed links.** `FeedLinksTest` with the `hub` fixture: the site, term and author feeds carry `<atom:link rel="hub" href="…"/>` after self, escaped; a `urls` setting gives one link each, at most 5; not granted `feeds.links`, or disabled: no link; the manifest refusals; saving the plugin's settings purges stored feeds after commit and not on rollback. Red.
5. **Site info.** `SiteInfoTest`: the five answers in a request, in a queued job and in the console agree; a second language adds its feed. Red.
6. **Feeds changed.** `FeedsChangedTest`: publishing a post filed under a tag sends one `FeedsChanged` with the main feed, the tag's feed and the author's feed, after commit; a rolled-back Operation sends none; a draft's save sends none; a save to `general` sends the main feeds with `all: true`; nothing is built when no plugin listens; an import run sends none. Red.
7. **Handbook and records.** `plugins/settings.md` (hosts from settings), `plugins/feeds-and-site.md` (feed links, `SiteInfo`, `FeedsChanged`, `postForm`), each with its capability and `@since`; Records; the gate.

### Quick check

The `.ai/rules/builders.md` gate after each task, with `php artisan test --parallel --compact` on `tests/Feature/Plugins/Sockets`, `tests/Unit/Contracts`, `tests/Feature/Plugins` and `tests/Feature/Feeds`. Once at the end: `composer check`, and `composer check:browser` for `PluginHostsAgreementTest` and the settings screens' existing browser tests.

### Records (the builder's)

#### Commits on `sockets/outbound-feeds`

-

#### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|
| | | |

#### Task 0

-

#### Architecture baselines

| Baseline | Before | After |
|---|---|---|
| design-system-baseline.txt | | |
| admin-words-baseline.txt | | |

#### Gate

-

#### Drafts (B-numbers from this block)

- B-01:
- B-02:
- B-03:
- B-04:
- B-05:
- B-06:
- B-07:
- B-08:
- B-09:
- B-10:
- B-11:
- B-12:
- B-13:
- B-14:
- B-15:
- B-16:
- B-17:
- B-18:
- B-19:
- B-20:

#### Contract changes

-

#### STOP

-

#### Changelog lines for the maintainers

-

#### Try it

-

---

### Added by the maintainers (two walls found while planning the handbook pass)

- [ ] **A-W13. Route handlers get the plugin's doors.** Today a declared public route's handler is built with no constructor arguments, so it has no settings reader, jobs door, secrets or outbound door. Build handlers the way the plugin's main class is built: with only what its constructor asks for and Teme offers plugins, by the same capability rules (PluginDoors). Tests first: a handler asking for SettingsReader gets it; one asking for a door its plugin wasn't granted is refused at validation with a plain message; a handler with no constructor still works.
- [ ] **A-W14. A plugin's words translate.** Teme never loads a plugin's `lang` folder. Load each enabled plugin's `lang/<locale>.json` files (the same JSON format core uses) so `__()` finds its sentences, with core's own words winning a clash, and the reader's or the person's locale as core uses it. Tests first: a plugin's Spanish JSON answers on a Spanish page; a missing file falls back to the English key; a disabled plugin's words are not loaded. Document it in the handbook's translation section (the handbook pass states today's rule; this part rewrites that paragraph).

## Part B: a plugin's settings page and its work (`sockets/plugin-admin`)

Walls W3, W6, W7, W11, W12 and issue #334.

### Owns

- **New:** `packages/teme-contracts/src/Settings/PluginSettingsWriter.php`; `packages/teme-contracts/src/Jobs/QueueOptions.php`; `packages/teme-contracts/src/Lists/HasPluginLists.php`, `PluginList.php`; `app/Domain/Plugins/Doors/PluginSettingsWrites.php`; `app/Domain/Plugins/Operations/WritePluginSettings.php`, `RunPluginAction.php` and their inputs; `app/Domain/Plugins/Lists/**` (`PluginLists`, `DeclaredList`); `app/Domain/Plugins/Jobs/ScheduleRunner.php`; `app/Http/Admin/PluginActionController.php`; one migration `add_unique_key_to_tracked_jobs`; `resources/js/components/plugins/plugin-actions.tsx`, `plugin-list-panel.tsx`; the fixture plugin `tests/Fixtures/plugins/workbench/` (settings, an action, a list, a scheduled job); tests `tests/Feature/Plugins/Sockets/WritePluginSettingsTest.php`, `PluginActionsTest.php`, `PluginListsTest.php`, `QueueOptionsTest.php`, `ConsoleSchedulesTest.php`; `tests/Browser/PluginSettingsPanelsTest.php`.
- **Changed:** `packages/teme-contracts/src/Jobs/Jobs.php` (one parameter); `app/Domain/Plugins/Jobs/PluginJobs.php`, `DueSchedules.php`; `app/Domain/Jobs/Operations/StartJob.php` and `Input/StartJobInput.php` (last param `?string $uniqueKey = null`); `app/Console/Commands/EnablePluginCommand.php` (`--as`), `ListPlugins.php`; `app/Domain/Plugins/Queries/PluginRow.php` (last param), `PluginList.php`, `PluginsScreen.php`; `app/Http/Admin/Settings/SettingsController.php` (two props); `routes/admin.php` (one route in the plugins block); `resources/js/pages/plugins/index.tsx` (the schedules line); `resources/js/pages/settings/group.tsx` (the panels); `docs/guides/developers/plugins/settings.md`, `jobs.md`; the shared files above.
- **Not owned:** everything in A and C; the Tools hub; Home's panels.

### Decisions

**B1. A plugin writes its own settings (W3).** Contract, offered to every plugin that declares settings (no capability: it is the plugin's own group):

```php
namespace Teme\Contracts\Settings;

/** Writes the calling plugin's own settings group, `plugin.{code}`, for a named person. @since V */
interface PluginSettingsWriter
{
    /**
     * Saves these values, by declared key, as $actingPersonId could on the settings page.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws \Teme\Contracts\Content\WriteRefused  when the person, a key, a type or a value is refused; nothing is written
     *
     * @since V
     */
    public function write(array $values, string $actingPersonId): void;
}
```

The door (`PluginSettingsWrites`, built with the plugin's code) calls `ActingPerson::require()` (only the person of the running job, or the signed-in person; never the operator), then the Operation `WritePluginSettings`:
- **authorize:** the person exists and holds `plugins.manage` (the group's ability), and the plugin is enabled.
- **validate:** every key is declared in this plugin's group; its type is one of `text`, `textarea`, `number`, `boolean`, `select`, `multiselect`, `email`, `color` (never `secret`, `url` or `urls`: secrets need the person present, and URL settings may carry hosts, A2); `SettingRules::validateValues()` and every tagged `SettingsCheck` for the group (C's root-file name check runs here too).
- **perform:** lock the group's row, read its current version under the lock, write through `SettingsWriter` with `origin: "plugin:{code}"` and the person as actor, so History and `SettingsChanged` say who, and through what. No elevation: the person isn't present, and the write is limited to non-secret values the person could change themselves.
A refusal throws `WriteRefused` with one plain sentence. Scheduled work runs as the enabling administrator, so a key generated in a scheduled job is saved; work started by an editor's hook is refused unless that editor manages plugins.

**B2. Actions (W6).** Manifest `contributes.actions`, at most 3, needs `jobs`:

```json
"actions": [ { "id": "test-ping", "label": "Send a test ping", "description": "Tells IndexNow about the home page now.", "job": "indexnow" } ]
```

`id` as the Tools link's pattern, `label` 1 to 40 characters, `description` up to 160, `job` a declared job. Drawn as buttons in an **Actions** panel on the plugin's settings page. A click posts to `admin/plugins/{code}/actions/{action}` (`plugins.actions.run`, inside the `can:plugins.manage` block), which runs `RunPluginAction`: authorize (holds `plugins.manage`; the plugin enabled and granted `jobs`; the action declared), then queue the job through `PluginJobs` **as that person**, payload `{"action": "<id>"}`, the action's label, and `QueueOptions(uniqueKey: "action:<id>")`, so a second click while one waits queues nothing. The answer is a toast `:label queued. Follow it in Tools › Jobs.` with a link. Refused: nothing queued, no tracked job row.

**B3. Lists on the settings page (W7).** Manifest `contributes.lists`, at most 2, each `{ "id", "label", "description"?, "columns": [ { "id", "label", "kind" } ], "rows"? }`: `kind` is `text`, `number` or `badge` (the list-columns part's `Cell` kinds), at most 6 columns, `rows` 1 to 50 (default 20). Contract, in `Teme\Contracts\Lists` beside `Cell`:

```php
/** A plugin with lists on its settings page, by declared id. @since V */
interface HasPluginLists
{
    /**
     * @return array<string, PluginList>
     *
     * @since V
     */
    public function pluginLists(): array;
}

/** One list's rows, newest or most useful first. @since V */
interface PluginList
{
    /**
     * At most $limit rows; each row its cells by declared column id. A missing cell shows empty.
     *
     * @return list<array<string, Cell>>
     *
     * @since V
     */
    public function rows(int $limit): array;
}
```

Asked only when the settings page is drawn, once per list, with the page. **Budget:** 100 ms per list, 250 ms per page (the list-columns part's `ColumnClock`); over it, or a throw, the list shows `This list couldn’t be shown this time.` and the fault is recorded through `ListenerFaults`. Cells are cleaned by the list-columns part's rules (reuse its cleaner; if it is private, move it unchanged into `app/Domain/Plugins/Columns/CellText.php` and call it from both). Core draws a table in a panel: headings, `text` truncated, `number` right-aligned and tabular, `badge` as `StatusPill`; empty: `Nothing to show yet.`; never `dangerouslySetInnerHTML`. A declared id with no class, or a class for an undeclared id, is a contribution fault, as `jobHandlers()` treats jobs.

**B4. Queue options (W11).** `Jobs::queue()` gains a last parameter `?QueueOptions $options = null` (see V). New value class:

```php
/** How to queue a plugin's job: after a delay, and once while one waits. @since V */
final readonly class QueueOptions
{
    /**
     * @param  int  $delaySeconds  0 to 3600: the run starts no sooner
     * @param  string|null  $uniqueKey  while a run of this job with this key waits, queue() returns that run's ID and queues nothing; its payload stands, the new one is dropped
     *
     * @throws \InvalidArgumentException  for a delay outside 0 to 3600, or a key not matching ^[a-z0-9][a-z0-9:._-]{0,63}$
     *
     * @since V
     */
    public function __construct(public int $delaySeconds = 0, public ?string $uniqueKey = null) {}
}
```

"Waits" means the tracked job's state is `waiting`; once it runs, the next `queue()` with the key queues a new run, which is the debounce IndexNow needs.

**B5. Schedules from the console (W12, #334).** `teme:plugins:enable {name} {--agree} {--as= : The email of the administrator its schedules run as}`.
- A plugin that declares `schedules`, enabled without `--as`: refused before anything is written: `:label runs work on a schedule, and scheduled work runs as an administrator. Name one with --as=<email>.`
- `--as` naming nobody, or someone without `plugins.manage`: `No one who may manage plugins has the email :email.`
- `EnablePluginInput` gains `?string $schedulesAs = null`; `EnablePlugin` validates it again (exists, holds `plugins.manage`) and stores `consented_by = actorId ?? schedulesAs`; the security log records that the operator enabled it, naming that person.
- `ScheduleRunner::problem(PluginRecord): ?string` is the one answer for the scheduler, the Plugins screen and `teme:plugins`, for an enabled plugin with schedules: `Its schedules don’t run: it was enabled from the command line without naming an administrator.` (no `consented_by`), `Its schedules don’t run: the administrator who enabled it no longer has an account.`, `Its schedules don’t run: the administrator who enabled it can no longer manage plugins.`, each followed by `Disable it and enable it again from Site › Plugins, or with --as.` `DueSchedules` skips such a plugin (as today) and writes it to the log at most once a day. `PluginRow` gains `?string $schedules`; the Plugins screen shows it as a warning line in the row; `teme:plugins` adds it to Why.

### The delicate code: once while waiting

In `PluginJobs::queue()`, when `$options?->uniqueKey` is set, one transaction around `StartJob`:

```php
return DB::transaction(function () use ($key, $payload, $label, $options, $declared, $actor): string {
    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["plugin-job:{$this->code}:{$key}:{$options->uniqueKey}"]);

    $waiting = DB::table('tracked_jobs')
        ->where('owner', $this->code)->where('run_type', PluginJobKind::KEY)->where('run_id', $key)
        ->where('unique_key', $options->uniqueKey)->where('state', 'waiting')
        ->value('id');

    if (is_string($waiting)) {
        return $waiting;                       // nothing queued; the waiting run's payload stands
    }

    $id = $this->start->execute(new StartJobInput(/* as today */ uniqueKey: $options->uniqueKey));
    $job = RunPluginJob::configure(new RunPluginJob($this->code, $key, $id, $actor), $declared);
    DB::afterCommit(fn () => dispatch($job->onConnection(/* as today */)->onQueue(/* as today */)->delay($options->delaySeconds)));

    return $id;
});
```

The migration adds `unique_key varchar(64) null` and, as the backstop, `CREATE UNIQUE INDEX tracked_jobs_waiting_unique ON tracked_jobs (owner, run_id, unique_key) WHERE state = 'waiting' AND unique_key IS NOT NULL`. Without a unique key, `queue()` behaves exactly as today, the delay aside.

### Tasks (tests first; each seen red for the reason given)

0. **Checks.** Record: V; that `plugins/list-columns` is on `main` (`git log --oneline -1 -- packages/teme-contracts/src/Lists/Cell.php`). **If it is not, build Tasks 1, 2, 4 and 5, and STOP before Task 3.** Record too: the `tracked_jobs` columns and states; where `SettingsWriter` takes `origin`; how `UpdateSettings` gets the group's version (B1 follows it).
1. **Settings write.** `WritePluginSettingsTest` with `workbench`: a scheduled job running as the enabling administrator saves a `text` value, History shows the person and `plugin:workbench`, `SettingsChanged` is sent after commit; refused, **each writing nothing**: no current person, a person other than the job's, the operator's job, a person without `plugins.manage`, a disabled plugin, an undeclared key, a `secret`, `url` or `urls` key, a value `SettingRules` refuses, another plugin's group. Red: contract missing.
2. **Queue options.** `QueueOptionsTest`: a delay reaches the queued job (`Queue::fake()` asserts it); two calls with one key while waiting give one row and one ID; once the run starts, a third call queues a new run; a bad delay or key throws; the partial index refuses a second waiting row inserted by hand. Red.
3. **Lists.** `PluginListsTest`: the settings page's props carry a list's cleaned rows; a slow list (stepping clock) and a throwing one give the failure line and are recorded; markup arrives as text; at most `rows`; the manifest refusals (more than 2 lists, 6 columns, 50 rows, an unknown kind). Red.
4. **Actions.** `PluginActionsTest`: a holder of `plugins.manage` runs the action; a tracked job owned by the plugin and started by that person waits, with payload `{"action":"test-ping"}`; a second click returns the same job; refused **writing nothing**: no `plugins.manage`, a disabled plugin, a plugin without `jobs`, an undeclared action; the manifest refusals. Red.
5. **Schedules from the console (#334).** `ConsoleSchedulesTest`: enabling a plugin with schedules without `--as` fails with the sentence and writes nothing; `--as` with someone who can't manage plugins fails and writes nothing; with an administrator, `consented_by` is theirs and the schedule queues its job as them; a plugin without schedules still enables without `--as`; `ScheduleRunner::problem()` gives each sentence; the Plugins screen's props and `teme:plugins` show it. Red: the plugin enables and nothing says why.
6. **The screen.** Only after the maintainers have approved the drawing of the Actions and lists panels (proposal, open question 8); until then, STOP before this task. The panels go below the settings form, each a `SettingsPanel`; then `PluginSettingsPanelsTest` (browser, 1280 and 375): the button queues and the toast links to Tools › Jobs; the list draws text, number and badge; the failure line; axe in light and dark.
7. **Handbook and records.** `plugins/settings.md` (writing your own settings, actions, lists), `plugins/jobs.md` (`QueueOptions`, schedules and `--as`); Records; the gate.

### Quick check

The builders' gate after each task, with `php artisan test --parallel --compact` on `tests/Feature/Plugins/Sockets`, `tests/Feature/Plugins`, `tests/Feature/Jobs` and `tests/Unit/Contracts`. Once at the end: `composer check`, and `composer check:browser` for `PluginSettingsPanelsTest` and the Plugins screen's and Tools › Jobs' browser tests.

### Records (the builder's)

#### Commits on `sockets/plugin-admin`

-

#### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|
| | | |

#### Task 0

-

#### Architecture baselines

| Baseline | Before | After |
|---|---|---|
| design-system-baseline.txt | | |
| admin-words-baseline.txt | | |

#### Gate

-

#### Drafts (B-numbers from this block)

- B-01:
- B-02:
- B-03:
- B-04:
- B-05:
- B-06:
- B-07:
- B-08:
- B-09:
- B-10:
- B-11:
- B-12:
- B-13:
- B-14:
- B-15:
- B-16:
- B-17:
- B-18:
- B-19:
- B-20:

#### Contract changes

-

#### STOP

-

#### Changelog lines for the maintainers

-

#### Try it

-

---

## Part C: files at the site's root, the indexable flag, and CI (`sockets/root-files`)

Walls W4, W5 and C3.

### Owns

- **New:** `app/Domain/PublicRoutes/RootFiles.php`, `RootFile.php`, `RootFileNames.php` (a `SettingsCheck`); `app/Http/Site/Middleware/ServeRootFiles.php`; `.github/workflows/plugins.yml`; `phpstan-plugins.neon`; the fixture plugin `tests/Fixtures/plugins/keyfile/`; tests `tests/Feature/Plugins/Sockets/RootFilesTest.php`, `ItemIndexableTest.php`; `docs/guides/developers/plugins/root-files.md`.
- **Changed:** `packages/teme-contracts/src/Content/ItemRef.php` (last param); `app/Domain/Hooks/Payloads.php`; `app/Domain/PublicRoutes/RouteCollisions.php` (literal names); `routes/public.php` (`ServeRootFiles` on the fallback routes, before `MatchDeclaredRoutes`); `phpunit.xml` (a `Plugins` suite); `docs/guides/developers/testing.md` (running a plugin's tests); `docs/guides/developers/plugins/public-routes.md` (a link); the shared files above.
- **Not owned:** everything in A and B; `composer.json` (C1 and a `check:plugins` script are the maintainers' own change, open question 9).

### Decisions

**C1. Root files (W4).** Manifest `rootFiles`, at most 2 entries, needs `routes.public`:

```json
"rootFiles": [ { "id": "indexnow-key", "name": "{indexnow_key}.txt", "body": "{indexnow_key}" } ]
```

- `name` is a literal matching `^[A-Za-z0-9-]{8,128}\.txt$`, or exactly `{key}.txt` naming the plugin's own `text` setting (never a `secret`). `body` is a literal of at most 1,024 bytes, or exactly `{key}` naming such a setting. A filled name that doesn't match the pattern, or an empty value, serves nothing.
- **Core serves it; no plugin code runs.** `ServeRootFiles` sits in front of the fallback: for a path `/{name}.txt` matching the pattern it asks `RootFiles::find(name)` (enabled plugins granted `routes.public`, their settings read now); a match answers `200`, `text/plain; charset=utf-8`, the body, `X-Robots-Tag: noindex`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`, and is **never stored**; no match passes on unchanged, so redirects and the 404 page still apply.
- **No overlap.** A literal name equal to a core address (`Paths::SITE_ROUTES`, `RouteCollisions::CORE_PUBLIC`) or another enabled plugin's literal name is refused at enable, naming the owner. A name filled from a setting is checked when the setting is saved: `RootFileNames`, tagged as a `SettingsCheck` for each plugin group with root files, refuses a value whose filled name another plugin answers now (`Another plugin already answers /:name.`); B's `WritePluginSettings` runs the same check. If two still meet at serving, the plugin enabled first answers and the other is recorded through `ListenerFaults`.
- **Consent.** Listed under `routes.public` as `GET /{indexnow_key}.txt (named by its setting “IndexNow key”)` or `GET /abcd1234.txt`; `Consent::routeLines()` includes these lines, so a new root file in an update asks again.
- **Stored 404s.** `ServeRootFiles` tags a stored 404 for a root-file-shaped path with `PageTags::ROOT_FILES`; a plugin with root files gets that tag in its settings group's `cacheTags`, and `EnablePlugin` and `DisablePlugin` purge it after commit when the plugin declares root files.
- `.txt` only in this round (open question 3).

**C2. Indexable (W5).** `ItemRef` gains a last parameter `public bool $indexable = true` (`@since V`): false when the item has no `url`, or its page would say `noindex`. `Payloads::item()` asks **the class the public head asks** for an item page's robots (Task 0 names it), so the flag can never disagree with the page: the item's own "Hide from search engines", its type hidden in Search and social, or the whole site discouraged.

**C3. Plugin tests and analysis in CI.** A new workflow `.github/workflows/plugins.yml`, on push to the branches `tests.yml` names and on `pull_request`, **each with this `paths` filter**: `plugins/**`, `packages/teme-contracts/**`, `app/Domain/Plugins/**`, `app/Domain/PublicRoutes/**`, `app/Domain/Hooks/**`, `app/Domain/Jobs/**`, `app/Domain/Settings/**`, `app/Domain/Feeds/**`, `app/Domain/Site/**`, `app/Http/Site/**`, `routes/public.php`, `composer.json`, `composer.lock`, `phpunit.xml`, `phpstan-plugins.neon`, `.github/workflows/plugins.yml`, `.github/actions/prepare/**`. One job: the shared `prepare` action, PostgreSQL and Valkey as in `tests.yml`'s suite job, then, only when some `plugins/*/tests` folder exists, `vendor/bin/pest --parallel --testsuite=Plugins` and `vendor/bin/phpstan analyse -c phpstan-plugins.neon --no-progress`. `phpunit.xml` gains `<testsuite name="Plugins"><directory>plugins/*/tests</directory></testsuite>` (Task 0 proves PHPUnit takes the wildcard). `phpstan-plugins.neon` stands alone (Larastan, `tests/PHPStan/rules.neon`, level 6, `paths: [plugins]`, `excludePaths: [plugins/*/vendor/*]`, its own `tmpDir`), so `tests.yml` and `composer check` are unchanged and an unrelated push waits on nothing new. `tests.yml` stays the required check; this workflow is not required (open question 9).

### Tasks (tests first; each seen red for the reason given)

0. **Checks.** Record: V; the class the head asks for an item page's robots; that `StoreRules` stores 404s; the fallback routes' middleware order in `routes/public.php`; that PHPUnit expands `plugins/*/tests` (make a throwaway `plugins/zz/tests/ExampleTest.php`, run `php artisan test --testsuite=Plugins`, delete it; never commit it).
1. **Root files.** `RootFilesTest` with `keyfile`: once its setting holds `abcd1234ef`, `/abcd1234ef.txt` answers the body as plain text with the four headers and is not stored; before that it answers the 404 page, stored with `root-files`, and saving the setting purges it; another `.txt` passes to the 404; a redirect at a root-file-shaped path still redirects; not granted, disabled or safe mode: 404; the manifest and collision refusals; a second plugin's colliding value refused **and nothing written**; the consent lines; an update adding a root file asks again. Red.
2. **Indexable.** `ItemIndexableTest`: a published post is `true`; with its own "Hide from search engines", its type hidden, or the site discouraged, `false`; a draft `false`; each agrees with the item page's `robots` meta. Red: parameter missing.
3. **CI.** The workflow, the suite and the neon file. Prove each: `php artisan test --testsuite=Plugins` and `vendor/bin/phpstan analyse -c phpstan-plugins.neon` with Task 0's throwaway plugin (not committed); `actionlint` if present, otherwise record that the workflow is checked by its first run on the `plugins/pings` branch.
4. **Handbook and records.** `plugins/root-files.md` (the rule, its limits, the consent line), `testing.md` (a plugin's tests run with `--testsuite=Plugins`, and in CI only when plugin or socket code changes); Records; the gate.

### Quick check

The builders' gate after each task, with `php artisan test --parallel --compact` on `tests/Feature/Plugins/Sockets/RootFilesTest.php`, `tests/Feature/Plugins/Sockets/ItemIndexableTest.php`, `tests/Feature/Plugins` and `tests/Feature/Cache`. Once at the end: `composer check`. No Browser test.

### Records (the builder's)

#### Commits on `sockets/root-files`

-

#### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|
| | | |

#### Task 0

-

#### Architecture baselines

| Baseline | Before | After |
|---|---|---|
| design-system-baseline.txt | | |
| admin-words-baseline.txt | | |

#### Gate

-

#### Drafts (B-numbers from this block)

- B-01:
- B-02:
- B-03:
- B-04:
- B-05:
- B-06:
- B-07:
- B-08:
- B-09:
- B-10:
- B-11:
- B-12:
- B-13:
- B-14:
- B-15:
- B-16:
- B-17:
- B-18:
- B-19:
- B-20:

#### Contract changes

-

#### STOP

-

#### Changelog lines for the maintainers

-

#### Try it

-

---

## What must not be done (every part)

- Hand a plugin the container, a model, a query builder on Teme's tables, or a framework type.
- Let a plugin write as the operator or with elevation; let a plugin agree to hosts for itself.
- Accept markup from a plugin anywhere this plan names: feed links are values, root files are text, list cells are `Cell`s.
- Add a singleton, a dependency, a permission or an `.ai/` edit; widen an Architecture allow-list; raise a baseline.
- Change `composer.json` or `composer.lock`; regenerate `docs/reference/*.md`.
- Edit another part's lines in a shared file.

## STOP conditions

Stop, write why under that part's Records, commit and push, then stop, when: a Task 0 line differs in a way that changes a decision; a decision or a contract named here does not fit the code; making it work needs a singleton, an `.ai/` edit, a dependency or a wider allow-list; the words people read would have to change; B's Task 6 is reached before the drawing is approved.

## Joining

Merge A, B and C in any order into one join branch; order the trailing parameters of `EnablePluginInput` (A's `settingHosts`, then B's `schedulesAs`); join the shared validators' and the schema's additions; regenerate `docs/reference/plugin-manifest.md` and `docs/reference/hooks.md`; run the Architecture suite and the shared tests (`tests/Feature/Plugins`, `tests/Unit/Contracts`); let GitHub run the rest. The Pings plugin then moves onto the sockets in its own part (not this plan): `postForm` for WebSub, `feedLinks` with `hostsFrom: ["websub_hubs"]`, its key in its settings written by its scheduled job and served by `rootFiles`, `indexable`, `SiteInfo`, `FeedsChanged` in place of its own inference, `QueueOptions` in place of `pings_pending`'s race, a "Send a test ping" action, and its log as a list.
