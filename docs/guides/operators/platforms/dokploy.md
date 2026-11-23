---
title: Running Teme on Dokploy
audience: operators
proved: tests
written_for: Dokploy v0.31.2
---

# Running Teme on Dokploy

This page is written for Dokploy v0.31.2. Teme runs on Dokploy as one Compose service with its own PostgreSQL. Dokploy puts Traefik in front, so you need no proxy of your own, and the image `ghcr.io/temecms/teme` is public, so Dokploy needs no registry sign-in.

## Install

Teme's Dokploy template lives in its own repository, [temecms/temecms-for-dokploy](https://github.com/temecms/temecms-for-dokploy). Its README gives the steps for every way Dokploy can make a service. The quickest is the template list:

1. In your Dokploy project, choose **Create Service**, then **Template**.
2. In **Base URL**, paste `https://raw.githubusercontent.com/temecms/temecms-for-dokploy/main`.
3. Choose **TemeCMS**, then **Create**, then **Deploy**. The template fills every setting.
4. Open **Logs**, choose the `deploy` container, and open the setup page's address its log ends with. Enter the setup code and make the first account.

The same repository holds the compose file for pasting as Raw or reading from Git, and says how to use your own domain and how to move an existing install to the template.

## What the file starts

| Service | Command | Stops within |
|---|---|---|
| `teme-postgres` | PostgreSQL 18, with its own volume | 1 minute |
| `teme-db-setup` | makes the `teme_app` login and both databases, then exits | n/a |
| `deploy` | `php artisan teme:deploy` | n/a: it runs once and exits |
| `web` | the image's own command; its health check asks `curl -f http://localhost:8080/up` | n/a |
| `worker` | `php artisan queue:work --tries=3 --max-time=3600 --memory=512` | 6 minutes |
| `worker-long` | `php artisan queue:work database-long --queue=long --tries=3 --max-time=3600 --memory=512` | 6 minutes |
| `scheduler` | `php artisan schedule:work` | 30 minutes |
| `installer` | `php artisan teme:installer`, as its own user | 2 minutes |
| `updater` | `php artisan teme:updater` | 1 minute |

Each step waits for the one before it: the database is healthy, then `teme-db-setup`, then `deploy`, then the rest. `updater` waits for `deploy` the first time; an update never replaces it midway.

Everything on the service's **Environment** tab reaches `deploy`, `web`, the workers and the scheduler: [Configuring Teme](../configure.md) lists them, such as mail and `TRUSTED_PROXIES`.

The plugin installer and the updater never read the **Environment** tab. Each has only the site's address, its app key, its database login and its own settings. Because both hold `APP_KEY`, both could decrypt what Teme keeps encrypted, the Dokploy API key included: the installer needs the app key to open a key saved for a private plugin source, and the updater to open the Dokploy key. Composer, which the installer runs, gets none of these: Teme starts it with none of the site's secrets.

## Switch on Update from the admin

1. **Make a key in Dokploy.** Open your profile settings in Dokploy, find **API/CLI**, and generate a key. Copy it.
2. **Paste it into Teme.** In Teme's admin, open **Settings › Updates**, paste the key into **Dokploy API key**, and save. Teme asks for your password again. The key is kept encrypted and never shown again.
3. **Check it.** While no update runs, the updater tries the key every two minutes. If it can't use it, Settings › Updates says why in one sentence, and what to do.

The updater finds this service by its App Name, `TEME_DOKPLOY_APP_NAME`, and reaches Dokploy at `http://dokploy:3000` on Dokploy's own network. To reach Dokploy at another address, set `TEME_DOKPLOY_URL` on the **Environment** tab to its `https://` address.

A key set on the server wins over the one pasted in the admin: if the **Environment** tab holds `TEME_DOKPLOY_TOKEN`, Teme uses it, and Settings › Updates says the server sets it. `TEME_DOKPLOY_COMPOSE_ID` there names the service instead of its App Name.

### The key

The key can do anything your Dokploy user can do, on every project that user reaches. A key for a Dokploy member with access to this service alone is enough, with these permissions on it: read the service, create services (to replace its compose file), write its environment variables, and create and read its deployments. Teme sends Dokploy only image names it builds from `TEME_IMAGE_REPOSITORY` and a release's version, and only compose files the template has released. Keep Dokploy up to date.

Dokploy keeps the service's environment as one text, so to change `TEME_IMAGE` the updater reads the whole text, every password in it included, changes that one value and saves it back. Don't edit the **Environment** tab while an update runs.

## Updating from the admin

**Tools › Updates › Update to …** asks for the update. The updater sets `TEME_IMAGE` on this service, only that line.

It also brings the template's newest compose file when the service's file is an older one. The template repository publishes [`compose-hashes.json`](https://github.com/temecms/temecms-for-dokploy/blob/main/compose-hashes.json), listing every compose file it has released. The updater replaces the service's file, in the same step, only when its hash is in that list. A file edited on the server stops the update before anything changes, and Tools › Updates says to paste the new template first. So does a list the updater can't read. A service that reads its compose file from Git keeps it: Dokploy's own Pull brings the new one. To read the list from somewhere else, set `TEME_DOKPLOY_COMPOSE_HASHES_URL` to its `https://` address.

Then the updater deploys. `deploy` backs up and migrates, then the rest start on the new release. The updater waits for the site to answer with the new version and every part to be healthy, then asks for itself on the new release. If anything isn't healthy, it puts back the old image and the old compose file and deploys again.

### The short outage

While the service deploys, the site is down. Compose stops every old Teme container before it starts the new ones, and each may take its time to stop (see [What the file starts](#what-the-file-starts)): up to 6 minutes for a busy worker, 30 for the scheduler during a backup. Then `deploy` runs (longer when a migration waits, because it backs up first), then the new `web` starts. To keep this short, the updater waits until no backup or job is running before it deploys, for up to 15 minutes. Updating by hand does the same without that wait, so pick a quiet moment.

### If the updater couldn't restart itself

When Tools › Updates says the updater couldn't be asked to restart, or refuses a request because the updater runs an older Teme than the site, set `TEME_UPDATER_IMAGE` to the same image as `TEME_IMAGE` and choose **Deploy**. Until then the updater refuses every request and changes nothing.

### Updating by hand

Set `TEME_IMAGE` and `TEME_UPDATER_IMAGE` to the new release on the **Environment** tab and choose **Deploy**. When the template's compose file has changed, paste the new one on the **General** tab first.

## Don't use Dokploy's scheduled jobs

Teme's schedule belongs to Teme: the `scheduler` service runs it. Putting it in Dokploy's scheduled jobs moves it into the panel, where a restore or a move would lose it.

## During a restore

While a restore runs, `/up` fails on purpose. Dokploy may replace `web` for failing its health check. That is harmless: the `web` role keeps no state.

## Platform backups of the database

Dokploy can back up its own databases to a bucket, but it does not see the database inside this service. Use Teme's own backups. [Backups](../backups.md) says what they hold.

## Good to know

- `DB_HOST` is a host name, never a connection address with `://`.
- The database service is called `teme-postgres`, not `postgres`, so `web`, which also joins Dokploy's network, never reaches another project's database by its name.
- Dokploy adds `web` to its own network when you add a domain on the **Domains** tab; you don't add the network by hand.
