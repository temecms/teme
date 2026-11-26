<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Run only by tests/Feature/Testing/TestDatabaseGuardTest.php, in a process
 * of its own pointed at a database that isn't a test database: two tests that
 * would refresh it, so the guard is seen to stop the run once, before either.
 */
uses(TestCase::class, RefreshDatabase::class);

it('never runs, because the guard stops the run first', function (): void {
    expect(true)->toBeFalse();
});

it('never runs either', function (): void {
    expect(true)->toBeFalse();
});
