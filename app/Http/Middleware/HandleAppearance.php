<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Preferences\Queries\PreferencesFor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives the page template the person's appearance — light or dark, their
 * look and high contrast — so it is applied before the page paints. Signed
 * in, it is their preference (1.5f); the cookie is read only on the
 * signed-out screens, sign-in and password reset.
 */
class HandleAppearance
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $preferences = $user === null ? null : PreferencesFor::user($user);

        View::share('appearance', $preferences['appearance.mode'] ?? $request->cookie('appearance') ?? 'system');
        View::share('look', $preferences['appearance.look'] ?? 'dark_chrome');
        View::share('highContrast', $preferences['appearance.high_contrast'] ?? false);

        return $next($request);
    }
}
