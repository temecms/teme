<?php

declare(strict_types=1);

use App\Domain\Benchmark\BenchmarkSeeder;
use App\Domain\Redirects\Queries\SiteAnswers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// By hand (I-913): TEME_MEASURE=1 TEME_MEASURE_ITEMS=10000 php artisan test tests/Feature/Import/CheckCostTest.php
it('measures what the address check costs an address', function (): void {
    config(['app.url' => 'http://localhost']);
    $items = (int) (getenv('TEME_MEASURE_ITEMS') ?: 10000);
    app(BenchmarkSeeder::class)->seed($items);
    $paths = DB::table('paths')->orderBy('id')->limit(2000)->pluck('path')->all();
    $answers = app(SiteAnswers::class);
    $started = hrtime(true);

    foreach ($paths as $path) {
        $answers->at('/'.$path.'/');
    }

    $each = (hrtime(true) - $started) / 1e6 / max(1, count($paths));
    fwrite(STDERR, sprintf("\nThe address check: %.2f ms an address, over %d addresses of %d items; 100,000 addresses would take about %.0f minutes.\n", $each, count($paths), $items, $each * 100000 / 60000));

    expect($each)->toBeGreaterThan(0.0);
})->skip(getenv('TEME_MEASURE') !== '1', 'Set TEME_MEASURE=1 to measure the address check (I-913).');
