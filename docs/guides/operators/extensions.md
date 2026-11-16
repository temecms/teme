---
title: Plugins and themes installed from the admin
audience: operators
proved: unproved
written_for: the installer service as the recipe runs it
---

# Plugins and themes installed from the admin

> Not proved yet: installing, updating and rolling back from the admin have not been run on a real server.

An administrator can ask for a plugin or a theme from the admin. The site never writes code itself: the admin only records the request, and a separate `installer` service carries it out. It builds a new copy of the installed plugins and themes, checks it, and switches to it in one step, keeping the copies before it. Enabling a plugin and activating a theme stay separate steps an administrator takes afterwards.

## The extensions folder

The recipe keeps installed plugins and themes in a volume of their own, `extensions`, mounted at `/var/teme/extensions`. `TEME_EXTENSIONS_PATH` names that folder; when it is not set, nothing is loaded from it and the installer does nothing.

Only the `installer` service can write the folder. It runs as its own user, `teme-installer`, uid and gid 9990, and every other role mounts the volume read-only. The image makes the folder with that owner, so a new volume starts right. If the installer's log says it can't write the folder, give it to that user while the installer runs:

```bash
docker compose exec --user root installer chown -R 9990:9990 /var/teme/extensions
```

Inside, `releases/` holds each copy the installer built, and `current` points at the one that runs. The installer keeps the current copy and the two before it.

## The installer service

The `installer` service runs `php artisan teme:installer`. It looks for a request every 30 seconds, every 3 while one runs, and carries them out one at a time. A request that starts while Teme itself is being updated stops and asks to be made again afterwards.

When it starts, which happens after every update of Teme, the installer first checks that the installed plugins and themes still fit the new version of Teme. When they do, nothing changes. When they don't, it rebuilds them, moving only the packages that must move. If even that fails, the site starts anyway with the installed plugins and themes held, and the installer's log names the package that blocks. The `web`, `worker`, `worker-long` and `scheduler` roles start only after this check.

Composer runs inside the installer with no package scripts and no Composer plugins, and with none of the site's secrets in its environment.

## Without the installer

A request asked for on a server with no installer running is still recorded, and waits. To carry it out by hand with the recipe:

```bash
docker compose run --rm installer --once
```

On a server without Docker, run it in the site's folder as the user that owns the extensions folder:

```bash
sudo -u teme-installer TEME_EXTENSIONS_PATH=/var/teme/extensions TEME_EXTENSIONS_ROLE=installer php artisan teme:installer --once
```

To check the installed plugins and themes against this version of Teme without carrying out any request, run `php artisan teme:installer --prepare-only`.

## When Teme is updated

Plugins and themes in the extensions folder are not part of the image, so updating Teme never removes them. Each copy is marked with the version of Teme it was checked against, and a site loads a copy only when it was checked against the image it runs. The check and, if need be, the rebuild happen when the installer starts after the update.

## Backing up the folder

Backups don't hold the extensions folder yet. A site restored onto a new server comes back with its plugins' settings and data but not their code. Until backups hold it, keep a copy of the current copy's `composer.json` and `composer.lock`: they list every package installed from the admin and its exact version, so you can ask for the same ones again on the new server.

```bash
docker compose exec -T installer tar -czf - -C /var/teme/extensions/current composer.json composer.lock > extensions.tar.gz
```
