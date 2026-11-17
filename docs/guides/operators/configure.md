---
title: Configuring Teme
audience: operators
proved: tests
written_for: the settings Teme reads at its own tag
---

# Configuring Teme

Teme reads its settings from the environment. With the recipe, that is the `.env` file beside `compose.yml`: Compose reads it and hands it to every Teme role. This page lists every setting an operator may set, with what it does and what it is when you leave it out.

`.env.example` in the repository is for development. Never copy it to a server: it holds development defaults that a production site refuses to start with.

You need only two settings to start: `TEME_IMAGE` and `APP_URL`. Everything else has a default that works. Change a setting by editing `.env`, then run `docker compose up --detach`, which restarts the roles whose environment changed.

## The site

| Variable | Default | What it does |
|---|---|---|
| `APP_URL` | none (required) | The address readers type, with `https`. Teme builds every address from it, and refuses requests for another host. |
| `APP_KEY` | none (required) | The key that encrypts what Teme stores. The recipe reads it from `secrets/app_key` through `APP_KEY_FILE`, so you do not set `APP_KEY` itself. |
| `APP_PREVIOUS_KEYS` | empty | The keys Teme used before a deliberate key change, comma-separated, so it can still read what they encrypted. See [Restoring and moving](restore-and-moving.md). |
| `APP_ENV` | `production` in the image | Leave it as it is on a server. |

## The database

| Variable | Default | What it does |
|---|---|---|
| `DB_HOST` | `postgres` in the recipe | The PostgreSQL server's host. |
| `DB_PORT` | `5432` in the recipe | Its port. |
| `DB_DATABASE` | `teme` in the recipe | The database's name. |
| `DB_USERNAME` | `teme` in the recipe | The role Teme signs in as. It is never a superuser: see [The database](database.md). |
| `DB_PASSWORD` | none | The role's password. The recipe reads it from `secrets/db_password` through `DB_PASSWORD_FILE`. |
| `TEME_VERIFY_DATABASE` | `teme_verify` in the recipe | The spare database the weekly test restore uses. Its name must end in `_verify`. |
| `TEME_VERIFY_DB_HOST` | `DB_HOST` | The spare database's host, when it is not the same server. |
| `TEME_VERIFY_DB_PORT` | `DB_PORT` | Its port. |
| `TEME_VERIFY_DB_USERNAME` | `DB_USERNAME` | Its role. |
| `TEME_VERIFY_DB_PASSWORD` | `DB_PASSWORD` | Its password. |
| `PGCONNECT_TIMEOUT` | `3` in the recipe | How long, in seconds, a connection waits for PostgreSQL before it gives up, so a health check never holds a worker for minutes. |

## Secrets from files

A secret can come from a file instead of a variable. Name the file in the variable of the same name with `_FILE` added, and leave the variable itself unset. The recipe does this for four secrets; you can do it for any of these fifteen:

| Secret | Variable that names its file |
|---|---|
| The application key | `APP_KEY_FILE` |
| The previous keys | `APP_PREVIOUS_KEYS_FILE` |
| The database password | `DB_PASSWORD_FILE` |
| The Valkey password | `REDIS_PASSWORD_FILE` |
| The mail password | `MAIL_PASSWORD_FILE` |
| Cloudflare's API token | `CLOUDFLARE_API_TOKEN_FILE` |
| The Anthropic key | `TEME_AI_ANTHROPIC_KEY_FILE` |
| The OpenAI key | `TEME_AI_OPENAI_KEY_FILE` |
| The DeepSeek key | `TEME_AI_DEEPSEEK_KEY_FILE` |
| The OpenRouter key | `TEME_AI_OPENROUTER_KEY_FILE` |
| The backup machine key | `TEME_BACKUP_MACHINE_KEY_FILE` |
| The spare database's password | `TEME_VERIFY_DB_PASSWORD_FILE` |
| The GitHub token | `TEME_GITHUB_TOKEN_FILE` |
| The Dokploy API key | `TEME_DOKPLOY_TOKEN_FILE` |
| The media bucket's secret key | `MEDIA_BUCKET_SECRET_ACCESS_KEY_FILE` |

Teme refuses to start, and says why, in two cases:

- Both the variable and its `_FILE` twin are set. Set one or the other.
- The file the `_FILE` variable names cannot be read.

Either way the message names the variable and the file's path, and never prints a secret's value.

## Media storage

Where the media files live, and the addresses they are served at. Give every role the same values: a worker writing images to the server while `web` reads them from a bucket breaks every image. Read [Media in object storage](object-storage.md) before you change `MEDIA_STORAGE` on a site that has media.

| Variable | Default | What it does |
|---|---|---|
| `MEDIA_STORAGE` | `server` | `server` keeps media in `storage/app` on the server; `bucket` keeps it in the two buckets below. |
| `MEDIA_URL` | `APP_URL` and `/storage/media` | The address the server's media are served at, for a pull CDN in front of the site, such as `https://media.example.com`. |
| `MEDIA_BUCKET` | none | The public bucket: image sizes and files that aren't images. |
| `MEDIA_ORIGINALS_BUCKET` | none | The private bucket: image originals. Never make it public. |
| `MEDIA_BUCKET_URL` | none | The CDN or public address in front of the public bucket. Required with `MEDIA_STORAGE=bucket`. |
| `MEDIA_BUCKET_ENDPOINT` | AWS | The provider's S3 address, such as `https://<account>.r2.cloudflarestorage.com`. |
| `MEDIA_BUCKET_REGION` | `us-east-1` | The bucket's region. Cloudflare R2 takes `auto`. |
| `MEDIA_BUCKET_PATH_STYLE` | `false` | `true` puts the bucket in the address's path instead of its host, as MinIO needs. |
| `MEDIA_BUCKET_ROOT` and `MEDIA_ORIGINALS_BUCKET_ROOT` | none | A folder inside each bucket to keep media in. |
| `MEDIA_BUCKET_ACCESS_KEY_ID` | none | The access key's ID, for both buckets. |
| `MEDIA_BUCKET_SECRET_ACCESS_KEY` | none | The access key's secret, for both buckets. Can come from a file. |
| `MEDIA_BUCKET_ACL` | none | `public-read` only for a provider that serves an object only when the object itself is public, such as DigitalOcean Spaces. Never applies to originals. |
| `MEDIA_DISK` and `MEDIA_ORIGINALS_DISK` | from `MEDIA_STORAGE` | One role's disk, overriding `MEDIA_STORAGE`: `MEDIA_ORIGINALS_DISK=media-originals` keeps originals on the server while the rest is in the bucket. |

## The edge

| Variable | Default | What it does |
|---|---|---|
| `TRUSTED_PROXIES` | `private` in production, nothing elsewhere | The proxies whose forwarded headers Teme believes. See [Proxies and HTTPS](proxies-and-https.md). |
| `TEME_HSTS` | `max-age=31536000` | The `Strict-Transport-Security` value Teme sends over https. Empty sends none. |

## The page cache

| Variable | Default | What it does |
|---|---|---|
| `PAGE_CACHE_ENABLED` | on, except in development | Turns the full-page cache on or off. |
| `PAGE_CACHE_DRIVER` | `static` in the recipe | Where pages are kept: `static` (files that nginx serves without PHP), `file`, or `redis` (Valkey, for several servers; start the recipe with `docker compose --profile valkey up --detach`). |
| `PAGE_CACHE_LIFETIME` | `3600` | The longest a page lives, in seconds. A change purges it sooner. |
| `PAGE_CACHE_WARM` | `true` | Whether a deploy warms the cache afterwards. |
| `PAGE_CACHE_WARM_PAGES` | `20` | How many pages it warms. |
| `PAGE_CACHE_CDN` | none | Set it to `cloudflare` to purge Cloudflare's copies. See [Cloudflare in front of Teme](cloudflare.md). |

## Updates

Set `TEME_UPDATES_CHECK` and `TEME_UPDATES_CHANNEL` the same on every role: `web` shows the banner and Tools › Updates from them. Only the token belongs to the scheduler alone.

| Variable | Default | What it does |
|---|---|---|
| `TEME_UPDATES_CHECK` | on | Whether Teme asks GitHub once an hour for its releases. It sends nothing about this site. `false` turns it off. |
| `TEME_GITHUB_TOKEN` | none | Optional. A fine-grained GitHub token with Contents: read on Teme's repository alone; it only raises GitHub's limit on requests. Give it to the scheduler only, never to `web` or the workers: the recipe reads it from `secrets/github_token` in the scheduler alone. |
| `TEME_UPDATES_CHANNEL` | follows the version this site runs | `stable` for releases only, `prerelease` for every release. |
| `TEME_UPDATER_PLATFORM` | none | `dokploy`. The updater only: the Dokploy file sets it on the `updater` service. |
| `TEME_IMAGE_REPOSITORY` | none | `ghcr.io/temecms/teme`, the image without its tag. The Dokploy file's, on the Environment tab. |
| `TEME_IMAGE` and `TEME_UPDATER_IMAGE` | none | The image with its exact tag, for every service but the updater, and for the updater. The Dokploy file's, on the Environment tab: the updater sets them when it updates. |
| `TEME_DOKPLOY_URL` | `http://dokploy:3000` | Dokploy's address, on Dokploy's own network. The updater only. |
| `TEME_DOKPLOY_TOKEN` | none | A Dokploy API key, set on the server. It wins over the key pasted in Settings › Updates. The updater only. |
| `TEME_DOKPLOY_APP_NAME` | the Compose project's name | The Compose service's App Name, which the updater finds the service by. The updater only. |
| `TEME_DOKPLOY_COMPOSE_ID` | none | The Compose service's ID in Dokploy. When set, the updater uses it instead of the App Name. The updater only. |
| `TEME_DOKPLOY_COMPOSE_HASHES_URL` | the Dokploy template's `compose-hashes.json` | Where the updater reads the list of compose files the Dokploy template has released: an `https://` address on a public name, never an IP address or a private one. Set on the server only. It replaces the service's compose file only when the file is in that list. The updater only. |
| `TEME_UPDATER_DEPLOY_TIMEOUT` | `3600` | Seconds the updater waits for the deploy to finish. The updater only. |
| `TEME_UPDATER_PART_TIMEOUT` | `900` | Seconds the updater waits for each later step. The updater only. |

## Sessions and mail

| Variable | Default | What it does |
|---|---|---|
| `SESSION_DRIVER` | `database` | Leave it as `database` in production: a site that keeps sessions anywhere else refuses to start, because ending a person's sessions must end every one of them. |
| `MAIL_MAILER` | `log` | How Teme sends mail. `log` writes mail to the log instead of sending it, so set a real mailer in production: the doctor warns you if you do not, and backups mail their alerts. |

## AI

| Variable | Default | What it does |
|---|---|---|
| `TEME_AI` | on | Set it to `off` to remove every AI feature. Anything that is not a yes (`on`, `true`, `1`, `yes`) means off. |
| `TEME_AI_ANTHROPIC_KEY` | none | An AI service's key, set here instead of in **Settings › AI**. |
| `TEME_AI_OPENAI_KEY` | none | The same, for OpenAI. |
| `TEME_AI_DEEPSEEK_KEY` | none | The same, for DeepSeek. |
| `TEME_AI_OPENROUTER_KEY` | none | The same, for OpenRouter. |

A key set here replaces the stored one, and nobody can change it in the admin.

## Site code

| Variable | Default | What it does |
|---|---|---|
| `TEME_DISALLOW_SITE_CODE` | `false` | Set it to `true` on a host that forbids code added from the admin: no page draws the entries in **Settings › Site code**, and nobody can change them. A deploy clears the page cache, so you need only deploy after changing it. |
| `TEME_PUBLIC_CSP` | `report-only` | The public site's content security policy. `report-only` blocks nothing and sends what it would block to the site, which `php artisan teme:csp:reports` lists; `enforce` blocks it; `off` sends no policy at all, as an escape hatch. A deploy clears the page cache, so you need only deploy after changing it. |

## Plugins

Adding a plugin to the site's build is itself an act of trust. Teme starts a plugin only after an administrator enables it, but Composer may run files that the package or its dependencies declare from the moment it is installed, enabled or not, and Teme can't prevent that. Add only plugins you trust, and prefer ones that declare no such files.

## Plugins and themes from the admin

The `installer` service carries out what an administrator asks for from the admin; [Plugins and themes installed from the admin](extensions.md) explains it. The recipe sets the first two variables.

| Variable | Default | What it does |
|---|---|---|
| `TEME_EXTENSIONS_PATH` | none | The extensions folder, `/var/teme/extensions` in the recipe. Unset, nothing is installed from the admin and nothing is loaded from the folder. Set it the same on every role. |
| `TEME_EXTENSIONS_ROLE` | none | `installer` on the installer service alone: it then loads no installed plugin's code itself. |
| `TEME_COMPOSER_BINARY` | `composer` | The Composer the installer runs: a path, or a name looked up on the PATH. |
| `TEME_EXTENSION_UPLOAD_MAX` | `33554432` | The largest package an administrator may upload, in bytes. |
| `TEME_EXTENSIONS_PACKAGIST` | `true` | `false` stops installs by name from Packagist; packages then come only from the sources an administrator adds. |
| `TEME_EXTENSIONS_ALLOW_PRIVATE_HOSTS` | `false` | `true` lets a Git or vendor source's address point inside the server's own network. |

## Passwords

| Variable | Default | What it does |
|---|---|---|
| `ARGON_MEMORY` | `65536` | Memory one password hash uses, in KiB. |
| `ARGON_TIME` | `4` | Passes of the hash. |
| `ARGON_THREADS` | `1` | Threads one hash uses. |
| `AUTH_TIMEBOX_DURATION` | `1100000` | The least time a sign-in takes to answer, in microseconds, so a wrong address and a wrong password take as long. |

How to choose them is on [Sizing](sizing.md).

## The queues

| Variable | Default | What it does |
|---|---|---|
| `DB_QUEUE_LONG_RETRY_AFTER` | `360` | The seconds, past a long job's own timeout, after which the long queue offers the job to another worker. The recipe's `worker-long` role works that queue, and nothing in the recipe changes this value. |

## Backups and the spare database

| Variable | Default | What it does |
|---|---|---|
| `TEME_RESTIC_BINARY` | `restic` | Where the `restic` program is, when it is not on the path. |
| `TEME_POSTGRES_BINDIR` | empty | The folder holding PostgreSQL's command-line tools, when they are not on the path. |
| `TEME_BACKUP_MACHINE_KEY` | none | The key scheduled backups use, when the server supplies it. Set it from a file with `TEME_BACKUP_MACHINE_KEY_FILE`. |
| `TEME_RESTIC_CACHE` | `storage/app/restic-cache` | The folder `restic` caches in. Empty runs it without a cache. |

The image installs `restic` and PostgreSQL's tools and sets neither of the first two, so they are for a build that keeps the tools elsewhere. The spare database's own settings are in [The database](#the-database) above, and [Backups](backups.md) says how backups use them.

## The image's own settings

The image is built on a PHP and nginx base that has its own settings. These are the ones you may need.

| Variable | Default | What it does |
|---|---|---|
| `PHP_FPM_PM_MAX_CHILDREN` | `10` in the recipe | How many PHP workers the `web` role runs at once. |
| `NGINX_CLIENT_MAX_BODY_SIZE` | `100M` | The largest request nginx accepts. |
| `PHP_UPLOAD_MAX_FILE_SIZE` | `100M` | The largest file PHP accepts. |
| `PHP_POST_MAX_SIZE` | `100M` | The largest request body PHP accepts. |

Raise the three size limits together, and to the same value, when you raise **Largest upload** in the admin. The smallest of them wins.

## The recipe's own variables

The recipe sets these for every role, and you do not normally change them. They are here so that nothing in `docker/compose.yml` is a mystery.

| Variable | What it is for |
|---|---|
| `TEME_IMAGE` | The image and its exact tag. |
| `APP_URL` | The site's address, as above. |
| `PAGE_CACHE_DRIVER` | The page cache's driver, as above. |
| `PHP_FPM_PM_MAX_CHILDREN` | The `web` role's worker count, as above. |
| `DB_HOST` | The database's host: the recipe's own `postgres`. |
| `DB_PORT` | Its port. |
| `DB_DATABASE` | The database's name. |
| `DB_USERNAME` | The role's name. |
| `DB_PASSWORD_FILE` | The file holding the role's password. |
| `APP_KEY_FILE` | The file holding the application key. |
| `TEME_VERIFY_DATABASE` | The spare database's name. |
| `PGCONNECT_TIMEOUT` | The connection timeout, as above. |
| `REDIS_HOST` | Where Valkey is, when the `valkey` profile is on. |
| `POSTGRES_PASSWORD_FILE` | The file holding PostgreSQL's own superuser password, used only by the `postgres` service to make its cluster. |
