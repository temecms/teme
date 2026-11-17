<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Linkfinder;

use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;

/**
 * Link Finder's job, which does nothing in the tests.
 */
final class FindLinksHandler implements JobHandler
{
    public function handle(JobContext $context): void {}
}
