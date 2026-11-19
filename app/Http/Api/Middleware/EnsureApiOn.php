<?php

/**
 * First in /api/v1's routes: while the API's switch is off, every request a
 * route of v1 matches is answered api_off (I-713). Read through the
 * settings, so the answer carries the seo tag and ticking the box purges it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Api\Middleware;

use App\Domain\Api\ApiNotFound;
use App\Domain\Api\Delivery\DeliverySwitches;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureApiOn
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! DeliverySwitches::apiOn()) {
            throw ApiNotFound::off();
        }

        return $next($request);
    }
}
