<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Jobs;

use Illuminate\Support\Facades\DB;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;

/**
 * Writes one line from the payload into the plugin's own table.
 */
final class WriteLineHandler implements JobHandler
{
    public function handle(JobContext $context): void
    {
        $line = $context->payload()['line'] ?? 'done';
        $context->progress(1, 1, 'Writing');
        DB::table('jobs_lines')->insert(['line' => (string) $line, 'created_at' => now(), 'updated_at' => now()]);
    }
}
