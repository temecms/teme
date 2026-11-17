---
title: Run Teme as one container
audience: operators
proved: unproved
written_for: the settings Teme reads at its own tag
---

# Run Teme as one container

> Not proved yet: the container is tested against a real PostgreSQL service in Teme's automated tests, but no host's panel has been followed from this page.

Teme's image can run the whole site in a single container: the web server, both queue workers, the scheduler, the plugin installer and the updater. Use it on a host that runs one container from an image, gives it a database and a volume, and puts a domain with HTTPS in front of it. The [Compose recipe](../install.md) is the way to install when you run Docker yourself and want each part in its own container.

You need:

- a host that can run the image `ghcr.io/temecms/teme` with environment variables, and stop it with `SIGTERM`;
- a PostgreSQL 18 database that the container can reach ([The database](../database.md) says what Teme needs from it);
- one volume to mount at `/var/teme`;
- a domain, with HTTPS provided by the host or a proxy in front ([Proxies and HTTPS](../proxies-and-https.md)).

## Steps

1. **Take the image.** Use `ghcr.io/temecms/teme` with an exact tag, such as `ghcr.io/temecms/teme:0.8.0-alpha.1`, never `latest`. A host that builds from Git or from a ZIP can build the repository's `Dockerfile` instead: the build takes several minutes and about 4 GB of memory, and leaves out every `.env` file, so the settings you give the host never enter the image.
2. **Make the database.** Make a PostgreSQL 18 database and a user that is a PostgreSQL superuser. Teme uses that login once, on each start, to make a limited login of its own, and every part of Teme then runs as the limited one. If the user is not a superuser, Teme runs as that user instead.
3. **Set the variables.**
   - `TEME_ROLE`: `all`. This is the one switch that makes the container run every role. Without it the container runs the web server alone.
   - `APP_URL`: your site's address, with `https://`.
   - `APP_KEY`: make it once and keep it. On Linux or macOS: `echo "base64:$(openssl rand -base64 32)"`. On Windows PowerShell: `$b = New-Object byte[] 32; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b); "base64:" + [Convert]::ToBase64String($b)`. If it is missing, the container stops at once and says how to make one.
   - The database, in one of two forms. Either `DATABASE_URL`, the database's whole address as the host shows it (`postgresql://user:password@host:5432/database`; a password with a special character is written with `%XX` codes), or the five settings `DB_HOST` (a name and nothing else), `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`. `DB_PASSWORD_FILE` names a file that holds the password, in place of `DB_PASSWORD`. A `DB_HOST` that holds a whole address, or a `DATABASE_URL` that cannot be read, stops the container without printing it.
   - `PORT`, only if your host says which port it routes to. The container listens on `8080` otherwise.

   [Configuring Teme](../configure.md) lists the other settings.
4. **Mount one volume at `/var/teme`.** It holds the media, the page cache, and the plugins and themes you install from the admin. Without it they are lost whenever the container is replaced, and the container's log says so on every start.
5. **Route your domain to the container's port.** The port is `8080`, or the one in `PORT`. Teme believes `X-Forwarded-Proto` and `X-Forwarded-For` only from the proxies you list in `TRUSTED_PROXIES`, so set it to the host's proxy, as [Proxies and HTTPS](../proxies-and-https.md) says.
6. **Give it time to stop.** Set the host's stop timeout to six minutes (`360` seconds), so a queue worker can finish its job before the container is killed. Teme stops every process within seconds when nothing is running.
7. **Use the image's health check.** The image carries one, and it fails when the web server does not answer, or when the web server, either worker, the scheduler or the installer is not running. It leaves the updater out, so that a crashed updater cannot make the host replace a site that is working. Its start period is 15 minutes, because the first start migrates the database before the check can pass. If your host asks for a health command of its own, give it `teme-healthcheck`.
8. **Start it, and make the first administrator.** The container's log shows the start: the database step, then the deploy, which ends with the setup page's address and a setup code. Open the address, enter the code and fill in the form. Or open a terminal in the container and run `artisan teme:user:create --email=you@example.com --name="Your Name" --role=administrator`, then open the link it prints.

Run one replica. Each replica would run its own scheduler, and two that start together can race when they make Teme's database login.

## What runs in the container

| Process | Runs as | What it does |
|---|---|---|
| the deploy step | `www-data` | Makes Teme's database login, caches the configuration, and runs `php artisan teme:deploy`, which backs up and migrates under a lock. The web server starts only once it has ended well. |
| the installer's check | `teme-installer` | Checks the installed plugins and themes against this release before the site starts. |
| nginx and PHP-FPM | `root` (their masters), `nginx` and `www-data` (their workers) | The web server. |
| `php artisan queue:work --tries=3 --max-time=3600` | `www-data` | The default queue. |
| `php artisan queue:work database-long --queue=long --tries=3 --max-time=3600` | `www-data` | The long queue. |
| `php artisan schedule:work` | `www-data` | The scheduler. |
| `php artisan teme:installer` | `teme-installer` | Installs, updates and removes the plugins and themes asked for from the admin. It alone can write `/var/teme/extensions`. |
| `php artisan teme:updater` | `www-data` | Carries out an update asked for from the admin, where the host has an update driver. |

The installer runs as its own user, with an environment of only what it needs: the database login Teme runs as, the application key and the extensions folder. Its environment never holds a host's API key, the media bucket's keys or the GitHub token, and its user cannot read the site's configuration file, the site's processes or the login you gave. That separation is of environment, files and processes, not of the database: the installer holds the login Teme runs as and the application key, so it can read what Teme keeps encrypted in the database, such as a saved API key. The Compose recipe is the same. The image starts as root so it can run the installer and the site as different users; with no `TEME_ROLE` it drops to `www-data` before anything else runs. A host that refuses to start a container as root, such as Kubernetes with `runAsNonRoot` or OpenShift with its random user IDs, cannot run this image as one container. When you open a terminal in the container, PHP hands itself to `www-data`, so what you run never leaves files the site cannot write.

## The database login

On each start Teme connects with the login you gave it. When that login is a PostgreSQL superuser, Teme makes the login `teme_app_` followed by the database's name, with no right to make databases or roles; makes it the owner of your database and of a spare one named after it with `_verify` on the end, which the weekly test restore uses; and lets no other login connect to them. Its password comes from `APP_KEY`, so every start gives the same one and nothing is stored. Each site on one PostgreSQL server gets a login of its own. Changing `APP_KEY` changes that password on the next start, so a container still running from before is refused by the database until it is replaced: replace the container when you change the key. When the login you gave is not a superuser, Teme makes nothing and runs as that login, and `teme:doctor` says if it has more rights than Teme needs.

## Updating

Where the host has no update driver, the admin's **Update** says to update from the host's own panel. Change the image's tag there and redeploy. If the host starts the new container before it stops the old one, both run against one database for a moment: a migration that the older release cannot run against waits for maintenance mode, as it does everywhere. Where the host builds from a source, put the new release in the source and build again.

## On a host you run yourself with `docker run`

```
docker volume create blog-data
docker run --detach --name blog --restart unless-stopped --stop-timeout 360 --publish 127.0.0.1:8080:8080 --volume blog-data:/var/teme --env TEME_ROLE=all --env APP_URL=https://example.com --env APP_KEY=base64:your-key --env DATABASE_URL=postgresql://user:password@db.internal:5432/blog ghcr.io/temecms/teme:0.8.0-alpha.1
```

Put a proxy that serves HTTPS in front of `127.0.0.1:8080`. To update, pull the new tag, remove the container and run the same command again; the volume keeps everything.

## On Dokploy

[temecms-for-dokploy](https://github.com/temecms/temecms-for-dokploy) has Teme's Dokploy template and its guide, including the Application steps for each source: the image, Git and a ZIP.

Teme's admin can update a Dokploy Application that runs from the image. Set `TEME_DOKPLOY_URL` to the panel's `https://` address and `TEME_DOKPLOY_APP_NAME` to the App Name Dokploy shows on the Application's General tab, and save a Dokploy API key in Settings › Updates. **Update to …** then changes the Application's image and redeploys it, and the updater in the new container finishes the update from where the old one stopped; if the new container never becomes healthy, it sets the image back. The key can do what its Dokploy user can do, so make it for a user who has access to this Application alone. When Dokploy builds the Application from Git or a ZIP, the button stops and says so: put the new release in the source and press **Deploy** in Dokploy.

## On other hosts

Any host that runs one container from an image, with environment variables, one volume, a database and a domain, runs Teme from the steps above. Teme has no files for any host in particular, and its admin cannot update the container itself: update from the host's panel, as the Updating section says.

## If the container stops

Read its log. Teme names what is wrong in one sentence, never with a password in it:

- `APP_KEY is empty`: set `APP_KEY` as step 3 says.
- `DB_HOST holds an address`, or `DATABASE_URL isn't a PostgreSQL address Teme can read`: set the database as step 3 says.
- `TEME_ROLE is …, and the only role Teme knows is all`: set `TEME_ROLE` to `all`, or remove it.
- `Teme couldn't connect to the database`: check the database is running, and its host, port, user and password.

When the container runs but is marked unhealthy, open a terminal in it and run `teme-healthcheck`: it names the first process that is not running.
