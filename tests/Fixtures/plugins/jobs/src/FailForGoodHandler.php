<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Jobs;

use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobFailed;
use Teme\Contracts\Jobs\JobHandler;

/**
 * Fails the run for good, so permanent failure without retry can be tested.
 */
final class FailForGoodHandler implements JobHandler
{
    public function handle(JobContext $context): void
    {
        throw JobFailed::permanently('Search Console isn’t connected.');
    }
}
