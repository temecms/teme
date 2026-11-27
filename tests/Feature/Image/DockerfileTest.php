<?php

declare(strict_types=1);

use App\Domain\Deployment\SecretFiles;
use Tests\Fixtures\Deployment\ImageFiles;

/*
 * The Dockerfile, read as instructions: the suite can't build it. CI's
 * image workflow builds it and its build checks run there.
 */

it('builds on serversideup/php v5.2.0\'s fpm-nginx image for trixie, pinned by digest (I-3104)', function (): void {
    expect(ImageFiles::instructions()[0])->toBe('ARG SERVERSIDEUP_IMAGE=serversideup/php:8.4-fpm-nginx-trixie-v5.2.0@sha256:a9152816e2a652b75bd68c3ed3621d2b6188d82d4d7ecae93660a3057136ba24')
        ->and(ImageFiles::stage('php')[0])->toBe('FROM ${SERVERSIDEUP_IMAGE} AS php')
        ->and(ImageFiles::stage('runtime')[0])->toBe('FROM php AS runtime');
});

it('pins every image it builds from by digest, and copies only from its own stages', function (): void {
    $instructions = ImageFiles::instructions();
    $images = array_values(array_filter($instructions, fn (string $instruction): bool => preg_match('/^ARG [A-Z_]+_IMAGE=/', $instruction) === 1));
    $stages = array_values(array_filter($instructions, fn (string $instruction): bool => str_starts_with($instruction, 'FROM ')));

    expect($images)->toHaveCount(2)
        ->and($stages)->toBe(['FROM ${NODE_IMAGE} AS node', 'FROM ${SERVERSIDEUP_IMAGE} AS php', 'FROM php AS code', 'FROM code AS assets', 'FROM php AS runtime'])
        ->and(implode("\n", $instructions))->not->toMatch('#COPY --from=[^ ]*[:/]#');

    foreach ($images as $image) {
        expect($image)->toMatch('/@sha256:[0-9a-f]{64}$/');
    }
});

it('installs restic only once its published checksum matches', function (): void {
    $php = implode("\n", ImageFiles::stage('php'));

    expect(ImageFiles::instructions())->toContain('ARG RESTIC_VERSION=0.19.1')
        ->and($php)->toContain('ARG RESTIC_SHA256=f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c')
        ->and($php)->toContain('restic_${RESTIC_VERSION}_linux_amd64.bz2')
        ->and($php)->toContain('echo "${RESTIC_SHA256}  /tmp/restic.bz2" | sha256sum -c -')
        ->and(strpos($php, 'sha256sum -c -'))->toBeLessThan(strpos($php, 'install -m 0755 /tmp/restic /usr/local/bin/restic'));
});

it('installs PostgreSQL 18\'s client from PGDG, its key checked by fingerprint first', function (): void {
    $php = implode("\n", ImageFiles::stage('php'));

    expect($php)->toContain('ARG PGDG_FINGERPRINT=B97B0AFCAA1A47F044F244A07FCC7D46ACCC4CF8')
        ->and($php)->toContain('ARG POSTGRESQL_CLIENT_VERSION=18.6-1.pgdg13+2')
        ->and($php)->toContain('grep -q "^fpr:::::::::${PGDG_FINGERPRINT}:$"')
        ->and($php)->toContain('https://apt.postgresql.org/pub/repos/apt trixie-pgdg main')
        ->and($php)->toContain('"postgresql-client-18=${POSTGRESQL_CLIENT_VERSION}"')
        ->and(strpos($php, '${PGDG_FINGERPRINT}:$"'))->toBeLessThan(strpos($php, '--dearmor'));
});

it('takes Debian\'s fixes but keeps the base\'s own nginx (I-3104)', function (): void {
    $php = implode("\n", ImageFiles::stage('php'));

    expect(strpos($php, 'apt-mark hold nginx'))->toBeLessThan(strpos($php, 'apt-get upgrade -y -o Dpkg::Options::=--force-confold'))
        ->and($php)->not->toMatch('/install[^&]*\bnginx\b/');
});

it('installs intl, exif, gd, OpenSSH\'s client and curl, and checks every extension and program Teme needs', function (): void {
    $php = implode("\n", ImageFiles::stage('php'));
    $checks = ImageFiles::text('docker/teme/build-checks.sh');

    expect($php)->toContain('install-php-extensions intl exif gd')
        ->and($php)->toContain('--no-install-recommends bzip2 ca-certificates curl gnupg openssh-client')
        ->and($checks)->toContain('for extension in intl exif gd pcntl redis pdo_pgsql; do')
        ->and($checks)->toContain('for program in ssh ssh-keyscan curl; do');
});

it('stops the build when something it needs is missing or too old', function (string $check): void {
    expect(ImageFiles::text('docker/teme/build-checks.sh'))->toContain($check);
})->with([
    'stopping at the first failure' => 'set -eu',
    'Argon2id' => "php -r 'exit(defined(\"PASSWORD_ARGON2ID\") ? 0 : 1);'",
    'WebP, which every media variant is' => "php -r 'exit(function_exists(\"imagewebp\") ? 0 : 1);'",
    'pg_dump 18' => 'at_least "$pg_dump_version" 18;',
    'nginx 1.29.3' => 'at_least "$nginx_version" 1.29.3;',
    'restic' => 'restic version | grep -q "^restic ${RESTIC_VERSION} "',
    'the code\'s owner' => '[ "$(stat -c %U /var/www/html/app)" = root ]',
    'storage\'s owner' => '[ "$(stat -c %U /var/www/html/storage)" = www-data ]',
    'the storage link' => '[ -L /var/www/html/public/storage ]',
]);

it('leaves the code root\'s, gives www-data storage alone, checks, then starts as root', function (): void {
    $runtime = ImageFiles::stage('runtime');
    $text = implode("\n", $runtime);
    preg_match_all('/chown\s+(?:-R\s+)?(\S+)\s+(\S+)/', $text, $chowns, PREG_SET_ORDER);

    expect(array_map(fn (array $chown): string => "{$chown[1]} {$chown[2]}", $chowns))->toBe(['root:root /var/www/html', 'www-data:www-data storage'])
        ->and($text)->not->toContain('--chown')
        ->and(end($runtime))->toBe('USER root')
        ->and($text)->toMatch('#chown -R www-data:www-data storage\s+&& RESTIC_VERSION="\$\{RESTIC_VERSION\}" sh docker/teme/build-checks\.sh$#m');
});

it('starts as root only so teme-entrypoint can drop to www-data, or keep root for TEME_ROLE=all', function (): void {
    $runtime = ImageFiles::stage('runtime');
    $entrypoint = ImageFiles::text('docker/teme/bin/teme-entrypoint');

    expect($runtime)->toContain('ENTRYPOINT ["teme-entrypoint"]')
        // Setting an ENTRYPOINT clears the base image's CMD.
        ->and($runtime)->toContain('CMD ["/init"]')
        ->and($entrypoint)->toContain('exec setpriv --reuid=www-data --regid=www-data --init-groups "$base" "$@"')
        ->and($entrypoint)->toContain('exec "$base" "$@"')
        ->and($entrypoint)->toContain('exec /usr/local/lib/teme/start-all "$@"')
        // No role keeps the way every compose service always started: as www-data, before anything runs.
        ->and(strpos($entrypoint, 'if [ "$role" != all ]'))->toBeLessThan(strpos($entrypoint, 'start-all'));
});

it('never lets root run PHP: the shim comes after every build step that does', function (): void {
    $runtime = ImageFiles::stage('runtime');
    $shim = array_search('COPY --chmod=755 docker/teme/bin/php-as-site /usr/local/sbin/php', $runtime, true);
    $lastRootPhp = null;

    foreach ($runtime as $index => $instruction) {
        if (str_starts_with($instruction, 'RUN ') && str_contains($instruction, 'php artisan')) {
            $lastRootPhp = $index;
        }
    }

    expect($shim)->toBeInt()->and($lastRootPhp)->toBeInt()->and($shim)->toBeGreaterThan($lastRootPhp)
        ->and(ImageFiles::text('docker/teme/bin/php-as-site'))->toContain('exec setpriv --reuid=www-data --regid=www-data --init-groups /usr/local/bin/php "$@"');
});

it('stops on SIGTERM, which s6\'s init answers, and not the base image\'s SIGQUIT, which it ignores', function (): void {
    expect(ImageFiles::stage('runtime'))->toContain('STOPSIGNAL SIGTERM');
});

it('checks the whole container in one health check, whose start period outlasts a migration', function (): void {
    $runtime = implode("\n", ImageFiles::stage('runtime'));

    expect($runtime)->toMatch('/^HEALTHCHECK --interval=10s --timeout=5s --start-period=15m --start-interval=3s --retries=3 CMD \["teme-healthcheck"\]$/m');
});

it('puts Teme\'s start files where its entrypoint, its s6 services and its health check look for them', function (string $from, string $to, string $mode): void {
    expect(ImageFiles::stage('runtime'))->toContain("COPY --chmod={$mode} {$from} {$to}");
})->with([
    ['docker/teme/bin/teme-entrypoint', '/usr/local/bin/teme-entrypoint', '755'],
    ['docker/teme/bin/teme-healthcheck', '/usr/local/bin/teme-healthcheck', '755'],
    ['docker/teme/lib/', '/usr/local/lib/teme/', '755'],
    ['docker/teme/s6-rc.d/', '/usr/local/share/teme/s6-rc.d/', '755'],
]);

it('joins nginx to www-data\'s group, so its workers read what PHP writes, as the entry for root runs them as nginx', function (): void {
    expect(implode("\n", ImageFiles::stage('runtime')))->toContain('usermod --append --groups www-data nginx');
});

it('takes the version and the commit as the runtime stage’s last settings, so a new version rebuilds nothing', function (): void {
    $runtime = ImageFiles::stage('runtime');
    $count = count($runtime);

    expect(array_slice($runtime, $count - 4))->toBe([
        'ARG TEME_VERSION=',
        'ARG TEME_REVISION=',
        'ENV TEME_VERSION=${TEME_VERSION} TEME_REVISION=${TEME_REVISION}',
        'USER root',
    ]);
});

it('sets the production environment every role starts from', function (string $name, string $value): void {
    expect(ImageFiles::runtimeEnvironment())->toHaveKey($name, $value);
})->with([
    ['APP_ENV', 'production'],
    ['APP_DEBUG', 'false'],
    ['LOG_CHANNEL', 'stderr'],
    ['APP_MAINTENANCE_DRIVER', 'cache'],
    ['APP_MAINTENANCE_STORE', 'database'],
    ['CACHE_STORE', 'database'],
    ['QUEUE_CONNECTION', 'database'],
    ['SESSION_DRIVER', 'database'],
    ['DB_CONNECTION', 'pgsql'],
    ['HEALTHCHECK_PATH', '/up'],
    ['PHP_OPCACHE_ENABLE', '1'],
    ['PHP_OPCACHE_VALIDATE_TIMESTAMPS', '0'],
]);

it('never migrates as a container starts, and runs none of serversideup\'s Laravel steps', function (): void {
    $environment = ImageFiles::runtimeEnvironment();

    expect($environment['AUTORUN_ENABLED'] ?? null)->toBe('false')
        ->and($environment['AUTORUN_LARAVEL_MIGRATION'] ?? null)->toBe('false')
        ->and(ImageFiles::text('Dockerfile').ImageFiles::text('docker/teme/entrypoint.d/60-teme-start.sh'))->not->toMatch('/artisan\s+migrate/');
});

it('caches events and views at build, and the configuration and routes only at start, under storage', function (): void {
    $runtime = implode("\n", ImageFiles::stage('runtime'));
    $environment = ImageFiles::runtimeEnvironment();

    expect($runtime)->toContain('php artisan event:cache', 'php artisan view:cache', 'php artisan storage:link', 'php artisan teme:themes:link', 'php artisan teme:themes:cache')
        ->and(ImageFiles::text('Dockerfile'))->not->toMatch('/artisan\s+(config:cache|route:cache|optimize)\b/')
        ->and($environment['APP_CONFIG_CACHE'] ?? null)->toBe('/var/www/html/storage/framework/cache/config.php')
        ->and($environment['APP_ROUTES_CACHE'] ?? null)->toBe('/var/www/html/storage/framework/cache/routes-v7.php');
});

it('bakes no key or secret into the image', function (): void {
    $names = implode('|', SecretFiles::NAMES);

    expect(ImageFiles::text('Dockerfile'))->not->toMatch("/\\b({$names})(_FILE)?=/")
        ->and(ImageFiles::text('Dockerfile'))->not->toContain('key:generate');
});

it('accepts uploads at least as large as Largest upload\'s default', function (string $name): void {
    // Largest upload defaults to 64 MB, and the server's limits must be at least that (docs/architecture/media.md).
    expect((int) rtrim(ImageFiles::runtimeEnvironment()[$name] ?? '0', 'M'))->toBeGreaterThanOrEqual(64);
})->with(['NGINX_CLIENT_MAX_BODY_SIZE', 'PHP_UPLOAD_MAX_FILE_SIZE', 'PHP_POST_MAX_SIZE']);

it('sizes nginx\'s FastCGI buffers to hold a full admin page\'s response headers', function (): void {
    // The image's PHP location sets these from its own variables, so a server-level line would lose to them.
    $environment = ImageFiles::runtimeEnvironment();

    expect($environment['NGINX_FASTCGI_BUFFER_SIZE'] ?? null)->toBe('32k')
        ->and($environment['NGINX_FASTCGI_BUFFERS'] ?? null)->toBe('16 16k');
});

it('puts the start step in the entrypoint\'s folder and each of Teme\'s nginx files over the image\'s own', function (string $from, string $to, string $mode): void {
    expect(ImageFiles::stage('runtime'))->toContain("COPY --chmod={$mode} {$from} {$to}");
})->with([
    ['docker/teme/entrypoint.d/60-teme-start.sh', '/etc/entrypoint.d/60-teme-start.sh', '755'],
    ['docker/nginx/conf.d/teme-static.conf', '/etc/nginx/conf.d/teme-static.conf', '644'],
    ['docker/nginx/conf.d/teme-edge.conf', '/etc/nginx/conf.d/teme-edge.conf', '644'],
    ['docker/nginx/site-opts.d/http.conf.template', '/etc/nginx/site-opts.d/http.conf.template', '644'],
    ['docker/nginx/server-opts.d/remoteip.conf', '/etc/nginx/server-opts.d/remoteip.conf', '644'],
    ['docker/nginx/server-opts.d/security.conf', '/etc/nginx/server-opts.d/security.conf', '644'],
    ['docker/nginx/server-opts.d/performance.conf', '/etc/nginx/server-opts.d/performance.conf', '644'],
    ['docker/nginx/server-opts.d/teme-backups.conf', '/etc/nginx/server-opts.d/teme-backups.conf', '644'],
]);
