<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Inbound;

use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Routes\PublicResponse;
use Teme\Contracts\Routes\PublicRoute;

/**
 * Answers a verified POST with ok; records that it ran.
 */
final class Callback implements PublicRoute
{
    public function handle(PublicRequest $request): PublicResponse
    {
        $hits = app()->bound('inbound.hits') ? app('inbound.hits') : [];
        $hits['callback'] = ($hits['callback'] ?? 0) + 1;
        app()->instance('inbound.hits', $hits);

        return new PublicResponse(200, 'text/plain; charset=UTF-8', 'ok');
    }
}
