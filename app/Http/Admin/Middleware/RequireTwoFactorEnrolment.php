<?php

/**
 * I-115: off until a site lists roles in security.two_factor_required_roles.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Middleware;

use App\Domain\Users\Queries\AccountStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class RequireTwoFactorEnrolment
{
    private const array ALWAYS_ALLOWED = [
        'security.edit',
        'two-factor.*',
        'password.confirm',
        'password.confirmation',
        'password.confirm.store',
        'verification.*',
        'logout',
    ];

    public function __construct(private readonly AccountStatus $status) {}

    public function handle(Request $request, Closure $next): Response
    {
        $userId = Auth::id();

        if ($userId === null || $request->routeIs(...self::ALWAYS_ALLOWED) || ! $this->status->lacksRequiredTwoFactor($userId)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('Your role requires two-factor authentication.')], 403);
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => __('Your role requires two-factor authentication. Set it up to continue.')]);

        return to_route('two-factor.edit');
    }
}
