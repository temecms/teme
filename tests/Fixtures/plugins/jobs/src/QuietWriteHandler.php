<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Jobs;

use Illuminate\Support\Facades\DB;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;

/**
 * Writes a line without reporting progress, so a cancelled tracked job that
 * still reaches the handler is visible in the plugin's table.
 */
final class QuietWriteHandler implements JobHandler
{
    public function handle(JobContext $context): void
    {
        $line = $context->payload()['line'] ?? 'quiet';
        DB::table('jobs_lines')->insert(['line' => (string) $line, 'created_at' => now(), 'updated_at' => now()]);
    }
}
