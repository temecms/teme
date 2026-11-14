---
title: Proxies and HTTPS
audience: operators
proved: tests
written_for: the edge settings Teme reads at its own tag
---

# Proxies and HTTPS

Teme never serves HTTPS itself. A proxy in front of it, yours or your platform's, ends HTTPS and passes requests on. This page explains how Teme learns a reader's real address and whether the request was secure, which settings control that, and how to check it.

## How a reader's address reaches Teme

A request passes through several hops:

1. The reader's browser.
2. Cloudflare, if you use it.
3. Your proxy: Traefik, in Dokploy, Coolify and the plain-VPS recipe.
4. nginx, inside the `web` container.
5. PHP, which is Teme.

Each proxy adds the address it received the request from to a header called `X-Forwarded-For`, and says whether the reader used HTTPS in `X-Forwarded-Proto`. Teme believes those headers only from the proxies you list in `TRUSTED_PROXIES`. From anyone else it ignores them, because anyone can send a header. If Teme trusted every sender, one reader could invent any address and walk past every limit that counts requests by address.

nginx never takes the address from a header. The image's CI checks that.

### What `TRUSTED_PROXIES` accepts

A comma-separated list of addresses and ranges, plus two names:

- `private` means the three ranges that Docker networks use: `10.0.0.0/8`, `172.16.0.0/12` and `192.168.0.0/16`.
- `cloudflare` means Cloudflare's published ranges, which [Cloudflare in front of Teme](cloudflare.md) lists.

When you leave it unset, production trusts `private` and nothing else, and every other environment trusts no proxy. When you set it, even to nothing, it means exactly what you wrote. Teme refuses to start if it contains `*` or a range that covers every address, because trusting everything is the mistake that setting exists to prevent.

For one proxy on the Docker network, which is the recipe's layout, you set nothing. Behind Cloudflare and Traefik, set `TRUSTED_PROXIES=private,cloudflare`, and make Traefik trust Cloudflare as well; [Cloudflare in front of Teme](cloudflare.md) says how.

## To set the site's address

Set `APP_URL` to the address readers type, with `https`:

```env
APP_URL=https://example.com
```

Teme answers requests for that host only. A request for another host name, which a forged `Host` header would make, is refused, so it builds no link and fills no cache. A health check made inside the container, from the container itself, may also name `localhost`.

If readers get links that begin with `http` instead of `https`, `APP_URL` is wrong, or your proxy is not sending `X-Forwarded-Proto`. See [Troubleshooting](troubleshooting.md).

## HSTS

In production, at an `https` address, Teme sends the `Strict-Transport-Security` header on every secure answer. It tells a browser to use HTTPS for this site and nothing else. The default, set by `TEME_HSTS`, is:

```text
max-age=31536000
```

That is one year. An empty `TEME_HSTS` sends no header at all, for a site whose proxy sends its own. nginx adds the same header to the answers PHP never sees, such as a static file or a stored page. Set HSTS at one layer, not also at your proxy, or readers get it twice.

Teme adds neither `preload` nor `includeSubDomains`, because both bind more than this site: a browser that has seen `includeSubDomains` forces HTTPS on every subdomain of the parent for a year, including a test subdomain that has no certificate. Add either to `TEME_HSTS` only when you mean it.

## Cookies

In production at an `https` address, Teme marks every cookie `Secure`, and names the session cookie with a `__Host-` prefix, which a browser accepts only over HTTPS and only for the exact host. Keep `SESSION_DRIVER` as `database`.

## No port is published

The recipe publishes no port. Your proxy reaches the `web` role on port 8080 over the Docker network. Do not publish it: a published port lets anyone reach Teme without going through your proxy.

## To check

From the server, ask for the health address, then a page, then the admin:

```sh
curl -I http://127.0.0.1:8080/up
```

```sh
curl -I https://example.com/
```

```sh
curl -I https://example.com/admin/login
```

The first answers `200`. The other two answer through your proxy, over HTTPS, and carry a `strict-transport-security` header. If a reader's address looks wrong in the logs, every reader showing the same address is the sign that the proxy is not trusted: check `TRUSTED_PROXIES`.
