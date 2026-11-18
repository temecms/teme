<?php

/**
 * Tells Operations that this request is a signed-in browser, so a recent
 * password confirmation can satisfy elevation. Without it every sensitive
 * change is refused.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Middleware;

use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\SessionElevation;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Teme\Contracts\Settings\SettingsReader;

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
