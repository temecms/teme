<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Export\ExportAs;
use Tests\Fixtures\Export\LargeSite;

uses(RefreshDatabase::class);

it('exports a large site in bounded memory, writing far more than it ever holds', function (): void {
    // 2,000 items of about 40 KB: about 80 MB of text to read and write.
    LargeSite::seed(items: 2000, kilobytes: 40);
    $folder = ExportAs::freshFolder();
    $pages = 0;

    // Counts ContentsKind's page reads only (the address lookup is written `from "contents" as "c"`).
    DB::listen(function (QueryExecuted $query) use (&$pages): void {
        if (str_contains($query->sql, 'FROM contents c')) {
            $pages++;
        }
    });

    gc_collect_cycles();
    $before = memory_get_usage();
    memory_reset_peak_usage();

    try {
        $result = ExportAs::operator($folder);
        $peak = memory_get_peak_usage() - $before;
        $written = (int) filesize("{$folder}/contents.ndjson");
    } finally {
        ExportAs::remove($folder);
    }

    expect($result->lines('contents'))->toBe(2000)
        ->and($pages)->toBeGreaterThanOrEqual(20)
        ->and($written)->toBeGreaterThan(75 * 1024 * 1024)
        ->and($peak)->toBeLessThan(32 * 1024 * 1024);
});
