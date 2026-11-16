<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Inbound;

use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Routes\PublicResponse;
use Teme\Contracts\Routes\PublicRoute;

/**
 * Answers GET /inbound/ping with a fixed body.
 */
final class Ping implements PublicRoute
{
    public function handle(PublicRequest $request): PublicResponse
    {
        $hits = app()->bound('inbound.hits') ? app('inbound.hits') : [];
        $hits['ping'] = ($hits['ping'] ?? 0) + 1;
        app()->instance('inbound.hits', $hits);

        return new PublicResponse(200, 'text/plain; charset=UTF-8', 'pong');
    }
}
