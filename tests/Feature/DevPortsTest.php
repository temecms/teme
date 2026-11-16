<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * Local Compose and phpunit.xml publish development ports that a second
 * checkout can move: the environment wins, today's values stay the default.
 */

it('lets the environment choose the development database and Valkey ports', function (): void {
    $compose = Yaml::parseFile(base_path('docker-compose.yml'));

    expect($compose['services']['postgres']['ports'])->toBe(['${TEME_DB_PORT:-5432}:5432'])
        ->and($compose['services']['postgres_test']['ports'])->toBe(['${TEME_TEST_DB_PORT:-5433}:5432'])
        ->and($compose['services']['valkey_test']['ports'])->toBe(['${TEME_VALKEY_TEST_PORT:-6380}:6379']);

    $phpunit = simplexml_load_file(base_path('phpunit.xml'));
    expect($phpunit)->not->toBeFalse();

    $declared = [];

    foreach ($phpunit->php->env as $variable) {
        $declared[(string) $variable['name']] = $variable;
    }

    expect((string) $declared['DB_PORT']['value'])->toBe('5433')
        ->and((string) $declared['REDIS_PORT']['value'])->toBe('6380');

    foreach (['DB_PORT' => '15433', 'REDIS_PORT' => '16380'] as $name => $fromEnvironment) {
        $force = isset($declared[$name]['force'])
            && filter_var((string) $declared[$name]['force'], FILTER_VALIDATE_BOOLEAN);

        expect($force)->toBeFalse();

        $wins = $force ? (string) $declared[$name]['value'] : $fromEnvironment;

        expect($wins)->toBe($fromEnvironment);
    }
});
