---
title: The migration runbook
audience: operators
proved: unproved
written_for: moving a WordPress site to Teme on two servers
---

# The migration runbook

> Not proved yet: this checklist has not been tested from start to finish. Fill in the Took and Surprise columns as you go, and correct a step when what happens differs from what it says.

This is a checklist for moving a real WordPress site to Teme, and then proving that you can restore it onto a second server. Do it once, on a copy, before you trust Teme with a site you cannot lose.

Work through the stages in order. For each step, write down how long it took and anything that surprised you. When a step is wrong, correct it here, from what happened and not from what was planned.

## Before you start

You need:

- **Two servers**, each with Docker and Compose, and a domain name for each.
- **A copy of a real WordPress site**, with Teme's companion plugin installed on it, and an application password for an administrator there.
  If the plugin can't be installed there, its export file will do for the content: see *If you import from an export file*, after Stage 2.
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
| 2.5 | Look through the imported site in the browser: pages, posts, images, menus, comments. The menus come across unplaced: place them under **Content › Navigation**. Widgets and theme settings don't come across: set them up in Teme's theme. | | |

### If you import from an export file

Where the companion plugin can't be installed, or the old site is gone, use its export file instead. In WordPress, open Tools › Export, choose All content and download the `.xml` file. In Teme, open **Tools › Import**, choose **Upload a file** and drop the file in; it goes up in pieces, so a dropped connection carries on. The file's plan then asks what the file can't say: who each author is here, whether the media files are downloaded from the old site (only while it answers), kept there as links, or brought after the articles in a picture pass, where each menu sits, and the category and tag addresses. Steps 2.2 to 2.5 are the same.

The file brings the content and each item's address, but not passwords and roles, site settings, widgets and theme settings, redirects kept by plugins or spam comments; the plan lists them. In Stage 3, bring what changed by exporting the site again and choosing **Upload a newer file** on the report, in place of the catch-up command in step 3.2. When the import is done, choose **Delete the file…** on the report: the file holds drafts, private posts and people's email addresses. Teme deletes it 30 days after the last import from it in any case.

### If the old site's pictures are on a copy of its uploads folder

When the old site is closed, offline, or only on a local address, or when a live import would put many pictures' worth of load on it, bring a copy of its `wp-content/uploads` folder instead. Copy it once, off-peak, with `tar` or `rsync`. Teme matches each picture by its path under `uploads/` and fetches only what the copy lacks. Attach the copy to the site's import on the server, where it stays where it is and Teme never changes or deletes it:

```bash
docker compose exec web php artisan teme:import:uploads https://old.example.com /srv/old-site/wp-content/uploads
```

The path is a folder, or a `.zip` of the folder, that the server can read. Attaching again replaces the copy. A catch-up reuses the copy. Import readers get no pictures from the copy in this release: their media carry only a file name.

A copy of up to 10 GB can also be uploaded in the browser, on the import's plan, in pieces like an export file. The cap is the `teme.import.max_uploads_mb` setting (`TEME_IMPORT_MAX_UPLOADS_MB`). A larger copy goes on the server with the command. A browser copy is kept on the private disk and deleted when it is replaced; a folder named on the server is never deleted by Teme. The report counts the pictures the import brought from the copy and from the old site, since the copy was attached.

## Stage 3: Rehearse the cut-over

| Step | What to do | Took | Surprise |
|---|---|---|---|
| 3.1 | Publish a new post on the WordPress copy. | | |
| 3.2 | Bring across what changed: `docker compose exec web php artisan teme:import https://old.example.com --catch-up`. | | |
| 3.3 | Confirm the new post arrived, with its images. | | |

## If the site has fediverse followers

Skip this section unless the WordPress site uses the ActivityPub plugin. Teme brings the plugin's accounts across with their keys and followers, and keeps every account at its old address, so people who follow the site on Mastodon keep following it with nothing to do. Prove that on a staging copy before you move the real site.

You need a staging copy of the WordPress site on a domain of its own, with the ActivityPub plugin active and Teme's companion plugin new enough to move followers (an import says when it isn't), and a throwaway account on a Mastodon server.

| Fediverse step | What to do | Took | Surprise |
|---|---|---|---|
| F.1 | From the throwaway account, follow the staging site's blog account, and an author's account if the site has them. Note the follower counts the ActivityPub plugin shows in WordPress. | | |
| F.2 | Import the staging copy into Teme, as in Stage 2. The report has a **fediverse-actor** row for each account and a **fediverse-follower** row for each follower, and no fediverse error. A line saying Teme made a new key is a warning to read: that account's followers' servers pick the new key up the next time they hear from it. | | |
| F.3 | Open **Settings › Fediverse**. Each imported account is listed with the followers WordPress had, and the switch is on if the plugin was active. | | |
| F.4 | Point the staging domain at Teme. Teme sends to the fediverse only from the site's own address, and outside production only when the configuration value `teme.fediverse.deliver_outside_production` is on: turn it on for the staging server for this test. | | |
| F.5 | Ask for each account at its old address, as Mastodon does: `curl -H 'Accept: application/activity+json' https://staging.example.com/?author=0`, and the same for each author's `?author=` number. Each answers its account, with the same `id` and key the WordPress site gave. | | |
| F.6 | Publish a new post in Teme. It arrives in the throwaway account's home timeline, from the account it already followed. | | |
| F.7 | Reply to that post from the throwaway account. The reply arrives in Teme as a comment held for moderation. | | |
| F.8 | Unfollow from the throwaway account. **Settings › Fediverse** shows one follower fewer. | | |
| F.9 | Turn `teme.fediverse.deliver_outside_production` off on the staging server again. | | |

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
