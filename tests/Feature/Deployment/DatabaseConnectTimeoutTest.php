<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/*
 * A database that can't be reached fails a command within seconds, naming
 * its error, instead of waiting the driver's thirty (issue #543).
 */

it('gives every PostgreSQL connection a connect timeout of a few seconds, which DB_CONNECT_TIMEOUT changes', function (): void {
    $connections = Arr::where(require base_path('config/database.php'), fn (): bool => true)['connections'];
    $postgres = array_filter($connections, fn (array $connection): bool => $connection['driver'] === 'pgsql');

    expect(array_keys($postgres))->toContain('pgsql', 'bench', 'teme_verify');

    foreach ($postgres as $name => $connection) {
        expect($connection['options'][PDO::ATTR_TIMEOUT] ?? null)->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(10, $name);
    }
});
