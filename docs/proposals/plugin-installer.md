# Proposal: the plugin and theme installer, in the standard install

**Status:** draft, 2026-11-16. **Decides:** I-211 as built, I-036, I-3462 (option C, brought forward by the maintainers on 2026-11-16), the first half of I-433 (a paid vendor's Composer source with a licence key). **Closes:** I-406 (the installer versus image-based deploys). **Slots in later:** I-3461 (Teme's signed directory). **Plan:** `docs/plans/plugin-installer.md` (drafted as `plugin-installer.md` beside this file). **Branches:** `installer/service`, `installer/admin`, `installer/sources`, after the v1 join merges. Roadmap 5.3c.

## What it is

An administrator installs, updates, removes and rolls back plugins and themes from Site › Plugins and Site › Themes: from Packagist by name, from a Git address (public, or private with a token), from a paid vendor's Composer source with a licence key, or by uploading a ZIP. It works on the standard Dokploy stack and the plain Compose recipe as published, with no repository or build of a site's own. Enabling a plugin and activating a theme stay separate steps, through the consent dialog and the Themes screen that exist.

## Why it is shaped this way

The web process never writes code (spec §3). So the admin only **records a request**, through an Operation, after the person's password. A separate **`installer` service** in the stack, running as its own system user (`teme-installer`), is the only thing that can write the **`extensions` volume**; every other part mounts it read-only. The installer builds a **new release folder** with Composer, checks it, and switches to it in one atomic step, keeping the previous releases for rollback. Plugins and themes live in that volume, not in the image, so updating Teme never removes them; the image stays exactly as published.

The hard part is making Composer packages installed after the image was built load beside the image's own. The extensions folder is **a second Composer project** whose `composer.json` pins every package the image already has, at the image's exact version (`replace`/`provide`). Composer then resolves each plugin's needs against Teme's real versions and refuses a conflict before anything changes, and never installs a second copy of a package Teme has. Its autoloader is loaded after core's and never in front of it. Teme's plugin and theme discovery already reads every registered Composer autoloader, so it finds the new packages without change.

## Settled

1. **One named volume, `extensions`, mounted at `/var/teme/extensions`;** releases in `releases/<id>/`, a relative `current` link as the one truth of what runs, the current release and the two before it kept. Every role except the installer mounts it read-only.
2. **A second Composer project per release, pinned to the image's packages,** loaded by a small bootstrap file after `vendor/autoload.php` with `prepend-autoloader: false`, so core's classes always win. Rejected: path repositories into the image's own `vendor/` (rewritten on every update, at every start, as root); merging autoload maps into core's loader as Winter does (discovery would not see the packages, and nothing would stop a second, different copy of a library).
3. **A release is loaded only when it was checked against the running image** (a stamp per image). After every Teme update the installer checks each plugin's locked requirements against the new image's versions without running Composer; when they no longer fit, it rebuilds with `composer update --minimal-changes` (nothing updates that need not). If that fails, the site runs with installed extensions held, the admin names the package that blocks, and the site stays up. The web, worker and scheduler start only after this check.
4. **The installer service** (`teme:installer`, user uid 9990, from the same image as the site, with a heartbeat like the updater's) carries out one request at a time: check space, stage, Composer, verify, validate, switch, finish. It restarts the queue workers after a switch, and purges the page cache when an enabled plugin or the active theme changed.
5. **Composer never runs package code here:** always `--no-scripts --no-plugins`, with `allow-plugins: false`, and with an environment that holds none of the site's secrets.
6. **Verification:** the lock matches `composer.json`; every package has a pinned reference; what was installed is exactly what was locked; Composer checks every checksum the source gives. Packagist signs nothing; signatures come with Teme's directory (I-3461).
7. **Refused:** a package of the wrong type; one that is part of the image; an invalid manifest or a Teme range that excludes this Teme; a plugin or theme code already in use (no package may shadow another); a plugin that declares `autoload.files` itself; any autoload namespace that collides with the image's (`App\`, `Teme\Contracts\`, `Illuminate\`, …); missing PHP version or extension; a known security advisory (provisional, P4).
8. **Asked first, then continued after the administrator agrees:** a dependency whose files run on every request (`autoload.files`); a dependency that is a Composer plugin (it won't run); a package coming from a different source than before; an upload that replaces an installed version (shown as current → new).
9. **The installer runs no plugin migrations.** I-3442 already decides: they run at enable, and with "Run database changes" when an enabled plugin's update brings new ones.
10. **Requests:** `RequestExtensionChange` (install, update, remove, rollback), `CancelExtensionRequest`, `AgreeToExtensionRequest`, each needing the new `extensions.install` permission (administrators) and the person's password, or the operator at the shell. One request open at a time, never during a Teme update (and a Teme update waits for an open request). Removing needs the plugin disabled or the theme inactive first; the plugin's data stays until **Remove its data…**, as today.
11. **Sources are a list of kinds, each a class:** `packagist`, `git`, `composer` (a vendor's source with a key), `upload`. Teme's signed directory is a later class, `directory`, with no change to the tables, the screens or the steps. Keys are encrypted under the app key, never shown again, never sent to the browser, never written to a file or a log; the installer hands them to Composer in its environment only.
12. **ZIP uploads are data until the installer unpacks them:** the web process stores the archive in the database and reads only its `composer.json`; the installer refuses unsafe paths, symlinks, archive bombs, and anything without a valid `composer.json` and manifest, then installs it as a path package that stays unchanged under `packages/`. A different archive with the same name and version is refused.
13. **Without an installer**, the request is recorded and the screen shows the exact commands to run it on the server (Docker or not), or to add the package to the site's own build, as Statamic does. It waits until someone does.
14. **Nothing updates itself.** Every update is a request a person makes.
15. **Git and vendor addresses must be https and must not point inside the server's network**, unless the operator allows private hosts for a self-hosted Git server.

## Provisional (built as written unless the maintainers say otherwise)

- P1. Three releases kept (the current one and two before it).
- P2. The installer's user is `teme-installer`, uid and gid 9990.
- P3. A request waiting for agreement is cancelled after 24 hours.
- P4. A known security advisory refuses the install; an unreachable advisory database is reported and doesn't.
- P5. Uploads up to 32 MB, 256 MB unpacked, 20,000 files.
- P6. Packagist on by default; `TEME_EXTENSIONS_PACKAGIST=false` turns it off (search and installs by name).
- P7. Free space needed before staging: 512 MB or three times the current release, whichever is more.

## Open questions for the maintainers

- **Q1. The drawing of the Add sheet and the request sheet** (described below) is shown before part B's screen task is built. The rest of part B, and parts A and C, don't wait for it.
- **Q2. `teme/contracts` has no version of its own.** In the image it installs from a folder with a branch version, so a plugin can only write `"teme/contracts": "*"`; a range like `^0.9` would be refused. (a) Recommended: give `packages/teme-contracts/composer.json` a `version` that follows Teme's release (set by the release script), so plugins can state a range; (b) keep `*` and rely on the manifest's `teme` range, which Teme already checks at enable. Unverified: what version Composer gives the path package in the image built from `git archive` with no `.git`.
- **Q3. Backing up the extensions folder.** A restore onto a new server brings back the plugins' rows but not their code. (a) Recommended: a follow-up adds `composer.json`, `composer.lock` and `packages/` of the current release to each backup (small), and the installer rebuilds from them on a restored site; until then the operators' guide says how to copy the folder by hand. (b) Back up the whole volume (larger, holds code anyone can download again).
- **Q4. A dependency that is a Composer plugin.** Settled 5 never runs it, even when agreed. Should agreeing allow it to run? Recommended: no; a package that needs one is installed through the site's own build.
- **Q5. Checking for newer versions.** This part installs a version someone asks for and shows no "update available". Recommended: a later part where the installer checks once a day (it holds the keys) and the Plugins screen shows it, still never updating by itself.
- **Q6. The public Dokploy stack** (`temecms/temecms-for-dokploy`) must gain the `installer` service and the `extensions` volume when this releases. It is a separate repository; the builders can't change it. Recommended: the maintainers sync it with its own script after the release, and mention the new volume in `template.toml`'s comment.
- **Q7. The permission name.** `docs/architecture/plugins.md` names `plugins.install`; this proposal uses `extensions.install` because the same permission installs themes. Recommended: `extensions.install`.

**Unverified, checked by part A's first task:** that a new named volume copies the image folder's owner on Dokploy (else the installer stops with a one-line fix); that the image's Composer is 2.7 or later (`--minimal-changes`); that Laravel boots as uid 9990 with the image's own start step skipped.

## The screens, for the drawing

Extends the screens that exist and the v10 demo's **Install requests** tab, with `components/ui` unchanged.

- **Site › Plugins:** an **Add plugin** button in the header (only for people who may install); the **Install requests** tab (requests for plugins and themes, newest first, with status); on a plugin from the extensions folder, **Update…** and **Remove…** in its row menu and "Installed from the admin, from {source}" in Details; on one from the image, "Part of the site's build". A banner when installed extensions are held after a Teme update, naming the package that blocks. **Roll back to the previous plugins and themes…** in the tab's header when an earlier release is kept.
- **Site › Themes:** **Add theme**, and **Update…** / **Remove…** on a theme from the folder.
- **The Add sheet** (new): *Add plugin* or *Add theme*, four tabs. **Packagist**: a search box, results with name, description, downloads and newest version, a version picker, **Ask to install**. **Git address**: the address, the package name, the version or branch, and *This repository is private*, which reveals a token field. **Vendor source**: choose a saved source or add one (address, username if the vendor gives one, licence key; "Saved. Teme never shows a key again."). **Upload ZIP**: a drop zone; after the upload, the name and version read from it, and the version installed now, if any. The footer: "Teme asks for your password, records the request, and the installer carries it out. Nothing is enabled until you enable it."
- **The request sheet** (opened from the tab, or right after asking): the steps, drawn as Tools › Updates draws them; when waiting, what to agree to (files that run on every request, Composer plugins that won't run, a different source, current → new version) with **Agree and continue** and **Cancel request**; when it failed, the reason and Composer's last lines; with no installer running, the commands to run, each with a copy button.

## Not in this part

Teme's signed directory (I-3461, phase 6), licence status (I-3132), update notices (Q5), backing up the folder (Q3), a sandboxed tier (I-213), installing through SSH Git addresses, installing several packages in one request, automatic updates of any kind.

## References checked

The reference systems were listed (40 folders) and each searched for installing extensions from an admin, Composer runs, ZIP uploads and staging. Those that have the feature:

| System | File read | What it teaches |
|---|---|---|
| WordPress (trunk) | `src/wp-admin/update.php` (`upload-plugin`, `overwrite`), `src/wp-admin/includes/class-plugin-upgrader.php` (`check_package()`), `class-wp-upgrader.php` (`download_package($package, $check_signatures = false)`, `move_to_temp_backup_dir()`, `restore_temp_backup()`), `class-file-upload-upgrader.php` | An upload is kept as data first (an attachment, cleaned up after two hours) and checked for a real plugin before anything is installed; replacing an installed one is confirmed with the two versions side by side; the old copy is moved aside and restored on failure. Signatures exist but are off. The web user writes the code, which Teme doesn't take. |
| Drupal (main) | `core/modules/package_manager/src/SandboxManagerBase.php` (`create`, `require`, `apply`, `postApply`, `destroy`), `Validator/ComposerPluginsValidator.php`, `Validator/DiskSpaceValidator.php`, `Validator/LockFileValidator.php`, `FailureMarker.php` | The staging lifecycle Teme follows; Composer plugins are restricted because they can change files; at least 1 GB free before staging; the lock's hash is stored when the stage is made and checked before applying, so a change made meanwhile is caught; without atomic swap a failure mid-copy leaves the site "indeterminate", which Teme's release swap avoids. Project Browser is not in core. |
| Craft CMS 5 | `src/services/Composer.php` (`install()`, `--no-scripts`, `COMPOSER_HOME`, `allow-plugins`, backups of `composer.json`), `src/controllers/BaseUpdaterController.php`, `src/config/GeneralConfig.php` (`allowUpdates`, `allowAdminChanges`) | Composer run with `--no-scripts` and its own home; Craft allows only its own Composer plugins; the request is broken into steps the screen shows. It runs in the web request and restores only `composer.json` on failure, which Teme's release folders replace. |
| Statamic | `src/Http/Controllers/CP/Addons/AddonsController.php`, `src/Marketplace/Marketplace.php`, `src/Updater/*` | The control panel lists addons and their releases and installs nothing; installing is a Composer command run where the site is built. Teme's fallback without an installer follows this. (The Vue sources that show the command aren't in this copy; the earlier study O recorded it.) |
| October CMS 4 | `modules/system/classes/updatemanager/ManagesPlugins.php` (`installPlugin()` through `ComposerManager::require`), `ManagesProject.php` (project key) | Composer in the web request; a project key ties a site to its purchases, which is I-433's licence key in another form. |
| Winter CMS | `modules/system/classes/ComposerManager.php` (`autoload()`), `PluginManager.php`, `UpdateManager.php` (`extractPlugin()`) | Plugins carrying their own `vendor/` are merged into core's autoloader with "core's prefix wins" pools: kept as Teme's namespace-collision rule, not as the mechanism. Marketplace ZIPs are checked by hash and extracted straight into `plugins/`. |
| Joomla 6.2 | `administrator/components/com_installer/src/Model/InstallModel.php` (`upload`, `url`, `folder`), `UpdatesiteModel.php` (`extra_query`: the download key), `libraries/src/Installer/InstallerHelper.php` (`unpack`, `detectType`), `libraries/src/Filter/InputFilter.php` (`isSafeFile`) | Several sources behind one installer (upload, address, folder, update sites), and a per-source download key added to every request: the shape of Teme's source list and vendor keys. Uploaded files are checked for unsafe content. |
| emdash | `packages/core/src/api/handlers/marketplace.ts` | A marketplace install checks the bundle's checksum against the marketplace's record and refuses a plugin whose ID would shadow a configured one: adopted as the code-collision refusal. |
| pursae (WordPress in Go) | `docs/developer/hub/marketplace.md` | Add Plugin with a catalog and an Upload tab; SHA-256 and signature checked; "later versions must come from the same publisher": adopted as asking before a package changes source; uploaded plugins are updated by uploading again; auto-updates exist there, which Teme leaves out. |
| Ghost | `ghost/core/core/server/services/themes/storage.js` (`setFromZip`), `validate.js` | Theme ZIP uploads are validated before use, can't override the default themes, and the existing theme is renamed aside and restored if the upload fails. |
| webblocks-cms | `src/Support/Plugins/PluginZipInstaller.php` (`safeEntries`, `assertSafePath`, `assertNotSymlink`) | ZIP entries with `..`, empty segments or symlinks are refused before extracting: part of Teme's archive rules. |
| artisanpack-ui-cms-framework | `src/Modules/Core/Updates/Support/ExtensionArchive.php` (`firstUnsafeEntry`, `uncompressedSize`) | Unsafe entries and the unpacked size are checked from the archive before extracting: the archive-bomb rule. |
| Study O (Teme's own) | `docs/studies/extensions.md` §2 | The comparison of WordPress, Craft, October, Statamic and Drupal that chose option C; this proposal builds it. |

Searched and without the feature (no install from an admin, or only console scaffolding): acorn, the other artisanpack-ui packages, basehim (themes are uploaded but not as packages; not read further), capell (installs developer tooling from the console), emdash-exporter, filament 5, laradashboard (builds module ZIPs from the console), millicache, MultilingualPress, Payload, kaletacms, Polylang, pubvana (its marketplace plugin sells and lists; installing not read further), Rank Math, react-starter-kit, Sage, Sharp, Strapi (plugins through npm and a rebuild), TranslatePress, Twill (console installs), TypiCMS, Wagtail, Yoast SEO.

**Outside (gap only):** Composer's own behavior is taken from its documentation as known before this conversation and **not re-read today**: `replace`/`provide` on the root package, `prepend-autoloader`, `--minimal-changes` (Composer 2.7), `COMPOSER_AUTH` with `github-oauth`, `gitlab-token`, `http-basic` and `bearer`, `check-platform-reqs`, `audit`, and path repositories with `symlink: false` and `versions`. Packagist's `search.json` (`type`, `per_page`) and `repo.packagist.org/p2/` are likewise from its API documentation, not re-read. Part A's tests prove each Composer behavior the plan depends on before anything is built on it.

## Decided (the maintainers, 2026-11-16)

All open questions as recommended:

- Q1: the Add sheet and the request sheet are drawn from v10 and shown before part B's screen task; the rest goes ahead.
- Q2: `teme/contracts` carries Teme's release version, set by the release script, so plugins can state a range.
- Q3: a follow-up adds the current release's `composer.json`, `composer.lock` and `packages/` to each backup, and the installer rebuilds from them after a restore; until then the operators' guide says how to copy the folder.
- Q4: an agreed Composer plugin never runs; a package that needs one is installed through the site's own build.
- Q5: "update available" comes in a later part: a daily check by the installer, shown on the Plugins screen, never updating by itself.
- Q6: the maintainers sync temecms-for-dokploy at release time.
- Q7: the permission is `extensions.install`.
