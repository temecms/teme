<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Status\Checks\UpdateAvailable;
use App\Domain\Status\StatusChecks;
use App\Domain\Updates\Updater\DokployApplicationPlatform;
use App\Domain\Updates\Updater\DokployPlatform;
use App\Domain\Updates\Updater\NoPlatform;
use App\Domain\Updates\Updater\Platform;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the Status banner that tells administrators a newer Teme release exists (I-221), and the platform the updater moves Teme's parts through.
 */
final class UpdatesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No platform until one is set up (I-3326): the updater then ends a request saying to update by hand.
        $this->app->bind(Platform::class, fn (Application $app): Platform => match (self::platform()) {
            'dokploy' => $app->make(DokployPlatform::class),
            'dokploy-application' => $app->make(DokployApplicationPlatform::class),
            default => $app->make(NoPlatform::class),
        });
    }

    /**
     * Returns the platform the updater moves Teme through: the one TEME_UPDATER_PLATFORM names, or, in a container
     * that runs every role and was told where Dokploy is, Dokploy's Application; null when none is set up.
     */
    private static function platform(): ?string
    {
        $named = config('teme.updater.platform');

        if (is_string($named) && $named !== '') {
            return $named;
        }

        $dokploy = config('teme.updater.dokploy.url');

        return config('teme.role') === 'all' && is_string($dokploy) && $dokploy !== '' ? 'dokploy-application' : null;
    }

    public function boot(): void
    {
        $this->app->make(StatusChecks::class)->register(UpdateAvailable::class);
    }
}
