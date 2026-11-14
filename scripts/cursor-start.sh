#!/usr/bin/env bash
#
# Starts the test services on a Cursor cloud agent's machine, as
# `.cursor/environment.json`'s start step: an environment's build keeps the
# disk, not running processes. PostgreSQL 18 on 5433 and Valkey on 6380, as
# phpunit.xml expects, with the test role and database. Idempotent.

set -euo pipefail

SUDO=""
[ "$(id -u)" -eq 0 ] || SUDO="sudo"

if ! pg_lsclusters -h | awk '$1==18 && $2=="test" && $4=="online"{up=1} END{exit !up}'; then
    $SUDO pg_ctlcluster 18 test start
fi
until pg_isready -q -h 127.0.0.1 -p 5433; do sleep 1; done
AS_POSTGRES="sudo -u postgres"
[ "$(id -u)" -eq 0 ] && AS_POSTGRES="runuser -u postgres --"
$AS_POSTGRES psql -p 5433 -Atq <<'SQL'
SELECT 'CREATE ROLE teme LOGIN SUPERUSER PASSWORD ''teme''' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'teme')\gexec
SELECT 'CREATE DATABASE teme_test OWNER teme' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'teme_test')\gexec
SQL

if ! valkey-cli -p 6380 ping >/dev/null 2>&1; then
    valkey-server --port 6380 --daemonize yes --save '' --appendonly no >/dev/null
fi
until valkey-cli -p 6380 ping >/dev/null 2>&1; do sleep 1; done

echo "Services up: PostgreSQL 18 on 5433, Valkey on 6380."
