<?php

declare(strict_types=1);

namespace App\Http\Admin\Middleware;

use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\SessionElevation;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Tells Operations that this request is a signed-in browser, so a recent
 * password confirmation can satisfy elevation. Without it every sensitive
 * change is refused.
 */
final class BindSessionElevation
{
    public function __construct(
        private readonly Application $app,
        private readonly SettingsReader $settings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->app->instance(Elevation::class, new SessionElevation($request, $this->settings));

        return $next($request);
    }
}
