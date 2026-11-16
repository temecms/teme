<?php

declare(strict_types=1);

use App\Domain\Deployment\SecureTransport;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Deployment\ImageFiles;

/*
 * The start step can't run here (it writes into /etc/nginx), so its order
 * is read as text. The map it writes is printed by a PHP script, which
 * runs here for real, with every variable it reads set or removed, and is
 * compared with Teme's own rule.
 */

it('caches the configuration, then the routes, then writes nginx\'s HSTS map, and stops at the first failure', function (): void {
    expect(ImageFiles::directives('docker/teme/entrypoint.d/60-teme-start.sh'))->toBe([
        'set -eu',
        'cd /var/www/html',
        // The configuration holds every secret: only the user that runs the site can read the file.
        '(umask 077 && php artisan config:cache --no-interaction)',
        'php artisan route:cache --no-interaction',
        'php docker/teme/nginx-hsts.php > /etc/nginx/conf.d/teme-hsts.conf.new',
        'mv /etc/nginx/conf.d/teme-hsts.conf.new /etc/nginx/conf.d/teme-hsts.conf',
    ]);
});

it('prints the map with the value Teme sends, under Teme\'s own conditions', function (string|false $environment, string $url, string|false $hsts): void {
    $process = new Process([PHP_BINARY, 'docker/teme/nginx-hsts.php'], base_path(), ['APP_ENV' => $environment, 'APP_URL' => $url, 'TEME_HSTS' => $hsts]);
    $process->mustRun();

    $value = SecureTransport::hsts(
        ($environment === false ? 'production' : $environment) === 'production',
        $url,
        $hsts === false ? SecureTransport::DEFAULT_HSTS : $hsts,
    );
    $sent = $value === null ? '' : "    \"|https\" \"{$value}\";\n";

    expect($process->getOutput())->toBe(
        "# Written by docker/teme/nginx-hsts.php when the container starts.\n"
        ."map \"\$upstream_addr|\$http_x_forwarded_proto\" \$teme_hsts {\n"
        .$sent
        ."    default \"\";\n"
        ."}\n"
    );
})->with([
    'production at https, TEME_HSTS unset' => ['production', 'https://example.com', false],
    'production at https, a value of its own' => ['production', 'https://example.com', 'max-age=63072000; includeSubDomains; preload'],
    'production at https, TEME_HSTS empty' => ['production', 'https://example.com', ''],
    'production at http' => ['production', 'http://example.com', false],
    'a local site at https' => ['local', 'https://example.com', false],
    'APP_ENV unset, which is production' => [false, 'https://example.com', false],
    'a scheme in capitals' => ['production', 'HTTPS://EXAMPLE.COM', false],
]);

it('refuses a TEME_HSTS nginx could not carry, and prints no map', function (): void {
    $process = new Process([PHP_BINARY, 'docker/teme/nginx-hsts.php'], base_path(), [
        'APP_ENV' => 'production',
        'APP_URL' => 'https://example.com',
        'TEME_HSTS' => 'max-age=1"; add_header X-Injected 1; "',
    ]);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toBe("Teme cannot start: TEME_HSTS isn't a Strict-Transport-Security value nginx can send.\n");
});
