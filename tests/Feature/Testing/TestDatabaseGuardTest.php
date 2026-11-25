<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Teme\Testing\TestDatabaseGuard;

/*
 * The guard both test cases run before the first database refresh (issue
 * 754): a cached configuration, or a database whose name doesn't end in
 * `_test`, stops the run before anything is dropped.
 */

it('lets a test database through, alone or as a parallel process’s copy', function (): void {
    expect(TestDatabaseGuard::refusal(null, 'teme_test'))->toBeNull()
        ->and(TestDatabaseGuard::refusal(null, 'teme_test_test_3', '3'))->toBeNull()
        ->and(TestDatabaseGuard::refusal(null, 'teme_test', '3'))->toBeNull();
});

it('refuses a database whose name does not end in _test, naming it and the expected one', function (): void {
    expect(TestDatabaseGuard::refusal(null, 'teme'))
        ->toContain('"teme"')
        ->toContain('"teme_test"')
        ->toContain('"_test"');
});

it('refuses the site’s database under parallel testing, which adds its own _test suffix', function (): void {
    expect(TestDatabaseGuard::refusal(null, 'teme_test_2', '2'))->toContain('"teme_test_2"');
});

it('refuses a cached configuration, saying config:clear fixes it', function (): void {
    $cache = tempnam(sys_get_temp_dir(), 'config');

    try {
        expect(TestDatabaseGuard::refusal($cache, 'teme_test'))
            ->toContain($cache)
            ->toContain('php artisan config:clear');
    } finally {
        unlink($cache);
    }

    expect(TestDatabaseGuard::refusal($cache, 'teme_test'))->toBeNull();
});

it('stops the whole run once, before any test refreshes the site’s database', function (): void {
    $run = new Process(
        [PHP_BINARY, 'vendor/bin/pest', 'tests/Fixtures/TestGuard/SiteDatabaseTest.php'],
        base_path(),
        ['DB_DATABASE' => 'teme_site', 'TEST_TOKEN' => false, 'LARAVEL_PARALLEL_TESTING' => false],
    );
    $run->run();
    $said = $run->getOutput().$run->getErrorOutput();

    expect($run->isSuccessful())->toBeFalse()
        ->and(substr_count($said, 'Teme stopped the tests'))->toBe(1)
        ->and($said)->toContain('"teme_site"');
    expect($said)->not->toContain('never runs');
});
