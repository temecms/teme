---
title: The operators' guide
audience: operators
proved: tests
written_for: the guide's own pages at Teme's own tag
---

# The operators' guide

This guide is for the person who runs a Teme site's server: who installs it, sets it up, keeps it updated, backs it up and fixes it when something goes wrong. You need to be comfortable with a terminal and with Docker. You need not know Laravel or PostgreSQL.

People who write and publish on the site have a guide of their own.

## The order to read it in

1. [Installing with the Compose recipe](install.md), then [Configuring Teme](configure.md).
2. [The database](database.md) and [Sizing a server](sizing.md).
3. Put it behind HTTPS: [Proxies and HTTPS](proxies-and-https.md), then [Cloudflare in front of Teme](cloudflare.md) if you use it.
4. The page for the place you run it: [Dokploy](platforms/dokploy.md), [Coolify](platforms/coolify.md) or [a plain VPS](platforms/plain-vps.md).
5. Keep it safe and current: [Backups](backups.md), [Updating and rolling back](updates.md), [Restoring, moving and exporting](restore-and-moving.md).
6. When something is wrong: [Troubleshooting](troubleshooting.md).
7. Before you move a real site: [The migration runbook](migration-runbook.md).
8. To let outside tools write to the site: [The management API](management-api.md).
9. When the media outgrows the server: [Media in object storage](object-storage.md).

## How far each page is proved

A guide that tells you something nobody has seen working is worse than none, so every page says how far it has been checked:

- **ci**: the image's automated build runs it.
- **run**: someone ran it on real servers.
- **tests**: automated tests check the page against the code it describes: its commands, settings, files and services exist.
- **unproved**: nothing has run it yet. The page opens with a line that says what is not known.
- **by hand on a platform, with a date**: someone installed Teme from the page on a real server of that platform and it ran.

| Page | How far it is proved |
|---|---|
| [Installing with the Compose recipe](install.md) | ci |
| [Configuring Teme](configure.md) | tests |
| [The database](database.md) | tests |
| [Sizing a server](sizing.md) | unproved |
| [Proxies and HTTPS](proxies-and-https.md) | tests |
| [Cloudflare in front of Teme](cloudflare.md) | unproved |
| [Running Teme on Dokploy](platforms/dokploy.md) | by hand on Dokploy v0.31.0, 2026-11-08 |
| [Running Teme on Coolify](platforms/coolify.md) | unproved |
| [Running Teme on a plain VPS](platforms/plain-vps.md) | unproved |
| [Updating and rolling back](updates.md) | unproved |
| [Backups](backups.md) | tests |
| [Restoring, moving and exporting](restore-and-moving.md) | unproved |
| [Media in object storage](object-storage.md) | unproved |
| [Troubleshooting](troubleshooting.md) | tests |
| [The migration runbook](migration-runbook.md) | unproved |
| [The management API](management-api.md) | tests |
