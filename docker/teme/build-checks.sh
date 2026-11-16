#!/bin/sh
# The image's build checks: the build stops here, naming what is
# missing, rather than ship an image that fails on a server later. The
# Dockerfile runs it last, as root, with RESTIC_VERSION set.
set -eu

missing() {
    echo "Teme's image can't be built: $*" >&2
    exit 1
}

# Whether version $1 is $2 or later, field by field.
at_least() {
    [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n 1)" = "$2" ]
}

for extension in intl exif gd pcntl redis pdo_pgsql; do
    php -m | grep -qix "$extension" || missing "PHP's $extension extension isn't loaded."
done

php -r 'exit(defined("PASSWORD_ARGON2ID") ? 0 : 1);' || missing "PHP has no Argon2id (PASSWORD_ARGON2ID)."

php -r 'exit(function_exists("imagewebp") ? 0 : 1);' || missing "PHP's gd can't write WebP."

pg_dump_version="$(pg_dump --version | sed -n 's/^pg_dump (PostgreSQL) \([0-9][0-9.]*\).*$/\1/p')"
{ [ -n "$pg_dump_version" ] && at_least "$pg_dump_version" 18; } || missing "pg_dump is ${pg_dump_version:-missing}; Teme needs 18 or later."

nginx_version="$(nginx -v 2>&1 | sed -n 's#^nginx version: nginx/\([0-9][0-9.]*\).*$#\1#p')"
{ [ -n "$nginx_version" ] && at_least "$nginx_version" 1.29.3; } || missing "nginx is ${nginx_version:-missing}; Teme's settings need 1.29.3 or later."

restic version | grep -q "^restic ${RESTIC_VERSION} " || missing "restic isn't ${RESTIC_VERSION}."

for program in ssh ssh-keyscan curl; do
    command -v "$program" >/dev/null || missing "$program isn't installed."
done

[ "$(stat -c %U /var/www/html)" = root ] || missing "/var/www/html isn't root's."
[ "$(stat -c %U /var/www/html/app)" = root ] || missing "the code isn't root's."
[ "$(stat -c %U /var/www/html/storage)" = www-data ] || missing "storage isn't www-data's."
[ -L /var/www/html/public/storage ] || missing "public/storage isn't linked."
id -u teme-installer 2>/dev/null | grep -qx 9990 || missing "the installer's user (teme-installer, uid 9990) doesn't exist."
[ "$(stat -c %u /var/teme/extensions)" = 9990 ] || missing "/var/teme/extensions isn't the installer's."
[ -f /var/www/html/bootstrap/cache/teme-core-pins.php ] || missing "the core pins (teme:extensions:pins) weren't written."

echo "Teme's image has PHP $(php -r 'echo PHP_VERSION;'), pg_dump ${pg_dump_version}, nginx ${nginx_version} and restic ${RESTIC_VERSION}."
