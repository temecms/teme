---
title: Backups
audience: operators
proved: tests
written_for: the backups Teme runs at its own tag
---

# Backups

Teme backs itself up, to places you choose, every night, and checks that it can read the backups back. This page says what a backup holds, how to set it up and check it, and what it needs from your server. You need backups before the site holds anything you would miss.

## What a backup holds

A backup holds:

- the database;
- the media: every original and every variant;
- the application key, so a restored site can read what it had encrypted.

It never holds code. To restore, you install the same version of Teme first: see [Restoring and moving](restore-and-moving.md).

## Where it goes

Backups go to a place. A place is a folder, an S3-compatible bucket or an SFTP server. You can have several: Teme copies each backup to every place, and one place failing never stops the others. A place on the server itself protects against a mistake, not against losing the server, so Teme keeps the verdict amber until at least one place is somewhere else.

Teme uses restic, which stores encrypted, deduplicated snapshots, so each night adds only what changed.

## The keys

Two kinds of key open a backup:

- **The recovery key** is yours alone. Teme shows it once, when you set backups up, and asks you to type two groups of it back to prove you kept it. Teme keeps no copy. Print it and keep it offline. Without it, and without the server, there is no way back to the backups.
- **The machine key** lets the server back up and check by itself, with nobody there. Teme makes one when you set up, or takes the one you give it in `TEME_BACKUP_MACHINE_KEY`, or in the file `TEME_BACKUP_MACHINE_KEY_FILE` names.

## To set up

Either run the command at a terminal, where you can see the recovery key:

```sh
docker compose exec web php artisan teme:backup:setup
```

or open **Tools › Backups** in the admin as an administrator. The command refuses to run without a person at the terminal, because it shows the recovery key once and asks for part of it back.

To add, test, change or remove places later, use the screen, or `teme:backup:destinations`. To add, check, extend or replace a recovery key, use `teme:backup:key`.

## To check

Run these when you set up, and whenever you want to know:

```sh
docker compose exec web php artisan teme:backup:tools
docker compose exec web php artisan teme:backup:list
docker compose exec web php artisan teme:backup:verify --read-back
docker compose exec web php artisan teme:backup:verify --test-restore
```

- `teme:backup:tools` says where `restic` and PostgreSQL's tools are, and what is wrong with them.
- `teme:backup:list` lists the backups at a place, newest first.
- `teme:backup:verify --read-back` reads part of every place's data back and checks it.
- `teme:backup:verify --test-restore` restores the newest backup into the spare database and compares it with what the backup says it holds. Without a spare database it is skipped. See [The database](database.md).

With neither option, `teme:backup:verify` runs both.

## The schedule

The `scheduler` role runs the schedule; there is nothing to set up on the host. Every minute it asks `teme:backup:due` what is due. In turn it starts:

- the nightly backup, at the hour you choose on the screen, and the read-back after it;
- the weekly prune, which forgets old backups by the rule you set on the screen: a backup from each of the last days, then of weeks, then of months;
- the weekly test restore.

A server that was off at the hour backs up when it is back. If no backup has started two hours after the hour, the Status page shows a red banner and Teme emails every administrator: a dead scheduler shows up that way.

You can start a backup at any time with `teme:backup:run`, or with **Back up now** on **Tools › Backups**. **Back up now** puts a backup in line as a tracked job; the scheduler picks it up and starts it within a minute, so the scheduler must be running.

## What it needs of the server

- `restic`, `pg_dump`, `pg_restore` and `ssh` in every role. The image has them all, in one image.
- Room for the dump under `storage/app`, about the size of the database.
- Working mail, so Teme can email an alert when backups fail. See [Configuring Teme](configure.md).
- The scheduler running.

The doctor checks the programs and the spare database: see **Backup programs** and **Test restore database** in [Troubleshooting](troubleshooting.md).

## Platform backups of the database

A platform such as Dokploy or Coolify can also back up the database it runs. That is an unencrypted last resort, not a replacement: if you use it, keep the bucket private and the retention to seven days, and never use it for the media volume.
