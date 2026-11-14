<?php

declare(strict_types=1);

use App\Domain\Benchmark\BenchmarkDatabase;
use Illuminate\Support\Facades\Cache;

it('moves the database cache to the benchmark connection with the default one', function (): void {
    // Built first, as boot builds it on a site: on the site's connection.
    $siteStore = Cache::store('database');
    $siteMemo = Cache::memo('database');

    // Reads the name from config; nothing connects.
    expect(BenchmarkDatabase::select())->toBe('teme_bench')
        ->and(Cache::store('database'))->not->toBe($siteStore)
        ->and(Cache::store('database')->getStore()->getConnection()->getName())->toBe('bench')
        ->and(config('cache.stores.database.lock_connection'))->toBe('bench')
        ->and(Cache::memo('database'))->not->toBe($siteMemo);
});
