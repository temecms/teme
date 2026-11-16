<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Hub;

use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Routes\PublicResponse;
use Teme\Contracts\Routes\PublicRoute;

/**
 * A route handler that asks for the jobs door, which the hub is never granted.
 */
final class WantsJobs implements PublicRoute
{
    public function __construct(private readonly Jobs $jobs) {}

    public function handle(PublicRequest $request): PublicResponse
    {
        return new PublicResponse(200, 'text/plain; charset=UTF-8', $this->jobs::class);
    }
}
