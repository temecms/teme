<?php

declare(strict_types=1);

namespace Teme\Contracts\Routes;

/**
 * A handler for one declared public address: core's or a plugin's.
 *
 * @since 0.8.0
 */
interface PublicRoute
{
    /**
     * Answers the request. Throws are caught by Teme and become a 500 with
     * no plugin detail shown.
     *
     * @since 0.8.0
     */
    public function handle(PublicRequest $request): PublicResponse;
}
