<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Jobs;

use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;

/**
 * Makes one outbound call with a long timeout, so tests can see the limit.
 */
final class LongCallHandler implements JobHandler
{
    public function __construct(private OutboundHttp $http) {}

    public function handle(JobContext $context): void
    {
        $this->http->get('https://api.example.com/long', timeoutSeconds: 180);
        $context->progress(1, 1, 'Called');
    }
}
