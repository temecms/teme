<?php

declare(strict_types=1);

namespace App\Http\Admin\Middleware;

use App\Domain\Users\Queries\AccountStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended person is signed out on their next request, however they got
 * in — password, remember-me or a second factor. Only someone who already
 * knew the password learns the account is suspended.
 */
final class EnsureAccountIsActive
{
    public function __construct(private readonly AccountStatus $status) {}

    public function handle(Request $request, Closure $next): Response
    {
        $userId = Auth::id();

        if ($userId === null || ! $this->status->isSuspended($userId)) {
            return $next($request);
        }

        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login')->with('status', __('This account is suspended. Contact your site\'s administrator.'));
    }
}
