<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Jobs;

use Illuminate\Support\Facades\DB;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;

/**
 * Records which of the plugin's own permissions the run's person holds.
 */
final class PermissionsHandler implements JobHandler
{
    public function handle(JobContext $context): void
    {
        DB::table('jobs_lines')->insert([
            'line' => json_encode($context->permissions(), JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
