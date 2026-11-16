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

- `0100522` feat(plugins): a form POST through the outbound door
- `08d6a8d` feat(plugins): hosts a plugin reaches from its own settings, agreed by a person
- `7412127` feat(plugins): agree to a plugin's new hosts on its settings page
- `b29bc45` feat(plugins): links a plugin adds to every feed
- `4c17645` feat(plugins): the site's public facts for every plugin
- `cbe2f65` feat(plugins): tell plugins the feeds a change touched
- `736a196` feat(plugins): a route's handler gets its plugin's doors (A-W13)
- `37c5d52` feat(plugins): a plugin's own words translate (A-W14)
- `6c7a23f` feat(plugins): the consent dialog lists the hosts a plugin's settings name
- `b9925b9` docs(plugins): feeds, the site and hosts from settings in the handbook
- this commit: docs(plans): part A's records

#### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|
| 1 | `OutboundFormTest.php` | Call to undefined method App\Domain\Plugins\Doors\PluginHttp::postForm() |
| 2 | `HostsFromSettingsTest.php` | Unknown named parameter $settingHosts; the manifest refusals' words missing |
| 2 | `HostsFromSettingsTest.php` (plugins page) | Property [settingHosts.tests/hub] does not exist. |
| 3 | `tests/Browser/PluginHostsAgreementTest.php` | Timeout 5000ms exceeded, waiting for `[data-test="hosts-agreement"]` |
| 4 | `FeedLinksTest.php` | "feeds.links" isn’t something a plugin can ask for. |
| 5 | `SiteInfoTest.php` | Undefined array key "Teme\Contracts\Site\SiteInfo" |
| 6 | `FeedsChangedTest.php` | HookRefused (FeedsChanged unknown), then: Target class [App\Domain\Feeds\ChangedFeeds] does not exist. |
| A-W13 | `RouteHandlerDoorsTest.php` | ArgumentCountError: Too few arguments to HubPage::__construct(), 0 passed; the refusal's words missing |
| A-W14 | `PluginTranslationsTest.php` | Failed asserting that 'Hub sent the ping.' is identical to 'El hub envió el ping.' |

#### Task 0

- V = 0.9.0: `git tag -l 'v0.9.0*'` lists nothing. Built on `origin/main` at `9b29abb`.
- The site's address `Addresses::urlFor()` uses: `url('/')`, the request's root in a request and `app.url` outside one. `CoreSiteInfo` reads the same.
- `PublicChanges::close()` returns the innermost Operation's tags, each once, sorted, and gathers them while the page cache is off; `purge()` alone checks `PageCache::enabled()`. **But** `Footprints` reads nothing while the page cache is off, so most of an Operation's tags were empty then (found in Task 6; B-07).
- `FeedBuilder`'s main feed lists `ListingTypes::onHome()`.
- `Contributions::add()` passes `$plugin instanceof InsertionContributor` into `DeclaredSettings::group()`: the precedent the `feedLinks` flag follows.

#### Architecture baselines

| Baseline | Before | After |
|---|---|---|
| design-system-baseline.txt | 222 | 222 |
| admin-words-baseline.txt | 876 | 876 |

#### Gate

- Before (`9b29abb`): Pint and PHPStan clean; Architecture 139 passed (1358 assertions); Unit and Feature 11711 passed, 2 skipped (60332 assertions).
- After (`b9925b9`): Pint and PHPStan clean; Architecture 139 passed (1358 assertions); Unit and Feature 11775 passed, 2 skipped, 3 failed (60549 assertions).
  - `GenerateManifestReferenceTest` and `GenerateHooksReferenceTest` fail as expected: the new manifest keys and `FeedsChanged` aren't in `docs/reference/plugin-manifest.md` and `hooks.md`, which this part must not regenerate; the join does.
  - `DatabaseDownTest` failed once in the parallel run and passes alone and in `tests/Feature/Plugins` run in parallel; nothing in this part touches it. Worth watching.
- Browser, after `npm run build`, one at a time: `PluginHostsAgreementTest` 4 passed; `Plugins/PluginsScreenTest` 19; `SettingControlsTest` 2; `SettingsSaveBarTest` 8; `SettingsPanelsTest` 18; `SettingsPlacementsTest` 10.

#### Drafts (B-numbers from this block)

- B-01: `PluginContractsTest`'s rows take an optional third value, method => `@since`, so `OutboundHttp` keeps 0.5.0 and `postForm` says 0.9.0. Options: a separate row for the method, or this. Chose this: one row per contract, as the file already is. `0100522`.
- B-02: `Consent::added()` lists `http` as new when an update adds a `hostsFrom` key, so Site › Plugins offers the review without a change to `PluginsScreen` (B's). Options: a new consent line in `PluginsScreen`. Chose this: no other part's file. `08d6a8d`.
- B-03: a re-enable keeps the hosts agreed before and their history, adding the new ones. Options: replace them. Chose keep: an agreement is never lost by a disable. `08d6a8d`.
- B-04: `UpdateSettings` counts a host as new only when neither `consent.settingHosts` nor the manifest's agreed `hosts` hold it. Options: setting hosts alone. Chose this: a manifest host typed into a setting is already agreed. `08d6a8d`.
- B-05: feeds carry `plugin:{code}` for each plugin that adds links, so `DisablePlugin`'s existing purge clears them without editing it; `EnablePlugin` purges `PageTags::FEED_LINKS`. Options: edit `DisablePlugin` (not in Owns). Chose the tag. `b29bc45`.
- B-06: `ContributionsSchemaTest` (outside Owns) lists `feedLinks` among the surfaces and checks the new constants against the schema; its meaning is unchanged. `b29bc45`.
- B-07: `Footprints` (outside Owns) reads footprints while the page cache is on **or a plugin listens for `FeedsChanged`**, so the notification comes from the same tags whether or not the page cache is on. Options: build the feeds from the changed rows instead (a second inference, which A4 rules out), or send only with the cache on. Chose this: nothing is read when nobody listens and the cache is off, as before. `cbe2f65`.
- B-08: `FeedsChanged` is added to `HookCatalogue` (outside Owns; no part owns it), and `HookContractsTest`'s count of hooks goes from 18 to 19; it still proves each short name is unique. `cbe2f65`.
- B-09: a type's archive feed is named in the default language only; a term's and an author's come from their stored paths. Options: one per language. Chose this: the archive pages' addresses in other languages aren't stored apart. `cbe2f65`.
- B-10: `SiteInfo` is offered last in `PluginDoors::for()`, and `PluginDoorsTest` and `PluginSettingsReadTest` (outside Owns) list it among what every plugin is offered; their meaning is unchanged. Last, so `JoinTest`'s positional build still lines up. `4c17645`.
- B-11: `SiteInfo::feeds()` lists the languages readers are shown (`SiteLanguages::shown()`), not the hidden ones. `4c17645`.
- B-12: A-W13 reads each route handler with `CodeScan::pluginClass()` beside the plugin's class (`ManifestValidator::classReading()` gains `handlers`), checks it with the plugin class's rules and words, and `RouteDispatcher` builds it with `PluginServices::for()` by the enabled record's consent; a handler with no constructor reads no consent. `PluginRegistry`'s cache `FORMAT` goes from 4 to 5 so an older cache is read again. Files outside Owns: `ManifestValidator`, `PluginRegistry`, `RouteDispatcher`. A handler whose file isn't found is left to the route's own checks, as before. `736a196`.
- B-13: A-W14 adds `lang/` with `addJsonPath()` when a plugin starts, so a plugin that doesn't start loads no words; Laravel reads the main `lang` folder last, so core wins a clash. The test checks the translator in Spanish rather than a whole Spanish page. File outside Owns: `PluginLoader`. The paragraph rewritten is in `rules/plugins-and-themes.md`. `37c5d52`.
- B-14: the web consent dialog lists the setting hosts from a new `settingHosts` prop that `PluginsController::index()` builds, not from `PluginsScreen` (B's), and the enable posts them; `EnablePluginRequest`, `PluginsController` and `components/plugins/consent-dialog.tsx` are outside Owns. `6c7a23f`.
- B-15: the new handbook page is listed in `GenerateHandbookIndex::PAGES` (outside Owns) and the README, and `llms.txt` is regenerated, as the guide tests require. `b9925b9`.
- B-17:
- B-18:
- B-19:
- B-20:

#### Contract changes

- `OutboundHttp::postForm()`, `@since 0.9.0`.
- `Teme\Contracts\Site\SiteInfo` (new), `@since 0.9.0`, offered to every plugin.
- `Teme\Contracts\Feeds\FeedsChanged` (new hook, Notification, no capability), `@since 0.9.0`.
- `Capability::FeedsLinks` (`feeds.links`).
- Schema: `hostsFrom`; `contributes.feedLinks`; `feeds.links` in `capabilities`.

#### STOP

- None.

#### Changelog lines for the maintainers

- Plugins can post a form to an agreed host with `OutboundHttp::postForm()`.
- Plugins can reach hosts a person types into their url settings (`hostsFrom`), once that person agrees on the settings page or when enabling.
- Plugins with `feeds.links` can add a hub link to every feed.
- Every plugin can read the site's address, name, language, home page and feeds through `SiteInfo`.
- Plugins can hear which feeds a change touched with the `FeedsChanged` hook.
- A public route's handler can ask for its plugin's doors in its constructor.
- A plugin's `lang/<locale>.json` files translate its words while it is enabled.

#### Follow-up R1

The review's findings 1, 2, 3 and 6, fixed on this branch alone, nothing merged into it.

| Finding | Commit | Test, and its first failure |
|---|---|---|
| 1 (blocking): a plugin's language file re-worded core's sentences | `3220006` | `PluginTranslationsTest`: the consent line came back as the fixture's "Nothing to worry about: api.example.com", not "Connect to services outside this site: api.example.com"; another plugin's `es.json` turned "Hub sent the ping." into "Otra cosa." |
| 2: the consent dialog's wording wasn't A2's | `725893d` | `HostsFromSettingsTest` (plugins page): "Property [settingHosts.tests/hub] does not match the expected value"; `tests/Browser/PluginHostsAgreementTest.php`: the asks list didn't include A2's line and the hosts under it |
| 3: the publish test filtered out extra notifications | `f28b8b9` | `FeedsChangedTest`, rewritten to save the draft first, clear what was heard, publish, and expect exactly one: **it passed at once**. One publish already sends one `FeedsChanged`, so the gathering across nested Operations the review offered as a fallback wasn't needed. |
| 6: the new-host line wasn't tied to its field | `7a59a13` | `tests/Browser/PluginHostsAgreementTest.php`: the field's `aria-describedby` didn't name the line, at both widths |

- B-13 corrected (`3220006`): Teme no longer calls `addJsonPath`. `PluginSentences` keeps a plugin's line only for a key its own `src/` passes to `__()` or `trans()` as a plain string (read by the new `CodeScan::sentences()`), that none of core's `lang/*.json` holds, and that no plugin started before it claimed. The translator asks these lines only for a key core has no line for, through `handleMissingKeysUsing`. The admin's catalogue (`AdminWords`) reads the file loader, so the browser gets only core's words. Core's words only (`PluginSentences::coreOnly()`): every `PluginWords` sentence, the Plugins page and its enable, `teme:plugins:enable`, and the refusals in `UpdateSettings` and `AgreePluginHosts`. The password screen draws from the admin's catalogue, which is core's only. What's left open: a plugin whose own code passes, as a plain string, one of core's sentences that no core `lang/*.json` holds can still re-word it on screens outside those. Files outside Owns: `CodeScan`, `PluginLoader`, `PluginWords`, `UpdateSettings`, `AgreePluginHosts`, `EnablePluginCommand`, `PluginsController`. The handbook's paragraph says the new rule.
- B-14 completed (`725893d`): `PluginsController::settingHosts()` now sends `replaces` (the plain http line), `line` (`PluginWords::httpWithSettings()`, A2's sentence) and `now` (`PluginWords::settingHostsNow()`). The dialog draws `line` and `now` in place of the line it replaces, so the web and the console say the same. The unplanned JS sentence is gone.
- B-16 (`7a59a13`): `HostsAgreement` adds the line's id to the field's `aria-describedby` with an effect while the line shows, and takes it out after. Options: a `describedBy` prop through `SettingsField` and `FieldControl` (two more files outside Owns). Chose the effect: only the shared `group.tsx` changes.
- Finding 4, the misplaced docblock, is LATER and not part of this follow-up. It is left as it was.
- Gate after `7a59a13`: Pint and PHPStan clean. Architecture: 139 passed (1358 assertions). Unit and Feature: 11779 passed, 2 skipped and 2 failed (60567 assertions). The 2 failures are `GenerateManifestReferenceTest` and `GenerateHooksReferenceTest`, as before. `DatabaseDownTest` passed this time. Browser, after `npm run build`, one file at a time: `PluginHostsAgreementTest` 5 passed, `Plugins/PluginsScreenTest` 19, `SettingsPanelsTest` 18, `SettingsSaveBarTest` 8. Baselines are unchanged, 222 and 876.

#### Try it

- Copy `tests/Fixtures/plugins/hub` into a plugin path, run `php artisan teme:plugins:enable tests/hub --agree`, open `/feed/` and see `<atom:link rel="hub" href="https://hub.example.com/"/>` after the self link.
- Open Settings › Hub, type `https://hub.example.net/` into More hubs: the agreement box appears; save without it and see the refusal; tick it, confirm the password, and the host is kept.

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

Setup: `cat /tmp/teme-cloud-setup.log` gave two lines, `2026-11-16T07:45:23Z ran in /home/user/teme as root` and `2026-11-16T07:57:41Z finished`; `pwd` gave `/home/user/teme`. Built on `origin/main` at `9b29abb`, which holds `plugins/list-columns` (merged by #336) and `chore/m0-kit-and-scripts` (#335).

Fixes #334: a plugin with schedules can no longer be enabled from the console without naming the administrator its schedules run as, and a plugin whose schedules can't run says why on the Plugins screen and in `teme:plugins`.

#### Commits on `sockets/plugin-admin`

- `c9e2969` feat(plugins): a plugin writes its own settings for the person its work runs for
- `96875fc` feat(plugins): queue a plugin's job after a delay, or once while one waits
- `36a6f26` feat(plugins): lists of plain rows on a plugin's settings page
- `12ef24b` feat(plugins): actions on a plugin's settings page queue its declared jobs
- `9cf630d` feat(plugins): schedules never fail in silence when a plugin is enabled from the console
- `a01be3c` test(plugins): EnablePlugin checks the named administrator again
- `433f75f` docs(plugins): writing a plugin's own settings, actions, lists, queue options and schedules from the console
- `037e8f3` docs(plugins): regenerate the handbook index for the settings and jobs pages
- the commit that fills these Records

#### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|
| 1 | `tests/Feature/Plugins/Sockets/WritePluginSettingsTest.php` | `Tests\Fixtures\Plugins\Workbench\Workbench asks for Teme\Contracts\Settings\PluginSettingsWriter; a plugin may ask only for what Teme offers plugins.` (ValidationException at enable: the door wasn't offered) |
| 2 | `tests/Feature/Plugins/Sockets/QueueOptionsTest.php` | `Class "Teme\Contracts\Jobs\QueueOptions" not found` (12 failed, 1 passed) |
| 3 | `tests/Feature/Plugins/Sockets/PluginListsTest.php` | Seen red before `contributes.lists` and the lists contracts existed; its exact first line wasn't kept in my notes, and I didn't rerun the old commit while the gate held the test database. |
| 4 | `tests/Feature/Plugins/Sockets/PluginActionsTest.php` | `Teme doesn’t offer the actions surface to plugins yet.` (14 failed) |
| 5 | `tests/Feature/Plugins/Sockets/ConsoleSchedulesTest.php` | `Unexpected status code 0 was received.` (the plugin with schedules enabled, saying nothing), then `The "--as" option does not exist.` (9 failed, 1 passed) |
| 6 | `tests/Browser/PluginSettingsPanelsTest.php` | Not written: the screen task waits for the drawing's approval (see STOP). |

#### Task 0

- V is `0.9.0` (no `v0.9.0` tag yet; the release the contracts already name).
- `plugins/list-columns` is on `main`: `git log --oneline -1 -- packages/teme-contracts/src/Lists/Cell.php` gives `731b598 Merge plugins/list-columns, with the review's fixes`. Task 3 went ahead.
- `tracked_jobs`: `id`, `kind` (64), `owner` (64, default `core`), `label` (200), `starter` (16), `started_by`, `run_type` (32), `run_id` (64), `state` (16), `total`, `done`, `message` (500), `payload` (jsonb), `created_at`, `updated_at`, `ended_at`; this part adds `unique_key` (64). States (`JobState`): `waiting`, `running`, `finished`, `failed`, `cancelled`.
- `SettingsWriter::write(SettingsWrite)` takes `origin` on `SettingsWrite` (default `human`); `settings_changes.origin` was `varchar(16)` with a CHECK naming the fixed origins (see B-01).
- `UpdateSettings` passes the form's `expectedVersion` to `SettingsWriter`, which locks the group's row (`lockForUpdate`) and compares the version under the lock (see B-02).

#### Architecture baselines

| Baseline | Before | After |
|---|---|---|
| design-system-baseline.txt | 222 | 222 |
| admin-words-baseline.txt | 876 | 876 |

#### Gate

- Before (on `9b29abb`): `composer check` green; Architecture 139 passed; Unit and Feature 11,711 passed, 2 skipped (1,893 s).
- After (on `433f75f`, before the index commit): Pint, PHPStan, types, lint and the themes' build green; Architecture 139 passed (1,358 assertions); Unit and Feature 3 failed, 11,784 passed, 2 skipped (60,573 assertions, 1,785 s). The three:
  - `GenerateHandbookIndexTest`: `llms.txt` behind the two handbook pages' new summaries. Regenerated in `037e8f3`; now green.
  - `GenerateManifestReferenceTest`: `docs/reference/plugin-manifest.md` lacks `contributes.actions` and `contributes.lists`. Expected: this plan says not to regenerate it, the join does.
  - `DatabaseDownTest` (`it serves an ordinary request with the database down`): no failure reported (0 where 1 is expected) in that run only. It passes alone (three runs) and in `tests/Feature/Plugins` in parallel (669 passed). A second run of Unit and Feature on `037e8f3`: 2 failed (`GenerateManifestReferenceTest`, as above, and `DiscoveryTest`, see the open items), 11,785 passed, 2 skipped (60,569 assertions, 1,796 s).
- The plan's quick check, after the last change: `tests/Feature/Plugins` 669 passed; `tests/Feature/Jobs` 81 passed; `tests/Unit/Contracts` 133 passed; `tests/Feature/Guides/Developers` 189 passed.
- Browser (after `npm run build`, `npm run build:themes` and `teme:themes:link`, one file at a time): `tests/Browser/Plugins/PluginsScreenTest.php` 19 passed; `tests/Browser/JobsScreenTest.php` 5 passed. `PluginSettingsPanelsTest` isn't written (Task 6).

#### Drafts (B-numbers from this block)

- B-01: `settings_changes.origin` was `varchar(16)` with a CHECK of fixed origins, so `plugin:{code}` couldn't be stored. A second migration, `2027_07_20_100000_allow_plugin_origin_in_settings_changes`, widens it to 64 and lets the CHECK also accept `^plugin:[a-z0-9][a-z0-9-]{1,39}$`. It changes no row; its `down()` maps `plugin:` origins to `system` before restoring the old CHECK. The plan names one migration only.
- B-02: `WritePluginSettings` passes `expectedVersion: null`. The writer already locks the group's row and reads its version under that lock, which is what B1 asks; a plugin has no form opened on an older version to compare with.
- B-03: The workbench fixture's namespace `Tests\Fixtures\Plugins\Workbench` maps through `autoload-dev` to `tests/Fixtures/Plugins/Workbench`, but plugin fixtures live in `tests/Fixtures/plugins/` (lower case), and `composer.json` may not change. The Sockets tests `require_once` the fixture's `src/*.php` at the top of the file.
- B-04: `ListColumns`' cell cleaner was private; it moved unchanged into `app/Domain/Plugins/Columns/CellText.php` and both `PluginColumns` and `PluginLists` call it, as B3 allows.
- B-05: The Plugins screen's row is drawn by `resources/js/components/plugins/plugin-row.tsx`, not `pages/plugins/index.tsx`, so the schedules warning line went there (`data-test="plugin-schedules"`), with `schedules: string | null` in `resources/js/types/plugins.ts`.
- B-06: `EnablePlugin` gets an account ID, not an email, so its own refusal reads `No one who may manage plugins has that account.`; the command says the plan's sentence with the email before anything reaches the Operation.
- B-07: `plugins.consented_by` is `nullOnDelete`, so deleting the administrator leaves it null and the first sentence (`enabled from the command line without naming an administrator`) shows, not `the administrator who enabled it no longer has an account`. The second sentence is reachable only for an ID that no longer resolves, and is tested with an unsaved record. Left for the maintainers (open items).
- B-08: `PluginActions` (which actions a plugin declares, for an enabled plugin granted `jobs`) lives in `app/Domain/Plugins/Lists/`, the folder this part owns, beside `PluginLists`.
- B-09: `RunPluginAction` queues as the person who clicked through a `PluginJobs` given that person (`JobActor::person`), a new last constructor parameter `?JobActor $as = null`; otherwise `PluginJobs` takes the signed-in person or the operator, as before.
- B-10: `SettingsController` already serves `pluginActions` and `pluginLists` for a plugin's group, so the lists are asked whenever a plugin's settings page is opened, though nothing draws them until Task 6.
- B-11: `DueSchedules` writes a skipped plugin to the log at most once a day with `Cache::add` on a key holding the plugin's code and the date (the disposable cache table; nothing serialized of ours).
- B-12: The plan says "STOP before" Task 6 until the drawing is approved; the owner's message for this part says to build every other task and leave Task 6 for a follow-up, not a STOP. Followed the owner.

#### Contract changes

- New, `@since 0.9.0`: `Teme\Contracts\Settings\PluginSettingsWriter`; `Teme\Contracts\Jobs\QueueOptions`; `Teme\Contracts\Lists\HasPluginLists`; `Teme\Contracts\Lists\PluginList`. All four have rows in `PluginContractsTest`.
- Changed: `Teme\Contracts\Jobs\Jobs::queue()` gains a last parameter `?QueueOptions $options = null`.
- Schema: `contributes.actions` and `contributes.lists` in `teme-plugin.schema.json`.

#### STOP

- None. Task 6 (the Actions and lists panels, `plugin-actions.tsx`, `plugin-list-panel.tsx`, `group.tsx`, `PluginSettingsPanelsTest`) is left for a follow-up once the maintainers approve the drawing, as the owner asked.

#### Open items

- A job can't learn whom it runs as: `JobContext` gives the payload, the attempt and progress, but no person ID, so a scheduled job can't name the administrator to `PluginSettingsWriter::write()` (B1's "a key generated in a scheduled job is saved"). The tests pass the ID in from outside. The smallest fix is a `JobContext::actingPersonId(): ?string` (`@since 0.9.0`, core implements `JobContext`, so no plugin breaks); it's a contract change for the maintainers to decide.
- Task 6 waits for the drawing (above).
- B-07: decide whether a deleted administrator should show its own sentence (for example by reading who enabled it from the security log) or whether the first sentence's wording should cover it.
- `DatabaseDownTest` failed once in the full run and passes alone and in its folder; a second full run of Unit and Feature (2 failed, 11,785 passed, 2 skipped, 1,796 s) passed it, and failed instead `DiscoveryTest` (`it finds the configured folders`), whose registry also held `tests/jobs`; that test passes alone (twice). Both look order-dependent: state from another test's fixtures leaking into the next one in the same worker. Every new test file here forgets its fixtures in `afterEach`, as `PluginSchedulesTest` does, and I didn't find the leak. Worth a look before the join.
- `docs/reference/plugin-manifest.md` to regenerate at the join.

#### Changelog lines for the maintainers

- Added: a plugin can save its own settings for the person its work runs for (`PluginSettingsWriter`), never secrets or addresses.
- Added: a plugin can queue a job after a delay, or once while one already waits (`QueueOptions`).
- Added: plugins can declare actions (buttons that queue their jobs) and small lists for their settings page; the panels that draw them follow.
- Changed: `teme:plugins:enable` asks for `--as=<email>` for a plugin with schedules, and the Plugins screen and `teme:plugins` say when a plugin's schedules can't run. Fixes #334.

#### Try it

- `php artisan teme:plugins:enable <a plugin with schedules> --agree` is refused and names `--as`; add `--as=<an administrator's email>` and it's enabled, its schedules running as that person.
- Enable one from the console on `main` before this part (no `consented_by`), then open Site › Plugins: the row says its schedules don't run and how to fix it; `php artisan teme:plugins` says the same under Why.
- `php artisan test --compact tests/Feature/Plugins/Sockets` runs the workbench fixture through settings writes, queue options, lists, actions and the console.

#### Follow-up R1

The review's verdict was "merge after fixes"; this round fixes Finding 1 and corrects Finding 2's wording. Findings 3 to 6 are left, as the owner asked.

- Commits: `c95e3c1` fix(jobs): retrying a failed keyed run while another waits is refused, not a database error; then the commit that adds this section.
- B-13: `app/Domain/Jobs/Operations/RetryJob.php` is outside this part's Owns; the maintainers approved changing it for this fix only. Under the job's row lock, before setting it waiting, `RetryJob` refuses with `A run of this job is already waiting.` when the job has a `unique_key` and another run with the same owner, job and key already waits. Without it, the update broke `tracked_jobs_waiting_unique` and the person got a database error (500) instead of a plain refusal. A job without a key retries as before.
- Tests seen red:

| File | Test | First failure |
|---|---|---|
| `tests/Feature/Plugins/Sockets/QueueOptionsTest.php` | `it refuses to retry a failed keyed run while another with its key waits, writing nothing` | `Expected: SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "tracked_jobs_waiting_unique"` |

  The companion test `it retries a failed keyed run when nothing with its key waits` passed before and after: it guards that the refusal is only for a waiting sibling.
- Correction to B1's wording (Finding 2): B1 says "Scheduled work runs as the enabling administrator, so a key generated in a scheduled job is saved". That holds for the door, but not yet for a real plugin: `JobContext` gives a handler no person ID, so a scheduled job's handler can't name the administrator to `PluginSettingsWriter::write()`. `WritePluginSettingsTest` passes the ID in from outside the job. Read B1 as: a plugin's write from a job is saved when it names the person the job runs as, and a handler can learn that person once round 3 adds `JobContext::personId()`. Until then the handbook's "the person a job runs as" has no source in the job itself; round 3 documents where it comes from. Not built here, as the owner asked.
- Gate: `composer check` on `c95e3c1`: Pint, PHPStan, types, lint and the themes' build green; Architecture 139 passed (1,358 assertions); Unit and Feature 1 failed, 11,788 passed, 2 skipped (60,579 assertions, 1,847 s). The one failure is `GenerateManifestReferenceTest`, expected as before (the join regenerates `docs/reference/plugin-manifest.md`). `DatabaseDownTest` and `DiscoveryTest` passed in this run. Baselines unchanged (222, 876).

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

Setup: `/tmp/teme-cloud-setup.log` "2026-11-16T07:45:31Z ran in /home/user/teme as root … 07:57:53Z finished"; `pwd` /home/user/teme. Built on `origin/main` at `9b29abb` (the m0 merge `ca95093` and the list-columns join).

- `47f1d54` feat(plugins): root files a plugin declares, served by core (Task 1)
- `050abf0` ci(plugins): run first-party plugins' tests and analysis when they change (Task 3)
- `17f9ead` docs(guides): running a first-party plugin's tests, and a link to root files (Task 4)
- `5dbd555` docs(plans): root files part's records
- `8876f99` feat(contracts): ItemRef says whether its page is indexable (Task 2, follow-up R1)
- this commit: docs(plans): root files part's records, follow-up R1

#### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|
| 1 | tests/Feature/Plugins/Sockets/RootFilesTest.php | `Unknown named parameter $rootFiles` (12 tests); the manifest refusals `Failed asserting that an array contains 'It declares root files but doesn’t ask for routes.public.'`; the consent lines `Failed asserting that two arrays are identical`; the console `Output does not contain "GET /{site_key}.txt …"` |
| 2 | tests/Feature/Plugins/Sockets/ItemIndexableTest.php | `Undefined property: Teme\Contracts\Content\ItemRef::$indexable` (6 tests) |
| 2 (R1) | tests/Unit/Contracts/HookContractsTest.php | `Failed asserting that two arrays are identical`, with a parameter without a default planted after `language` in `ItemRef` (removed before the commit) |

#### Task 0

- V = `0.9.0` (`git tag -l 'v0.9.0*'` lists nothing).
- The class the head asks for an item page's robots: `App\Domain\Site\Head\RobotsMeta::directives($kind, $hidden, $fields)`, called by `PageHead::article()` with `$hidden` = the revision's `BuiltInFields::NOINDEX`, or `TitlesAndListing::hidesType($type)` when the item isn't the home page; kind `single`, or `protected` for core's password form; `WholeSite::discouraged()` is checked inside.
- `StoreRules::refusal()` stores 200 and 404 answers (`! in_array($status, [200, 404])` refuses the rest); a `Cache-Control: no-store` answer is refused as `private`.
- Fallback order in `routes/public.php`: `feeds.show` (`{path}` matching `FeedPath::ROUTE`), `markdown.show` (`{path}.md`), `declared.plugin` (`{code}/{path?}`, code `[a-z0-9][a-z0-9-]{1,39}`, no dot), then `public.path` with `throttle:declared-routes`, `MatchDeclaredRoutes`. A root-file-shaped path (`/name.txt`) reaches only `public.path`, so `ServeRootFiles` sits there, before `MatchDeclaredRoutes`.
- PHPUnit expands `plugins/*/tests`: with a throwaway `plugins/zz/tests/ExampleTest.php`, `php artisan test --testsuite=Plugins` and `vendor/bin/pest --parallel --testsuite=Plugins` each ran 1 test; with no `plugins/` folder the suite says "No tests found." and exits 0. `phpstan analyse -c phpstan-plugins.neon` said no errors on a clean class and found the planted `return.type` error. Removed, never committed.
- `actionlint` isn't installed: the workflow is checked by its first run on the `plugins/pings` branch (its YAML parses).
- The Plugins screen needs no change: `PluginsScreen::accessLines()` lists `Consent::routeLines()` under routes.public, so root-file lines reach the consent dialog through it; `PluginWords` and `plugins/index.tsx` are unchanged.

#### Architecture baselines

| Baseline | Before | After |
|---|---|---|
| design-system-baseline.txt | 222 | 222 |
| admin-words-baseline.txt | 876 | 876 |

#### Gate

- Before (base `9b29abb`): Architecture 139 passed (1358 assertions); Unit and Feature 11,711 passed, 2 skipped (60,333 assertions).
- After: Pint, PHPStan, types and `npm run check` pass; Architecture 139 passed (1358 assertions); Unit and Feature 11,740 passed, 2 skipped, **2 failed** (60,427 assertions):
  - `GenerateManifestReferenceTest` "keeps the committed reference in step": the new `rootFiles` rows are missing from `docs/reference/plugin-manifest.md`, which this plan says the join regenerates, once. Expected; not regenerated here.
  - `MediaFiltersTest` "finds each month…" (a query plan under parallel load): passes alone (7 passed). Not touched by this part.
- No Browser test (the plan names none).

#### Drafts (B-numbers from this block)

- B-01: How a starting plugin's settings group learns it has root files. `rootFiles` is a top-level manifest key, not under `contributes`, so `Contributions::add()` never sees it. Options: a lookup inside the static `DeclaredSettings::group()`; or a last parameter `bool $rootFiles = false` on `PluginContributions::add()` and `Contributions::add()`, passed by `PluginLoader` from the manifest. Chose the parameter (explicit, no hidden read). `Contributions` gains a `Container` to register the per-group check, and the test fixture `RecordingContributions` takes the same parameter so it still implements the seam. `47f1d54`.
- B-02: `RootFileNames` must be one `SettingsCheck` per plugin group, and `UpdateSettings` reads checks from a container tag. `RootFileNames::register()` binds `RootFileNames:{code}` (a plain binding, not a singleton) and tags it, once a process, from `Contributions::add()` when the plugin declares root files. `47f1d54`.
- B-03: `EnablePlugin` and `DisablePlugin` aren't in this part's Owns, but C1 says both purge the root-files tag. Added one tag to each one's existing after-commit purge; `DisablePlugin` gains a `PluginRegistry` to read the manifest and purges when the plugin declares root files or its code is gone. `EnablePlugin::validate()` also refuses a literal name another enabled plugin declares, through `RouteCollisions::rootFilesAgainst()`. `47f1d54`.
- B-04: The consent line (`GET /{key}.txt (named by its setting “Label”)`) is stored in `consent.routes` and compared by `Consent::covers()`, so it is built untranslated, as `DeclaredRoute::consentLine()` is; translating it would make the stored consent depend on the enabling person's language. `47f1d54`.
- B-05: The handbook page `plugins/root-files.md` was written in Task 1, not Task 4, because the new code cites it and `DocumentationTest` refuses a citation of a missing document; adding a page also needs its line in `GenerateHandbookIndex::PAGES`, a row in the handbook README and `llms.txt` regenerated. `47f1d54`.
- B-06: A setting value is trimmed; a filled body that is empty or over 1,024 bytes serves nothing, as a too-long literal is refused (the plan sizes only the literal). `47f1d54`.
- B-07: Outside production, `NoindexOutsideProduction` turns the answer's `X-Robots-Tag` into `noindex, nofollow`; the test checks it contains `noindex`. In production the header is `noindex`. `47f1d54`.
- B-08: The "two still meet" test writes the second plugin's value straight to the settings table (`cmSettings`), as a value saved before the first plugin took the name would stand; `SettingsWriter` outside an Operation refuses a group with cache tags. `47f1d54`.
- B-09: The workflow checks for `plugins/*/tests` before installing anything, so a run with no plugin tests takes seconds; analysis passes `--memory-limit=2G` as `composer check:plugins` does. `050abf0`.
- B-11: ItemRef grows at the end, per .ai/rules/contracts.md. The maintainers' decision (follow-up R1): the order test, renamed "gives an item reference its first values in this order, and anything added later at the end with a default (I-3360, contracts rule)", proves the first six parameters are exactly `id, type, title, status, url, language`, none of them has a default, and every later parameter has one. `indexable = true` passes it; a later parameter without a default fails it. `8876f99`.
- B-10: `testing.md` shows `vendor/bin/pest --testsuite=Plugins` rather than `php artisan test --testsuite=Plugins`: the guides' check reads artisan's own options and refuses `--testsuite`. `17f9ead`.

#### Contract changes

- `packages/teme-contracts/schema/teme-plugin.schema.json`: `rootFiles` added (the plan names it). `Teme\Contracts\Content\ItemRef` gains a last parameter `public bool $indexable = true` (`@since 0.9.0`), and joins the `PluginContractsTest` list at `@since 0.5.0` (`8876f99`).

#### STOP

- **Resolved by follow-up R1 (B-11); Task 2 is committed as `8876f99`.** Was: **Task 2 (indexable) is not committed.** `tests/Unit/Contracts/HookContractsTest.php` "gives an item reference its values in this order, its language last (I-3360)" asserts `ItemRef`'s parameters are exactly `id, type, title, status, url, language` and that none has a default. Adding `indexable = true` last, as C2 and the contracts rule require, makes it fail, and making it pass changes what it proves (language last, no defaults). The brief makes that a STOP. The work is ready: `ItemRef` gains `public bool $indexable = true` (`@since 0.9.0`); `Payloads::item()` sets it to `$url !== null && ! in_array('noindex', RobotsMeta::directives($kind, $hidden, $fields))`, with the published revision's fields, `hidesType()` except for the home page and its language versions, and kind `protected` for a protected item; `ItemIndexableTest` (6 tests: published true; own box, type hidden, site discouraged false and agreeing with the page's robots meta; a draft false; the parameter last with its default and `@since 0.9.0`) passed with it. Needs the maintainers' word on the HookContractsTest line (for example, the six in order with no default, then `indexable` last with one). Task 3 and Task 4 don't depend on it and are done.

#### Changelog lines for the maintainers

- Added: a plugin granted routes.public can declare up to two `rootFiles`, plain-text files at the site's root (such as an IndexNow key), named and filled literally or from its own text settings; core serves them with noindex, nosniff and no-store, never stores them, and refuses a name another plugin or core already answers.
- Added: the consent lists each root file, and an update that adds one asks again.
- Added: `ItemRef::$indexable`, whether search engines may list the item's page, as its robots meta says.
- Added: a `Plugins` test suite, `phpstan-plugins.neon` and a `plugins` workflow that run first-party plugins' tests and analysis only when plugin or socket code changes.

#### Follow-up R1

- Decision: B-11, ItemRef grows at the end, per .ai/rules/contracts.md. The order test was reshaped as the maintainers set out, then Task 2 was finished as written.
- Seen red: `HookContractsTest` (the reshaped test) `Failed asserting that two arrays are identical` with a planted parameter without a default; `ItemIndexableTest` `Undefined property: Teme\Contracts\Content\ItemRef::$indexable` (6 failed), before the code.
- Quick check: Pint, PHPStan, Architecture 139 passed (1358 assertions); `tests/Feature/Plugins` 626, `tests/Unit/Contracts` 130, `tests/Feature/Hooks` 78, `tests/Feature/Cache` 322, `tests/Feature/Seo` 195, all passed.
- `composer check`: Pint, PHPStan, types and `npm run check` pass; Architecture 139 passed (1358 assertions); Unit and Feature 11,747 passed, 2 skipped, **2 failed** (60,450 assertions):
  - `GenerateManifestReferenceTest` "keeps the committed reference in step": as before, the join regenerates `docs/reference/plugin-manifest.md`.
  - `DiscoveryTest` "finds the configured folders…": under parallel load it found a third fixture, `tests/jobs`, which another test had configured; it passes alone (8 passed). Not touched by this part. (`MediaFiltersTest` passed this run.)
- Baselines unchanged: design-system 222, admin-words 876.

#### Try it

- Point `TEME_PLUGIN_PATHS` at `tests/Fixtures/plugins/keyfile` (the fixture requires Teme ^0.5, which an untagged build reports as 0.1.0, so set `teme.version` as `PluginFixtures` does), run `php artisan teme:plugins:enable tests/keyfile` (it lists `GET /{site_key}.txt (named by its setting “Site key”)` and `GET /keyfile-literal.txt`), then again with `--agree`. Open `/keyfile-literal.txt`; set "Site key" to `abcd1234ef` on the plugin's settings page and open `/abcd1234ef.txt`: plain text, `X-Robots-Tag: noindex`, `Cache-Control: no-store`.
- `vendor/bin/pest tests/Feature/Plugins/Sockets/RootFilesTest.php`.

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
