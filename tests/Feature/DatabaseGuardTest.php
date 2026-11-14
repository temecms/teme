<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * This file deliberately uses no database trait: it is the case the guard in
 * Tests\TestCase exists for (issue #14).
 */

it('stops a test that touches the database with no database trait, naming the fix', function (): void {
    expect(fn () => DB::select('select 1'))->toThrow(LogicException::class, 'touches the database: add RefreshDatabase');
});

it('leaves a connection other than the default alone, as the benchmark\'s is', function (): void {
    config(['database.connections.guard_other' => config('database.connections.'.config('database.default'))]);

    expect(DB::connection('guard_other')->selectOne('select 1 as one')->one)->toBe(1);
});
