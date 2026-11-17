<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Status\Checks\UpdateAvailable;
use App\Domain\Status\StatusChecks;
use App\Domain\Updates\Updater\DokployPlatform;
use App\Domain\Updates\Updater\NoPlatform;
use App\Domain\Updates\Updater\Platform;
use App\Domain\Updates\UpdatesSettings;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Teme\Contracts\Settings\SettingsRegistrar;

/**
 * Registers the Status banner that tells administrators a newer Teme release exists (I-221), and the platform the updater moves Teme's parts through.
 */
final class UpdatesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No platform until one is set up (I-3326): the updater then ends a request saying to update by hand.
        $this->app->bind(Platform::class, fn (Application $app): Platform => config('teme.updater.platform') === 'dokploy'
            ? $app->make(DokployPlatform::class)
            : $app->make(NoPlatform::class));
    }

    public function boot(): void
    {
        $this->app->make(StatusChecks::class)->register(UpdateAvailable::class);
        $this->app->make(SettingsRegistrar::class)->group(UpdatesSettings::group());
    }
}
