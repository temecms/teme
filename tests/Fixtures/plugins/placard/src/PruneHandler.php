<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Placard;

use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;

/**
 * Placard's prune job, which does nothing in the tests.
 */
final class PruneHandler implements JobHandler
{
    public function handle(JobContext $context): void {}
}
