---
title: The migration runbook
audience: operators
proved: unproved
written_for: moving a WordPress site to Teme on two servers
---

# The migration runbook

> Not proved yet: nobody has run this checklist from start to finish. The Took and Surprise columns are empty on purpose: whoever runs it fills them in, and corrects the steps from what really happened.

This is a checklist for moving a real WordPress site to Teme, and then proving that you can restore it onto a second server. Do it once, on a copy, before you trust Teme with a site you cannot lose.

Work through the stages in order. For each step, write down how long it took and anything that surprised you. When a step is wrong, correct it here, from what happened and not from what was planned.

## Before you start

You need:

- **Two servers**, each with Docker and Compose, and a domain name for each.
- **A copy of a real WordPress site**, with Teme's companion plugin installed on it, and an application password for an administrator there.
- **A place off the servers for backups**: an S3-compatible bucket or an SFTP server.
- **Outgoing mail** that works, so Teme can email you.
- **Cloudflare**, optionally, in front of the first server.
- **A clock**, to time every step.

## Stage 1: Deploy the first server

| Step | What to do | Took | Surprise |
|---|---|---|---|
| 1.1 | Install Teme on the first server, as [Installing with the Compose recipe](install.md) says, with your proxy or platform in front. | | |
| 1.2 | Wait for the `deploy` role to finish and for `web` to be healthy. Note how long the first deploy took. | | |
| 1.3 | Ask the health address: `curl -I http://127.0.0.1:8080/up` answers `200`. | | |
| 1.4 | Run `docker compose exec web php artisan teme:doctor`. Every check is green, including **Database role**. Fix what it names. | | |
| 1.5 | Make the first administrator on the setup page: open the address the deploy's log ends with and enter its setup code (`docker compose exec web php artisan teme:setup-code` prints them again). Or use `docker compose exec web php artisan teme:user:create --email=you@example.com --name=You --role=administrator`, open the link and sign in. | | |

## Stage 2: Import a copy of a real site

| Step | What to do | Took | Surprise |
|---|---|---|---|
| 2.1 | Connect the WordPress copy: `docker compose exec web php artisan teme:import https://old.example.com --user=admin`, or use **Tools › Import**. | | |
| 2.2 | Look at what the analysis found, and choose what to import. | | |
| 2.3 | Run the import and wait for it to finish. Note how long it took and how much it brought. | | |
| 2.4 | Walk every old address: `docker compose exec web php artisan teme:import:walk https://old.example.com`. It must show no address a reader would get a 404 for. | | |
| 2.5 | Look through the imported site in the browser: pages, posts, images, menus, comments. | | |

## Stage 3: Rehearse the cut-over

| Step | What to do | Took | Surprise |
|---|---|---|---|
| 3.1 | Publish a new post on the WordPress copy. | | |
| 3.2 | Bring across what changed: `docker compose exec web php artisan teme:import https://old.example.com --catch-up`. | | |
| 3.3 | Confirm the new post arrived, with its images. | | |

## Stage 4: Publish from Teme

| Step | What to do | Took | Surprise |
|---|---|---|---|
| 4.1 | Publish a post in Teme. | | |
| 4.2 | Check that the page cache serves it, and that the post shows in the sitemap and the feed. | | |
| 4.3 | If Cloudflare is on, check that the purge happened: [Cloudflare in front of Teme](cloudflare.md). | | |

## Stage 5: Back up off the server

| Step | What to do | Took | Surprise |
|---|---|---|---|
| 5.1 | Set backups up, with the off-server place: `docker compose exec web php artisan teme:backup:setup`. Keep the recovery key on paper. | | |
| 5.2 | Run a first backup: `docker compose exec web php artisan teme:backup:run`. Note how long it took and how big it is. | | |
| 5.3 | Verify it: `docker compose exec web php artisan teme:backup:verify`. Both the read-back and the test restore pass. | | |

## Stage 6: Restore onto the second server

| Step | What to do | Took | Surprise |
|---|---|---|---|
| 6.1 | Install Teme on the second server, with the same image tag, and let it deploy. | | |
| 6.2 | Restore with the recovery key alone: `docker compose exec web php artisan teme:backup:restore`, naming the off-server place as [Restoring, moving and exporting](restore-and-moving.md) says. Note how long it took. | | |
| 6.3 | Say that this server is a copy: `docker compose exec web php artisan teme:backup:restored copy`. | | |
| 6.4 | Smoke test the second server: sign in, open the imported pages, look at the media, publish nothing. | | |

## Stage 7: Compare, and write down what surprised you

| Step | What to do | Took | Surprise |
|---|---|---|---|
| 7.1 | On the first server, write its fingerprint to a file; copy the file to the second server; there, compare. It asks the second server for every address the first one's lists, and ends with the two sites matching or each difference. On the first server: `docker compose exec -T web php artisan teme:site:fingerprint > fingerprint-a.txt` then on the second, once the file is there: `docker compose cp fingerprint-a.txt web:/tmp/fingerprint-a.txt` then: `docker compose exec web php artisan teme:site:fingerprint --compare=/tmp/fingerprint-a.txt` | | |
| 7.2 | Add up the times of every step above. | | |
| 7.3 | Correct this page from what happened: every step that was wrong, missing or slow. | | |
