<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Jobs;

use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Contracts\Jobs\Jobs;

/**
 * Queues another declared job from inside a run, so nested CurrentJobActor
 * queuing can be tested through the real handler path.
 */
final class NestQueueHandler implements JobHandler
{
    public function __construct(private Jobs $jobs) {}

    public function handle(JobContext $context): void
    {
        $this->jobs->queue('write-line', ['line' => (string) ($context->payload()['line'] ?? 'from nest')]);
    }
}
