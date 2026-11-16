<?php

declare(strict_types=1);

namespace App\Http\Api\Manage\Middleware;

use App\Domain\ManageApi\ApiAbility;
use App\Domain\ManageApi\ManageApiProblem;
use App\Http\Api\Manage\ManageRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a key that wasn't given every ability a route names, before the
 * route reads or writes anything; the person's own permission is then the
 * Operation's to decide.
 */
final class RequireApiAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $caller = ManageRequest::caller($request);

        foreach ($abilities as $value) {
            $ability = ApiAbility::from($value);

            if (! $caller->may($ability)) {
                throw ManageApiProblem::missingAbility($ability);
            }
        }

        return $next($request);
    }
}
