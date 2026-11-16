<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Scheduler;

use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;

/**
 * Does nothing: the tests count the runs a schedule starts.
 */
final class PublishQueueHandler implements JobHandler
{
    public function handle(JobContext $context): void {}
}
