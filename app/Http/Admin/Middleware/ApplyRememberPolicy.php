<?php

declare(strict_types=1);

namespace App\Http\Admin\Middleware;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Remember-me lasts security.session_remember_days — 14, not Laravel's 400 —
 * and 0 removes it. Runs on every admin request, so the two-factor challenge,
 * which completes the sign-in on a later request, gets the same duration.
 */
final class ApplyRememberPolicy
{
    public function __construct(private readonly SettingsReader $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $days = $this->settings->int('security', 'session_remember_days');
        $guard = Auth::guard();

        if ($days === 0) {
            $request->merge(['remember' => false]);
        } elseif ($guard instanceof SessionGuard) {
            $guard->setRememberDuration($days * 24 * 60);
        }

        return $next($request);
    }
}
