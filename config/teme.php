<?php

declare(strict_types=1);

use App\Domain\Deployment\SecureTransport;
use App\Domain\Updates\TemeVersion;

/*
 * Teme itself (slice 1.6b). `version` is the release this build is, from the
 * image (I-?? draft), and what a theme's `requires.teme` constraint is
 * checked against. Themes are Composer packages of type
 * `teme-theme`; `theme_paths` (TEME_THEME_PATHS, folders separated by commas) adds plain folders beside them, for tests and
 * local work, and is always read live. `theme_cache` is where
 * `teme:themes:cache` writes Composer's themes, which `optimize` runs.
 * `disallow_site_code` is the host's switch for site code (I-288).
 * `ai.enabled` is the host's switch for AI (I-315).
 */
return [
    'version' => TemeVersion::fromEnvironment(env('TEME_VERSION')),

    /*
     * Whether this build is a tagged release (I-?? draft): false in
     * development and for an image built from a branch, which report
     * TemeVersion::UNTAGGED. revision is the commit the image was built from.
     */
    'tagged' => TemeVersion::isRelease((string) env('TEME_VERSION', '')),
    'revision' => TemeVersion::revision(env('TEME_REVISION')),

    /*
     * The one role a container runs, set only to `all`: web, both queue
     * workers, the scheduler, the plugin installer and the updater in one
     * container, on any host that runs one (docs/guides/operators/platforms/one-container.md).
     */
    'role' => env('TEME_ROLE'),

    /*
     * The edge (I-380): the Strict-Transport-Security value every https
     * answer carries in production at an https APP_URL; empty sends none,
     * for a proxy in front that sends its own. The image's nginx sends the
     * same TEME_HSTS with the files it serves itself.
     */
    'deployment' => [
        'hsts' => (string) env('TEME_HSTS', SecureTransport::DEFAULT_HSTS),
    ],

    'theme_paths' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('TEME_THEME_PATHS', ''))))),

    'theme_cache' => base_path('bootstrap/cache/teme-themes.php'),

    /*
     * Plugins (I-3353) are Composer packages of type `teme-plugin`;
     * `plugin_paths` (TEME_PLUGIN_PATHS, folders separated by commas) adds plain folders beside them, for tests and local
     * work. `plugin_cache` is where `teme:plugins:cache`, which `optimize`
     * runs, writes what was found in them; nothing else writes it.
     */
    'plugin_paths' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('TEME_PLUGIN_PATHS', ''))))),

    'plugin_cache' => base_path('bootstrap/cache/teme-plugins.php'),

    /*
     * The server's switch (I-3353): plugins that don't start, whatever their
     * row says, as names separated by commas, or * for every plugin.
     * Removing a name brings it back; nothing is written.
     */
    'plugins' => [
        'disabled' => env('TEME_DISABLED_PLUGINS', ''),
    ],

    /*
     * Whether an import may read a site on this computer or its private
     * network, and over plain http to it (docs/studies/import.md). On only
     * where APP_ENV is local, as on a developer's machine.
     */
    'import' => [
        'local_sources' => env('APP_ENV', 'production') === 'local',
    ],

    /*
     * The host's switch for Settings › Site code (I-288): true keeps every
     * entry off every page and refuses every change, administrators' too.
     * After changing it, clear the page cache: php artisan teme:cache:clear.
     */
    'disallow_site_code' => (bool) env('TEME_DISALLOW_SITE_CODE', false),

    /*
     * The public site's content security policy (I-3375): off, report-only
     * or enforce. Deployment configuration, never a setting
     * (docs/architecture/security.md). A deploy clears the page cache, so
     * every stored page takes the new mode.
     */
    'public_csp' => (string) env('TEME_PUBLIC_CSP', 'report-only'),

    /*
     * The host's lock on the site's own content types (I-411): true refuses
     * every person's change to them, so they change where they are made and
     * arrive with php artisan teme:types:import. Off by default.
     */
    'types_locked' => (bool) env('TEME_TYPES_LOCKED', false),

    /*
     * The host's switch for AI (I-315): TEME_AI=off switches every AI surface
     * off for the whole site. Unset or empty means on; a yes (on, true, 1,
     * yes) means on; a no, or any other word, means off, so a mistyped value
     * never leaves AI on.
     */
    'ai' => [
        'enabled' => in_array(env('TEME_AI'), [null, ''], true)
            || filter_var(env('TEME_AI'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true,
    ],

    /*
     * The update check (I-?? draft): once an hour the scheduler asks GitHub
     * for Teme's releases, sending nothing about this site. TEME_UPDATES_CHECK
     * switches it off, read as TEME_AI is. TEME_GITHUB_TOKEN, a read-only
     * token, is optional: it only raises GitHub's limit on requests.
     * TEME_UPDATES_CHANNEL is stable or prerelease; unset, it follows the
     * version this site runs.
     */
    'updates' => [
        'check' => in_array(env('TEME_UPDATES_CHECK'), [null, ''], true)
            || filter_var(env('TEME_UPDATES_CHECK'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true,
        'repository' => 'temecms/teme',
        'token' => env('TEME_GITHUB_TOKEN'),
        'channel' => env('TEME_UPDATES_CHANNEL'),
    ],

    /*
     * The updater (I-3326): the updater service, php artisan teme:updater, carries
     * out an update asked for from the admin through the platform. Set on the
     * updater alone. Dokploy's API key is Settings › Updates' secret, or
     * TEME_DOKPLOY_TOKEN on the server, read by the updater when it calls
     * Dokploy and never kept in configuration. The updater finds its Compose
     * service by app_name, unless the server names compose_id. It replaces the
     * service's compose file only with the Dokploy template's current one, and
     * only when compose_hashes_url lists the service's file as released.
     */
    'updater' => [
        'platform' => env('TEME_UPDATER_PLATFORM'),
        'image_repository' => env('TEME_IMAGE_REPOSITORY'),
        'deploy_timeout' => (int) env('TEME_UPDATER_DEPLOY_TIMEOUT', 3600),
        'part_timeout' => (int) env('TEME_UPDATER_PART_TIMEOUT', 900),
        'dokploy' => [
            'url' => env('TEME_DOKPLOY_URL') ?: 'http://dokploy:3000',
            'compose_id' => env('TEME_DOKPLOY_COMPOSE_ID'),
            'app_name' => env('TEME_DOKPLOY_APP_NAME'),
            'compose_hashes_url' => env('TEME_DOKPLOY_COMPOSE_HASHES_URL') ?: 'https://raw.githubusercontent.com/temecms/temecms-for-dokploy/main/compose-hashes.json',
        ],
    ],

    /*
     * Plugins and themes installed from the admin (I-211; docs/architecture/plugins.md).
     * path is the extensions folder, the installer's volume, unset where
     * there is none; composer is the binary the installer runs. An upload
     * may be upload_max bytes. packagist off stops installs by name from
     * Packagist; private_hosts lets a Git or vendor address point inside the
     * server's own network.
     */
    'extensions' => [
        'path' => env('TEME_EXTENSIONS_PATH'),
        'composer' => env('TEME_COMPOSER_BINARY', 'composer'),
        'upload_max' => (int) env('TEME_EXTENSION_UPLOAD_MAX', 33554432),
        'packagist' => (bool) filter_var(env('TEME_EXTENSIONS_PACKAGIST', true), FILTER_VALIDATE_BOOLEAN),
        'private_hosts' => (bool) filter_var(env('TEME_EXTENSIONS_ALLOW_PRIVATE_HOSTS', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
     * Backups (I-366). restic is a path, or a name looked up on the PATH;
     * the PostgreSQL folder holds pg_dump and pg_restore from one
     * installation, each looked up on the PATH when it is empty. restic
     * keeps its cache in cache_path, or none when that is empty.
     */
    'backups' => [
        'restic' => env('TEME_RESTIC_BINARY', 'restic'),
        'postgres_bindir' => env('TEME_POSTGRES_BINDIR', ''),
        'cache_path' => env('TEME_RESTIC_CACHE', storage_path('app/restic-cache')),
    ],

    /*
     * The fediverse (I-058). A site delivers to other servers only when it
     * runs in production, unless this is on: a staging or local copy sends
     * nothing (docs/plans/fediverse-publishing.md).
     */
    'fediverse' => [
        'deliver_outside_production' => (bool) env('TEME_FEDIVERSE_DELIVER_OUTSIDE_PRODUCTION', false),
    ],
];
