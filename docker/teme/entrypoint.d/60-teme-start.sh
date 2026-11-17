#!/bin/sh
# Teme's start step. The image's entrypoint runs it before every
# role's command (web, worker, scheduler and deploy), as the user the
# container runs as. It caches the configuration and the routes with this
# server's environment and key, then writes the map nginx needs to send
# Strict-Transport-Security with the files it serves itself. Any failure
# stops the container: config:cache names every reason a production site
# can't start.
set -eu

cd /var/www/html

php artisan config:cache --no-interaction
php artisan route:cache --no-interaction

php docker/teme/nginx-hsts.php > /etc/nginx/conf.d/teme-hsts.conf.new
mv /etc/nginx/conf.d/teme-hsts.conf.new /etc/nginx/conf.d/teme-hsts.conf
