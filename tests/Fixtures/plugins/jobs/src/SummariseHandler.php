<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Jobs;

use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;

/**
 * Reports progress, then sets a closing summary, so the finish line can be tested.
 */
final class SummariseHandler implements JobHandler
{
    public function handle(JobContext $context): void
    {
        $context->progress(1, 1, 'Almost done');
        $context->summary('Wrote the line.');
    }
}
