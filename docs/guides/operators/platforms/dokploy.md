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

## Install as one Application

Teme can run as a single Dokploy Application. Web, both queue workers, the scheduler, the plugin installer and the updater share one container, and the database is a PostgreSQL service that Dokploy runs for you. Use it when you want one thing to look after, or when you want Dokploy to build Teme from the repository.

| You give Dokploy | Source | Updating from the admin |
|---|---|---|
| The image `ghcr.io/temecms/teme` and a tag | Docker | The admin's **Update** changes the tag and redeploys. |
| The repository, which has a `Dockerfile` | Git (GitHub, GitLab, Bitbucket, Gitea or any Git address) | Dokploy rebuilds from the source: change the branch or tag, then press **Deploy**. |
| A ZIP of the repository | Drop | Dokploy rebuilds from the source: upload the new ZIP, then press **Deploy**. |

A build from Git or a ZIP compiles Teme's PHP packages and front end, which takes several minutes and about 4 GB of memory on the server.

### Steps

1. **Make the database.** In your project choose Create Service, then Database, then PostgreSQL. Give it a name, a database name such as `teme`, a user name, a long password, and the Docker image `postgres:18`. Deploy it. Its page shows the **Internal Host**, the **Internal Port** and the **Internal Connection URL**. Dokploy's user is a PostgreSQL superuser. Teme uses that login on each start only to make a limited login of its own, and every part of Teme then runs as the limited one: [The database](../database.md) says what it makes.
2. **Make the Application.** Choose Create Service, then Application, and name it. On its General tab, note its **App Name** (it looks like `app-teme-x1y2z3`): the updater finds the Application by it.
3. **Set its source.** On the General tab, set the provider from the table above. For Docker, give the image with its exact tag, such as `ghcr.io/temecms/teme:0.8.0-alpha.1`, never `latest`; add a registry login under Settings, then Registry, while the image is private. For Git or a ZIP, set the build type to Dockerfile, with `Dockerfile` as its path and `.` as its context. The build leaves out every `.env` file, so what you type below never enters the image.
4. **Fill the Environment tab.**
   - `TEME_ROLE`: `all`. This is the one switch that makes the container run every role. Without it the container runs the web server alone.
   - `APP_URL`: your site's address, with `https://`.
   - `APP_KEY`: make it once and keep it. On Linux or macOS: `echo "base64:$(openssl rand -base64 32)"`. On Windows PowerShell: `$b = New-Object byte[] 32; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b); "base64:" + [Convert]::ToBase64String($b)`. If it is missing, the container stops at once and says how to make one.
   - The database, in one of two forms. Either `DATABASE_URL`, set to the **Internal Connection URL** exactly as Dokploy shows it, or the five settings `DB_HOST` (the **Internal Host**, a name and nothing else), `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`. A `DB_HOST` that holds a whole address stops the container, without printing it.
   - For updates from the admin: `TEME_DOKPLOY_URL`, the panel's `https://` address, and `TEME_DOKPLOY_APP_NAME`, the App Name from step 2.
   - `TEME_IMAGE_REPOSITORY` only if you run the image from a copy of your own: `ghcr.io/temecms/teme` is assumed. [Configuring Teme](../configure.md) lists the other settings.
5. **Mount one volume.** On the Advanced tab, under Volumes, add a Volume Mount. Name it for the site, such as `blog-data`, and set its mount path to `/var/teme`. That one volume holds the media, the page cache, and the plugins and themes you install from the admin. Without it they are lost whenever the container is replaced, and the container's log says so on every start.
6. **Give it time to stop.** On the Advanced tab, under Cluster Settings, then Swarm Settings, set the **Stop Grace Period** to `360000000000` (six minutes, in nanoseconds) so a worker can finish its job. Leave Update Config, Rollback Config and Health Check empty. Dokploy then starts a new container before it stops the old one, and puts the old one back when the new one does not become healthy; Teme's image supplies its own health check.
7. **Set the domain.** Under Domains, add your site's address with the port `8080` and HTTPS on.
8. **Deploy.** The Logs tab shows the start: the database step, then the deploy, which ends with the setup page's address and a setup code. Open the address, enter the code and fill in the form. Or open the Application's Terminal and run `artisan teme:user:create --email=you@example.com --name="Your Name" --role=administrator`, then open the link it prints.
9. **Let the admin update it.** Skip this if you update by hand. In Dokploy open Settings, then Profile, then API/CLI Keys, and generate a key. In Teme open Settings › Updates and paste it there. The key can do what its user can do in Dokploy, so make it for a Dokploy user who has access to this Application alone, with the right to change and deploy it.

### What runs in the container

| Process | Runs as | What it does |
|---|---|---|
| the deploy step | `www-data` | Makes Teme's database login, caches the configuration, and runs `php artisan teme:deploy`, which backs up and migrates under a lock. The web server starts only once it has ended well. |
| the installer's check | `teme-installer` | Checks the installed plugins and themes against this release before the site starts. |
| nginx and PHP-FPM | `root` (their masters), `nginx` and `www-data` (their workers) | The web server. |
| `php artisan queue:work --tries=3 --max-time=3600` | `www-data` | The default queue. |
| `php artisan queue:work database-long --queue=long --tries=3 --max-time=3600` | `www-data` | The long queue. |
| `php artisan schedule:work` | `www-data` | The scheduler. |
| `php artisan teme:installer` | `teme-installer` | Installs, updates and removes the plugins and themes asked for from the admin. It alone can write `/var/teme/extensions`. |
| `php artisan teme:updater` | `www-data` | Carries out an update asked for from the admin. |

The installer runs as its own user, with an environment of only what it needs: the database login Teme runs as, the application key and the extensions folder. It never has the Dokploy key, the media bucket's keys or the GitHub token, and it cannot read the site's configuration. One health check covers the container: it fails when the web server does not answer, or when the web server, either worker, the scheduler or the installer is not running. When you open a terminal in the container, PHP hands itself to `www-data`, so what you run never leaves files the site cannot write.

### The database login

On each start Teme connects with the login you gave it. When that login is a PostgreSQL superuser, Teme makes the login `teme_app_` followed by the database's name, with no right to make databases or roles; makes it the owner of your database and of a spare one named after it with `_verify` on the end, which the weekly test restore uses; and lets no other login connect to them. Its password comes from `APP_KEY`, so every start gives the same one and nothing is stored. Each site on one PostgreSQL server gets a login of its own. When the login you gave is not a superuser, Teme makes nothing and runs as that login, and `teme:doctor` says if it has more rights than Teme needs.

### Updating

With the Docker source, Tools › Updates › **Update to …** changes the Application's image to the new release's tag and redeploys it. Dokploy starts the new container while the old one still answers. The new container migrates and starts, and the updater in it carries the update on from where the old one stopped: it waits for the site to answer with the new version, then ends the update. If the new container never becomes healthy, Dokploy puts the old one back, and the updater sets the image back too. For a moment both containers run against one database; a migration that the older release cannot run against waits for maintenance mode, as it does everywhere.

To update by hand, change the image's tag on the General tab and press **Deploy**.

With the Git or Drop source, the admin's Update stops and says that Dokploy builds this site from its source. Put the new release in the source, then press **Deploy** on the Application. Updating the source is the whole update: the deploy step migrates the database as the new container starts.

### If the container stops

Read the Application's Logs. Teme names what is wrong in one sentence, never with a password in it:

- `APP_KEY is empty`: set `APP_KEY` as step 4 says.
- `DB_HOST holds an address`, or `DATABASE_URL isn't a PostgreSQL address Teme can read`: set the database as step 4 says.
- `TEME_ROLE is …, and the only role Teme knows is all`: set `TEME_ROLE` to `all`, or remove it.
- `Teme couldn't connect to the database`: check the database service is running, and its host, port, user and password against the service's page.

When the container runs but is marked unhealthy, open its Terminal and run `teme-healthcheck`: it names the first process that is not running.

## Dokploy notes

- `DB_HOST` is a host name, never a connection URL.
- Only `web` joins `dokploy-network`, and service names must be unique. A `postgres` elsewhere on that network was reached by mistake, which is why the database here is called `teme-postgres`.
- Compose's `configs.content` did not run on Dokploy, so the database setup is a service, `teme-db-setup`, not a config file.
- To update by hand, set `TEME_IMAGE` and `TEME_UPDATER_IMAGE` to the new tag and Deploy. To update from the admin, see [Updating from the admin](#updating-from-the-admin).
