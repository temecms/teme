<?php

declare(strict_types=1);

/*
 * PHPUnit applies phpunit.xml first (DB_PORT and REDIS_PORT forced at
 * today's defaults). When a second checkout moves Compose's test ports,
 * TEME_TEST_DB_PORT and TEME_VALKEY_TEST_PORT name the same host ports
 * for the suite; PHPUnit does not expand ${…} in <env> values, so map
 * them here before Laravel boots (issue #341).
 */

require dirname(__DIR__).'/vendor/autoload.php';

foreach ([
    'TEME_TEST_DB_PORT' => 'DB_PORT',
    'TEME_VALKEY_TEST_PORT' => 'REDIS_PORT',
] as $from => $into) {
    $value = getenv($from);

    if ($value === false || $value === '') {
        continue;
    }

    putenv("{$into}={$value}");
    $_ENV[$into] = $value;
    $_SERVER[$into] = $value;
}
