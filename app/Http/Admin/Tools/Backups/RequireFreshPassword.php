<?php

/**
 * Asks for the password before a page where a recovery key or a credential
 * will be typed (I-2401), by the same window the Operations ask by
 * (security.elevation_minutes), then comes back to the page.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools\Backups;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;
use Teme\Contracts\Settings\SettingsReader;

final readonly class RequireFreshPassword
{
    public function __construct(private SettingsReader $settings) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $window = $this->settings->int('security', 'elevation_minutes') * 60;

        if ($confirmedAt > 0 && Date::now()->getTimestamp() - $confirmedAt <= $window) {
            return $next($request);
        }

        // Fortify's confirmation answers with redirect()->intended().
        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('password.confirm');
    }
}
