<?php

declare(strict_types=1);

namespace App\Http\Api\Manage\Middleware;

use App\Domain\ManageApi\ManageApiProblem;
use App\Domain\ManageApi\ManageApiSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While the management API's own switch is off, every address of it is
 * answered api_off, before any key is read (I-485).
 */
final class EnsureManageApiOn
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ManageApiSettings::on()) {
            throw ManageApiProblem::off();
        }

        return $next($request);
    }
}
