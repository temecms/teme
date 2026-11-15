<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Inbound;

use RuntimeException;
use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Routes\PublicResponse;
use Teme\Contracts\Routes\PublicRoute;

/**
 * Throws so the dispatcher can show a contained 500.
 */
final class Throwing implements PublicRoute
{
    public function handle(PublicRequest $request): PublicResponse
    {
        throw new RuntimeException('inbound fixture blew up');
    }
}
