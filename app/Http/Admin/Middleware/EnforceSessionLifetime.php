<?php

/**
 * Idle and absolute limits from the security settings (study G, S9).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;
use Teme\Contracts\Settings\SettingsReader;

/**
 * An expired session is emptied, not signed out: a valid remember-me cookie
 * then signs the browser in again on the next request, as a fresh sign-in,
 * and without one the auth middleware sends it to the sign-in page.
 */
final class EnforceSessionLifetime
{
    public function __construct(private readonly SettingsReader $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! Auth::check()) {
            return $next($request);
        }

        $session = $request->session();
        $now = Date::now()->getTimestamp();
        $signedInAt = (int) $session->get('auth.signed_in_at', $now);
        $lastSeenAt = (int) $session->get('auth.last_seen_at', $now);

        $idleLimit = $this->settings->int('security', 'session_idle_minutes') * 60;
        $absoluteLimit = $this->settings->int('security', 'session_absolute_hours') * 3600;

        if ($now - $lastSeenAt > $idleLimit || $now - $signedInAt > $absoluteLimit) {
            Auth::guard()->forgetUser();
            $session->invalidate();
            $session->regenerateToken();

            return $request->isMethod('GET') ? redirect()->to($request->fullUrl()) : redirect()->back();
        }

        $session->put(['auth.signed_in_at' => $signedInAt, 'auth.last_seen_at' => $now]);

        return $next($request);
    }
}
