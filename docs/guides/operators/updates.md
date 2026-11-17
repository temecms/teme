---
title: Updating and rolling back
audience: operators
proved: unproved
written_for: teme:deploy as the recipe runs it
---

# Updating and rolling back

> Not proved yet: an update by hand, and a rollback, have not been run on a real server. An update from the admin has been run on Dokploy.

An update is a new image. You never change code on a running server. You change the tag in `.env`, pull the new image and start it, and a one-off `deploy` role brings the database up to date before the other roles start.

## How an update works

1. The new image is pulled.
2. The `deploy` role runs `teme:deploy`, which migrates the database under a lock, taking a backup first when it must.
3. The new `web`, `worker`, `worker-long` and `scheduler` containers start and pass their health checks.
4. The old containers stop.

## To update

1. Read the release notes. They say whether the release needs maintenance mode (see below) and what its migrations change.
2. Change `TEME_IMAGE` in `.env` to the new exact tag.
3. Pull the image, then start it:

   ```sh
   docker compose pull
   docker compose up --detach
   ```

4. Check that nothing waits, and that the doctor is satisfied:

   ```sh
   docker compose exec web php artisan teme:deploy --check
   docker compose exec web php artisan teme:doctor
   ```

   `teme:deploy --check` changes nothing. It exits with 6 while migrations are pending, and with 0 when none are.

## Seeing new releases

Teme asks GitHub once an hour which releases exist. It sends nothing about the site. Tools › Updates shows the version running, the releases after it and what changed, and the deploys; a banner says when one is available.

To ask now, run the check in the scheduler's container, the only one with the token:

```sh
docker compose exec scheduler php artisan teme:updates:check
```

## Updating from the admin

On Dokploy, with the `updater` service running and a Dokploy API key pasted in **Settings › Updates** ([Running Teme on Dokploy](platforms/dokploy.md#switch-on-update-from-the-admin) says how), open **Tools › Updates** and press **Update to …**. Teme asks for your password again, records the request, and emails every administrator. The updater starts it within a minute, and the page shows each step as it is taken: the deploy (which backs up and migrates), then `web`, the workers and the scheduler on the new release, then a last check, then the updater itself.

The site is down for a short while as the service deploys, as when updating by hand. [Running Teme on Dokploy](platforms/dokploy.md) says how long and why. If the new release isn't healthy, the updater switches every part back, and the page says so. The database keeps the new release's additions, which the old one runs beside.

**Roll back** is offered after an update, as an update to the release before. It can't undo a release that needs maintenance mode (see below), or a failed migration: for that, restore the backup taken just before it with `teme:backup:restore`, as [Restoring and moving](restore-and-moving.md) says.

From the shell, in any Teme container, `php artisan teme:updates:request <version>` asks for an update, and `php artisan teme:updates:cancel` cancels one that hasn't started. The updater carries it out.

**Without Dokploy:** the updater works through Dokploy only. Update by hand, as *To update* says.

## The command and its exit codes

The command is `teme:deploy`, and it takes three options: `teme:deploy --check`, `teme:deploy --json` and `teme:deploy --wait=60`. It never asks a question, so a platform's deploy hook can run it.

| Exit code | Meaning |
|---|---|
| 0 | Deployed, or nothing was waiting. |
| 1 | It failed. |
| 2 | It refused, and nothing changed. |
| 3 | The backup failed, and nothing changed. |
| 4 | A migration failed. The ones before it have run. |
| 5 | It migrated, but a later step failed. Run it again. |
| 6 | Migrations are pending (`teme:deploy --check` only). |
| 7 | Busy: another deploy, or a backup, still held its lock after the wait. |

With `teme:deploy --json` it prints one line of JSON and nothing else, for a program to read. When a backup was taken, the line carries its run ID under `backup`, which is the ID `teme:backup:restore` takes.

## What `teme:deploy` does

1. Runs the preflight: the doctor's checks. An error stops the deploy, with exit code 2, before anything changes.
2. Takes the lock, so only one deploy runs on a database at a time. A second one waits, then finds nothing to do.
3. Takes a backup, only when migrations are waiting and backups are set up. If the release needs maintenance mode and the site is not in it, the deploy refuses first. If the backup fails, the deploy stops with exit code 3 and changes nothing. With backups not set up it says so, and carries on. A new database skips it: there is nothing to lose.
4. Runs `php artisan migrate --force`, also only when migrations are waiting.
5. Checks again the doctor checks that could not run before the database was migrated.
6. Queues the release's rebuilds, which fill in data a release adds to existing content. The worker runs them, as the operator.
7. Runs `queue:restart`, so the workers start again with this release's code.
8. Clears the page cache with `teme:cache:clear`.
9. Warms the page cache with `teme:cache:warm`, unless the page cache is off, `PAGE_CACHE_WARM` is `false`, or the site is in maintenance mode.

Every deploy clears the page cache. So after you change `APP_ENV` or `TEME_DISALLOW_SITE_CODE`, a deploy is all you need.

### What it waits for

It waits for the lock. `teme:deploy --wait=60` limits two waits, the lock and then a running backup, so a run can wait up to twice that before it does anything, and exits with 7 if either runs out. Without it, the deploy waits as long as it takes.

A deploy during the nightly backup is safe: it waits for the running backup, then takes its own.

The roles have stop grace periods, so containers get time to finish what they are doing: 6 minutes for a worker and 30 minutes for the scheduler.

### Data a release adds

When a release adds data to content that already exists, the migration fills it in when one statement can. Otherwise a rebuild is queued as the operator, so the `worker` role must be running for the data to appear.

## Releases that cannot be rolled back by image

Most migrations only add, so the old image can still run on the new database. A release that changes the database in a way the previous release cannot work with is marked in its release notes. Its migration runs only while the site is in maintenance mode, and pages keep being served from the page cache meanwhile.

If you deploy such a release without maintenance mode, the deploy refuses, with exit code 2, and says:

```text
Nothing was changed. Run php artisan down, then php artisan teme:deploy again, and php artisan up once the new release is serving.
```

So run `docker compose exec web php artisan down`, deploy, then `docker compose exec web php artisan up`.

Maintenance mode must be one that every container sees. The image sets `APP_MAINTENANCE_DRIVER=cache` and `APP_MAINTENANCE_STORE=database` for that. A build made any other way must set both, or `php artisan down` in one container goes unseen by the `deploy` container.

## To roll back

1. Put the previous tag in `.env`.
2. Run `docker compose pull`, then `docker compose up --detach`.

This is safe because migrations only add: the previous release runs on the newer database. A release that cannot be rolled back this way says so in its notes. A broken migration is undone from the backup the deploy took just before it: [Restoring and moving](restore-and-moving.md) says how.
