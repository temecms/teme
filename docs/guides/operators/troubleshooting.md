---
title: Troubleshooting
audience: operators
proved: tests
written_for: the doctor's checks at Teme's own tag
---

# Troubleshooting

When something is wrong, ask the doctor first. It checks that the server can run Teme, and says how to fix what it finds.

## To read `teme:doctor`

Run it in any Teme role:

```sh
docker compose exec web php artisan teme:doctor
```

It prints one line for each check: how serious the finding is, the check's name, and what it found. A finding that can be fixed has a second line that begins `Fix:`. The most serious findings come first, and the last line counts the errors and the warnings.

- An **error** means Teme will not work properly. The command exits with 1, and `teme:deploy` refuses to run until you fix it.
- A **warning** means Teme works but something is wrong that you should fix.
- **OK** and **info** need nothing from you.

### The health address

`/up` is the address a proxy or a platform asks to know whether `web` is well. It answers `200` when Teme can reach its database and `503` when it cannot, with no detail in the body, because anyone can ask it. The detail is in the log:

```sh
docker compose logs web
```

`/up` fails for a moment during a restore, on purpose.

### If a check says it couldn't run yet

The doctor may print this:

```text
The check couldn't run yet: it reads a table or column that a migration which hasn't run makes.
Fix: Run php artisan teme:deploy, which runs the migrations.
```

It means the doctor ran before the database was migrated. A deploy's preflight reads it as a warning, goes on, and asks again after it has migrated. On a new database you see it for every check that reads a table the migrations make, until the first deploy has run.

### The scheduler and worker banners

The scheduler and the queue workers each leave a heartbeat. The heartbeats are telemetry. A banner on the admin's Status page shows only in production, and only when a heartbeat is missing or old: start the `scheduler` role, or the `worker` role, which the recipe starts for you.

### Logs

Every role writes its log to its container's standard error. Read it with `docker compose logs` and the role's name.

## What the doctor checks

Each check has a heading here, named as the doctor names it.

### Database role

*What it means.* Teme's database role must not be a superuser, must not be able to create roles or databases, and must own the schema it works in.

*How to fix.* Make the role as [The database](database.md) says. If the doctor names a schema that does not exist, make it, owned by Teme's role. If the role can create databases, a superuser removes the power with `ALTER ROLE` and `NOCREATEDB`.

### Production settings

*What it means.* The application key, the site address, the session driver, the cookies and `TEME_HSTS` are as a production site needs. If they are not, Teme refuses to start and says which.

*How to fix.* Read the message. It names the setting and what to write. See [Configuring Teme](configure.md).

### Site address

*What it means.* `APP_URL` is not an `https` address, so passwords and session cookies would cross the network unencrypted.

*How to fix.* Set `APP_URL` to the site's `https` address, and let the proxy in front answer HTTPS.

### Debug mode

*What it means.* `APP_DEBUG` is on. An error page then shows visitors the site's settings and secrets.

*How to fix.* Set `APP_DEBUG=false`, or remove the line: the image already sets it.

### Trusted proxies

*What it means.* `TRUSTED_PROXIES` is wrong, or it is empty where a proxy sits in front: every reader then looks like the proxy and every link starts with `http`.

*How to fix.* Behind Traefik alone, remove the setting. Behind Cloudflare too, use `private,cloudflare`. See [Proxies and HTTPS](proxies-and-https.md).

### Email

*What it means.* `MAIL_MAILER` is `log`, so password resets, invitations and backup alerts are written to the log and never sent.

*How to fix.* Set `MAIL_MAILER` and its settings for a real mail service.

### Password hashing

*What it means.* Either this PHP has no Argon2id, which the image always has, or hashing one password takes less than 250 or more than 500 milliseconds.

*How to fix.* Raise `ARGON_TIME` if it is too fast, lower it if it is too slow, and keep `AUTH_TIMEBOX_DURATION` above the time it takes. See [Sizing a server](sizing.md).

### Storage

*What it means.* A folder Teme writes to cannot be written. The finding names it.

*How to fix.* Give the user PHP runs as write access to the folder. In the recipe that means the `media` and `page-cache` volumes: check that they exist and that the roles mount them.

### Page cache folder

*What it means.* This process does not see the folder the web server stores pages in, so a page Teme removes stays served until it expires.

*How to fix.* Mount the same `page-cache` volume in every role, at the same path.

### User

*What it means.* Teme tells you which user it runs as, and warns if it is root, or says it cannot tell.

*How to fix.* Nothing to fix in the image: it runs as an ordinary user. If you build your own, do not run as root.

### Scheduler

*What it means.* The scheduler has not run, or has not run lately, so scheduled posts, backups and other timed work are waiting.

*How to fix.* Start the `scheduler` role, which runs `php artisan schedule:work`. See [Backups](backups.md).

### Queue workers

*What it means.* No worker has finished a job lately and none is waiting, so queued work, such as emails and imports, may not be running.

*How to fix.* Start the `worker` and `worker-long` roles. The recipe starts both.

### Backup programs

*What it means.* `restic`, `pg_dump` or `pg_restore` is missing, or is too old for the database it must back up.

*How to fix.* Run `teme:backup:tools` to see which, and use the image the recipe names, which carries all three. See [Backups](backups.md).

### Test restore database

*What it means.* The spare database for the weekly test restore is not named, cannot be reached, is not owned by Teme's role, or is the site's own database.

*How to fix.* Make a database of its own whose name ends in `_verify`, owned by Teme's role, and name it in `TEME_VERIFY_DATABASE`. See [The database](database.md).

### Public site's security policy

*What it means.* One of these. Browsers reported things the policy would block, in the last day, while `TEME_PUBLIC_CSP` is `report-only`: nothing was blocked, and the check warns so you look before you enforce it. Or browsers reported things the policy blocked, or would block. Or `TEME_PUBLIC_CSP` holds a value Teme doesn't know, so the policy only reports. Or `TEME_PUBLIC_CSP` is `off`, so the public site sends no policy at all; that is a warning on a production site. Or a `.bad` file exists in the `csp` folder of the page cache folder: a file of reports could not be recorded, and Teme set it aside.

Anyone can send a report, so one naming a host you don't know may be made up. The warning clears a day after the last report.

*How to fix.* Run `teme:csp:reports` to see what was reported and from which pages; it still lists what was recorded when a `.bad` file exists. A theme declares the hosts its pages load from, in the `csp` member of its `teme-theme.json`. A piece of site code declares the outside sites it connects to in Settings › Site code, under *This code also connects to*; the sites written in the code itself are found and allowed without being declared. A report nobody recognises is often a browser extension or a made-up report, and needs nothing. Set `TEME_PUBLIC_CSP` to `report-only`, `enforce` or `off`, and deploy: a deploy clears the page cache, so every page takes the new value. Look at a `.bad` file if you wish, then delete it; the warning goes with it.

## Common failures

### Teme will not start

In production, Teme checks its settings when each role starts, and refuses to start if one is wrong. The log begins "Teme cannot start in production" and lists every problem, one to a line, never a secret's value. Fix each one, then run `docker compose up --detach`. If a secret's file cannot be read, or both a secret and its `_FILE` twin are set, the message names the variable: see [Configuring Teme](configure.md).

### A request is refused for its host

Teme answers requests for the host in `APP_URL` and no other. If you reach the site by a different name, or your proxy sends a different `Host`, the request is refused. Make `APP_URL` the address readers use, and make the proxy pass the host on.

### Links start with `http` instead of `https`

`APP_URL` is `http`, or the proxy does not send `X-Forwarded-Proto` and is not trusted. See [Proxies and HTTPS](proxies-and-https.md).

### Every reader shows the same address

Teme does not trust your proxy, so it reports the proxy's address. Check `TRUSTED_PROXIES`. Behind Cloudflare, it must be `private,cloudflare`, and Traefik must trust Cloudflare as well: see [Cloudflare in front of Teme](cloudflare.md).

### Uploads fail above 100 MB

nginx and PHP both refuse a request larger than 100 MB by default. Raise `NGINX_CLIENT_MAX_BODY_SIZE`, `PHP_UPLOAD_MAX_FILE_SIZE` and `PHP_POST_MAX_SIZE` together, and **Largest upload** in the admin to match. A proxy in front may have a limit of its own.

### Tools › Backups times out behind a proxy

Setting a place up, adding a key and testing a place wait for restic, which can take a while. The image gives these requests 15 minutes. Your proxy must allow them as long, or it will cut the request off and show an error even though Teme carries on.

### The page cache folder is not shared

The Status page shows a banner, and the doctor's **Page cache folder** check warns. Mount the same `page-cache` volume in every role.

### The disk is full

A full disk makes writes fail in ways the health check cannot foresee: uploads, the page cache and backups all stop. Free space, then look for what grew: the `media` volume, PostgreSQL's data, the folders under `storage/app` that a restore, an export or a test restore uses, and the container logs. Set an alarm well before the disk fills.
