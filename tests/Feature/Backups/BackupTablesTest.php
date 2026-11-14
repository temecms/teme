<?php

declare(strict_types=1);

use App\Domain\Backups\BackupTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tables = array_map(fn (object $row): string => $row->table_name, DB::select(
        "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'",
    ));
    // Named like a cache's, a session's, a queue's, a lock's or a heartbeat's table.
    $this->looksDisposable = fn (string $table): bool => preg_match('/(^|_)(cache|sessions?|jobs|job_batches|locks|heartbeats)(_|$)/', $table) === 1;
});

it('keeps the cache, queue, session and page-cache tables as structure only, each a table that exists (I-357)', function (): void {
    expect(BackupTables::STRUCTURE_ONLY)->toEqualCanonicalizing([
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions',
        'page_cache_tags', 'page_cache_purges', 'page_cache_cdn_purges', 'page_cache_files', 'page_cache_cdn_addresses',
        'heartbeats',
    ]);

    foreach (BackupTables::STRUCTURE_ONLY as $table) {
        expect($this->tables)->toContain($table);
    }
});

it('lists every table whose name looks disposable, or keeps it on purpose, so a new one is a decision', function (): void {
    $listed = [...BackupTables::STRUCTURE_ONLY, ...BackupTables::KEPT_DESPITE_NAME];
    $unlisted = array_values(array_filter($this->tables, fn (string $table): bool => ($this->looksDisposable)($table) && ! in_array($table, $listed, true)));

    expect($unlisted)->toBe([])
        // The pattern sees every listed name, so the check above can fail.
        ->and(array_values(array_filter($listed, fn (string $table): bool => ! ($this->looksDisposable)($table))))->toBe([])
        ->and(($this->looksDisposable)('content_counts'))->toBeFalse()
        ->and(($this->looksDisposable)('backup_runs'))->toBeFalse();
});
