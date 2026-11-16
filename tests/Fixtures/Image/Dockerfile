# The image of Teme's container test: the base image, Teme's start files exactly
# as the Dockerfile places them, and a stand-in for the application.
ARG SERVERSIDEUP_IMAGE
FROM ${SERVERSIDEUP_IMAGE}
USER root
COPY --chmod=755 docker/teme/bin/teme-entrypoint /usr/local/bin/teme-entrypoint
COPY --chmod=755 docker/teme/bin/teme-healthcheck /usr/local/bin/teme-healthcheck
COPY --chmod=755 docker/teme/bin/php-as-site /usr/local/sbin/php
COPY --chmod=755 docker/teme/lib/ /usr/local/lib/teme/
COPY --chmod=755 docker/teme/s6-rc.d/ /usr/local/share/teme/s6-rc.d/
COPY --chmod=755 docker/teme/entrypoint.d/60-teme-start.sh /etc/entrypoint.d/60-teme-start.sh
COPY --chmod=644 docker/nginx/conf.d/teme-static.conf /etc/nginx/conf.d/teme-static.conf
COPY --chmod=644 docker/nginx/conf.d/teme-edge.conf /etc/nginx/conf.d/teme-edge.conf
COPY --chmod=644 docker/nginx/site-opts.d/http.conf.template /etc/nginx/site-opts.d/http.conf.template
COPY --chmod=644 docker/nginx/server-opts.d/ /etc/nginx/server-opts.d/
COPY tests/Fixtures/Image/app/nginx-hsts.php /var/www/html/docker/teme/nginx-hsts.php
COPY tests/Fixtures/Image/app/artisan /var/www/html/artisan
COPY tests/Fixtures/Image/app/public/index.php /var/www/html/public/index.php
RUN groupadd --system --gid 9990 teme-installer \
    && useradd --system --uid 9990 --gid 9990 --no-create-home --shell /usr/sbin/nologin teme-installer \
    && install -d -o 9990 -g 9990 -m 0755 /var/teme/extensions \
    && usermod --append --groups www-data nginx \
    && chown root:root /var/www/html && chmod 0755 /var/www/html \
    && mkdir -p storage/framework/cache storage/app/public /var/www/html/storage/page-cache \
    && ln -s ../storage/app/public /var/www/html/public/storage \
    && chown -R www-data:www-data /var/www/html/storage
WORKDIR /var/www/html
ENV APP_ENV=production AUTORUN_ENABLED=false SHOW_WELCOME_MESSAGE=false HEALTHCHECK_PATH=/up \
    APP_CONFIG_CACHE=/var/www/html/storage/framework/cache/config.php
ENTRYPOINT ["teme-entrypoint"]
CMD ["/init"]
STOPSIGNAL SIGTERM
HEALTHCHECK --interval=2s --timeout=3s --start-period=60s --start-interval=1s --retries=2 CMD ["teme-healthcheck"]
