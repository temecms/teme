<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Jobs;

use RuntimeException;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;

/**
 * Throws so retries and the final failure message can be tested.
 */
final class ThrowHandler implements JobHandler
{
    public function handle(JobContext $context): void
    {
        throw new RuntimeException("The workspace answered nothing.\nAnd more detail.");
    }
}
