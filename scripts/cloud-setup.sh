#!/usr/bin/env bash
#
# Takes a fresh Ubuntu 24.04 cloud container (root, no Docker daemon) to a green
# `composer check` and `composer check:browser`. Idempotent: safe to re-run.
#
# Run from the repository root:  bash scripts/cloud-setup.sh
# Then, in the shell that runs the gate:
#   export PATH=/opt/node24/bin:$PATH COMPOSER_ALLOW_SUPERUSER=1
#   composer check            # Pint, PHPStan, tsc, vp check, theme build, feature suite
#   composer check:browser    # Browser suite (Pest + Playwright)
#
# Written from a pilot run; the numbers in the comments are what that run saw.

set -euo pipefail

# Leaves a line when it starts and another when it finishes, so a person can
# tell whether the environment's own setup step ran this, from which folder, and
# whether it got to the end: a line with no "finished" after it was cut off (B-456).
echo "$(date -u +%FT%TZ) ran in $PWD as $(id -un)" >> /tmp/teme-cloud-setup.log

export DEBIAN_FRONTEND=noninteractive
export COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_MIRROR_PATH_REPOS=1

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# phpstan/phpstan is the only locked package with no git source, only a
# zipball on api.github.com. This sandbox's GitHub proxy answers 403 for that
# URL ("GitHub access to this repository is not enabled for this session"),
# though plain `git clone` of public repos works. So composer.lock is edited
# temporarily to point phpstan at a git checkout of the same commit, then
# restored. Drop this whenever zipball access works.
# The commit is read from composer.lock, so a PHPStan update needs no edit here.
PHPSTAN_COMMIT=$(python3 -c 'import json; lock = json.load(open("composer.lock", encoding="utf-8")); print(next(p["dist"]["reference"] for p in lock["packages-dev"] + lock["packages"] if p["name"] == "phpstan/phpstan"))')
PHPSTAN_DIR=/home/user/phpstan/phpstan
NODE_MAJOR=24

# --- 1. apt: PHP 8.4 (the lock needs >= 8.4; the image ships 8.3) and PostgreSQL 18

mkdir -p /etc/apt/keyrings
if [ ! -f /etc/apt/sources.list.d/ondrej-php.list ]; then
    curl -fsSL 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x71DAEAAB4AD4CAB6' | gpg --dearmor --yes -o /etc/apt/keyrings/ondrej-php.gpg
    echo "deb [signed-by=/etc/apt/keyrings/ondrej-php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main" > /etc/apt/sources.list.d/ondrej-php.list
fi
if [ ! -f /etc/apt/sources.list.d/pgdg.list ]; then
    curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor --yes -o /etc/apt/keyrings/pgdg.gpg
    echo "deb [signed-by=/etc/apt/keyrings/pgdg.gpg] https://apt.postgresql.org/pub/repos/apt noble-pgdg main" > /etc/apt/sources.list.d/pgdg.list
fi
apt-get update -qq
apt-get install -y -qq unzip git \
    php8.4-cli php8.4-pgsql php8.4-intl php8.4-gd php8.4-zip php8.4-bcmath \
    php8.4-mbstring php8.4-xml php8.4-curl php8.4-redis php8.4-sqlite3 \
    postgresql-18 valkey-server
update-alternatives --set php /usr/bin/php8.4 >/dev/null 2>&1 || true

# --- 1b. restic, pinned by the SHA-256 its release publishes (I-2400), for
# the backup tests. Its SHA256SUMS, read 2026-10-15:
# https://github.com/restic/restic/releases/download/v0.19.1/SHA256SUMS

RESTIC_VERSION=0.19.1
RESTIC_SHA256=f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c

if ! restic version 2>/dev/null | grep -q "^restic ${RESTIC_VERSION} "; then
    [ "$(uname -m)" = x86_64 ] || { echo "restic: only linux amd64 is pinned here" >&2; exit 1; }
    apt-get install -y -qq bzip2
    tmp="$(mktemp -d)"
    curl -fsSL -o "$tmp/restic.bz2" "https://github.com/restic/restic/releases/download/v${RESTIC_VERSION}/restic_${RESTIC_VERSION}_linux_amd64.bz2"
    echo "${RESTIC_SHA256}  $tmp/restic.bz2" | sha256sum -c -
    bunzip2 "$tmp/restic.bz2"
    install -m 0755 "$tmp/restic" /usr/local/bin/restic
    rm -rf "$tmp"
fi
restic version

# --- 2. Node 24: tests/Fixtures/speculation.mjs uses the URLPattern global,
# which Node 22 (the image's) lacks. Node >= 23.8 is enough.

if ! /opt/node24/bin/node -v 2>/dev/null | grep -q "^v${NODE_MAJOR}\."; then
    tmp="$(mktemp -d)"
    version="$(curl -fsS https://nodejs.org/dist/index.json | python3 -c "import json,sys;print([x['version'] for x in json.load(sys.stdin) if x['version'].startswith('v${NODE_MAJOR}.')][0])")"
    curl -fsS -o "$tmp/node.tar.xz" "https://nodejs.org/dist/${version}/node-${version}-linux-x64.tar.xz"
    (cd "$tmp" && curl -fsS "https://nodejs.org/dist/${version}/SHASUMS256.txt" | grep "node-${version}-linux-x64.tar.xz" | sed "s#  node-.*#  node.tar.xz#" | sha256sum -c -)
    mkdir -p /opt/node24
    tar -xJf "$tmp/node.tar.xz" -C /opt/node24 --strip-components=1
    rm -rf "$tmp"
fi
export PATH=/opt/node24/bin:$PATH

# --- 3. PostgreSQL 18 on 5433 and Valkey on 6380, matching phpunit.xml.
# No Docker daemon here, so these run natively instead of via docker-compose.yml.
# The cluster must be UTF8: with the default SQL_ASCII, the migration that adds
# the "und-x-icu" collation fails and nearly every test errors.

if ! pg_lsclusters -h | awk '$1==18 && $2=="test"{found=1} END{exit !found}'; then
    pg_createcluster 18 test -p 5433 -e UTF8 --locale C.UTF-8 -- --auth-local=trust --auth-host=scram-sha-256
fi
pg_lsclusters -h | awk '$1==18 && $2=="test" && $4=="online"{up=1} END{exit !up}' || pg_ctlcluster 18 test start
until pg_isready -q -h 127.0.0.1 -p 5433; do sleep 1; done
su postgres -c "psql -p 5433 -Atq" <<'SQL'
SELECT 'CREATE ROLE teme LOGIN SUPERUSER PASSWORD ''teme''' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'teme')\gexec
SELECT 'CREATE DATABASE teme_test OWNER teme' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'teme_test')\gexec
SQL

if ! valkey-cli -p 6380 ping >/dev/null 2>&1; then
    valkey-server --port 6380 --daemonize yes --save '' --appendonly no >/dev/null
fi
until valkey-cli -p 6380 ping >/dev/null 2>&1; do sleep 1; done

# --- 4. PHP dependencies. --prefer-source because the proxy 403s the zipball
# (dist) URLs; git clones work. The phpstan override is applied to composer.lock
# only for the install, and reverted afterwards, so nothing is left to commit.

if [ ! -d "$PHPSTAN_DIR/.git" ]; then
    mkdir -p "$PHPSTAN_DIR"
    git -C "$PHPSTAN_DIR" init -q
    git -C "$PHPSTAN_DIR" remote add origin https://github.com/phpstan/phpstan
fi
if [ "$(git -C "$PHPSTAN_DIR" rev-parse HEAD 2>/dev/null || true)" != "$PHPSTAN_COMMIT" ]; then
    git -C "$PHPSTAN_DIR" fetch -q --depth 1 origin "$PHPSTAN_COMMIT"
    git -C "$PHPSTAN_DIR" checkout -q FETCH_HEAD
fi

# Put back the committed lock, never a saved copy: a run cut off before this
# point would leave the edited lock, and the next run would save that as the
# original (B-452).
restore_lock() { git -C "$REPO_ROOT" checkout -- composer.lock; }
restore_lock
trap restore_lock EXIT
PHPSTAN_COMMIT="$PHPSTAN_COMMIT" PHPSTAN_DIR="$PHPSTAN_DIR" python3 - <<'PY'
import json, os
lock = json.load(open('composer.lock'))
for group in ('packages', 'packages-dev'):
    for package in lock[group]:
        if package['name'] == 'phpstan/phpstan':
            assert package['dist']['reference'] == os.environ['PHPSTAN_COMMIT'], 'phpstan pin changed; update PHPSTAN_COMMIT'
            package['dist'] = {'type': 'path', 'url': os.environ['PHPSTAN_DIR'], 'reference': os.environ['PHPSTAN_COMMIT']}
json.dump(lock, open('composer.lock', 'w'), indent=4)
PY
composer install --no-interaction --prefer-source --no-progress
restore_lock
trap - EXIT

# --- 5. App config and front-end build. No migrate: tests migrate their own databases.

[ -f .env ] || cp .env.example .env
# PostgreSQL 18's own tools, never postgresql-common's wrapper in /usr/bin,
# which picks a version by cluster (I-2400).
grep -q '^TEME_POSTGRES_BINDIR=' .env || printf '\nTEME_POSTGRES_BINDIR=/usr/lib/postgresql/18/bin\n' >> .env
grep -q '^APP_KEY=.\+' .env || php artisan key:generate --no-interaction
npm ci --no-audit --no-fund
npm run build
# The route helpers with their form variants, as the Vite plugin makes them;
# without them `composer check`'s types:check fails on every form (B-461).
php artisan wayfinder:generate --with-form

# --- 6. Playwright for the Browser suite. The sandbox pre-installs one
# Chromium headless shell under /opt/pw-browsers, and `playwright install` is
# off-limits here. When the repo's Playwright asks for another revision, its
# directory is pointed at the newest one installed: Chromium launches fine under
# a newer client. The wanted revision is read from Playwright itself, so a
# Playwright update needs no edit here.

PW=/opt/pw-browsers
WANT="$(node -p 'require("./node_modules/playwright-core/browsers.json").browsers.find((b) => b.name === "chromium-headless-shell").revision' 2>/dev/null || true)"
if [ -n "$WANT" ] && [ ! -e "$PW/chromium_headless_shell-$WANT/INSTALLATION_COMPLETE" ]; then
    HAVE="$(ls -d "$PW"/chromium_headless_shell-* 2>/dev/null | grep -v -- "-$WANT\$" | sort -t- -k2 -n | tail -1)"
    if [ -n "$HAVE" ]; then
        if [ -d "$HAVE/chrome-headless-shell-linux64" ]; then
            SRC="$HAVE/chrome-headless-shell-linux64"
        else
            # Older images keep the binary as chrome-linux/headless_shell.
            SRC="$HAVE/chrome-linux"
            ln -sfn headless_shell "$SRC/chrome-headless-shell"
        fi
        mkdir -p "$PW/chromium_headless_shell-$WANT"
        ln -sfn "$SRC" "$PW/chromium_headless_shell-$WANT/chrome-headless-shell-linux64"
        touch "$PW/chromium_headless_shell-$WANT/INSTALLATION_COMPLETE"
    fi
fi

echo "$(date -u +%FT%TZ) finished" >> /tmp/teme-cloud-setup.log
echo "Setup complete. Run: export PATH=/opt/node24/bin:\$PATH COMPOSER_ALLOW_SUPERUSER=1 && composer check"
