<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Jobs;

use Illuminate\Support\Facades\DB;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;

/**
 * Reports progress twice, then writes: used to prove progress() stops after
 * the plugin is disabled between reports.
 */
final class ProgressWriteHandler implements JobHandler
{
    public function handle(JobContext $context): void
    {
        $context->progress(1, 2, 'First');
        $context->progress(2, 2, 'Second');
        DB::table('jobs_lines')->insert([
            'line' => (string) ($context->payload()['line'] ?? 'progress'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
