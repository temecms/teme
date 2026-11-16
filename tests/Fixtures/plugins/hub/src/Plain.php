<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Hub;

use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Routes\PublicResponse;
use Teme\Contracts\Routes\PublicRoute;

/**
 * A route handler with no constructor.
 */
final class Plain implements PublicRoute
{
    public function handle(PublicRequest $request): PublicResponse
    {
        return new PublicResponse(200, 'text/plain; charset=UTF-8', 'plain');
    }
}
