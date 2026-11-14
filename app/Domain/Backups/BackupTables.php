<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * The tables a backup keeps as structure only (I-357): their rows are
 * disposable, or would do harm restored, as the queue's would run twice.
 * pg_dump leaves their rows out, so a restore makes them empty.
 */
final class BackupTables
{
    /** @var list<string> */
    public const array STRUCTURE_ONLY = [
        // Rule 4's first named exception (I-261).
        'cache',
        'cache_locks',
        // Rule 4's second (I-534).
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        // The page cache's own records (docs/architecture/page-cache.md).
        'page_cache_tags',
        'page_cache_purges',
        'page_cache_cdn_purges',
        'page_cache_files',
        'page_cache_cdn_addresses',
        // A restored heartbeat would be another server's (I-3029).
        'heartbeats',
    ];

    /**
     * Tables named like a cache's, a session's, a queue's or a lock's whose
     * rows a restore needs: the tracked jobs, for catching up after a
     * restore (I-1714).
     *
     * @var list<string>
     */
    public const array KEPT_DESPITE_NAME = ['tracked_jobs'];
}
