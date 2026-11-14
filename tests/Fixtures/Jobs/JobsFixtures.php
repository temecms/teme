<?php

declare(strict_types=1);

namespace Tests\Fixtures\Jobs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rows written straight into the jobs and inbox tables, as the Operations
 * would leave them, for tests that need a job or an item to exist first.
 */
final class JobsFixtures
{
    /**
     * Writes a tracked job and returns its ID: by default the operator's
     * import of 400 items, 188 done and still running, started now, of the
     * kind "fixture"; $changes by column.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function job(array $changes = []): string
    {
        $row = array_replace([
            'id' => strtolower((string) Str::ulid()),
            'kind' => 'fixture',
            'owner' => 'core',
            'label' => 'Import from WordPress',
            'starter' => 'operator',
            'started_by' => null,
            'run_type' => null,
            'run_id' => null,
            'state' => 'running',
            'total' => 400,
            'done' => 188,
            'message' => 'Importing posts',
            'created_at' => now(),
            'updated_at' => now(),
            'ended_at' => null,
        ], $changes);

        DB::table('tracked_jobs')->insert($row);

        return (string) $row['id'];
    }

    /**
     * Writes an item in this person's inbox and returns its ID: by default
     * an unread "Finished: Import from WordPress" with no link, arrived now;
     * $changes by column, `body` as an array or as JSON text.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function inboxItem(int $userId, array $changes = []): string
    {
        $row = array_replace([
            'id' => strtolower((string) Str::ulid()),
            'user_id' => $userId,
            'kind' => 'job.finished',
            'owner' => 'core',
            'body' => ['title' => 'Finished: Import from WordPress', 'text' => '1,204 posts came in.', 'link' => null],
            'read_at' => null,
            'created_at' => now(),
        ], $changes);

        DB::table('inbox_items')->insert([...$row, 'body' => is_string($row['body']) ? $row['body'] : json_encode($row['body'])]);

        return (string) $row['id'];
    }
}
