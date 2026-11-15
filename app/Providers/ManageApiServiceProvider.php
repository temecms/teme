<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\ManageApi\ManageApiSettings;
use Illuminate\Support\ServiceProvider;
use Teme\Contracts\Settings\SettingsRegistrar;

/**
 * Registers the management API (docs/proposals/management-api.md): its
 * settings group, drawn as its own tab under Settings.
 */
final class ManageApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(SettingsRegistrar::class)->group(ManageApiSettings::group());
    }
}
