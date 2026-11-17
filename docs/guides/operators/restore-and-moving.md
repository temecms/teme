---
title: Restoring, moving and exporting
audience: operators
proved: unproved
written_for: the restore and export commands at Teme's own tag
---

# Restoring, moving and exporting

> Not proved yet: a restore onto a second server has not been tested outside development.

A backup is how you get a site back after a failure, and how you move it to a new server. This page covers restoring onto a fresh server, moving to a new domain, changing the application key on purpose, and exporting everything as plain files.

## To restore on a fresh server

Code never travels in a backup, so install the same version of Teme first.

1. Install Teme on the new server, as [Installing with the Compose recipe](install.md) says, with the same image tag the backup was made by.
2. Make sure the database has run this version's migrations. `deploy` does that when the recipe starts. A restore refuses a database that has not.
3. Make sure there is room under `storage/app` for one copy of the database's dump, and that `ssh` is there if a place is an SFTP server. The image has it.
4. Run the restore, naming the place the backups are in. A person must type the recovery key, so run it at a terminal:

   ```sh
   docker compose exec web php artisan teme:backup:restore --place=offsite --kind=s3 --bucket=my-backups
   ```

   Give the run ID from `teme:backup:list` as the first argument to restore a particular backup; without one it restores the newest complete backup. The place options are the same ones `teme:backup:list` takes, and `teme:backup:restore --help` lists them all.
5. When it finishes, say whether this server is now the site, or only a copy of it:

   ```sh
   docker compose exec web php artisan teme:backup:restored site
   ```

   Answer `site` and the server's backups resume. Answer `copy` and the backup places are removed from this server, so a copy never writes into the real site's backups. Until you answer, backups are paused and the Status page says so.

The restore takes a safety backup of whatever the server holds first, unless you pass `teme:backup:restore --without-safety-backup`. It asks before it replaces anything, unless you pass `teme:backup:restore --force`. While it runs, the site is in maintenance mode and `/up` fails on purpose.

## To move to a new domain

1. Change `APP_URL` in `.env` to the new address.
2. Run `docker compose up --detach`, then clear the page cache:

   ```sh
   docker compose exec web php artisan teme:cache:clear
   ```

Teme stores no site address in content: internal links hold the target's ID and media is referenced by ID, and every address is made from `APP_URL` when a page is drawn. So nothing in your content is rewritten.

## To change the application key on purpose

The application key encrypts secrets Teme keeps, such as the stored backup places' credentials. Changing it takes five steps, in this order:

1. Print a new key without changing any file:

   ```sh
   docker compose exec web php artisan key:generate --show
   ```

2. In `.env`, set `APP_PREVIOUS_KEYS` to the old key, with any that are already there, comma-separated. Put the new key in `secrets/app_key`. Restart with `docker compose up --detach`. Sessions and cookies keep working, because Teme opens them with the previous keys meanwhile.
3. Re-encrypt the stored secrets under the new key. Teme tells you what it will do and waits for you to press Enter:

   ```sh
   docker compose exec web php artisan teme:secrets:reencrypt
   ```

4. When it says every value opens with this server's key, let the queue drain, and retry or flush any failed jobs: a queued job can hold a value encrypted under the old key. Then remove the old key from `APP_PREVIOUS_KEYS` and restart.
5. Take a backup. Older backups carry the old key.

To open secrets in a restored site that were encrypted under a key you hold in a file, give the file with `teme:secrets:reencrypt --key-file=old-key.json`.

## To export

An export writes the site's content, people and media as plain files, so you can read them without Teme.

```sh
docker compose exec web php artisan teme:export
```

Without a folder it writes under `storage/app/exports`, in a folder named for the date and time. Name a folder that does not exist yet to put it elsewhere.

- **Room:** as much free disk as your media's originals plus the text.
- **Copy it off the server, then delete it.** The folder holds people's email addresses and every draft.
- **Do not export during a deploy.** An export holds a long transaction, so a migration waits for it, and the site's queries queue behind the migration.
- **A killed export** can leave a folder named with `.partial-` beside the export's folder. Delete it by hand.
