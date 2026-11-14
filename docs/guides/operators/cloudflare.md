---
title: Cloudflare in front of Teme
audience: operators
proved: unproved
written_for: Cloudflare's published ranges as Teme knows them
---

# Cloudflare in front of Teme

> Not proved yet: the purge and the real-address path have run only in tests, never through a real Cloudflare account in front of a real server.

Cloudflare can sit in front of your proxy. It caches your pages so most readers never reach your server. Teme tells it what to keep and purges its copies when you change something. This page makes the two work together.

## What Cloudflare does for Teme

Cloudflare keeps a copy of each public page for as long as Teme says it may. When you publish or change something, Teme purges the copies of the pages that change touches, so readers see the new page, not the old one. Signed-in people are never served a cached page.

## To make readers' real addresses reach Teme

Behind Cloudflare, the request that reaches Teme has passed through two proxies. Teme sees your proxy's address, and a forwarded chain that ends in Cloudflare's. Two things must both be true, or every reader looks like Cloudflare:

1. Teme trusts Cloudflare's ranges as well as the Docker network's:

   ```env
   TRUSTED_PROXIES=private,cloudflare
   ```

2. Traefik trusts the same ranges on its `websecure` entry point, so it keeps the chain Cloudflare sends instead of replacing it. Give it this list as the value of its `forwardedHeaders.trustedIPs` option:

   ```text
   173.245.48.0/20,103.21.244.0/22,103.22.200.0/22,103.31.4.0/22,141.101.64.0/18,108.162.192.0/18,190.93.240.0/20,188.114.96.0/20,197.234.240.0/22,198.41.128.0/17,162.158.0.0/15,104.16.0.0/13,104.24.0.0/14,172.64.0.0/13,131.0.72.0/22,2400:cb00::/32,2606:4700::/32,2803:f800::/32,2405:b500::/32,2405:8100::/32,2a06:98c0::/29,2c0f:f248::/32
   ```

   On the plain-VPS recipe, add it to the `command` of the `traefik` service in `docker/compose.proxy.yml`, as one more line, with the list after the equals sign:

   ```text
   - --entryPoints.websecure.forwardedHeaders.trustedIPs=<the list above>
   ```

Cloudflare publishes its own list, and it changes now and then. Teme's list and Traefik's list must stay equal to it, and to each other: update both together when Cloudflare's changes.

## To turn purges on

Set these in `.env`:

```env
PAGE_CACHE_CDN=cloudflare
CLOUDFLARE_ZONE_ID=your-zone-id
CLOUDFLARE_API_TOKEN=your-token
```

Better, keep the token in a file and name the file with `CLOUDFLARE_API_TOKEN_FILE`, as [Configuring Teme](configure.md) explains for every secret.

Make the token in Cloudflare with one permission: Zone, then Cache Purge, then Purge (write `Cache Purge` when you search for it). Give it no other permission, and limit it to the one zone.

A plan above Free may purge faster than five requests a minute. Set `CLOUDFLARE_PURGES_PER_MINUTE` to the number your plan allows.

An unknown name in `PAGE_CACHE_CDN`, or Cloudflare without its zone or token, fails every page Teme stores, so a mistake shows at once and never as pages that nothing purges.

## The two Cache Rules

Cloudflare caches no HTML until you tell it to. Make two Cache Rules, once, in the Cloudflare dashboard for the site's zone:

1. **Eligible for cache**, for the site's host. Set its Edge TTL to *Use cache-control header if present, bypass cache if not*. Teme sends the life each page has left in a header Cloudflare reads before any other.
2. **Bypass cache**, when the cookie contains `teme_auth`. Teme sets that cookie when someone signs in. Without this rule Cloudflare serves its copy even to a signed-in editor.

Never cache /up: the health check and the updater read it live.

## SSL mode

Set Cloudflare's SSL/TLS mode to Full (strict) once your proxy has a certificate Cloudflare can check. Cloudflare's own documentation describes the modes.

## A gap

Anyone who can send a request to your server from inside Cloudflare's ranges can set a reader's address, because Teme trusts Cloudflare to tell the truth. A Cloudflare Worker that someone else runs is inside those ranges. A firewall that admits only Cloudflare's ranges on ports 80 and 443 closes it for requests that skip Cloudflare, but it does not close it for a Worker. Teme does not close this gap yet.

## To check a purge

Ask Teme to purge a page, then ask Cloudflare for that page and look at its cache status:

```sh
docker compose exec web php artisan teme:cache:purge --url=/about/
```

```sh
curl -sI https://example.com/about/
```

The first request after a purge shows a miss. The next shows a hit.
