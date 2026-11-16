---
title: Backups
audience: editors
kind: task
who: Administrators
screens:
  - tools.backups.index
  - tools.backups.setup
  - tools.backups.keys.index
sources:
  - resources/js/pages/tools/backups
  - resources/js/components/backups
  - app/Domain/Backups/BackupWords.php
---

# Backups

**Tools › Backups** is where an administrator sets up the site's backups and checks that they are safe. Teme backs up the database and every media file each night, and checks each backup by reading it back.

This page is for administrators. Everyone else can skip it.

## To see whether your backups are safe

Open **Tools › Backups**. The top of the screen, under **The state of the backups**, gives a verdict in one of three levels.

- **All well**: the backups are made and checked, and nothing needs you.
- **Needs a look**: something could be better, such as a place that hasn't answered yet. The screen says what.
- **Needs attention**: something is wrong and a backup may not be safe. The screen says what, and what to do.

Below the verdict are the newest backup, with what it holds, and a list of checks. When a banner at the top of the admin says to see the backups, it is repeating the first thing the verdict found; see [status banners](status-banners.md).

## To set up backups

If nothing is backed up yet, the screen says so and offers **Set up backups**.

1. Choose **Set up backups**.
2. Choose where backups are kept: a folder on this server, S3-compatible storage, or an SFTP server. For a place off this server, fill in what the screen asks for.
3. Name the recovery key, after who keeps it, such as "Ada's key".
4. Teme shows you the **Recovery key** once. Have a pen and paper or a printer ready, and write it down. Keep it offline, somewhere safe.
5. Type back the two groups of the key that the screen asks for. This proves you wrote it down.

*Warning:* backups are locked with the recovery key. With it and a copy of the backups, this site can be brought back on any server. Without it, nobody can open them, Teme included. Teme forgets the key as soon as it has set up.

Until you have typed the key back, the screen says backups aren't set up yet. To finish, type the groups it asks for. If you never saw the key, choose **Show a new key instead**.

## To add a place or change one

Under **Where backups are kept**, each place is a row.

- Choose **Add a place** to keep a copy off this server, in case the server is lost. A second place is kept in step with the others.
- Choose **Change** on a row to edit it, or **Remove** to stop keeping backups there. The backups already there stay there, and only a recovery key opens them.

For S3-compatible storage you give the address and the keys the service gave you. For an SFTP server, setup has two steps:

1. Teme makes a key pair and shows you the **Public key**. Choose **Copy** and add it to the server's `authorized_keys`, as the screen describes. It lets Teme sign in to that server only.
2. Teme reads the server's fingerprint and shows it to you. Ask whoever runs that server to check that it is theirs, then continue.

## To change the schedule

The schedule form says **Back up each night at** an hour, in the site's time zone. Choose an hour when few people visit. It also says how many daily, weekly and monthly backups to keep. Older backups are removed once a week, never while a backup runs. Choose **Save**.

## To back up now

Choose **Back up now**. It puts a backup in line, and the scheduler starts it within a minute, once nothing else is running. The button is off, with the reason shown, while a backup waits or runs, or while backups are paused. While it waits you can **Cancel** it.

## To check a place

On a place's row, choose **Test** to see whether Teme can reach it. Choose **Measure what it holds** to see how much is there. A place that hasn't answered shows that on its row.

## To make a new recovery key or extend one

Open the recovery keys from the screen. Each key is listed with the places it opens.

- **Replace** makes a new key, shows it once, and keeps the old one working until you have typed part of the new one back.
- **Add another key** makes one more key that opens every place, for someone else to keep, such as a partner or a colleague. Name it after who keeps it.
- If a place was added after a key was made, the screen says the key doesn't open it. Choose **Add my key to new places**, type your recovery key, and Teme adds it there too, then forgets it.
- **Check my recovery key** tries a key you hold on every place, to be sure it still opens them.

## To restore

You do not restore from this screen. Restoring is done at the server, so ask whoever runs the server. A restore checks everything first, backs the site up as it is, then restores.

After a restore, backups are paused until an administrator says whether this server is now the site or a copy of it, because two sites must never back up into one place. The screen asks the question and shows what each answer does.
