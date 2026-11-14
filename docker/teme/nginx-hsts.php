<?php

/*
 * Prints the nginx map behind the Strict-Transport-Security header nginx
 * sends with the answers it gives without PHP: the page cache's files, the
 * build's and media. The value and its conditions are Teme's own rule,
 * SecureTransport::hsts(), read from the environment as config/app.php and
 * config/teme.php read it, so nginx and Teme never disagree. The start step
 * writes what this prints to /etc/nginx/conf.d/teme-hsts.conf (I-380).
 */

declare(strict_types=1);

use App\Domain\Deployment\SecureTransport;
use Illuminate\Support\Env;

require __DIR__.'/../../vendor/autoload.php';

$value = SecureTransport::hsts(
    Env::get('APP_ENV', 'production') === 'production',
    (string) Env::get('APP_URL', 'http://localhost'),
    (string) Env::get('TEME_HSTS', SecureTransport::DEFAULT_HSTS),
);

if ($value !== null && preg_match(SecureTransport::HSTS_FORM, $value) !== 1) {
    fwrite(STDERR, "Teme cannot start: TEME_HSTS isn't a Strict-Transport-Security value nginx can send.\n");

    exit(1);
}

$sent = $value === null ? '' : "    \"|https\" \"{$value}\";\n";

echo "# Written by docker/teme/nginx-hsts.php when the container starts.\n"
    ."map \"\$upstream_addr|\$http_x_forwarded_proto\" \$teme_hsts {\n"
    .$sent
    ."    default \"\";\n"
    ."}\n";
