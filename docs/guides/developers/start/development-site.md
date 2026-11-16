---
title: Set up a development site
type: how-to
audience: both
summary: One local Teme site that loads every plugin and theme you are building, straight from their own folders.
rests_on: [config/teme.php, docker-compose.yml, .env.example]
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

5. Serve the site (Herd serves the folder for you; otherwise `php artisan serve`). Run `php artisan teme:setup-code`, open the address it prints, enter the code, and fill in the form: you are the site's first administrator.

6. Enable your plugin: Plugins in the admin, or

   ```bash
   php artisan teme:plugins:enable your/package --agree
   ```

   Check your theme with `php artisan teme:theme:check your-theme-code`, then choose it under Site › Themes.

## Tests

Your plugin's tests run inside this copy of Teme: see [Testing a plugin or theme](../testing.md). The tests use their own database, which `docker compose up -d` already started beside the site's.

## Keeping it current

Pull Teme's `main` branch, or check out a release tag, then run `composer install`, `npm ci && npm run build` and `php artisan migrate`. Your folders aren't touched.
