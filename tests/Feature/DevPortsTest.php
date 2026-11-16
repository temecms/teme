<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/*
 * Local Compose publishes host ports from TEME_*_PORT. phpunit.xml keeps
 * DB_PORT and REDIS_PORT forced at today's defaults so a developer's site
 * ports never leak in; tests/bootstrap.php maps TEME_TEST_DB_PORT and
 * TEME_VALKEY_TEST_PORT onto those names after phpunit applies them.
 */

it('publishes Compose host ports from TEME_*_PORT with today\'s defaults', function (): void {
    $compose = Yaml::parseFile(base_path('docker-compose.yml'));

    expect($compose['services']['postgres']['ports'])->toBe(['${TEME_DB_PORT:-5432}:5432'])
        ->and($compose['services']['postgres_test']['ports'])->toBe(['${TEME_TEST_DB_PORT:-5433}:5432'])
        ->and($compose['services']['valkey_test']['ports'])->toBe(['${TEME_VALKEY_TEST_PORT:-6380}:6379']);
});

it('keeps phpunit\'s DB_PORT and REDIS_PORT forced at today\'s defaults', function (): void {
    $phpunit = simplexml_load_file(base_path('phpunit.xml'));
    expect($phpunit)->not->toBeFalse()
        ->and((string) $phpunit['bootstrap'])->toBe('tests/bootstrap.php');

    $declared = [];

    foreach ($phpunit->php->env as $variable) {
        $declared[(string) $variable['name']] = $variable;
    }

    expect((string) $declared['DB_PORT']['value'])->toBe('5433')
        ->and((string) $declared['REDIS_PORT']['value'])->toBe('6380')
        ->and(filter_var((string) $declared['DB_PORT']['force'], FILTER_VALIDATE_BOOLEAN))->toBeTrue()
        ->and(filter_var((string) $declared['REDIS_PORT']['force'], FILTER_VALIDATE_BOOLEAN))->toBeTrue();
});

it('maps TEME_TEST_DB_PORT and TEME_VALKEY_TEST_PORT onto DB_PORT and REDIS_PORT in the bootstrap', function (): void {
    $child = new Process([
        PHP_BINARY,
        '-r',
        <<<'PHP'
        putenv('DB_PORT=5433');
        $_ENV['DB_PORT'] = '5433';
        $_SERVER['DB_PORT'] = '5433';
        putenv('REDIS_PORT=6380');
        $_ENV['REDIS_PORT'] = '6380';
        $_SERVER['REDIS_PORT'] = '6380';
        putenv('TEME_TEST_DB_PORT=15433');
        $_ENV['TEME_TEST_DB_PORT'] = '15433';
        $_SERVER['TEME_TEST_DB_PORT'] = '15433';
        putenv('TEME_VALKEY_TEST_PORT=16380');
        $_ENV['TEME_VALKEY_TEST_PORT'] = '16380';
        $_SERVER['TEME_VALKEY_TEST_PORT'] = '16380';
        require 'tests/bootstrap.php';
        echo getenv('DB_PORT'), ',', getenv('REDIS_PORT');
        PHP,
    ], base_path());

    $child->mustRun();

    expect($child->getOutput())->toBe('15433,16380');
});
