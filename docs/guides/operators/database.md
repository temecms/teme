---
title: The database
audience: operators
proved: tests
written_for: PostgreSQL 18 and the role the recipe makes
---

# The database

Teme needs PostgreSQL 18 and nothing else for its data. The recipe runs one for you. This page says what the recipe makes, and how to make the same thing on a PostgreSQL that a platform or a provider runs for you.

## Why the role is not a superuser

Teme signs in to PostgreSQL as its own role, and that role is never a superuser. A site that is attacked through a flaw in the application must not be able to read other databases, load files from the server or run programs on it. A superuser can; Teme's role cannot. The role owns the site's database and can do everything inside it, and that is all it can do.

## What the recipe makes

When PostgreSQL first creates its data folder, the recipe runs one script, `docker/postgres/initdb.d/10-teme-role.sh`, as PostgreSQL's own superuser. It runs once, never again, and it makes:

- the role Teme signs in as, with a login and none of the powers that matter: no superuser, no creating databases, no creating roles, no replication, no bypassing row security;
- the site's database, owned by that role;
- a second, spare database, owned by the same role, which the weekly test restore uses;
- no access to either database for anyone else.

The names and the password come from the recipe's environment and its `secrets/db_password` file.

## To make the role on a platform's PostgreSQL

If a platform or a provider runs your PostgreSQL, you make the role yourself. Run these statements once, as the service's own superuser, connected to the `postgres` database. Choose a long random password in place of the one shown, and put the same password in the file or variable Teme reads it from.

```sql
CREATE ROLE teme LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD 'choose-a-long-random-password';
CREATE DATABASE teme OWNER teme TEMPLATE template0 ENCODING 'UTF8';
CREATE DATABASE teme_verify OWNER teme TEMPLATE template0 ENCODING 'UTF8';
REVOKE ALL ON DATABASE teme FROM PUBLIC;
REVOKE ALL ON DATABASE teme_verify FROM PUBLIC;
```

Then set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` (or `DB_PASSWORD_FILE`) to match, and `TEME_VERIFY_DATABASE` to the spare database's name.

A managed service whose own account is not a superuser may refuse the second statement, because PostgreSQL only lets you create a database for a role you are a member of. In that case grant yourself the role first, as that account: `GRANT teme TO your_account;`. Your provider's documentation says what its account is called.

## The spare database

The spare database is the one Teme restores a recent backup into every week, to prove that the backup works. Teme empties it before and after each test, so never put anything of yours in it. Its name must end in `_verify`. [Backups](backups.md) says how the test runs.

Without a spare database the test restore is never due, and the backup's verdict stays amber.

## The version

The image carries `pg_dump` and `pg_restore` from PostgreSQL 18, and the recipe runs PostgreSQL 18. A database server newer than the image's `pg_dump` is refused by the doctor and by the deploy, because the dump could not be restored with confidence. Update the image first, then the server.

## No connection pooler

Connect Teme to PostgreSQL directly. A pooler in the middle that hands out a different connection for each statement breaks the lock a deploy holds while it migrates, and two deploys could then run at once.

## Where the data lives

In the recipe, PostgreSQL's data is the `pgdata` volume, mounted at `/var/lib/postgresql`. Back the database up through Teme's own backups, not by copying that volume while PostgreSQL runs.
