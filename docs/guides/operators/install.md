---
title: Installing with the Compose recipe
audience: operators
proved: ci
written_for: the Compose recipe at the image's own tag
---

# Installing with the Compose recipe

Teme runs from one image and one Compose recipe. You need a server, a domain and a proxy that serves HTTPS. This page takes you from nothing to a running site with its first administrator.

## What you need

- **A server** with Docker Engine and Compose v2 (`docker compose version` prints a version).
- **A domain** whose address points at the server.
- **A proxy that ends HTTPS in front of Teme.** A platform such as Dokploy or Coolify has one; on a server with no platform, [the plain VPS page](platforms/plain-vps.md) gives you one.
- **The image.** `ghcr.io/temecms/teme` is public, so the server needs no registry sign-in.

The recipe publishes no port. Your proxy reaches the `web` role on port 8080.

## To get the recipe

The recipe travels inside the image, so it always matches the version you run. Copy it out of the image you are about to run.

Copy the recipe, using the exact tag you will run (never a moving tag such as `latest`). Replace `<version>` below with that release, such as `1.0.0`; [the releases page](https://github.com/temecms/teme/releases) lists them:

```sh
docker create --name teme-recipe ghcr.io/temecms/teme:<version>
docker cp teme-recipe:/var/www/html/docker ./teme
docker rm teme-recipe
cd teme
```

Everything below happens inside the `teme` folder: `compose.yml` and the `postgres` folder are the files the recipe needs.

## To make the secrets

Teme reads four secrets from files, so none of them sits in an environment variable. Make them once, and keep them: the application key decrypts what Teme has stored.

```sh
umask 022
mkdir -p secrets
printf 'base64:%s\n' "$(openssl rand -base64 32)" > secrets/app_key
openssl rand -hex 24 > secrets/db_password
openssl rand -hex 24 > secrets/postgres_password
touch secrets/github_token
chmod 0644 secrets/app_key secrets/db_password secrets/postgres_password secrets/github_token
```

Leave it empty, or put Teme's read-only GitHub token in it (see [Configuring Teme](configure.md), Updates).

The application key is 32 random bytes, written as `base64:` and then the encoded bytes, because Teme's cipher is AES-256-CBC. The files must be readable by the containers, which is why they are `0644`: the folder is the protection, so keep it to yourself.

Never copy `.env.example` to a server. It is for development.

## To write `.env`

Create a file named `.env` beside `compose.yml` with the two settings the recipe requires:

```env
TEME_IMAGE=ghcr.io/temecms/teme:<version>
APP_URL=https://example.com
```

`TEME_IMAGE` is the image with its exact tag, the same one you copied the recipe from. `APP_URL` is the address readers type, with `https`. Nothing else is required. [Configuring Teme](configure.md) lists every other setting you may add.

## To start

Check that the recipe reads, then start it:

```sh
docker compose config --quiet
docker compose up --detach
```

The recipe starts `postgres`, then `deploy`, which makes the database ready and exits, then `web`, `worker`, `worker-long` and `scheduler`. `web` is healthy when it answers its health check; wait for that:

```sh
docker compose ps
```

The `valkey` profile is the only one: it adds Valkey for a page cache that several servers share. Leave it off on one server.

## To make the first administrator

The deploy's log ends with the setup page's address and a setup code. Open the address, enter the code, and fill in the form: you are signed in as the site's first administrator. Lost the log? `docker compose exec web php artisan teme:setup-code` prints them again.

Or make the first administrator from the shell:

```sh
docker compose exec web php artisan teme:user:create --email=you@example.com --name="Your Name" --role=administrator
```

The command prints a link. Open it to choose a password.

## To check

Ask Teme's doctor, and ask the health address:

```sh
docker compose exec web php artisan teme:doctor
```

```sh
curl -I http://127.0.0.1:8080/up
```

The doctor lists what is wrong, with a line saying how to fix each. A green doctor and a `200` from `/up` mean the site is running. [Troubleshooting](troubleshooting.md) explains each check.

## Where things live

The recipe keeps three named volumes:

| Volume | Holds |
|---|---|
| `pgdata` | PostgreSQL's data. |
| `media` | Uploaded files and their variants, at `storage/app` in every Teme role. |
| `page-cache` | The page cache, shared by every Teme role. |

Every Teme role mounts `media` and `page-cache`. A page cache that the roles do not share serves stale pages; the Status page warns you when that happens.

## What next

- Put the site behind HTTPS and, if you use it, Cloudflare: [Proxies and HTTPS](proxies-and-https.md).
- Set up backups before you put anything on the site: [Backups](backups.md).
- Learn how to update: [Updating](updates.md).
