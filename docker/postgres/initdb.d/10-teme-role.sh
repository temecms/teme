#!/bin/sh
# Teme's database role and its two databases (I-379), made once, when the
# PostgreSQL image first creates its cluster, as the image's superuser,
# which Teme is never given. The role logs in and owns both databases, and
# so their public schema, but can't create databases or roles. The second
# database is the weekly test restore's.
set -eu

# The password as Teme reads its file: all of it, less one line break at
# the end. Through the environment and \getenv, never a command line.
TEME_DB_PASSWORD="$(cat "${DB_PASSWORD_FILE:?DB_PASSWORD_FILE names the file holding Teme's database password}"; printf .)"
TEME_DB_PASSWORD="${TEME_DB_PASSWORD%.}"
newline='
'
carriage_return="$(printf '\r')"
case "$TEME_DB_PASSWORD" in
    *"$newline")
        TEME_DB_PASSWORD="${TEME_DB_PASSWORD%"$newline"}"
        TEME_DB_PASSWORD="${TEME_DB_PASSWORD%"$carriage_return"}"
        ;;
esac
export TEME_DB_PASSWORD

psql -v ON_ERROR_STOP=1 --no-psqlrc --username "$POSTGRES_USER" --dbname postgres \
    --set=teme_role="${DB_USERNAME:?DB_USERNAME names Teme's database role}" \
    --set=teme_database="${DB_DATABASE:?DB_DATABASE names Teme's database}" \
    --set=teme_verify_database="${TEME_VERIFY_DATABASE:?TEME_VERIFY_DATABASE names the spare database}" <<'SQL'
\getenv teme_password TEME_DB_PASSWORD
CREATE ROLE :"teme_role" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD :'teme_password';
CREATE DATABASE :"teme_database" OWNER :"teme_role" TEMPLATE template0 ENCODING 'UTF8';
CREATE DATABASE :"teme_verify_database" OWNER :"teme_role" TEMPLATE template0 ENCODING 'UTF8';
REVOKE ALL ON DATABASE :"teme_database" FROM PUBLIC;
REVOKE ALL ON DATABASE :"teme_verify_database" FROM PUBLIC;
SQL
