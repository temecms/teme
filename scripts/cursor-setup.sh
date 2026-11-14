#!/usr/bin/env bash
#
# Prepares a Cursor cloud agent's machine (Ubuntu 24.04) for `composer check`
# and `composer check:browser`. Cursor runs it as `.cursor/environment.json`'s
# install step, when it builds the environment; running processes don't carry
# over from a build, so scripts/cursor-start.sh starts the services. Idempotent:
# safe to re-run. scripts/cloud-setup.sh is the same for Claude's cloud, whose
# sandbox differs (root, blocked zipballs, a pre-installed browser).
#
# Then, in the shell that runs the gate:
#   export PATH=/opt/node24/bin:$PATH
#   composer check            # Pint, PHPStan, tsc, vp check, theme build, feature suite
#   composer check:browser    # Browser suite (Pest + Playwright)

set -euo pipefail

echo "$(date -u +%FT%TZ) cursor-setup ran in $PWD as $(id -un)" >> /tmp/teme-cursor-setup.log

SUDO=""
[ "$(id -u)" -eq 0 ] || SUDO="sudo"
export DEBIAN_FRONTEND=noninteractive
export COMPOSER_MIRROR_PATH_REPOS=1

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

NODE_MAJOR=24

# --- 1. apt: PHP 8.4 (the lock needs >= 8.4), PostgreSQL 18 and Valkey.

$SUDO mkdir -p /etc/apt/keyrings
if [ ! -f /etc/apt/sources.list.d/ondrej-php.list ]; then
    curl -fsSL 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x71DAEAAB4AD4CAB6' | $SUDO gpg --dearmor --yes -o /etc/apt/keyrings/ondrej-php.gpg
    echo "deb [signed-by=/etc/apt/keyrings/ondrej-php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main" | $SUDO tee /etc/apt/sources.list.d/ondrej-php.list >/dev/null
fi
if [ ! -f /etc/apt/sources.list.d/pgdg.list ]; then
    curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc | $SUDO gpg --dearmor --yes -o /etc/apt/keyrings/pgdg.gpg
    echo "deb [signed-by=/etc/apt/keyrings/pgdg.gpg] https://apt.postgresql.org/pub/repos/apt noble-pgdg main" | $SUDO tee /etc/apt/sources.list.d/pgdg.list >/dev/null
fi
$SUDO apt-get update -qq
$SUDO apt-get install -y -qq unzip git bzip2 python3 \
    php8.4-cli php8.4-pgsql php8.4-intl php8.4-gd php8.4-zip php8.4-bcmath \
    php8.4-mbstring php8.4-xml php8.4-curl php8.4-redis php8.4-sqlite3 \
    postgresql-18 valkey-server
$SUDO update-alternatives --set php /usr/bin/php8.4 >/dev/null 2>&1 || true

# --- 1b. Composer, checked against the installer's published signature.

if ! command -v composer >/dev/null 2>&1; then
    tmp="$(mktemp -d)"
    curl -fsSL -o "$tmp/composer-setup.php" https://getcomposer.org/installer
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    actual="$(php -r "echo hash_file('sha384', '$tmp/composer-setup.php');")"
    [ "$expected" = "$actual" ] || { echo "composer installer: signature mismatch" >&2; exit 1; }
    php "$tmp/composer-setup.php" --quiet --install-dir="$tmp"
    $SUDO install -m 0755 "$tmp/composer.phar" /usr/local/bin/composer
    rm -rf "$tmp"
fi

# --- 1c. restic, pinned by the SHA-256 its release publishes (I-2400), for
# the backup tests: https://github.com/restic/restic/releases/download/v0.19.1/SHA256SUMS

RESTIC_VERSION=0.19.1
RESTIC_SHA256=f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c

if ! restic version 2>/dev/null | grep -q "^restic ${RESTIC_VERSION} "; then
    [ "$(uname -m)" = x86_64 ] || { echo "restic: only linux amd64 is pinned here" >&2; exit 1; }
    tmp="$(mktemp -d)"
    curl -fsSL -o "$tmp/restic.bz2" "https://github.com/restic/restic/releases/download/v${RESTIC_VERSION}/restic_${RESTIC_VERSION}_linux_amd64.bz2"
    echo "${RESTIC_SHA256}  $tmp/restic.bz2" | sha256sum -c -
    bunzip2 "$tmp/restic.bz2"
    $SUDO install -m 0755 "$tmp/restic" /usr/local/bin/restic
    rm -rf "$tmp"
fi

# --- 2. Node 24 in /opt/node24: tests/Fixtures/speculation.mjs needs the
# URLPattern global (Node >= 23.8).

if ! /opt/node24/bin/node -v 2>/dev/null | grep -q "^v${NODE_MAJOR}\."; then
    tmp="$(mktemp -d)"
    version="$(curl -fsS https://nodejs.org/dist/index.json | python3 -c "import json,sys;print([x['version'] for x in json.load(sys.stdin) if x['version'].startswith('v${NODE_MAJOR}.')][0])")"
    curl -fsS -o "$tmp/node.tar.xz" "https://nodejs.org/dist/${version}/node-${version}-linux-x64.tar.xz"
    (cd "$tmp" && curl -fsS "https://nodejs.org/dist/${version}/SHASUMS256.txt" | grep "node-${version}-linux-x64.tar.xz" | sed "s#  node-.*#  node.tar.xz#" | sha256sum -c -)
    $SUDO mkdir -p /opt/node24
    $SUDO tar -xJf "$tmp/node.tar.xz" -C /opt/node24 --strip-components=1
    rm -rf "$tmp"
fi
export PATH=/opt/node24/bin:$PATH

# --- 3. The test cluster: PostgreSQL 18 on 5433, UTF8 (phpunit.xml), made
# here and started by scripts/cursor-start.sh. The role and database are made
# there too, once the cluster runs.

if ! pg_lsclusters -h | awk '$1==18 && $2=="test"{found=1} END{exit !found}'; then
    $SUDO pg_createcluster 18 test -p 5433 -e UTF8 --locale C.UTF-8 -- --auth-local=trust --auth-host=scram-sha-256
fi
bash "$REPO_ROOT/scripts/cursor-start.sh"

# --- 4. PHP dependencies, from the committed lock.

composer install --no-interaction --no-progress

# --- 5. App config and front-end build. No migrate: tests migrate their own databases.

[ -f .env ] || cp .env.example .env
grep -q '^TEME_POSTGRES_BINDIR=' .env || printf '\nTEME_POSTGRES_BINDIR=/usr/lib/postgresql/18/bin\n' >> .env
grep -q '^APP_KEY=.\+' .env || php artisan key:generate --no-interaction
npm ci --no-audit --no-fund
npm run build
php artisan wayfinder:generate --with-form

# --- 6. Playwright's headless Chromium for the Browser suite, at the revision
# the locked Playwright asks for.

npx playwright install --with-deps chromium-headless-shell

echo "$(date -u +%FT%TZ) cursor-setup finished" >> /tmp/teme-cursor-setup.log
echo "Setup complete. Run: export PATH=/opt/node24/bin:\$PATH && composer check"
