---
title: Running Teme on a plain VPS
audience: operators
proved: unproved
written_for: the Compose recipe with Traefik v3.7.15 in front
---

# Running Teme on a plain VPS

> Not proved yet: the proxy file `docker/compose.proxy.yml` has not been run on a real server. The recipe itself is proved by CI.

A plain VPS is a server with Docker and no panel. The Compose recipe runs Teme; this page adds the one thing the recipe leaves out, a proxy that gets a certificate and serves HTTPS.

## What the server needs

- Docker Engine with Compose v2.
- Ports 80 and 443 reachable from the internet.
- A DNS record that points your domain at the server.
- A firewall that admits only ports 22, 80 and 443.

## To install

Follow [Installing with the Compose recipe](../install.md) first, but stop before you start it.

## To put Traefik in front

The recipe ships a second file, `docker/compose.proxy.yml`, that adds Traefik. You copied it with the rest of the folder, beside `compose.yml`. Add three lines to `.env`:

```env
TEME_HOST=example.com
TEME_ACME_EMAIL=you@example.com
COMPOSE_FILE=compose.yml:compose.proxy.yml
```

`TEME_HOST` is the site's host name, with no `https://`. `TEME_ACME_EMAIL` is where Let's Encrypt writes about your certificate. `COMPOSE_FILE` tells Compose to read both files. Then start everything:

```sh
docker compose up --detach
```

### What the file does

| Line | What it does |
|---|---|
| The `traefik` image, pinned by its digest | Runs exactly the build that was checked, not whatever the tag points at today. |
| The Docker provider, with `exposedByDefault=false` | Traefik routes only containers that carry its labels, so only `web`. |
| Two entry points, on ports 80 and 443 | One for plain HTTP, one for HTTPS. |
| The redirect | Every request on port 80 is sent to HTTPS. |
| The Let's Encrypt resolver, with the HTTP challenge | Gets and renews the certificate, and keeps it in the `traefik-certificates` volume. |
| The router rule, from `TEME_HOST` | Sends requests for your host to `web`. |
| The service port, 8080 | Where `web` listens inside the Docker network. |

## The Docker socket

Traefik reads the Docker socket to find the containers it routes to. The file mounts it read-only, which is the usual arrangement, and which is not a sandbox: a flaw in Traefik could still read what Docker knows. Keep Traefik's image current and the firewall closed.

## Cloudflare

If Cloudflare is in front of the server, there are two flags to add and one setting to change. They are on [Cloudflare in front of Teme](../cloudflare.md).

## A gap at each update

Containers are replaced one by one, so readers may see a few seconds of errors at each update. Platforms with rolling updates avoid that; this layout does not.

Updating from the admin needs the updater, which works with Dokploy only. On plain Compose, update by hand: [Updating and rolling back](../updates.md).

## Looking after the host

- Install the host's security updates.
- Raise an alarm before the disk fills: a full disk fails writes in ways the health check cannot foresee.
- A snapshot of the whole host is not a backup. Use Teme's own backups ([Backups](../backups.md)).
