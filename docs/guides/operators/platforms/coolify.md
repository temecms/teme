---
title: Running Teme on Coolify
audience: operators
proved: unproved
written_for: Coolify v4.4.6
---

# Running Teme on Coolify

> Not proved yet: nobody has run Teme on Coolify v4.4.6. Unknown: whether several resources may mount one named volume, and how Coolify's own database backups are stored in this version.

This page was written for Coolify v4.4.6, from Coolify's documentation.

## Two ways

Try the first way first.

**A Docker Compose resource, with the recipe as it is.** Add the recipe's `compose.yml` as a Docker Compose resource. It is the simplest way: Coolify runs `docker compose up`, and Compose starts every role in the right order. The cost is a gap of a few seconds at each update, because Coolify does not support rolling updates for Docker Compose applications.

**Docker Image resources, one for each role.** Make a resource for `web`, `worker`, `worker-long`, `scheduler`, and a one-off for `deploy`, all from the same image and tag. Rolling updates work for `web` when it has a health check, publishes no host port, and has neither a custom container name nor the consistent-name mode. Coolify overlaps the old and new containers, waits for the health check, then stops the old one.

## The commands, the volumes and the grace periods

For the second way, each role runs the command the recipe gives it:

| Resource | Command | Stop grace period |
|---|---|---|
| `deploy` | `php artisan teme:deploy` | n/a: it runs once and exits |
| `web` | the image's own command; leave it empty | n/a |
| `worker` | `php artisan queue:work --tries=3 --max-time=3600` | 6 minutes |
| `worker-long` | `php artisan queue:work database-long --queue=long --tries=3 --max-time=3600` | 6 minutes |
| `scheduler` | `php artisan schedule:work` | 30 minutes |

Without `worker-long`, nothing works the long queue and long jobs wait for ever.

Coolify's Stop Grace Period field takes 1 to 3600 seconds, so the scheduler's 30 minutes, which is 1800 seconds, fits. Set 360 seconds for the two workers.

Every resource mounts two volumes: `media` at `/var/www/html/storage/app` and `page-cache` at `/var/www/html/storage/page-cache`. Since Coolify 4.4.0, a new volume mount is a named Docker volume, which is what the recipe wants. Coolify's page on persistent storage does not say whether several resources may mount one named volume. That is the same open question as on Dokploy, and it decides whether the second way works at all.

For `web`, set the health check to `curl -f http://localhost:8080/up`, and publish no port: Coolify's proxy reaches port 8080 itself.

## A private image

A private image needs a registry login on the server. Coolify 4.4.0 added registry logins and a page of Docker images. Sign in there, or run `docker login ghcr.io` on the server.

## Do not use Coolify's scheduled tasks

Teme's schedule belongs to Teme: the `scheduler` role runs it. Putting it in Coolify's scheduled tasks moves it into the panel, where a restore or a move would lose it.

## Platform backups of the database

Coolify can back up its databases. The version before this one stored them unencrypted, and this page has not checked whether 4.4.6 does the same. Use them only as a last resort beside Teme's own backups, and never for the media volume. [Backups](../backups.md) says what Teme's backups hold.
