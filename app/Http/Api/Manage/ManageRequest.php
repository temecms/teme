<?php

declare(strict_types=1);

namespace App\Http\Api\Manage;

use App\Domain\ManageApi\ApiCaller;
use App\Domain\ManageApi\ManageApiProblem;
use Illuminate\Http\Request;

/**
 * The caller AuthenticateApiKey kept on a management API request.
 */
final class ManageRequest
{
    /** The request attribute the caller is kept under. */
    public const string CALLER = 'teme.manage_api.caller';

    /**
     * The request's caller; a route reached without signing in is refused.
     */
    public static function caller(Request $request): ApiCaller
    {
        $caller = $request->attributes->get(self::CALLER);

        if (! $caller instanceof ApiCaller) {
            throw ManageApiProblem::unauthorized();
        }

        return $caller;
    }
}
