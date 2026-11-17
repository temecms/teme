---
title: Set up a development site
type: how-to
audience: both
summary: One local Teme site that loads every plugin and theme you are building, straight from their own folders.
rests_on: [config/teme.php, docker-compose.yml, .env.example, app/Domain/Plugins/PluginRegistry.php, app/Domain/Updates/TemeVersion.php, docs/reference/contracts.md]
---

# Set up a development site

You build a plugin or theme in its own folder or repository, and test it against one local copy of Teme. One copy is enough for everything you build: it loads as many plugin and theme folders as you list.

## What you need

- PHP 8.4 with the `pgsql`, `intl` and `redis` extensions, and Composer. Laravel Herd provides these on macOS and Windows; Laravel Sail, DDEV or your system's packages work too.
- Node.js 24 and npm, to build the admin.
- Docker, for the database and the cache. Teme runs on PostgreSQL 18 only.

## Steps

1. Get Teme and install it:

   ```bash
   git clone https://github.com/temecms/teme.git
   ```

   ```bash
   cd teme && composer install && npm ci && npm run build
   ```

2. Start PostgreSQL and Valkey from Teme's own `docker-compose.yml`:

   ```bash
   docker compose up -d
   ```

   If ports 5432, 5433 or 6380 are already in use, set `TEME_DB_PORT`, `TEME_TEST_DB_PORT` and `TEME_VALKEY_TEST_PORT` before that command.
   The test suite reads the same `TEME_TEST_DB_PORT` and `TEME_VALKEY_TEST_PORT` for `postgres_test` and `valkey_test`.

3. Make the settings file and its key, then the database:

   ```bash
   cp .env.example .env && php artisan key:generate && php artisan migrate
   ```

4. Point Teme at your work. In `.env`, list your folders, separated by commas. Each plugin folder holds its `teme-plugin.json`; each theme folder its `teme-theme.json`.

   ```dotenv
   TEME_PLUGIN_PATHS=../my-plugin,../my-other-plugin
   TEME_THEME_PATHS=../my-theme
   ```

   Then tell the site which Teme it is. A copy of `main` that isn't a tagged release reports Teme 0.1.0, which no plugin's `requires.teme` accepts. The [contracts reference](../../../reference/contracts.md) says in its first lines the newest version this copy's contracts offer; put that in `.env`, as `TEME_VERSION=1.0.0` for one at 1.0.0. A tagged release needs nothing.

5. Serve the site (Herd serves the folder for you; otherwise `php artisan serve`). Run `php artisan teme:setup-code`, open the address it prints, enter the code, and fill in the form: you are the site's first administrator.

6. Start the scheduler and a queue worker, each in its own terminal, and leave them running while you work. Without them a plugin's jobs and schedules never run, and `teme:doctor` says the scheduler has never run:

   ```bash
   php artisan schedule:work
   ```

   ```bash
   php artisan queue:work
   ```

7. Enable your plugin: Plugins in the admin, or

   ```bash
   php artisan teme:plugins:enable your/package --agree
   ```

   A plugin with schedules is enabled from the console only with the administrator its scheduled work runs as: add `--as=admin@example.com`; see [Jobs and schedules](../plugins/jobs.md#schedules).

   Check your theme with `php artisan teme:theme:check your-theme-code`, then choose it under Site › Themes.

## Your plugin through Composer

A folder in `TEME_PLUGIN_PATHS` is the quickest way to work. To try your plugin as a site will install it, with Composer, add your folder to the site's `composer.json` as a path repository, symlinked so your edits show at once:

```json
"repositories": [{ "type": "path", "url": "../my-plugin", "options": { "symlink": true } }]
```

Then require it at its development version, `composer require your/package:@dev`, and take its folder out of `TEME_PLUGIN_PATHS`. Teme finds a package Composer installed with the type `teme-plugin` with no path of its own.

The version Teme shows and records is the `version` in `teme-plugin.json`. Leave `version` out of your plugin's `composer.json` when the plugin has its own repository, so Composer takes versions from your tags.

## Tests

Your plugin's tests run inside this copy of Teme: see [Testing a plugin or theme](../testing.md). The tests use their own database, which `docker compose up -d` already started beside the site's.

## Keeping it current

Pull Teme's `main` branch, or check out a release tag, then run `composer install`, `npm ci && npm run build` and `php artisan migrate`. Your folders aren't touched.
