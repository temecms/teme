<?php

declare(strict_types=1);

namespace App\Http\Api\Manage\Middleware;

use App\Domain\ManageApi\ApiKeys;
use App\Domain\ManageApi\ManageApiProblem;
use App\Http\Api\Manage\ManageRequest;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a request in with its key, sent as `Authorization: Bearer …`: the
 * request's user becomes the key's person and the caller is kept on the
 * request. No key, or one unknown, revoked or expired, is answered the same
 * unauthorized problem, and counted against the address.
 */
final class AuthenticateApiKey
{
    public function __construct(private readonly ApiKeys $keys) {}

    public function handle(Request $request, Closure $next): Response
    {
        $caller = $this->keys->signIn((string) $request->bearerToken());

        if ($caller === null) {
            RateLimiter::hit(ThrottleManageAddress::key($request), 60);

            throw ManageApiProblem::unauthorized();
        }

        $request->attributes->set(ManageRequest::CALLER, $caller);
        $request->setUserResolver(fn () => $caller->person());

        return $next($request);
    }
}
