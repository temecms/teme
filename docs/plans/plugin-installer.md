# Plan: the plugin and theme installer, in the standard install

**Three parts, three branches, built side by side:** `installer/service` (part A), `installer/admin` (part B), `installer/sources` (part C), all from `origin/main` after the v1 join (`join/v1-wave2`) has merged. **Part A's Task 1 is the seam** (tables, enums, the source contract, the permission): A pushes it first; B and C branch from that commit, and nothing else of A's. Proposal: `docs/proposals/plugin-installer.md` (drafted as `plugin-installer-proposal.md` beside this file; its Settled section is binding). Roadmap 5.3c, brought forward 2026-11-16. Decides I-211's build, I-036, I-3462 (option C), the first half of I-433 (a paid vendor's Composer source with a key), and closes I-406 (installer versus image deploys).

Steps use checkbox (`- [ ]`) syntax for tracking.

**Written 2026-11-16.** Lighter plan (I-569): decisions, names, schema, permissions, tests and the delicate code are written out; the rest is described.  P- and Q-numbers are the proposal's (provisional choices and open questions).

**Goal.** Anyone running the standard Dokploy stack or the plain Compose recipe installs, updates, removes and rolls back plugins and themes from the admin, with no repository or build of their own. The public image stays as published. The web process never writes code: an Operation records the request; a separate `installer` service, as its own system user that alone can write the extensions volume, stages a new release with Composer, verifies and validates it, swaps it in atomically and keeps the previous one. Enabling a plugin or activating a theme stays a separate step, through the screens that exist.

**Bound by:** rule 1 (every change through an Operation; the installer records through three Operations, as the updater does with `BeginTemeUpdate` / `RecordTemeUpdateStep` / `EndTemeUpdate`), rule 3, rule 4 (JSONB; uploads are `bytea`, never serialized), rule 5 (the source contract is internal, in `App\Domain`, not `teme/contracts`), rule 6 (page-cache purge after commit), spec §3 (code is read-only at runtime), `docs/architecture/plugins.md` (*Installing*, *Trust*, *Lifecycle*), `docs/architecture/themes.md` (*Discovery and the cache*), `docs/architecture/operations.md` (*The operator*), I-3442 (a plugin's tables run at enable and with "Run database changes"), I-569, I-3317, I-3439 (American English).

**New dependency:** none. Composer is already in the image (the `code` stage runs `composer install` on the same base the runtime stage uses); `composer/semver` is already used by the theme validator. **Migrations:** four (three tables, one permission). **Permission:** one new, `extensions.install`, for administrators. **Operations:** nine new (listed under *Names*).

---

## What main does today (Task 0 of each part confirms each, with its file and line)

- **Discovery is Composer's runtime data.** `PluginRegistry::installedPackages()` and `ThemeRegistry::discoverComposer()` call `InstalledVersions::getInstalledPackagesByType('teme-plugin' | 'teme-theme')` and `getInstallPath()`. `InstalledVersions::getInstalled()` walks **every registered Composer `ClassLoader`** (`ClassLoader::getRegisteredLoaders()`) and reads each one's `vendor/composer/installed.php` (`vendor/composer/InstalledVersions.php`, around line 358). So a second Composer project whose autoloader is registered is discovered with no change to either registry.
- `InstalledPackages` reads one file, the image's `vendor/composer/installed.json` (or `COMPOSER_VENDOR_DIR`).
- The caches: `config('teme.plugin_cache')` = `bootstrap/cache/teme-plugins.php`, `config('teme.theme_cache')` = `bootstrap/cache/teme-themes.php`, written at image build by `teme:plugins:cache` and `teme:themes:cache`. The plugin cache carries no Teme version (checked on every read); an entry whose identity no longer matches is read live for that process. Only those commands write a cache.
- `PackagesWithoutPlugins` keeps every `teme-plugin` out of Laravel's package discovery, which reads only the image's `vendor/composer/installed.json`.
- `CodeScan` reads a plugin's code without running it and refuses a plugin that declares `autoload.files`.
- `teme:themes:link` links `public/themes/{code}` to each usable theme's `public/`; it runs at image build as root. `public/` is not writable by `www-data`.
- **The image:** `USER www-data` at the end; `PHP_OPCACHE_VALIDATE_TIMESTAMPS=0`; `/var/www/html` owned by root; only `storage` chowned. `docker/teme/entrypoint.d/60-teme-start.sh` runs `config:cache` and `route:cache` into `storage/` before every role.
- **The stack:** `docker/compose.dokploy.yml` and `docker/compose.yml` run `deploy` (migrates, warms pages), `web`, `worker`, `worker-long`, `scheduler` and, on Dokploy, `updater` (`teme:updater`, its own image `TEME_UPDATER_IMAGE`). Volumes: `pgdata`, `media`, `page-cache`. The public stack `temecms/temecms-for-dokploy` copies `compose.dokploy.yml` with marked changes (`env_file`, resource variables) and its `template.toml` names the three volumes.
- **The updater pattern to copy:** `RunUpdater` (`teme:updater`): a loop that beats `Heartbeats::UPDATER`, takes `pg_try_advisory_lock` per pass, runs one step through `UpdateRunner::tick()`, binds `OperatorElevation`. `RequestTemeUpdate` implements `RequiresElevation`, takes an advisory transaction lock, inserts a `teme_updates` row; a partial unique index allows one open request. `UpdatesScreen` shows `updaterSeenAt`.
- **Secrets:** `EncryptedValues::all()` lists every encrypted column; `tests/Feature/Security/EncryptedValuesTest.php` fails until a new one is listed.
- `SafeMode::PATHS` and `SafeMode::COMMANDS` (the latter holds `teme:updater`, `teme:updates:`) list what runs with no plugin loaded.
- The admin: `resources/js/pages/plugins/index.tsx` (tabs All, Enabled, Disabled, Needs attention; `ConsentDialog`, `DetailsDialog`, `RemoveDataDialog`; the sessionStorage "pending" marker for password-gated requests), `resources/js/pages/themes/index.tsx` (`ThemeList`), `PluginsController`, `ThemesController`, routes in `routes/admin.php` under `can:plugins.manage` and `can:settings.design.manage`. The v10 demo (`docs/demos/v10/plugins.js`) draws an **Install requests** tab marked Soon.
- `teme/contracts`' own `composer.json` has no `version`; core installs it from a path repository, so its installed version is a branch version (`dev-<branch>` here; **unverified** in the image, which builds from `git archive` without `.git`). Fixture plugins require `"teme/contracts": "*"`.

## The decisions, written out

### D1. Where extensions live: one named volume, in releases

The stack gains a volume `extensions`, mounted at `/var/teme/extensions` (env `TEME_EXTENSIONS_PATH`, unset by default: no installer, nothing loaded). Layout:

```
/var/teme/extensions/                    owned by teme-installer (uid 9990), 0755
  current -> releases/<ulid>             relative symlink; the one truth of what runs
  releases/<ulid>/                       immutable once swapped in
    composer.json  composer.lock         the second Composer project (D2)
    vendor/                              its packages and autoloader
    compat/<core-hash>                   empty stamp: checked against this image's core (D4)
    cache/<core-hash>/teme-plugins.php   discovery caches for this core
    cache/<core-hash>/teme-themes.php
    public/themes/<code> -> ../../vendor/<package>/public
    release.json                         request id, core hash, packages with versions and references
  packages/<vendor>/<name>/<version>/    uploaded packages, unpacked (part C); never changed once written
  .composer/                             COMPOSER_HOME (cache only), 0700
  stage.lock                             flock while a release is being built
```

Every role except the installer mounts the volume **read-only** (`extensions:/var/teme/extensions:ro`), so even a wrong permission can't let `www-data` write it. The installer mounts it read-write. The image creates `/var/teme/extensions` owned by `teme-installer` so a new named volume starts with that owner; the installer checks ownership and write access at start and stops with one plain sentence naming the fix when either is wrong (**unverified** on Dokploy that a new named volume copies the mount point's owner; Task A0 checks it locally, the live check on Dokploy).

Releases kept: `current` and the two before it (P1). A release that never went live is deleted when its request ends.

### D2. How Composer autoloading merges: a second Composer project, loaded after core's

**Chosen:** the extensions folder is its own Composer project, and core requires its autoloader after its own (`bootstrap/extensions.php`, included by `public/index.php` and `artisan` right after `vendor/autoload.php`).

Options weighed:
- (a) **A second Composer project** whose `replace`/`provide` pin every package the image already has, and whose autoloader is registered after core's with `prepend-autoloader: false`. Discovery keeps working unchanged (InstalledVersions walks every registered loader); Composer resolves each plugin's needs against core's exact versions and refuses a conflict before anything is swapped; the image's `vendor/` is never touched. **Chosen.**
- (b) **Path repositories in core's own `composer.json`**: means running Composer against the image's `vendor/`, which the image owns and replaces on every update; it would have to be redone at every container start, with the network, as root. Rejected.
- (c) **Winter's way** (`modules/system/classes/ComposerManager.php`): read each extension's `autoload_psr4.php`, classmap and files and add them to core's loader, skipping prefixes core already has. It never registers a second loader, so InstalledVersions would not see the packages, and without pins a plugin's dependency could silently get core's different version. Its "core's prefix wins" rule is kept, as a validation (D6), and its pools idea is kept by `prepend-autoloader: false`.

The extension project's `composer.json`, written only by the installer:

```json
{
    "name": "teme/site-extensions",
    "description": "Plugins and themes installed from Teme's admin. Written by the installer; never edit it by hand.",
    "type": "project",
    "require": { "acme/link-suggestions": "^1.2" },
    "replace": { "laravel/framework": "v13.4.0", "teme/contracts": "dev-main", "...": "every package the image has, and what each one replaces" },
    "provide": { "psr/log-implementation": "3.0.0", "...": "what the image's packages provide" },
    "repositories": [],
    "minimum-stability": "stable",
    "prefer-stable": true,
    "config": {
        "allow-plugins": false,
        "prepend-autoloader": false,
        "optimize-autoloader": true,
        "secure-http": true,
        "sort-packages": true,
        "lock": true
    },
    "extra": { "teme": { "core": "<core hash>", "sources": { "acme/link-suggestions": "packagist" } } }
}
```

`repositories` holds one entry per source a required package came from (D7); Packagist stays enabled unless the operator turns it off (P6). `extra.teme.sources` records which source each requested package came from, so an update or a reinstall from another source asks first (pursae's "later versions must come from the same publisher").

**The delicate code: the core pins** (`App\Domain\Extensions\CorePins`), computed from the image's `vendor/composer/installed.json` and written at image build by `teme:extensions:pins` to `bootstrap/cache/teme-core-pins.php` (root-owned, OPcache keeps it):

```php
/**
 * @return array{replace: array<string, string>, provide: array<string, string>, hash: string}
 */
public function build(array $installed, string $rootName): array
{
    $replace = [$rootName => 'dev-main'];
    $provide = [];

    foreach ($installed['packages'] ?? $installed as $package) {
        $version = (string) $package['version'];          // pretty version, as Composer wrote it
        $replace[$package['name']] = $version;

        foreach ($package['replace'] ?? [] as $name => $constraint) {
            $replace[$name] ??= $constraint === 'self.version' ? $version : $constraint;
        }

        foreach ($package['provide'] ?? [] as $name => $constraint) {
            $provide[$name] ??= $constraint === 'self.version' ? $version : $constraint;
        }
    }

    ksort($replace);
    ksort($provide);

    return [
        'replace' => $replace,
        'provide' => $provide,
        'hash' => substr(hash('sha256', json_encode([$replace, $provide], JSON_THROW_ON_ERROR)), 0, 16),
    ];
}
```

Only production packages are pinned (the image installs with `--no-dev`, so its `installed.json` has no dev packages; a developer machine's has, and `teme:extensions:pins --no-dev` reads `dev-package-names` to leave them out). `teme/contracts` is pinned at its installed version; with a branch version, a plugin's `"*"` matches and a range such as `^0.9` does not (Q2).

**The delicate code: loading** (`bootstrap/extensions.php`, plain PHP, before Laravel):

```php
<?php

declare(strict_types=1);

// Loads the plugins and themes installed from the admin (docs/architecture/plugins.md).
(static function (): void {
    $root = getenv('TEME_EXTENSIONS_PATH');
    $pins = getenv('TEME_CORE_PINS') ?: __DIR__.'/cache/teme-core-pins.php';

    if (! is_string($root) || $root === '' || getenv('TEME_EXTENSIONS_ROLE') === 'installer' || ! is_file($pins)) {
        return;
    }

    // readlink(), never realpath(): the realpath cache would keep the old release for minutes.
    $target = @readlink($root.'/current');

    if ($target === false) {
        return;
    }

    $release = $root.'/releases/'.basename($target);
    $hash = (require $pins)['hash'];

    if (! is_file("{$release}/compat/{$hash}") || ! is_file("{$release}/vendor/autoload.php")) {
        define('TEME_EXTENSIONS_HELD', $release);

        return;
    }

    define('TEME_EXTENSIONS_RELEASE', $release);
    define('TEME_EXTENSIONS_CORE', $hash);
    require "{$release}/vendor/autoload.php";
})();
```

Each release has its own paths, so with `validate_timestamps=0` OPcache compiles the new release's files fresh and never serves a stale copy under an old path; the old release's compiled files stay in OPcache memory until the next restart, which is bounded by how often extensions change. The installer itself never loads extension code (`TEME_EXTENSIONS_ROLE=installer`): it reads manifests and Composer's data only.

**What reads the release** (`App\Domain\Extensions\ActiveRelease`, scoped, reads the constants): `PluginRegistry` and `ThemeRegistry` use `{release}/cache/{core}/teme-plugins.php` / `teme-themes.php` when that file exists, else the configured image cache; `InstalledPackages` reads the release's `vendor/composer/installed.json` after the image's; the Plugins screen says why extensions are held when `TEME_EXTENSIONS_HELD` is defined. The config keys stay as they are (config is cached at container start; the release changes at runtime, so its paths are never in config).

Laravel's package discovery reads only the image's `installed.json`, so a library in the extensions folder that lists a Laravel service provider is not discovered. That is the intended behavior for plugins (`PackagesWithoutPlugins`); a plugin registers what its dependencies need itself. The developer guide says so (Task B9).

### D3. The installer service

`teme:installer` (`App\Console\Commands\RunInstaller`), modelled on `teme:updater`: a loop that beats `Heartbeats::INSTALLER`, takes `pg_try_advisory_lock(hashtext('teme.installer'))` per pass, binds `OperatorElevation`, and calls `InstallerRunner::tick()`; `--once` runs one pass (used by the fallback commands, D9); `--prepare-only` runs D4 and exits. Waits 30 seconds between passes, 3 while a request runs. It runs as `teme-installer` (uid/gid 9990, P2) with `TEME_EXTENSIONS_ROLE=installer`, from `TEME_IMAGE` (not the updater's image: its pins must be the running core's).

Compose (both `docker/compose.dokploy.yml` and `docker/compose.yml`):

```yaml
    installer:
        <<: *teme
        user: '9990:9990'
        # The image's start step writes caches into storage/, which this user can't write; the installer needs none.
        entrypoint: ['php', 'artisan', 'teme:installer']
        init: true
        stop_grace_period: 2m
        environment:
            <<: *teme-environment
            TEME_EXTENSIONS_ROLE: installer
        volumes:
            - extensions:/var/teme/extensions
        healthcheck:
            test: ['CMD', 'test', '-f', '/tmp/teme-installer-ready']
            interval: 5s
            retries: 3
            start_period: 15m
        depends_on:
            deploy:
                condition: service_completed_successfully
        deploy:
            resources:
                limits: { cpus: '0.5', memory: 1g }
```

`x-teme` gains `TEME_EXTENSIONS_PATH: /var/teme/extensions` and the read-only mount; `web`, `worker`, `worker-long` and `scheduler` gain `installer: { condition: service_healthy }` under `depends_on`, so they start on a release already checked against the new image. The ready file is written after D4 whatever its outcome, so a failed rebuild never keeps the site down. nginx (`docker/nginx/site-opts.d/http.conf.template`) gains:

```nginx
location ^~ /themes/ {
    location ~ \.php$ { return 404; }
    try_files $uri @teme_extension_themes;
}

location @teme_extension_themes {
    root /var/teme/extensions/current/public;
    try_files $uri =404;
}
```

The Dockerfile's runtime stage gains: `groupadd --system --gid 9990 teme-installer && useradd --system --uid 9990 --gid 9990 --no-create-home --shell /usr/sbin/nologin teme-installer`, `install -d -o 9990 -g 9990 -m 0755 /var/teme/extensions`, and `php artisan teme:extensions:pins` after `teme:plugins:cache`.

### D4. Every start: is the current release still right for this image?

`InstallerRunner::prepare()`, once when the service starts (so after every Teme update, which redeploys the stack):

1. Check the folder's owner and write access (D1); make `releases/`, `packages/`, `.composer/` if missing.
2. With no `current`, nothing to do. With `compat/<this core hash>` present, nothing to do.
3. Else **check compatibility** without Composer: for each package in the release's `composer.lock`, every requirement on a name in the new pins (`replace` or `provide`) must be satisfied by the pinned version (`Composer\Semver\Semver::satisfies`; a branch version satisfies only `*` or itself), `php` and `ext-*` must be satisfied by the running PHP, and **no package in the lock may now be one the image has** (core started shipping it, so two copies would load). All hold: write the stamp and the caches for the new core into the current release ("immutable once swapped" is about its code; `compat/` and `cache/` are the installer's notes beside the code, so writing them changes nothing that runs).
4. Otherwise **rebuild**: a request with action `rebuild`, requested by Teme (`requested_by` null), carried out at once: a new release from the current `composer.json` with the new pins and `composer update --minimal-changes` (nothing moves that need not move: no plugin updates itself), then verify, validate, swap as in D5. If it fails, the site runs with extensions held (D2's `TEME_EXTENSIONS_HELD`): enabled plugins from the folder don't load, and the Plugins and Themes screens say why and name the package that blocks. The site itself stays up.

Pages warmed by `deploy` before this step ran drew no held extensions; the rebuild's end purges the page cache (D5 step 9).

### D5. One request, step by step (`InstallerRunner`)

The row is the state; each pass reads it and moves one step at most, recording each through `RecordExtensionStep`.

| Step | What happens | Fails when |
|---|---|---|
| `checking` | `BeginExtensionRequest` claims the oldest `requested` row. Refuse while a Teme update is open (`teme_updates_one_open`). Free space on the volume ≥ max(512 MB, 3 × the current release's size) (Drupal's `DiskSpaceValidator` asks 1 GB). | No space; a Teme update running |
| `preparing` | `flock(stage.lock)`. New `releases/<ulid>/` with the current `composer.json`/`composer.lock` copied in (or a fresh one), the pins written in, the request's source's repository added (D7); the source's `prepare()` runs (part C: an upload is unpacked). Record which release `current` pointed at. | A source refuses |
| `fetching` | Composer, in that folder only (below). | Composer can't resolve or download; the reason is Composer's own last lines |
| `verifying` | `composer validate --no-check-all --no-check-publish --check-lock` (the lock matches `composer.json`); every locked package has a `dist.reference` or `source.reference`; `vendor/composer/installed.json` lists exactly the lock's names and references. Composer itself checked each `dist.shasum` that the source gives. | Any mismatch |
| `validating` | D6. | A refusal (the request fails) or something to agree to (the request waits) |
| `switching` | Write `compat/<core>`, the two caches and the theme links into the new release, then check `current` still points where step `preparing` saw it (Drupal's `LockFileValidator`: a change made meanwhile fails the request), then swap (below). | `current` moved |
| `finishing` | `php artisan queue:restart` (workers reload on the new release; the scheduler's `schedule:run` children are new processes each minute), prune to three releases, delete the request's upload, `EndExtensionRequest` with the report. | — |

A request that fails leaves `current` untouched and deletes its unswapped release. `EndExtensionRequest` purges the whole page cache after commit when the change touched an **enabled** plugin or the **active** theme (an update, removal, rollback or rebuild); an install of something not yet enabled purges nothing.

**Composer, exactly** (`App\Domain\Extensions\Installer\ComposerRunner`, Symfony Process, 15-minute timeout):

```php
$command = match ($request->action) {
    ExtensionAction::Install, ExtensionAction::Update => ['require', "{$request->package}:{$request->constraint}", '--update-with-dependencies', '--update-no-dev'],
    ExtensionAction::Remove => ['remove', $request->package, '--update-with-dependencies', '--update-no-dev'],
    ExtensionAction::Rebuild => ['update', '--minimal-changes', '--no-dev'],
};

$process = new Process(
    [config('teme.extensions.composer'), ...$command,
     '--no-interaction', '--no-scripts', '--no-plugins', '--no-progress',
     '--prefer-dist', '--optimize-autoloader', '--working-dir='.$release],
    $release,
    // Only what Composer needs: never APP_KEY, the database password or any other secret of the site.
    [
        'PATH' => getenv('PATH'),
        'HOME' => $root.'/.composer',
        'COMPOSER_HOME' => $root.'/.composer',
        'COMPOSER_AUTH' => json_encode($auth, JSON_THROW_ON_ERROR),   // part C; never written to a file
        'COMPOSER_NO_INTERACTION' => '1',
        'COMPOSER_MEMORY_LIMIT' => '768M',
    ] + array_fill_keys(array_keys(getenv()), false),   // Symfony Process: false removes an inherited variable
);
```

`--no-scripts --no-plugins` always: no package code runs while installing, agreed or not (Q4). A rollback runs no Composer: it swaps `current` back to the newest kept release that has a stamp for this core.

**The swap** (`ReleaseStore::swap()`), atomic because `rename(2)` replaces a link in one step on the same filesystem:

```php
$link = "{$this->root}/current";
$next = "{$link}.".bin2hex(random_bytes(4));

if (! symlink("releases/{$id}", $next) || ! rename($next, $link)) {
    @unlink($next);
    throw new InstallerFailed("Couldn't switch to the new release {$id}.");
}
```

The installer **runs no plugin migrations** (I-3442 decides when they run: at enable, and with "Run database changes" when an enabled plugin's update brings new ones). The existing waiting-migrations state shows after an update.

### D6. Validation (`StageValidator`), on the staged release only

Refused (the request fails with the reason):
- The requested package isn't in the lock, or its `type` isn't `teme-plugin` for a plugin request / `teme-theme` for a theme request.
- The image has the package (it is part of Teme's build): refused by the request Operation already, and again here.
- Its manifest is invalid (`Plugins\ManifestValidator`, `Themes\ManifestValidator`, read from the package folder, never its code), or its Teme range excludes this Teme.
- Its plugin `code` or theme `code` is already used by another installed plugin or theme (emdash's `PLUGIN_ID_CONFLICT`: a package must never shadow another).
- A plugin that declares `autoload.files` itself (`CodeScan` refuses it at enable anyway).
- Any autoload prefix in the release's `vendor/composer/autoload_psr4.php` or `autoload_namespaces.php`, or any classmap class, that equals or falls under a prefix of the image's own autoloader (`App\`, `Teme\Contracts\`, `Illuminate\`, `Database\Factories\` and every other): core's code always wins, so a clash could only mean a broken or hostile package.
- `composer check-platform-reqs --lock --no-dev` fails (PHP version or extension missing).
- `composer audit --locked --no-dev --format=json` lists an advisory for any locked package (P4). If the advisory database can't be reached, the report says so and the request goes on.

Waits for the administrator's agreement (status `waiting`, step `validating`, `needs` filled):
- A dependency that declares `autoload.files` (its files run on every request from the moment it is installed, enabled or not: `docs/guides/operators/configure.md`, *Plugins*). Listed by package and file.
- A locked package of type `composer-plugin` (it would never run here, but its presence says the package expects install-time code).
- The package was installed before from another source (`extra.teme.sources`), or an upload replaces an installed version (WordPress's "replace current with uploaded" comparison: shown as current version → new version).

`AgreeToExtensionRequest` records exactly the list shown (`agreed` JSONB); the next pass validates again and goes on only if the new `needs` is covered by `agreed`. A request waiting longer than 24 hours is cancelled and its release deleted (P3).

### D7. Sources: one list, each kind a class (the slot for Teme's directory)

`App\Domain\Extensions\Sources\SourceKind` (internal, in the seam):

```php
interface SourceKind
{
    /** The kind's key, stored in extension_sources.kind and extension_requests.source_kind. */
    public function key(): string;

    /** Composer repository entries this source adds to the release's composer.json. @return list<array<string, mixed>> */
    public function repositories(?ExtensionSource $source, string $extensionsPath): array;

    /** This source's part of COMPOSER_AUTH, decrypted only inside the installer. @return array<string, mixed> */
    public function auth(?ExtensionSource $source): array;

    /** Runs in the installer before Composer, in the new release's folder: an upload is unpacked here. */
    public function prepare(ExtensionRequest $request, string $releasePath, string $extensionsPath): void;
}
```

`SourceKinds` resolves kinds from the container tag `teme.extensions.sources`. Kinds: `packagist` (part A: no repository entry, no auth, nothing to prepare), `git`, `composer`, `upload` (part C). `ExtensionSource` and `ExtensionRequest` are readonly value classes the seam defines (not models), so the contract carries no Eloquent type. **Teme's signed directory (I-3461) is a fifth class, `directory`, later**: a `composer` repository entry plus a signature check in `prepare()`; no table, enum or screen change, because `kind` is free text checked against the registered keys (the database checks only its pattern).

### D8. The admin (part B)

`extensions.install` (new) guards every request, source and upload; Plugins needs `plugins.manage` as today, Themes `settings.design.manage`. Every Operation that changes code or stores a key **requires elevation** (`RequiresElevation`, as `RequestTemeUpdate`), and the operator at the shell may do the same (`Operator::isPresent()`).

**`RequestExtensionChange`** refuses (validate, then again under `pg_advisory_xact_lock(hashtext('teme.extensions.request'))`): another request open (the partial unique index enforces it); a Teme update open; an invalid package name (Composer's pattern, at most 214 characters); a package the image has; installing one already in the extensions folder (that is an update); updating or removing one not in the folder ("It is part of Teme's build; change it where the site is built"); removing an **enabled** plugin or the **active** theme (or one a design draft or preview uses) — disable or switch first; a rollback with no earlier release kept. The plugin's data is never touched: after removal the plugin's row shows "code gone" and the existing **Remove its data…** applies.

**`RequestTemeUpdate`** gains one refusal (shared file, one line in `UpdatePlan::refusal()`): an extension request open.

Fallback (D9) and the screens are in *Screens*.

### D9. Without an installer

The installer is "present" when `Heartbeats::INSTALLER` beat in the last two minutes. Without it the request is still recorded, and the screen shows, with copy buttons, the exact commands to carry it out on the server, and waits:

- With Docker Compose: `docker compose run --rm installer` (the recipe's service, one pass with `--once` set through `command`), or, on a stack without the service, the steps to add it (link to the guide).
- Without Docker: `sudo -u <the user that owns the extensions folder> TEME_EXTENSIONS_PATH=<path> php artisan teme:installer --once` in the site's folder.
- Or, as Statamic does, add it to the site's own build: `composer require acme/link-suggestions:^1.2` and deploy; then cancel the request.

On a host with no `TEME_EXTENSIONS_PATH` the Add sheet still opens, says that this server has no extensions folder, and shows only the last option.

---

## Schema (part A, Task A1, the seam)

`2027_07_20_100000_create_extension_tables.php`:

```php
Schema::create('extension_sources', function (Blueprint $table): void {
    $table->ulid('id')->primary();
    $table->text('kind');                              // a registered SourceKind key
    $table->text('label');
    $table->text('url')->nullable();                   // https only (part C checks)
    $table->text('username')->nullable();
    $table->text('credential')->nullable();            // encrypted (EncryptedValues); never sent to the browser
    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestampsTz();
    $table->unique(['kind', 'url']);
});
DB::statement("ALTER TABLE extension_sources ADD CONSTRAINT extension_sources_kind CHECK (kind ~ '^[a-z][a-z0-9_]{1,31}$')");

Schema::create('extension_uploads', function (Blueprint $table): void {
    $table->ulid('id')->primary();
    $table->text('file_name');
    $table->bigInteger('size');
    $table->char('sha256', 64);
    $table->text('package');                           // read from the archive's composer.json, checked again by the installer
    $table->text('version');
    $table->binary('bytes');                           // bytea: the archive as data; never unpacked by the web process
    $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestampTz('created_at');
});

Schema::create('extension_requests', function (Blueprint $table): void {
    $table->ulid('id')->primary();
    $table->text('action');                            // install | update | remove | rollback | rebuild
    $table->text('kind');                              // plugin | theme
    $table->text('package')->nullable();               // null for rollback and rebuild
    $table->text('constraint')->nullable();
    $table->text('source_kind')->nullable();
    $table->foreignUlid('source_id')->nullable()->constrained('extension_sources')->nullOnDelete();
    $table->foreignUlid('upload_id')->nullable()->constrained('extension_uploads')->nullOnDelete();
    $table->text('status');                            // requested | running | waiting | done | failed | cancelled
    $table->text('step')->nullable();
    $table->jsonb('steps')->default(DB::raw("'[]'::jsonb"));
    $table->jsonb('needs')->default(DB::raw("'{}'::jsonb"));   // what waits for agreement
    $table->jsonb('agreed')->default(DB::raw("'{}'::jsonb"));
    $table->jsonb('report')->default(DB::raw("'{}'::jsonb"));  // packages, versions, references, warnings
    $table->text('error')->nullable();
    $table->text('from_release')->nullable();
    $table->text('to_release')->nullable();
    $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();  // null: the operator or Teme
    $table->foreignId('agreed_by')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestampTz('requested_at');
    $table->timestampTz('started_at')->nullable();
    $table->timestampTz('finished_at')->nullable();
    $table->index('requested_at');
});
DB::statement("ALTER TABLE extension_requests ADD CONSTRAINT extension_requests_action CHECK (action IN ('install', 'update', 'remove', 'rollback', 'rebuild'))");
DB::statement("ALTER TABLE extension_requests ADD CONSTRAINT extension_requests_kind CHECK (kind IN ('plugin', 'theme'))");
DB::statement("ALTER TABLE extension_requests ADD CONSTRAINT extension_requests_status CHECK (status IN ('requested', 'running', 'waiting', 'done', 'failed', 'cancelled'))");
DB::statement("ALTER TABLE extension_requests ADD CONSTRAINT extension_requests_package CHECK ((action IN ('rollback', 'rebuild')) = (package IS NULL))");
DB::statement("ALTER TABLE extension_requests ADD CONSTRAINT extension_requests_objects CHECK (jsonb_typeof(steps) = 'array' AND jsonb_typeof(needs) = 'object' AND jsonb_typeof(agreed) = 'object' AND jsonb_typeof(report) = 'object')");
DB::statement("CREATE UNIQUE INDEX extension_requests_one_open ON extension_requests ((true)) WHERE status IN ('requested', 'running', 'waiting')");
```

`2027_07_20_100100_add_extensions_permission.php`: `extensions.install` → `administrator`, written exactly as `add_plugins_permission` (only adds; `RoleSeeder` gains the same line).

`EncryptedValues::all()` gains `new EncryptedColumn('extension_sources', 'credential', 'id', EncryptedForm::String, 'Package sources’ keys', 'package source')`.

## Names

- Domain: `App\Domain\Extensions` (`ActiveRelease`, `CorePins`, `ExtensionsAccess::INSTALL = 'extensions.install'`, enums `ExtensionAction`, `ExtensionKind`, `RequestStatus`, `RequestStep`), `App\Domain\Extensions\Installer` (`InstallerRunner`, `ReleaseStore`, `ComposerRunner`, `StageValidator`, `CompatibilityCheck`, `InstallerFailed`), `App\Domain\Extensions\Sources` (`SourceKind`, `SourceKinds`, `ExtensionSource`, `ExtensionRequest`, `PackagistSource`; part C: `GitSource`, `ComposerSource`, `UploadSource`, `ArchiveReader`, `SafeAddress`), `Models\ExtensionRequestRecord`, `ExtensionSourceRecord`, `ExtensionUploadRecord`, `Queries\ExtensionsScreen`, `Queries\PackagistSearch`, `Queries\InstallerRequests`.
- Operations (rule 1): installer side, as the operator: `BeginExtensionRequest`, `RecordExtensionStep`, `EndExtensionRequest`. Admin side, elevated: `RequestExtensionChange`, `CancelExtensionRequest`, `AgreeToExtensionRequest`, `AddExtensionSource`, `RemoveExtensionSource`, `UploadExtension`.
- Commands: `teme:installer` (`--once`, `--prepare-only`), `teme:extensions:pins` (`--no-dev`), `teme:extensions:request {action} {package?} {--constraint=} {--kind=plugin}` and `teme:extensions:cancel` (the operator's, as `teme:updates:request`).
- Config (`config/teme.php`, `extensions` key): `path` (`TEME_EXTENSIONS_PATH`), `composer` (`TEME_COMPOSER_BINARY`, default `composer`), `upload_max` (`TEME_EXTENSION_UPLOAD_MAX`, default 33554432), `packagist` (`TEME_EXTENSIONS_PACKAGIST`, default true), `private_hosts` (`TEME_EXTENSIONS_ALLOW_PRIVATE_HOSTS`, default false).
- Heartbeat: `Heartbeats::INSTALLER = 'installer'`. Lock names: `teme.installer` (the loop), `teme.extensions.request` (requests).
- Routes (part B), under `admin/extensions`, `can:extensions.install`: `POST requests` (`extensions.requests.store`), `POST requests/{id}/cancel`, `POST requests/{id}/agree`, `GET requests/{id}` (progress, polled), `GET search` (Packagist), `POST sources`, `DELETE sources/{id}`, `POST uploads`. `admin/extensions` joins `SafeMode::PATHS`; `teme:installer` and `teme:extensions:` join `SafeMode::COMMANDS`.

## Screens (part B; the drawing comes first)

Extends the existing screens, reusing `components/ui` unchanged and the v10 demo's **Install requests** tab.

- **Site › Plugins:** a header button **Add plugin** (only with `extensions.install`); the **Install requests** tab from v10 (requests for plugins and themes, newest first, with status and a link to each); in each row from the extensions folder, **Update…** and **Remove…** in the row menu, and *Installed from the admin, from {source}* in Details; a row from the image says *Part of the site's build*. A banner when extensions are held (D4) names the blocking package. **Roll back to the previous plugins and themes…** sits in the tab's header when an earlier release is kept.
- **Site › Themes:** **Add theme**, and **Update…** / **Remove…** on a theme from the folder; requests link to the Plugins tab.
- **The Add sheet** (new, **drawn for the maintainers before Task B6 is built**): title *Add plugin* or *Add theme*; tabs **Packagist** (search box; results with name, description, downloads, the newest version; a version picker; **Ask to install**), **Git address** (address, package name, version or branch, *This repository is private* revealing a token field), **Vendor source** (pick a saved source or add one: address, username if the vendor gives one, licence key; *Saved. Teme never shows a key again*), **Upload ZIP** (drop zone; after upload, the package name and version read from it, and the version installed now if any). Footer: *Teme asks for your password, records the request, and the installer carries it out. Nothing is enabled until you enable it.*
- **The request sheet** (opened from the tab or right after asking): the steps as Tools › Updates draws them; when waiting, the list to agree to (files that run on every request, Composer plugins that won't run, a different source, current → new version) with **Agree and continue** and **Cancel request**; when failed, the reason and Composer's last lines.
- **No installer:** inside the request sheet, the D9 commands with copy buttons.

---

## Part A: the installer service, the stack, the volume, autoloading — branch `installer/service`

### Owns

- New: `bootstrap/extensions.php`, `app/Domain/Extensions/**` except `Sources/{Git,Composer,Upload}Source.php`, `Sources/ArchiveReader.php`, `Sources/SafeAddress.php`, `Queries/ExtensionsScreen.php`, `Queries/PackagistSearch.php` and the six admin Operations; `app/Console/Commands/RunInstaller.php`, `WriteCorePins.php`, `RequestExtensionCommand.php`, `CancelExtensionCommand.php`; the two migrations; `tests/Feature/Extensions/Installer/**`, `tests/Fixtures/Extensions/**`; `docs/guides/operators/extensions.md`.
- Changed: `public/index.php`, `artisan` (one line each), `Dockerfile`, `docker/compose.yml`, `docker/compose.dokploy.yml`, `docker/nginx/site-opts.d/http.conf.template`, `docker/teme/build-checks.sh` (the user and folder exist), `config/teme.php` (the `extensions` key), `app/Domain/Plugins/PluginRegistry.php`, `InstalledPackages.php`, `app/Domain/Themes/ThemeRegistry.php` (the release's cache), `database/seeders/RoleSeeder.php` (one line), `docs/guides/operators/configure.md` (a section), `docs/guides/operators/README.md` (a link).
- **Shared, additions only:** `app/Domain/Deployment/Health/Heartbeats.php` (one constant), `app/Domain/Security/EncryptedValues.php` (one line, in Task A1), `app/Domain/Plugins/SafeMode.php` (B adds the path; A adds the commands).

### Tasks (tests first; each seen red for the reason given)

- [ ] **A0. Confirm the start.** Each line of *What main does today*, with file and line, into the records. Build the image locally (`docker build`), run it as `--user 9990:9990` with `--entrypoint php ... artisan teme:installer --prepare-only` against the development database, and record whether Laravel boots with nothing to write, and whether a new named volume mounted at `/var/teme/extensions` starts owned by 9990. Record `composer --version` in the image (it must be 2.7 or later for `--minimal-changes`; **unverified**). STOP if it isn't.
- [x] **A1. The seam** (pushed first; B and C start here). The migrations, the three models, the enums, `ExtensionsAccess`, `SourceKind`/`SourceKinds`/`ExtensionSource`/`ExtensionRequest`/`PackagistSource`, the `EncryptedValues` line, the `config/teme.php` key, `Heartbeats::INSTALLER`, the fixture packages (below). Tests: the checks refuse a second open request, a rollback with a package, a bad kind; a new encrypted column is listed (`EncryptedValuesTest` passes); `extensions.install` reaches administrators and no one else; `SourceKinds` resolves a tagged kind and refuses an unknown key. Red: tables and classes missing.
- [x] **A2. Core pins.** `CorePins::build()` (as written) and `teme:extensions:pins`. Tests: every package and every `replace`/`provide` of the fixture `installed.json` is pinned, `self.version` is resolved, dev packages are left out with `--no-dev`, the hash changes when one version changes and not when the order does. Red.
- [x] **A3. Loading.** `bootstrap/extensions.php`, `ActiveRelease`, the registries' release cache, `InstalledPackages` reading both files. Tests run PHP in a child process (`Process`, `php -r` requiring `vendor/autoload.php` then `bootstrap/extensions.php`) against a fixture extensions folder built by the test: a fixture plugin there is listed by `InstalledVersions::getInstalledPackagesByType('teme-plugin')` and by `PluginRegistry::all()`; a fixture theme by `ThemeRegistry::all()`; with no stamp for this core it is **held** (not listed, `TEME_EXTENSIONS_HELD` defined); with `TEME_EXTENSIONS_ROLE=installer` nothing is loaded; after the link is switched, the next child process loads the new release (readlink, not the realpath cache); a class under `App\` in the folder never replaces core's class (prepend off). Red.
- [x] **A4. Releases and the swap.** `ReleaseStore`: create, swap (as written), previous, prune to three, delete an unswapped one; refuses an id that isn't a lowercase ULID. Tests: the link is relative and replaced in one rename; a failed swap leaves the old link; pruning never deletes `current` or the one before. Red.
- [x] **A5. Composer in the stage.** `ComposerRunner` with the exact flags. Tests use only local fixtures: a release whose `composer.json` disables Packagist (`{"packagist.org": false}`) and adds a `path` repository to `tests/Fixtures/Extensions/packages/*` with `"options": {"symlink": false}`. Install `fixtures/hello-extension` (requires `teme/contracts: *`): resolved against the pins, nothing from the image copied into the release's `vendor/`; a fixture requiring `laravel/framework: ^99` fails with Composer's reason; the child environment holds no `APP_KEY` and no `DB_PASSWORD` (a fixture `path` package can't see them: assert on the `Process` env); a fixture with a `composer-plugin` dependency installs without running it (its plugin writes a marker file if run; assert absent). Mark these tests `->group('composer')`; they take seconds each. Red.
- [x] **A6. Verification and validation.** `StageValidator` (D6) and the verifying step. One test per refusal and per wait in D6, each on a fixture (`autoloads-app` declares `App\\Extra\\`, `files-dependency`, `duplicate-code`, `wrong-type`, `needs-ext-nonexistent`); the lock-mismatch case edits `composer.json` after the lock. `composer audit` is replaced by a fake in these tests (`ComposerRunner` is an injected class). Red.
- [x] **A7. The runner and its Operations.** `BeginExtensionRequest`, `RecordExtensionStep`, `EndExtensionRequest` (operator only; each test proves a person, even an administrator, is refused and nothing is written), `InstallerRunner::tick()`: one fixture request from `requested` to `done`, the steps recorded in order, `current` switched, `queue:restart` called, the upload row deleted; a failing request leaves `current` and deletes its release; a request needing agreement stops at `waiting` with `needs`, and continues after `agreed` covers it; a request waiting over 24 hours is cancelled; a request while a Teme update is open waits; `current` moved meanwhile fails the request; `EndExtensionRequest` purges the page cache after commit for an enabled plugin's update and not for a new install, and not on rollback of the transaction. Red.
- [x] **A8. Rollback and the start check.** Rollback swaps to the kept release with a stamp; refuses with none. `prepare()`: a release compatible with new pins gets its stamp and caches without Composer; one whose lock needs a version the pins no longer satisfy is rebuilt with `--minimal-changes` (a sibling fixture plugin's locked version doesn't move); one whose lock holds a package the image now has is rebuilt; a failed rebuild leaves the release held and the ready file written. Red.
- [x] **A9. The command and the stack.** `teme:installer` loop (heartbeat, lock, `--once`, `--prepare-only`, SIGTERM ends the pass in hand), `teme:extensions:request` / `:cancel` (operator), `SafeMode::COMMANDS` lines. The compose files, the Dockerfile lines, nginx, `build-checks.sh`. Tests: `LayeringTest` passes (commands call Operations only); `docker compose -f docker/compose.yml config` and the Dokploy file parse (CI already runs compose checks: add the service to what they assert). Red: command missing.
- [x] **A10. Documents and records.** `docs/guides/operators/extensions.md` (the volume, the service, the fallback commands, what a Teme update does to installed extensions, how to back up the folder by hand until the proposal's Q3 is decided), `configure.md`'s variables, the operator README link. Records.

### Fixtures (`tests/Fixtures/Extensions/packages/`, Task A1)

`hello-extension` (a valid plugin, `teme/contracts: *`), `hello-theme` (a valid theme with `public/build`), `autoloads-app`, `files-dependency` (a plugin requiring `fixtures/with-files`, which declares `autoload.files`), `with-files`, `composer-plugin-dependency` + `marker-plugin` (type `composer-plugin`, writes a marker file if run), `duplicate-code` (same code as `hello-extension`), `wrong-type` (type `library`), `needs-ext-nonexistent`, `wants-new-laravel` (`laravel/framework: ^99`), `hello-extension` at `1.0.0` and `1.1.0` (two folders, each with `version` in `composer.json`), and `zips/` for part C.

### Quick check (part A)

`php artisan test --compact tests/Feature/Extensions/Installer` (it includes the `composer` group), `php artisan test --testsuite=Architecture`, `vendor/bin/pint --dirty`; `composer check` once at the end.

---

## Part B: the admin — branch `installer/admin`

### Owns

- New: `app/Domain/Extensions/Operations/{RequestExtensionChange,CancelExtensionRequest,AgreeToExtensionRequest,AddExtensionSource,RemoveExtensionSource,UploadExtension}.php` and their `Input/`, `app/Domain/Extensions/Queries/{ExtensionsScreen,PackagistSearch}.php`, `app/Http/Admin/ExtensionsController.php`, `resources/js/components/extensions/**` (the Add sheet, the request sheet, the requests list, the commands box), `tests/Feature/Extensions/Admin/**`, `tests/Browser/ExtensionsTest.php`.
- Changed: `routes/admin.php` (one group), `resources/js/pages/plugins/index.tsx`, `resources/js/pages/themes/index.tsx`, `resources/js/components/plugins/plugin-row.tsx` and `details-dialog.tsx`, the theme list's row, `app/Domain/Plugins/Queries/PluginsScreen.php` and the themes' screen query (each row's origin: `image` or `extensions`, its source), `app/Domain/Updates/UpdatePlan.php` (one refusal), `resources/js/types` (the new page types), `docs/architecture/plugins.md` (*Installing*, rewritten to what is built; in Task B9 only).
- **Shared, additions only:** `app/Domain/Plugins/SafeMode.php` (`admin/extensions` in `PATHS`).

### Tasks

- [ ] **B0. Confirm the start** (part B's lines of *What main does today*) and that the seam's commit is the branch's base.
- [ ] **B1. `RequestExtensionChange`.** Every refusal in D8, one test each; the advisory lock; the row as written (`requested_by`, `requested_at`, `source_kind`); elevation required; the operator allowed with no person; a person without `extensions.install` is refused and **nothing is written**; one open at a time under two concurrent calls. Red.
- [ ] **B2. `CancelExtensionRequest`, `AgreeToExtensionRequest`.** Cancel only `requested` or `waiting` (a running one can't be cancelled; it ends on its own); agree only `waiting`, records exactly the `needs` shown (the input carries the `needs` it agreed to; a changed `needs` refuses: "What the installer found has changed. Look again."). Refusal tests write nothing. Red.
- [ ] **B3. Sources and uploads, the web side.** `AddExtensionSource` (kind from `SourceKinds`, URL https, label, username, credential encrypted, elevation), `RemoveExtensionSource` (refused while a package in the current release came from it), `UploadExtension`: checks the size against `upload_max`, the ZIP signature, reads `composer.json` from the archive **as data** (`ZipArchive::getFromName` on the one top-level folder or the root; never extracts), stores name, version, sha256, bytes. Tests: a credential is never in any response or Inertia prop (assert on the JSON); an upload over the limit or not a ZIP is refused and nothing is written; a person without the permission writes nothing. Red.
- [ ] **B4. `PackagistSearch`.** `https://packagist.org/search.json?q=…&type=teme-plugin|teme-theme&per_page=15` and versions from `https://repo.packagist.org/p2/{name}.json`, through Laravel's HTTP client with a 5-second timeout, cached 10 minutes; off when `extensions.packagist` is false. Tests with `Http::fake`: results mapped, failure says *Packagist didn't answer. Try again, or use another tab.* Red.
- [ ] **B5. `ExtensionsScreen` and the controller.** The props: requests (newest 20), the open one with its steps, `installerSeenAt`, whether a path is configured, the held banner, the commands for D9 filled with the request's real values, sources (never the credential), each row's origin. Routes as named. Tests: each route's permission; the controller calls Operations only (`LayeringTest`). Red.
- [ ] **B6. The screens** (after the drawing is approved): the Plugins tab and Add button, the Add sheet, the request sheet (polls `GET requests/{id}` every 3 seconds while open, as Tools › Updates polls), row actions, Themes. The password-gated "pending" marker pattern of `plugins/index.tsx` is reused for a request asked before the password. Words through `__()`.
- [ ] **B7. One refusal in the updater.** `UpdatePlan::refusal()` refuses while an extension request is open. Test. Red.
- [ ] **B8. Browser test** (`ExtensionsTest`, 1280 and 375): the Add sheet's four tabs pass axe; asking on the Packagist tab (HTTP faked) asks for the password, then shows the request sheet at *Waiting for the installer* with the commands box.
- [ ] **B9. Documents and records.** `docs/architecture/plugins.md`'s *Installing* section rewritten to what is built (the volume, the second Composer project, the steps, consent at enable, no migrations by the installer); a paragraph in the developer guide that a library's Laravel provider isn't discovered from the extensions folder. Records.

### Quick check (part B)

`php artisan test --compact tests/Feature/Extensions/Admin`, the Architecture suite, `npm run types:check`, `npm run check`, `vendor/bin/pint --dirty`; at the end `composer check` and `composer check:browser` for `tests/Browser/ExtensionsTest.php` only (GitHub runs the rest).

---

## Part C: private sources and ZIP uploads, the installer side — branch `installer/sources`

### Owns

- New: `app/Domain/Extensions/Sources/{GitSource,ComposerSource,UploadSource,ArchiveReader,SafeAddress}.php`, their registration (one service provider line under the `teme.extensions.sources` tag, in a new `ExtensionSourcesServiceProvider`), `tests/Feature/Extensions/Sources/**`, the ZIP fixtures in `tests/Fixtures/Extensions/zips/` (built by the tests from folders, never committed as binaries).
- Changed: nothing of A's or B's. The end-to-end check runs at the join.

### The delicate code

**Auth, per source, only inside the installer:**

```php
// GitSource::auth()
$host = parse_url($source->url, PHP_URL_HOST);

return match (true) {
    $source->credential === null => [],
    $host === 'github.com' => ['github-oauth' => ['github.com' => $source->credential]],
    $host === 'gitlab.com' => ['gitlab-token' => ['gitlab.com' => $source->credential]],
    default => ['http-basic' => [$host => ['username' => $source->username ?? 'token', 'password' => $source->credential]]],
};

// ComposerSource::auth(): a vendor's key, as the vendor documents it
$host = parse_url($source->url, PHP_URL_HOST);

return $source->username === null
    ? ['bearer' => [$host => $source->credential]]
    : ['http-basic' => [$host => ['username' => $source->username, 'password' => $source->credential]]];
```

The runner merges every source in use into one `COMPOSER_AUTH` value for the child process. No key is written to a file, a log, the request's report or an exception message (a test greps all of them).

**Repositories:** `GitSource` → `{"type": "vcs", "url": …}`, `ComposerSource` → `{"type": "composer", "url": …}` (a vendor's Composer source; Teme's directory later uses the same type with its own `prepare()`), `UploadSource` → `{"type": "path", "url": "/var/teme/extensions/packages/<vendor>/<name>/<version>", "options": {"symlink": false, "versions": {"<vendor>/<name>": "<version>"}}}`. Uploaded packages stay in `packages/` (never changed once written), so later Composer runs for other packages still find them; a version no release uses is deleted when its last release is pruned.

**`SafeAddress`:** https only; the host must resolve, and to no private, loopback, link-local or reserved address (the same ranges as the plugin HTTP guard), unless `extensions.private_hosts` is true (a self-hosted Git server on the operator's own network). Checked in `prepare()` before Composer runs. Composer's own `secure-http: true` stays on.

**`ArchiveReader`, unpacking an upload into `packages/<vendor>/<name>/<version>/`** (WordPress checks an upload holds a valid plugin before install; webblocks' `PluginZipInstaller` and artisanpack's `ExtensionArchive` refuse unsafe entries):
- the archive's sha256 matches the upload row; at most 20,000 entries; at most 256 MB unpacked (summed from the central directory before extracting, and counted again while writing);
- every entry name is relative, has no `..` segment, no backslash, no NUL, no drive letter; no entry is a symlink (`ZipArchive::getExternalAttributesIndex`, the Unix mode's `S_IFLNK`); no entry outside the one top-level folder when there is one (that folder is stripped);
- `composer.json` exists at the root, is valid JSON, its `name` and `version` match the upload row, its `type` is `teme-plugin` or `teme-theme` matching the request, and the manifest (`teme-plugin.json`, or the theme's) exists; otherwise refused with the reason;
- the target folder doesn't exist yet, or holds byte-identical content (the same upload again); a different archive claiming the same name and version is refused: *A different {name} {version} was uploaded before. Raise the version.*;
- files are written 0644, folders 0755, by the installer user; nothing in the archive is executed or included.

### Tasks

- [ ] **C0. Confirm the start**, the seam's interface, and the plugin HTTP guard's ranges (`HostPattern` / the guarded client) to reuse.
- [ ] **C1. `SafeAddress`.** Dataset: `http://` refused, `https://localhost`, `127.0.0.1`, `10.x`, `169.254.169.254`, `[::1]` refused, a public host allowed (DNS faked), private allowed with the setting. Red.
- [ ] **C2. `GitSource` and `ComposerSource`.** Repositories and auth as written; a test that `COMPOSER_AUTH` built for two sources holds both; the key appears in no log line, report or exception (plant a key, run a failing `prepare()`, grep). Red.
- [ ] **C3. `ArchiveReader` and `UploadSource`.** One test per rule above, each on a ZIP the test builds (`../evil.php`, an absolute path, a symlink entry, 20,001 entries, a 300 MB zero-filled entry that compresses small, no `composer.json`, a wrong type, no manifest, a name differing from the row, the same name and version with different bytes); a good archive lands at the right folder, stripped of its top folder, with the right modes; the path repository entry is as written. Red.
- [ ] **C4. Records.**

### Quick check (part C)

`php artisan test --compact tests/Feature/Extensions/Sources`, the Architecture suite, `vendor/bin/pint --dirty`; `composer check` once at the end.

---

## The join (after A, then B, then C merge onto the integration branch)

One feature test, `tests/Feature/Extensions/EndToEndTest.php`, written by whoever joins: an administrator uploads the `hello-extension` ZIP through `UploadExtension`, asks through `RequestExtensionChange`, `teme:installer --once` (Packagist off, the fixture path) carries it out, and the plugin is listed on the Plugins screen as not enabled; enabling it through the existing consent path works; an update to 1.1.0 by a second upload waits for agreement (replacing an installed version) and goes on after `AgreeToExtensionRequest`; rollback returns 1.0.0; removal is refused while enabled and goes through once disabled, after which **Remove its data…** is offered. Then the full `composer check`, and the Browser file.

## Records

<!-- Filled in by each part's builder under its own heading: setup; commits; each test seen red (first line of the failure); B-?? decisions with the options weighed; architecture baselines before and after; the full gate's totals; STOP; changelog lines for the maintainer; try it. Part A uses B-01 to B-08, part B B-09 to B-14, part C B-15 to B-20. -->

### Part A — `installer/service`

**Setup:** `cat /tmp/teme-cloud-setup.log` gave nothing (no such file); `pwd` gave `/home/user`, then `/home/user/teme`. `bash scripts/cloud-setup.sh` and `npm ci` ran; Composer 2.8.12 in the cloud; no Docker daemon. Built on `origin/main` at `6be0340`.

**A0, what main does today (at `6be0340`):** every line holds. `PluginRegistry.php:416` and `ThemeRegistry.php:136` (in `discoverComposer()`, line 130) call `InstalledVersions::getInstalledPackagesByType()`, which walks `ClassLoader::getRegisteredLoaders()` (`vendor/composer/InstalledVersions.php:358`). `InstalledPackages.php:49` reads one `installed.json`. `config/teme.php:41` and `:51` name the theme and plugin caches. `PackagesWithoutPlugins.php:33` keeps plugins out of package discovery. `CodeScan.php:21` refuses `autoload.files`. `Dockerfile:99` links themes as root, `:76` turns timestamp checks off, `:110` ends as `www-data`. `RunUpdater.php:66` takes the lock per pass. `SafeMode.php:39` held `teme:updater`, `teme:updates:`. `compose.dokploy.yml:96` and `:180` run deploy and the updater. **Not checked here:** the image build, whether Laravel boots as uid 9990 with the start step skipped, whether a new named volume copies the folder's owner on Dokploy, and the image's own Composer version: the cloud has the Docker CLI but no daemon. The image's `code` stage runs `composer install` from the serversideup base (`Dockerfile:43`), so the runtime has Composer, at a version not seen. These stay open for the first image build: `docker/teme/build-checks.sh` now stops the build when the user, the folder's owner or the pins are missing.

#### Commits

- `b897bbd` feat(extensions): the seam for the plugin and theme installer (A1, pushed first)
- `43a967a` feat(extensions): pin the image's packages for the extensions folder (A2)
- `0853289` feat(extensions): keep releases and swap the current one atomically (A4)
- `5c8d172` feat(extensions): run Composer in a release with no package code and no secrets (A5)
- `2a44b63` test(extensions): let the seam's test hold beside part C's source kinds
- `c4b578f` feat(extensions): load the current release after core's own packages (A3)
- `21eef64` feat(extensions): verify and validate a staged release before it runs (A6)
- `9dcd5c9` feat(extensions): carry out a request step by step as the operator (A7)
- `bd4833b` feat(extensions): rebuild only what no longer fits when the image changes (A8)
- `f36f0bf` feat(extensions): run the installer as its own service in both stacks (A9)
- `ae7a8a3` docs(guides): the installer service for operators (A10)

#### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|
| A1 | `SeamTest.php` | `SQLSTATE[42P01]: Undefined table: relation "extension_requests" does not exist` |
| A2 | `CorePinsTest.php` | `ErrorException` before `CorePins` existed; once built, `Failed asserting that two strings are identical` on the hash of reordered packages (B-01) |
| A3 | `LoadingTest.php` | the child process found no release class: the registries read only the image's cache (exact line not kept) |
| A4 | `ReleaseStoreTest.php` | `Failed asserting that two arrays are identical` (prune) |
| A5 | `ComposerRunnerTest.php` | the runner class was missing (exact line not kept) |
| A6 | `StageValidatorTest.php` | `Tests: 8 failed, 8 passed`: `Failed asserting that two arrays are identical` |
| A7 | `InstallerRunnerTest.php` | the runner and its operations were missing (exact line not kept) |
| A8 | `PrepareTest.php` | `Failed asserting that two arrays are identical`: `fixtures/hello-extension` moved `1.0.0` → `1.1.0` in a rebuild (B-07) |
| A9 | `RunInstallerTest.php` | `Class "App\Console\Commands\RunInstaller" not found` |

#### B-?? (draft)

- **B-01 (draft):** the plan's `CorePins` built the hash in `installed.json`'s order, so the same packages in another order gave another hash. Options: keep the order; sort by name. Chose sorting, so a stamp means the same versions whatever the order. `43a967a`.
- **B-02 (draft):** Packagist is a source kind, but part A owns no service provider to tag it. Options: tag it in a provider (not in Owns); build it into `SourceKinds` first, before tagged kinds. Chose built in. `b897bbd`.
- **B-03 (draft):** writing a release's caches reads its plugins' manifests through the registries, which would load extension code into the installer. Options: read in the installer; a hidden command, `teme:extensions:caches`, in a child process that registers an empty `ClassLoader` for the release's vendor, so `InstalledVersions` sees the packages and none of their code runs. Chose the child. `9dcd5c9`.
- **B-04 (draft):** `teme:extensions:request` and `teme:extensions:cancel` (part A's Owns) call `RequestExtensionChange` and `CancelExtensionRequest`, which are part B's files and not on this branch. Options: write B's operations (another part's files: STOP); write the commands against classes this branch lacks (PHPStan and the suite fail); leave both commands to the join. Chose leaving them, so the fallback guide names only `teme:installer --once`. Nothing built.
- **B-05 (draft):** `ComposeRecipeTest` and `DokployComposeTest` assert `depends_on` and `volumes` exactly. The plan (A9) names adding the service to what they assert: the roles now also wait for `installer` and mount `extensions` read-only, and one new test in each covers the installer. No assertion was dropped. `f36f0bf`.
- **B-06 (draft):** the new page needs one line in `tests/Fixtures/Guides/OperatorGuide.php` PAGES and the README's row. The guide's Compose parser doesn't know `--entrypoint` takes a value, so the guide uses `docker compose exec` for `chown` and `tar`. `ae7a8a3`.
- **B-07 (draft):** the plan's rebuild, `update --minimal-changes --no-dev`, allow-lists every root requirement, and in the test it moved the sibling plugin too (seen red). Options: as written; name the packages that no longer fit, `update <those> --with-all-dependencies --minimal-changes --no-dev`. Chose naming them (`CompatibilityCheck::blocking()`), which keeps the Settled "nothing updates that need not". `bd4833b`.
- **B-08 (draft):** small choices. A rollback skips fetching, verifying and validating, and refuses with no kept release that has this core's stamp. `from_release` is `''` when there was no release. `RunInstaller` uses a bound `InstallerRunner` when a test binds one. The fallback without Docker also sets `TEME_EXTENSIONS_ROLE=installer`. The failed-swap test is skipped when the suite runs as root (the cloud does). `9dcd5c9`, `f36f0bf`, `ae7a8a3`.

#### Architecture baselines

| Baseline | Before | After |
|---|---|---|
| `admin-words-baseline.txt` | 876 | 876 |
| `design-system-baseline.txt` | 222 | 222 |

#### Gate

Base `6be0340`: Architecture 139 passed; Unit and Feature 11,578 passed, 2 skipped. This branch, `composer check` at `ae7a8a3`: Pint and PHPStan clean; Architecture 139 passed; Unit and Feature 11,676 passed, 3 skipped, 1 failed. The skip added is the failed-swap test as root.

The one failure, `tests/Feature/Plugins/DiscoveryTest` "finds the configured folders", is main's, not this part's: `tests/Feature/Jobs/JobsScreenTest.php` calls `PluginFixtures::installed()` without `PluginFixtures::forget()`, so `tests/jobs` stays in Composer's runtime data for whatever test the same worker runs next. `pest tests/Feature/Jobs/JobsScreenTest.php tests/Feature/Plugins/DiscoveryTest.php` fails the same way with `6be0340`'s registries put back; `DiscoveryTest` alone passes. Neither file is in part A's Owns, so it is left open: one `afterEach(fn () => PluginFixtures::forget())` in `JobsScreenTest` fixes it.

#### STOP

None.

#### Changelog lines for the maintainer

- Added: an `installer` service in both Compose files that installs, updates, removes and rolls back plugins and themes asked for from the admin, as its own user (uid 9990), in an `extensions` volume the other roles mount read-only.
- Added: after every Teme update, the installer checks the installed plugins and themes against the new image and rebuilds only what no longer fits; the site starts with them held if that fails.
- Added: `teme:installer` (`--once`, `--prepare-only`) and `teme:extensions:pins`; the operators' guide page "Plugins and themes installed from the admin".
- Changed: the image adds the `teme-installer` user, `/var/teme/extensions` and the core pins; nginx serves `/themes/` from the current release after the image's own.

#### Try it

`php artisan test --compact tests/Feature/Extensions/Installer` (the `composer` group runs Composer against the fixture packages, with Packagist off). By hand: set `TEME_EXTENSIONS_PATH` to an empty folder, insert an `install` row in `extension_requests` for a package Composer can reach, and run `php artisan teme:installer --once`; then `ls -l "$TEME_EXTENSIONS_PATH"/current`.

#### Follow-up R1

The review's BLOCKING 1 and 2 and IMPORTANT 3, 4 and 5 are fixed. The LATER items are left for the maintainer.

| Finding | Commit | Test seen red (first line of the failure) |
|---|---|---|
| 1. The rebuild after a Teme update never started | `d671122` | `PrepareTest` "rebuilds while the Teme update that started it is still running": `Failed asserting that 'Another request is open, so the installed plugins and themes wait to be rebuilt for this version of Teme.' is null.` |
| 2. A theme's links were served by nginx | `a1f12be` | `StageValidatorTest` "refuses a package holding a link that points outside it": `Failed asserting that actual size 0 matches expected size 2.` `ThemeAssetsTest` "follows no link from a theme's files to a file the installer doesn't own": the `disable_symlinks` line was missing. |
| 3. `/themes/` addresses that are no theme file gave a 404 | `d99f0f4` | `ThemeAssetsTest` "hands an address under /themes/ that is no theme's file to the site": no `try_files $uri @teme_app;`. |
| 4. The start check ran outside the installer lock | `1089e25` | `RunInstallerTest` "waits for the installer lock before the start check": `Failed asserting that null is false.` (the check never waited) |
| 5. Agreements were asked for again; the start loop repeated the check | `28e3efd` | `StageValidatorTest` "asks again only about packages the release that runs doesn't already hold": `Failed asserting that two arrays are identical.` `PrepareTest` "stops the start check … once the rebuild waits for an administrator": `Expected: fixtures/grows needs fixtures/core-lib ^1.0; this Teme has 2.0.0.` |

- **Finding 1:** a rebuild skips the open-update check, both in `BeginExtensionRequest` and in the `checking` step. With no update check left on that path, `prepare()`'s "Another request is open" now appears only when another request is open; a second new test proves it.
- **Finding 2:** `StageValidator::validate()` refuses any link in `vendor/<package>/` whose target, read as written or resolved on disk, lies outside that package. A link that stays inside is taken. `vendor/bin` belongs to no package, so it isn't checked. nginx's `@teme_extension_themes` gains `disable_symlinks if_not_owner from=/var/teme/extensions/current/public;`.
- **Finding 3:** `@teme_extension_themes` ends with `try_files $uri @teme_app;`, and PHP under `/themes/` still answers 404.
- **Finding 4, B-08 addendum (draft):** `prepare()` now runs while holding `teme.installer`, the same advisory lock every pass takes. It waits with `pg_try_advisory_lock` and a one-second sleep, and gives up only on SIGTERM. Options: a blocking `pg_advisory_lock`, which can't be stopped and hung the suite's lock test; or a waiting loop that can be stopped. Chose the loop. The plan's `flock(stage.lock)` from preparing through switching is **not** added. Every step, and the whole start-up rebuild, already runs under that one lock, so no two processes can work in a release at once. A second lock would guard nothing more. `RunInstallerTest`'s existing "takes no step while another installer holds the lock" now calls one `pass()` directly instead of `--once`, because `--once` starts with the start check, which now waits for that lock. What it proves is unchanged.
- **Finding 5:** autoload-files dependencies and Composer plugins count as needs only when the running release's lock doesn't hold that name at that reference. The runner already passes the running release for every action. `prepare()` stops its loop when the rebuild waits, and says it waits for an administrator. The installer's loop takes up the waiting request as before. New fixture: `fixtures/grows` (1.0.0, and 1.1.0, which needs `fixtures/with-files`). `ExtensionsFolder::runner()` can take a Composer runner, so a test can count audits.
- **Gate (R1):** `composer check` at `28e3efd`: Pint and PHPStan clean; Architecture 139 passed; Unit and Feature 11,685 passed, 3 skipped, 1 failed. The failure this time is `tests/Feature/Cache/TermAndPeoplePurgesTest` "purges the pages…" (line 138: `/lists-someone-else/` purged too). That file and `app/Domain/Cache` are unchanged from `6be0340`, no code of this part runs in it, and it passes alone three times in a row, so it is a flaky parallel run, left open. `DiscoveryTest`'s order-dependent failure from the first gate didn't happen this run, because the workers ran the tests in a different order.

### Part B — `installer/admin`

Setup: `2026-11-16T06:20:01Z ran in /home/user/teme as root`, finished 06:32:34Z; PHP 8.4.27, PostgreSQL and Valkey answering. Base: the seam's commit `b897bbd` of `installer/service`.

#### Commits

- `cfaf981` feat(extensions): ask the installer for a plugin or theme change (B1)
- `8c61232` feat(extensions): cancel a request, or agree to what it found (B2)
- `0c846b8` feat(extensions): keep package sources and uploaded archives (B3)
- `d2a2b0e` feat(extensions): search Packagist for plugins and themes (B4)
- `f1daba0` feat(extensions): the admin's routes and what the screens read (B5)
- `a452dfd` feat(updates): wait for a plugin or theme request before updating Teme (B7)
- `339aa45` feat(extensions): add, update and remove plugins and themes from the admin (B6)
- `6405bf4` test(extensions): ask to install from Packagist in a real browser (B8)
- `934b110` docs(extensions): installing from the admin, as built (B9)
- `6e5dfd0` fix(types): reserve the admin's extensions segment for its routes (found by the full gate)

#### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|
| B1 | `tests/Feature/Extensions/Admin/RequestExtensionChangeTest.php` | Target class [App\Domain\Extensions\Operations\RequestExtensionChange] does not exist. |
| B2 | `tests/Feature/Extensions/Admin/CancelAndAgreeTest.php` | Target class [App\Domain\Extensions\Operations\CancelExtensionRequest] does not exist. |
| B3 | `tests/Feature/Extensions/Admin/SourcesAndUploadsTest.php` | Target class [App\Domain\Extensions\Operations\AddExtensionSource] does not exist. |
| B4 | `tests/Feature/Extensions/Admin/PackagistSearchTest.php` | Target class [App\Domain\Extensions\Queries\PackagistSearch] does not exist. |
| B5 | `tests/Feature/Extensions/Admin/ExtensionsRoutesTest.php` | Expected response status code [302] but received 405. |
| B5 | `tests/Feature/Extensions/Admin/ExtensionsScreenTest.php` | Failed asserting that an array has the key 'origin'. |
| B7 | `tests/Feature/Extensions/Admin/UpdaterRefusalTest.php` | Failed asserting that null is identical to 'A plugin or theme request is under way. Wait for it to end, or cancel it.'. |
| B6 | screens | No PHP test: checked by `npm run types:check`, `npm run check`, the Architecture suite (AdminWords, DesignSystem, NoTypeLiterals) and B8. |
| Gate | `tests/Feature/Types/TypeRoutingTest.php` | it reserves every first segment: `+    0 => 'extensions'` (B5's `admin/extensions` routes; fixed in `6e5dfd0`). |
| B8 | `tests/Browser/ExtensionsTest.php` | Written after the screens it drives; its first failure was its own measure (the sheet 365 wide at 375, the scroll bar's room), fixed by comparing with a fixed layer as `PhoneOverlaysTest` does. |

#### B-?? (draft)

- **B-09 (draft): shared code stays inside Owns.** The folder reading every Operation and the screen need (current and earlier releases, what the folder and the image hold, which source a release uses) lives in `ExtensionsScreen`; the permission check shared by the six Operations is a trait in `Operations/Concerns`. Weighed: a new `Extensions/Folder` class (outside Owns). `ExtensionsScreen` with its `data()` and `origins()` landed in B1, so B5's red came from the missing routes and the rows' missing `origin`.
- **B-10 (draft): Agree records, the installer goes on.** `AgreeToExtensionRequest` keeps the request `waiting` and writes `agreed` and `agreed_by`; part A's installer continues it. The keys of `needs` (`files`, `composerPlugins`, `source`, `replaces`) are this part's reading of D6 until the join; the sheet draws any key as a heading and its value as lines, so another key still reads.
- **B-11 (draft): password window per the maintainers, not per request.** Every Operation requires elevation through the existing window (`security.elevation_minutes`, 15 by default): a request without a confirmation inside the window is refused and writes nothing, and a second request inside it goes through without asking (both tested in `ExtensionsRoutesTest`). The screens ask in place: the route answers 423 to `X-Teme-Ask-In-Place`, the sheet opens the fresh-password dialog and sends the same request again, so nothing chosen in the sheet is lost. Weighed: the plan's sessionStorage "pending" marker of `plugins/index.tsx`, which leaves the page for the password screen and loses the sheet.
- **B-12 (draft): screens built from the approved drawing.** As drawn, with these readings: a Packagist result shows its newest version once chosen (`search.json` carries no version, and reading fifteen `p2` files per search would spend Packagist's patience); the Version select defaults to `^newest`, worded *The newest that fits (x.y.z now)*; an upload of a package installed here asks for an update; the Git address and Vendor source tabs say the installer doesn't offer them until part C registers those kinds; the sheet's footer keeps the drawing's words, which stay true once per window.
- **B-13 (draft): the screens' controllers pass the data.** `PluginsController::index` and `ThemesController::index` gain one `extensions` prop, null for anyone without `extensions.install`, and `DesignScreen` takes `ExtensionsScreen` to give each theme its origin. The plan's *Changed* list names the pages and the screens' queries but not the two `index` methods the props travel through. Weighed: reading the data in a separate request from the page, a second round trip for every visit.
- **B-14 (draft): what the plan named that wasn't there, and what stays.** `docs/demos/v10/plugins.js`, named as the drawing's source, isn't in the repository; the screens follow the approved drawing as Kingsley described it. `docs/architecture/plugins.md`'s *Not built yet* still lists "the installer and install requests": outside *Installing*, so left for the join to strike once part A is merged. `ContentTypeRegistry::RESERVED` gains `extensions`, because the admin's routes now take that first segment (the gate's `TypeRoutingTest` caught it); a stored type already listed at `extensions` would be refused, as one at `plugins` is.

#### Architecture baselines

| Baseline | Before | After |
|---|---|---|
| `tests/Architecture/admin-words-baseline.txt` | 876 lines | 876 lines |
| `tests/Architecture/design-system-baseline.txt` | 222 lines | 222 lines |

No line added, no count raised; nothing else under `tests/Architecture` changed.

#### Gate

Base (`b897bbd`): Pint passed; PHPStan no errors; Architecture 139 passed (1361 assertions); Unit and Feature 11602 passed, 2 skipped (60022 assertions), 1958 s.

After (`6e5dfd0`): Pint passed; PHPStan no errors; Architecture 139 passed (1367 assertions); Unit and Feature 11713 passed, 2 skipped (60563 assertions), 1919 s. `composer check` exited 0.

A first full run on `934b110` found two failures: `TypeRoutingTest` (the reserved segment, fixed) and `DocumentShellTest`'s URLPattern, because the resumed shell had lost the setup's `/opt/node24/bin` on its PATH and ran Node 22; the second run uses Node 24 as the setup says.

Browser, `tests/Browser/ExtensionsTest.php` only, after `npm run build` and `npm run build:themes`: 4 passed (130 assertions), 1280 and 375 × light and dark.

#### STOP

None.

#### Changelog lines for the maintainer

- Added: plugins and themes can be added from the admin. **Add plugin** and **Add theme** take a package from Packagist, a Git address, a vendor's source with its license key, or an uploaded ZIP; Teme records the request and the installer carries it out. Nothing is enabled until you enable it. (I-211)
- Added: **Install requests** on Site › Plugins lists plugin and theme requests together; each request's sheet follows the installer step by step, asks for your agreement when it finds something to agree to, and shows the exact commands when no installer is running.
- Added: **Update…** and **Remove…** for plugins and themes installed from the admin, and each plugin and theme says where it comes from.
- Changed: a Teme update waits while a plugin or theme request is open.

#### Try it

As an administrator, open Site › Plugins and press **Add plugin**. On Packagist, search a word, press **Choose** on a result, keep *The newest that fits*, and press **Ask to install**; type your password if it asks. The request sheet opens at *Waiting for the installer* with the commands to run, since part A's installer isn't on this branch. The request is on **Install requests**; **Cancel request** ends it. Site › Themes has **Add theme** and links to the same list. Tests: `php artisan test --compact tests/Feature/Extensions/Admin`, and `php artisan test --compact tests/Browser/ExtensionsTest.php` after `npm run build`.

### Part C — `installer/sources`

**Setup:** `2026-11-16T06:20:06Z ran in /home/user/teme as root` / `2026-11-16T06:32:13Z finished`; `pwd` `/home/user/teme`. PHP 8.4.27, PostgreSQL on 5433 and Valkey on 6380 answered after `scripts/cloud-setup.sh`. Branched from `origin/installer/service` at `b897bbd` ("the seam"), nothing else of A's.

**C0, the start:** the seam gives `SourceKind` (`key`, `repositories(?ExtensionSource, string)`, `auth(?ExtensionSource)`, `prepare(ExtensionRequest, string $releasePath, string $extensionsPath)`), `SourceKinds::TAG = 'teme.extensions.sources'`, the readonly `ExtensionSource` (credential `#[SensitiveParameter]`, `__debugInfo` without it) and `ExtensionRequest` (`sourceId`, `uploadId`, `kind`, `package`), `ExtensionSourceRecord` (credential cast `encrypted`) and `ExtensionUploadRecord::archive()`, and `config('teme.extensions.private_hosts')`. The plugin HTTP guard's ranges are `App\Domain\Plugins\Doors\PublicAddress::is()` with the door's `HostResolver` (bound to `DnsResolver` in `NavigationServiceProvider`, line 41; used by `PluginHttp::publicAddress()`, `app/Domain/Plugins/Doors/PluginHttp.php` line 251); `SafeAddress` reuses both.

#### Commits

- `83ee1f9` feat(extensions): check Git and vendor addresses before Composer fetches (C1)
- `0e3bccb` feat(extensions): Git addresses and vendors' Composer sources (C2)
- `8096608` feat(extensions): unpack uploaded ZIPs in the installer (C3)
- `9a69833` test(extensions): Composer installs an unpacked upload from packages/ (C3, proves B-15)
- this records commit (C4)

#### Tests seen red (first line of the failure)

| Task | File | First failure |
|---|---|---|
| C1 | `tests/Feature/Extensions/Sources/SafeAddressTest.php` | `Target class [App\Domain\Extensions\Sources\SafeAddress] does not exist.` (15 failed) |
| C2 | `tests/Feature/Extensions/Sources/PrivateSourcesTest.php` | `Target class [App\Domain\Extensions\Sources\GitSource] does not exist.`; the registration test: `No package source is registered as "git".` (14 failed) |
| C3 | `tests/Feature/Extensions/Sources/UploadsTest.php` | `Target class [App\Domain\Extensions\Sources\ArchiveReader] does not exist.`; the registration test: `No package source is registered as "upload".` (27 failed) |

The Composer test added in `9a69833` (group `composer`) checks code already built, so it was not red first.

#### B-?? (draft)

- **B-15 (draft):** `SourceKind::repositories()` receives the saved source (null for an upload) and no request, so `UploadSource` can't know the uploaded package's name and version that the plan's entry (`packages/<vendor>/<name>/<version>` with `options.versions`) needs; and D5 adds repositories before `prepare()` unpacks. Options: (a) one path entry with a wildcard, `{"type": "path", "url": "<extensions>/packages/*/*/*", "options": {"symlink": false}}`, read by Composer when it runs, after `prepare()`; (b) `prepare()` edits the release's `composer.json` to add the exact entry; (c) list the folders already on disk in `repositories()` (misses the new upload). Chose (a): Composer reads each package's version from its `composer.json`, which `ArchiveReader` has checked equals the upload row's, so `versions` adds nothing; the hidden staging folders (`.<version>.<random>`) never match `*`. A `composer`-group test installs 1.0.0 with 1.1.0 also unpacked, copied, not linked. Commits `8096608`, `9a69833`.
- **B-16 (draft):** registering `ExtensionSourcesServiceProvider` needs one line in `bootstrap/providers.php` (and its `use`), not in part C's Owns. Added after `PluginServiceProvider`. Commit `0e3bccb`.
- **B-17 (draft):** the seam has no exception for a source's refusal (`InstallerFailed` is part A's, after the seam). Options: a new exception class (outside Owns), A's class (not on this branch), PHP's own. Chose: `SafeAddress` throws the existing `App\Domain\Support\Network\AddressRefused` (its existing words: scheme, credentials, unknown host, private network, malformed), and the archive, upload and missing-source refusals throw `RuntimeException` with the reason through `__()` as the message. The runner records the message either way; the joiner may wrap them in `InstallerFailed`.
- **B-18 (draft):** `SafeAddress` goes slightly past the plan's list: it also refuses a user name or key written into the address (it would be written into the release's `composer.json`, a file) and a trailing dot, as `PublicAddresses` does. `prepare()` reads only the source's `url` (`SafeAddress::checkSource()`), so the key is not even decrypted there. `ArchiveReader` also refuses an entry name twice, empty and `.` path parts, and checks the package name against Composer's pattern and the version against a one-folder pattern before using either in a path.
- **B-19 (draft):** part A's `tests/Feature/Extensions/Installer/SeamTest.php` ("resolves Packagist and a tagged kind by key") asserts `keys()` is exactly `['packagist', 'vendor_test']`; with part C's kinds registered it is `['packagist', 'git', 'composer', 'upload', 'vendor_test']`, so it fails on this branch and will at the join. It's A's file, so it is unchanged here; A's builder was told. Its meaning holds with "Packagist first, `vendor_test` present".
- **B-20 (draft):** the ZIP fixtures are built by the tests, in a temporary folder, from part A's fixture folders in `tests/Fixtures/Extensions/packages/` (`hello-extension`, `hello-extension-1.1.0`, `hello-theme`), with entries added, replaced or left out per case; no `tests/Fixtures/Extensions/zips/` folder was needed, and nothing binary is committed.

#### Architecture baselines

| Baseline | Before | After |
|---|---|---|
| `admin-words-baseline.txt` | 876 lines | 876 lines |
| `design-system-baseline.txt` | 222 lines | 222 lines |

#### Gate

- **Before** (at `b897bbd`): Pint passed; PHPStan no errors; Architecture 139 passed (1361 assertions); Unit and Feature 1 failed, 2 skipped, 11601 passed (60019 assertions). The failure, `Plugins\DiscoveryTest` "finds the configured folders", is a parallel-run flake (an extra `tests/jobs` fixture folder seen).
- **After** (at `9a69833`): Pint passed; PHPStan no errors; Architecture 139 passed (1361 assertions); Unit and Feature 2 failed, 2 skipped, 11657 passed (60160 assertions). The failures: `Extensions\Installer\SeamTest` (B-19), and `Plugins\DatabaseDownTest` "serves an ordinary request", which passes alone (1 passed), a parallel-run flake.
- Part C's quick check: `tests/Feature/Extensions/Sources` 57 passed (139 assertions). No Browser tests in this part.

#### STOP

None.

#### Left open

- `SafeAddress` checks the host when `prepare()` runs; Composer looks the name up again when it fetches, and Teme can't pin Composer's connection, so a host that changes its DNS answer between the two isn't caught. `secure-http` stays on.
- An archive made by macOS's Finder carries a `__MACOSX/` folder beside the package folder, and is refused as "more than one package". The plan's rule, built as written; the Add sheet may want to say so.
- The second count of unpacked bytes, while writing, has no test of its own (it needs an archive whose central directory lies); the sum from the central directory is tested with the 300 MB entry.

#### Changelog lines for the maintainer

- Plugins and themes can be installed from a Git address, public or private with a token, and from a vendor's Composer source with a license key; keys go to Composer only inside the installer and are never written to a file or a log.
- Git and vendor addresses must use https and point outside the server's own network, unless the operator allows private hosts (`TEME_EXTENSIONS_ALLOW_PRIVATE_HOSTS`).
- Uploaded ZIPs are unpacked by the installer only after checking for unsafe paths, symbolic links, archive bombs (20,000 files, 256 MB unpacked) and a `composer.json` and manifest that match the upload; a different archive with the same name and version is refused.

#### Try it

`php artisan test --compact tests/Feature/Extensions/Sources` (57 tests; the `composer` group runs Composer against a temporary folder, with Packagist off). By hand, in `php artisan tinker`: `app(App\Domain\Extensions\Sources\SafeAddress::class)->check('https://127.0.0.1/x.git')` throws "127.0.0.1 is on a private network, so Teme won’t connect to it."; `app(App\Domain\Extensions\Sources\SourceKinds::class)->keys()` lists `packagist`, `git`, `composer`, `upload`. The end-to-end run waits for the join.

#### Follow-up R1

The review of 2026-11-16 (verdict: merge after fixes). Fixed on this branch:

- **IMPORTANT 1, the file modes test on Windows:** `UploadsTest` "writes files 0644 and folders 0755, whatever the umask" now ends `->skipOnWindows()`. Commit `b68a015`. Not seen red: the failure happens only on Windows, and this container is Linux, where the test still runs and passes (1 passed, 4 assertions).
- **LATER 7, composer.json read without a size cap:** `ArchiveReader` checks the entry's size from the archive's directory against `MOST_COMPOSER_BYTES` (1 MB) and caps `getFromName` at it. Seen red first: the new dataset case "a composer.json over 1 MB" failed with `Exception [RuntimeException] not thrown.`; it now gives "The archive’s composer.json is larger than 1 MB." Commit `3cb3ced`.
- **LATER 6, British spelling:** "licence key" is "license key" in `ComposerSource`'s docblock, in the test's planted values, and in the changelog line above. Commit `4065014`. The proposal's own "licence" is outside part C's files and stays.

Left for the join, as the review marks them: **2** (delete an unpacked upload's folder on a failed or cancelled request when no kept release's lock names it, and at prune) and **3** (check every `vcs` and `composer` repository in the staged `composer.json` with `SafeAddress::check()` before `fetching`, not only the request's own source).

Recorded for later, as the review asks (a builder can't write `docs/ideas.md`):

- **4:** `SafeAddress` covers only the first connection. Composer follows redirects, `git` follows them on its first request, and a vendor's `packages.json` may list dist addresses on any https host, internal ones included. Wants an ideas entry and an issue.
- **5:** two sources on the same host keep only one key, because the runner merges `auth()` with `array_replace_recursive` and the keys are by host (two private GitHub repositories with different tokens, or two vendors on one host). Wants a line in the operators' guide (part A's file).

**Gate after R1** (at `4065014`): Pint passed; PHPStan no errors; Architecture 139 passed (1361 assertions); Unit and Feature 1 failed, 2 skipped, 11659 passed (60165 assertions). The one failure is A's `SeamTest`, B-19, already changed on `installer/service`. Part C's own: 58 passed (142 assertions). Baselines unchanged: 876 and 222 lines.

### The join — `join/installer`

**Setup:** a local Windows 11 working copy from `main` at `df2f85d`, PHP 8.4 (Herd), PostgreSQL and Valkey from the `teme-join` containers on 5433 and 6380. Another process was running migrations on the shared `teme_test` database (two deadlocks seen), so this join's tests ran on databases of their own, `teme_test_installer` and `teme_test_installer2`, on the same server (`DB_DATABASE` set for the run).

#### Commits

- `f42aaa0` Merge installer/service (part A); `01b29e7` Merge installer/admin (part B); `c426189` Merge installer/sources (part C). No conflicts: `SafeMode.php`, `docs/architecture/plugins.md` and this plan merged on their own, each part's records kept.
- `97f938e` test(extensions): the admin's tests use part C's registered source kinds
- `9477185` fix(extensions): never flash a source's key into the session (review B, BLOCKING)
- `719e626` fix(extensions): read an upload's composer.json only up to 64 KB in the web process (review B, IMPORTANT)
- `56fbba8` fix(extensions): only Teme's own rebuild goes on while a Teme update is open (review B, 3; A's later 2)
- `6c24851` fix(extensions): hide saved keys in what the installer records (review B, 4)
- `b94dc30` fix(extensions): delete unpacked uploads no kept release names (review C, 2)
- `9585fbb` test(extensions): build the upload tests' ZIPs with forward slashes on every system
- `30e97a0` fix(extensions): check every Git and Composer address before Composer fetches (review C, 3)
- `7daaa0a` fix(extensions): a source's refusal is the request's reason (B-17)
- `6f011e1` fix(extensions): an agreement holds for the same version and reference only (A's later 1)
- `0b63d0c` fix(extensions): the request sheet reads the installer's keys for what waits (B-10)
- `26e29f5` feat(extensions): ask for and cancel a plugin or theme change from the shell (B-04)
- `064da41` docs(extensions): the installer is built; Installing says what the join added (B-14)
- `39323b1` docs(guides): keep the theme option beside its command
- `6d65126` style(extensions): Pint on the installer runner
- `8dc84ef` test(extensions): the installer's three parts end to end
- this records commit

#### What the merge broke, and how it was joined

- Part B's tests tagged stand-in `git` and `upload` kinds "until part C registers them"; with C's real kinds registered, `SourceKinds` refused both (`Two package sources are registered as "git".`, 13 tests). The stand-ins were removed; the tests run against C's kinds, otherwise unchanged.
- Part B's request sheet headed the needs `composerPlugins` and `replaces`; part A's `StageValidator` writes `composer_plugins` and `replace`. `words.ts` now reads A's keys (B-10). `AgreeToExtensionRequest` records the needs exactly as A wrote them, and A's `lookAgain()` compares them as arrays, so the same keys come back.
- B-17: part C's sources refuse with `RuntimeException`; A's runner recorded those as "an error it didn't expect". `preparing()` now wraps a source's refusal in `InstallerFailed`, so the request shows C's words.
- B-19 (`SeamTest`'s kinds) was already settled on `installer/service`; it passes.

#### Tests seen red (first line of the failure)

| Item | Test | First failure |
|---|---|---|
| a | `ExtensionsRoutesTest` "never flashes a source’s key into the session when the form is refused" | `Expecting '{"_token":…}' not to contain 'ghp_x'.` |
| b | `SourcesAndUploadsTest` dataset "a composer.json over 64 KB" | `Exception [Illuminate\Validation\ValidationException] not thrown.` |
| c | `InstallerRunnerTest` "stops a begun request, an administrator’s rebuild among them, when an update of Teme opened meanwhile" | the administrator's rebuild: `-'failed' +'running'` (the install case already held) |
| c | `InstallerRunnerTest` "takes Teme’s own rebuild past the check while the update that asked for it is open" | not red: it guards the other side of the same line |
| d | `InstallerRunnerTest` "records no saved key in a failed request’s reason, steps or report, nor on the screens that show it" | `Expecting '{"id":"01m7hw8gqp…"}' not to contain 'secret-key-123'.` |
| e | `InstallerRunnerTest` "deletes an unpacked upload no kept release names when its request fails, and when one is cancelled" | `Expecting '…/packages/acme/gone/1.0.0' not to be directory.` |
| f | `InstallerRunnerTest` "refuses before fetching when the staged composer.json holds a Git or Composer address on a private network" | `-'10.0.0.5 is on a private network, so Teme won’t connect to it.' +'Composer couldn’t install the packages: Composer ran.'` |
| B-17 | `InstallerRunnerTest` "fails a request with the source’s own reason when the source refuses to prepare it" | `+'The installer stopped on an error it didn’t expect; the server’s log has the details.'` |
| g | `StageValidatorTest` "asks again about a package the running release holds at the same reference but another version" | `-Array &0 [ 'files' => … ] +Array &0 []` |
| h | `InstallerRunnerTest` "goes on once an administrator agrees, through the admin’s Operation, to every key it found" | not red: it confirms the seam B-10 asked about, which already held |
| i | `RequestCommandsTest` (9 tests) | `The command "teme:extensions:request" does not exist.` |
| guide | `OperatorPagesTest` "sets every command in code, each option beside its command" | `+    0 => '--kind=theme',` (the first wording of the new paragraph) |

- **c:** the begin step already refused a non-rebuild request while an update is open (`BeginExtensionRequest`); the gap was the `checking` step, which skipped the update check for every rebuild. It now skips it only for a rebuild with `requested_by` null.
- **d:** the runner replaces every saved source's key, as written and JSON-escaped, with `[hidden]` in each step's detail, the needs, the reason and the report before recording them; keys under four characters are left, since they would blank ordinary words.
- **e:** after a failure, a cancel after a day, a prune, and on any pass with no request open (which catches a request cancelled from the admin), the installer deletes each release a failed or cancelled request built that never went live (not current, never any request's `from_release`, never a done request's `to_release`), then each `packages/<vendor>/<name>/<version>` no remaining release's lock names.
- **f:** `vcs`, `git`, `github`, `gitlab`, `bitbucket` and `composer` entries are checked; `path` entries are local.
- **i:** `teme:extensions:request {action} {package?} {--constraint=} {--kind=plugin}` asks from Packagist (the plan's signature has no source option); `teme:extensions:cancel` cancels the open request through `CancelExtensionRequest`, which refuses a running one in its own words.

#### Gate (this Windows machine)

- Pint: clean on every PHP file the join brings. PHPStan on `app`: no errors.
- `tests/Feature/Extensions`: 218 passed, 1 skipped, 68 failed. Every failure is this machine's: 52 run Composer, whose child process here gets no `TEMP` (`PHP temp directory (C:\WINDOWS) does not exist or is not writable to Composer`), 15 need a symbolic link (this account may not make links, and PHP on Windows resolves a relative target against the working folder), and 1 compares a command quoted by `escapeshellarg()`, which quotes with `"` on Windows. The end-to-end test is among the 15. Right after the three merges the same folder had 101 failures: these 67, plus the 13 from the stand-in kinds and the 19 from the ZIP helper's backslashes, both fixed above.
- With a local, uncommitted patch that gave Composer `TEMP` and the link an absolute target, `EndToEndTest` ran the upload, the request, Composer's install from `packages/`, verifying and validating, and stopped at the swap (no link privilege). The rest of it has not been seen here.
- `tests/Feature/Plugins`: 528 passed, 1 skipped, 68 failed. The same 68 tests, with the same first lines, fail on `main` at `df2f85d` on this machine (run on the same database for comparison), so all are pre-existing. They copy or link fixture plugins and key them by path, which breaks on Windows paths (`Undefined array key "tests/C:…"`, `No plugin is named "tests/tables"`), need a symbolic link, or time out on a real socket (`Failed asserting that 60 is identical to 7`).
- `tests/Architecture`: 139 passed (1368 assertions); baselines unchanged (no file under `tests/Architecture` changed).
- `tests/Feature/Guides/Operators`: 172 passed, after `39323b1`.

#### Left open

- `EndToEndTest` and the other link- and Composer-bound installer tests have not been seen green on this machine; GitHub's Linux run decides them. The end-to-end test stands in for a fresh process with `PluginFixtures::installed()` at the current release's package folder (what `bootstrap/extensions.php` makes `InstalledVersions` see; `LoadingTest` covers the loading itself in a child process), and gives the plugin a recorded table, because `hello-extension` has no migrations and **Remove its data…** is offered only for a plugin with tables.
- A request cancelled from the admin is cleaned up on the installer's next idle pass, not at once: the web process can't write the folder.
- Not run here: the full `composer check` and the Browser file (`tests/Browser/ExtensionsTest.php`); GitHub runs both.
- Part C's review items 4 (redirects past `SafeAddress`) and 5 (two keys on one host) stay as C recorded them, for an ideas entry and an issue.
