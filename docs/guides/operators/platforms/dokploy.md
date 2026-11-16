---
title: Running Teme on Dokploy
audience: operators
proved: by hand on Dokploy v0.31.0, 2026-11-08
written_for: Dokploy v0.31.0
---

# Running Teme on Dokploy

This page was written for Dokploy v0.31.0 and proved by hand on it on 8 November 2026: Teme was installed from the steps below, on a real server, and ran.

Teme runs on Dokploy as one Compose service. The file is `docker/compose.dokploy.yml` in the repository. It is [the recipe](../install.md) with two changes: the passwords come from the service's Environment tab instead of secret files, and a `teme-db-setup` service makes Teme's limited database login on every deploy, where the recipe uses an initdb script. Dokploy puts Traefik in front, so you need no proxy of your own.

## What the file starts

| Service | Command | Stops within |
|---|---|---|
| `teme-postgres` | PostgreSQL 18, with its own volume | 1 minute |
| `teme-db-setup` | makes the `teme_app` login and both databases, then exits | n/a |
| `deploy` | `php artisan teme:deploy` | n/a: it runs once and exits |
| `web` | the image's own command; its health check asks `curl -f http://localhost:8080/up` | n/a |
| `worker` | `php artisan queue:work --tries=3 --max-time=3600` | 6 minutes |
| `worker-long` | `php artisan queue:work database-long --queue=long --tries=3 --max-time=3600` | 6 minutes |
| `scheduler` | `php artisan schedule:work` | 30 minutes |
| `updater` | `php artisan teme:updater` | 1 minute |

Each step waits for the one before it: the database is healthy, then `teme-db-setup`, then `deploy`, then the rest. `updater` waits for `deploy` the first time; an update never replaces it midway. `media` and `page-cache` are volumes every Teme service shares.

## Steps

1. **Add the registry.** While the images are private, open Settings, then Registry, and add `ghcr.io` with a GitHub user name and a token that can read packages. Skip this once the images are public.
2. **Make the service.** Choose Create Service, then Compose. Set the provider to Raw and the Compose type to Docker Compose, and paste the whole of `docker/compose.dokploy.yml`.
3. **Fill the Environment tab.**
   - `TEME_IMAGE`: the image with its exact tag, never `latest`.
   - `TEME_UPDATER_IMAGE`: the same image and tag as `TEME_IMAGE`.
   - `TEME_IMAGE_REPOSITORY`: `ghcr.io/temecms/teme`, the image without its tag.
   - `APP_URL`: your site's address, with `https://`.
   - `APP_KEY`: make it once and keep it. On Linux or macOS: `echo "base64:$(openssl rand -base64 32)"`. On Windows PowerShell: `$b = New-Object byte[] 32; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b); "base64:" + [Convert]::ToBase64String($b)`.
   - `DB_PASSWORD` and `POSTGRES_PASSWORD`: two long passwords of letters and digits only. A `$` in a value is rewritten by Compose.
   - `TEME_GITHUB_TOKEN` (optional): a read-only token that raises GitHub's limit when the scheduler asks for new releases. [Configuring Teme](../configure.md) lists the other settings. The updater's three Dokploy settings are in [Updating from the admin](#updating-from-the-admin).
4. **Set the domain.** In Domains, add your site's address with the service `web`, port `8080`, and HTTPS on.
5. **Deploy.**
6. **Make the first administrator.** The `deploy` container's log (the service's Logs, `deploy`) ends with the setup page's address and a setup code: open the address, enter the code and fill in the form. Or open the Terminal for the `web` container. It opens in `/`, not in Teme's folder, so run `cd /var/www/html` first, then `php artisan teme:user:create --email=you@example.com --name="Your Name" --role=administrator`. Open the link it prints and sign in.

## Updating from the admin

Updating from the admin has not been tested on a real Dokploy server.

### What it does

Tools › Updates › **Update to …** asks for the update. The updater sets `TEME_IMAGE` on this service, only that line, and deploys it. `deploy` backs up and migrates, then the rest start on the new release. The updater waits for the site to answer with the new version and every part to be healthy, then asks for itself on the new release. If anything isn't healthy it sets `TEME_IMAGE` back and deploys again.

### The short outage

While the service deploys, the site is down. Compose stops every old Teme container before it starts the new ones, and each may take its grace period to finish what it is doing: up to 6 minutes for a busy worker, 30 for the scheduler during a backup. Then `deploy` runs (longer when a migration waits, because it backs up first), then the new `web` starts. To keep this short, the updater waits until no backup or job is running before it deploys, for up to 15 minutes. Updating by hand does the same without that wait, so pick a quiet moment.

### If the updater couldn't restart itself

When Tools › Updates says the updater couldn't be asked to restart, or refuses a request because the updater runs an older Teme than the site, set `TEME_UPDATER_IMAGE` to the same image as `TEME_IMAGE` and Deploy. Until then the updater refuses every request, and changes nothing.

### Its settings

On this service's Environment tab:

- `TEME_DOKPLOY_URL`: the panel's `https://` address. If the panel has none, add `dokploy-network` to the updater's `networks` in the file and use `http://dokploy:3000`.
- `TEME_DOKPLOY_COMPOSE_ID`: in the service's address in the panel, after `/services/compose/`.
- `TEME_DOKPLOY_TOKEN`: a Dokploy API key.

### The token

This is your own Dokploy token. It can do anything you can do in Dokploy, on every project on this server, the WordPress sites included. Only the `updater` service may name it; the file is written so. Keep Dokploy up to date: its 2026 advisories let a Dokploy user reach the server's root. Teme sends Dokploy only image names it builds from `TEME_IMAGE_REPOSITORY` and a release's version.

Dokploy keeps this service's environment as one text, so to change `TEME_IMAGE` the updater reads the whole text, every password in it included, changes that one value and saves it back. Don't edit the Environment tab while an update runs.

### A narrower key

A Dokploy Member's key limited to this one service, with read on the service, write on its environment variables, and create and read on its deployments, is enough.

## Do not use Dokploy's scheduled jobs

Teme's schedule belongs to Teme: the `scheduler` service runs it. Putting it in Dokploy's scheduled jobs moves it into the panel, where a restore or a move would lose it.

## During a restore

While a restore runs, `/up` fails on purpose. Dokploy may replace `web` for failing its health check. That is harmless: the `web` role keeps no state.

## Platform backups of the database

Dokploy can back up its own databases to a bucket, but it does not see the database inside this service. Use Teme's own backups. [Backups](../backups.md) says what they hold.

## Advanced: separate Applications

You can instead make each role its own Dokploy Application from the same image and tag, with the commands in the table above, a shared `media` and `page-cache` volume, and a database of your own made as [The database](../database.md) describes. This has not been proved. The single Compose service is the way to install.

## Dokploy notes

- `DB_HOST` is a host name, never a connection URL.
- Only `web` joins `dokploy-network`, and service names must be unique. A `postgres` elsewhere on that network was reached by mistake, which is why the database here is called `teme-postgres`.
- Compose's `configs.content` did not run on Dokploy, so the database setup is a service, `teme-db-setup`, not a config file.
- To update by hand, set `TEME_IMAGE` and `TEME_UPDATER_IMAGE` to the new tag and Deploy. To update from the admin, see [Updating from the admin](#updating-from-the-admin).
