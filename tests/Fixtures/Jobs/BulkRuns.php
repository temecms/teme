<?php

declare(strict_types=1);

namespace Tests\Fixtures\Jobs;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Select all matching's runs and lists for tests: a run and its tracked job
 * written straight in, and drafts whose order is known (I-143).
 */
final class BulkRuns
{
    /**
     * Writes a tracked job and its run and returns the run's ID: by default a
     * waiting "Move all posts to the trash" over the All tab; $changes by
     * column, a `filters` or `skipped` given as a string written as it is and
     * as an array JSON-encoded.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function run(User $person, array $changes = []): string
    {
        $id = strtolower((string) Str::ulid());
        $jobId = JobsFixtures::job([
            'kind' => 'content.bulk',
            'label' => 'Move all posts to the trash',
            'starter' => 'person',
            'started_by' => $person->id,
            'run_type' => 'content-bulk',
            'run_id' => $id,
            'state' => 'waiting',
            'total' => null,
            'done' => 0,
        ]);

        $row = array_replace([
            'id' => $id,
            'tracked_job_id' => $jobId,
            'user_id' => $person->id,
            'content_type' => 'post',
            'action' => 'trash',
            'filters' => '{"tab": "all"}',
            'skipped' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], $changes);

        foreach (['filters', 'skipped'] as $column) {
            if (is_array($row[$column])) {
                $row[$column] = json_encode($row[$column] === [] ? new stdClass : $row[$column], JSON_THROW_ON_ERROR);
            }
        }

        DB::table('bulk_runs')->insert($row);

        return $id;
    }

    /**
     * Writes that many drafts, each saved a second after the one before and
     * all in the past, so the list's order is known and a run started
     * afterwards takes every one (a step leaves rows changed after its run
     * started, I-143). Returns their IDs, oldest first.
     *
     * @return list<string>
     */
    public static function drafts(User $author, int $count, string $type = 'post'): array
    {
        $ids = [];

        for ($n = 1; $n <= $count; $n++) {
            $draft = lifecycleDraft($author, "Draft {$n}", $type);

            DB::table('contents')->where('id', $draft->id)->update(['updated_at' => now()->subSeconds($count + 1 - $n)]);

            $ids[] = $draft->id;
        }

        return $ids;
    }
}
