---
title: Sizing a server
audience: operators
proved: unproved
written_for: the defaults Teme ships with, not measurements
---

# Sizing a server

> Not proved yet: the figures below are Teme's defaults and a formula, not measurements from a real server.

Three things decide how big a server Teme needs: how many people sign in at once, how much you store, and how many Teme processes run beside the web server. This page gives the formula for each.

## The four settings

Passwords are hashed with Argon2id, which is slow on purpose. These four settings control how slow, and how long a sign-in takes to answer.

| Setting | Default | What it controls |
|---|---|---|
| `ARGON_MEMORY` | 65536 | The memory one hash uses, in KiB. 65536 is 64 MiB. |
| `ARGON_TIME` | 4 | How many passes the hash makes. |
| `ARGON_THREADS` | 1 | How many threads one hash uses. |
| `AUTH_TIMEBOX_DURATION` | 1100000 | The shortest time a sign-in takes to answer, in microseconds. |

## To size memory for simultaneous sign-ins

One hash uses `ARGON_MEMORY` of memory for as long as it runs. The `web` role runs up to `PHP_FPM_PM_MAX_CHILDREN` PHP workers (the recipe's default is 10), and every one of them can be hashing at once, so the worst case is the number of workers times the hash's memory: 10 times 64 MiB is 640 MiB. Add PHP's own memory for each worker on top, and give the `web` container more than the total.

If memory is short, lower `PHP_FPM_PM_MAX_CHILDREN` before you lower the hash's cost: the hash's cost is what protects a stolen password from being cracked.

## To choose the cost

Aim for a hash that takes 250 to 500 milliseconds on the server. `docker compose exec web php artisan teme:doctor` times one hash and warns when it is outside that range.

- Too fast: raise `ARGON_TIME`, or `ARGON_MEMORY` if the server has the memory to spare.
- Too slow: lower `ARGON_TIME` first, then `ARGON_MEMORY`.

Raise `ARGON_TIME` before `ARGON_MEMORY` when memory is short, because more passes cost time, not memory.

## To set the sign-in floor

`AUTH_TIMEBOX_DURATION` makes a sign-in with a wrong address take as long as one with a wrong password, so nobody can tell which addresses have accounts. It must be longer than the hash takes on your server. Set it to about twice the measured time, in microseconds: for a hash that takes 500 milliseconds, that is `1000000`.

## Disk

Count these:

- Originals and their variants, under the `media` volume.
- The local copy of a backup, if one of your places is a folder on the server.
- Room under `storage/app` for a restore's dump, for the weekly test restore and for an export. Each is about the size of the database; an export also holds your media.
- PostgreSQL's data, in the `pgdata` volume.

## Memory of the other roles

The recipe runs `worker`, `worker-long` and `scheduler` as three more PHP processes beside `web`, each with its own memory. Count each one when you size the server. They are small beside `web` at its busiest, but not nothing.
