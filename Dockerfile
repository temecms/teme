# Teme's image (I-375): one image for every role, on serversideup/php's
# fpm-nginx variant at v4.5.1. Built in CI from a checkout of tracked files
# (.dockerignore); a build on a person's machine uses git archive HEAD as
# its context. amd64 only: restic is pinned for it.

ARG SERVERSIDEUP_IMAGE=serversideup/php:8.4-fpm-nginx-trixie-v4.5.1@sha256:8e0864511c48a943b59c59c2845eb8de1dc402b6d549a3b2e0e45018f1b99567
ARG NODE_IMAGE=node:24.21.0-trixie-slim@sha256:173f125896c3b47ddf056734c7ea789d04595a6a08769a8f78e0df642781fb66
ARG RESTIC_VERSION=0.19.1

FROM ${NODE_IMAGE} AS node

# PHP, every extension and program Teme needs, and Debian's own fixes.
FROM ${SERVERSIDEUP_IMAGE} AS php
USER root
ARG RESTIC_VERSION
ARG RESTIC_SHA256=f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c
ARG PGDG_FINGERPRINT=B97B0AFCAA1A47F044F244A07FCC7D46ACCC4CF8
ARG POSTGRESQL_CLIENT_VERSION=18.6-1.pgdg13+2
RUN export DEBIAN_FRONTEND=noninteractive \
    && apt-mark hold nginx \
    && apt-get update \
    && apt-get upgrade -y -o Dpkg::Options::=--force-confold \
    && apt-get install -y --no-install-recommends bzip2 ca-certificates curl gnupg openssh-client \
    && curl -fsSL -o /tmp/pgdg.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc \
    && gpg --batch --show-keys --with-colons /tmp/pgdg.asc | grep -q "^fpr:::::::::${PGDG_FINGERPRINT}:$" \
    && gpg --batch --yes --output /usr/share/keyrings/pgdg.gpg --dearmor /tmp/pgdg.asc \
    && echo "deb [signed-by=/usr/share/keyrings/pgdg.gpg] https://apt.postgresql.org/pub/repos/apt trixie-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends "postgresql-client-18=${POSTGRESQL_CLIENT_VERSION}" \
    && curl -fsSL -o /tmp/restic.bz2 "https://github.com/restic/restic/releases/download/v${RESTIC_VERSION}/restic_${RESTIC_VERSION}_linux_amd64.bz2" \
    && echo "${RESTIC_SHA256}  /tmp/restic.bz2" | sha256sum -c - \
    && bunzip2 /tmp/restic.bz2 \
    && install -m 0755 /tmp/restic /usr/local/bin/restic \
    && install-php-extensions intl exif gd \
    && apt-get purge -y gnupg \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/* /tmp/*

# The code, with Composer's production packages only.
FROM php AS code
COPY . /var/www/html
RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

# The admin's and the themes' builds. Vite's Wayfinder plugin runs artisan,
# so this stage builds on the code, with Node copied in.
FROM code AS assets
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && npm ci --no-audit --no-fund \
    && npm run build \
    && npm run build:themes

# What every role runs.
FROM php AS runtime
ARG RESTIC_VERSION
ENV APP_NAME=Teme \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info \
    APP_MAINTENANCE_DRIVER=cache \
    APP_MAINTENANCE_STORE=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=database \
    SESSION_DRIVER=database \
    DB_CONNECTION=pgsql \
    APP_CONFIG_CACHE=/var/www/html/storage/framework/cache/config.php \
    APP_ROUTES_CACHE=/var/www/html/storage/framework/cache/routes-v7.php \
    AUTORUN_ENABLED=false \
    AUTORUN_LARAVEL_MIGRATION=false \
    HEALTHCHECK_PATH=/up \
    SHOW_WELCOME_MESSAGE=false \
    PHP_OPCACHE_ENABLE=1 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    NGINX_CLIENT_MAX_BODY_SIZE=100M \
    NGINX_FASTCGI_BUFFER_SIZE=32k \
    NGINX_FASTCGI_BUFFERS="16 16k" \
    PHP_UPLOAD_MAX_FILE_SIZE=100M \
    PHP_POST_MAX_SIZE=100M
COPY --from=code /var/www/html /var/www/html
COPY --from=assets /var/www/html/public/build /var/www/html/public/build
COPY --from=assets /var/www/html/themes /var/www/html/themes
COPY --chmod=755 docker/teme/entrypoint.d/60-teme-start.sh /etc/entrypoint.d/60-teme-start.sh
COPY --chmod=644 docker/nginx/conf.d/teme-static.conf /etc/nginx/conf.d/teme-static.conf
COPY --chmod=644 docker/nginx/conf.d/teme-edge.conf /etc/nginx/conf.d/teme-edge.conf
COPY --chmod=644 docker/nginx/site-opts.d/http.conf.template /etc/nginx/site-opts.d/http.conf.template
COPY --chmod=644 docker/nginx/server-opts.d/remoteip.conf /etc/nginx/server-opts.d/remoteip.conf
COPY --chmod=644 docker/nginx/server-opts.d/security.conf /etc/nginx/server-opts.d/security.conf
COPY --chmod=644 docker/nginx/server-opts.d/performance.conf /etc/nginx/server-opts.d/performance.conf
COPY --chmod=644 docker/nginx/server-opts.d/teme-backups.conf /etc/nginx/server-opts.d/teme-backups.conf
RUN chown root:root /var/www/html \
    && chmod 0755 /var/www/html \
    && mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs storage/page-cache \
    && php artisan event:cache \
    && php artisan view:cache \
    && php artisan storage:link \
    && php artisan teme:themes:link \
    && php artisan teme:themes:cache \
    && php artisan teme:plugins:cache \
    && php artisan teme:extensions:pins \
    && groupadd --system --gid 9990 teme-installer \
    && useradd --system --uid 9990 --gid 9990 --no-create-home --shell /usr/sbin/nologin teme-installer \
    && install -d -o 9990 -g 9990 -m 0755 /var/teme/extensions \
    && rm -f package.json package-lock.json .npmrc tsconfig.json vite.config.ts vite.theme.config.ts \
    && chown -R www-data:www-data storage \
    && RESTIC_VERSION="${RESTIC_VERSION}" sh docker/teme/build-checks.sh
# The release this image is, and its commit (I-?? draft): passed by the image
# workflow; empty for a build from a branch, which reports 0.1.0 untagged.
ARG TEME_VERSION=
ARG TEME_REVISION=
ENV TEME_VERSION=${TEME_VERSION} TEME_REVISION=${TEME_REVISION}
USER www-data
