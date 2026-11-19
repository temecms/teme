<?php

/**
 * Registers the management API (docs/proposals/management-api.md): its
 * settings group, drawn as its own tab under Settings.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\ManageApi\ManageApiSettings;
use Illuminate\Support\ServiceProvider;
use Teme\Contracts\Settings\SettingsRegistrar;

final class ManageApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(SettingsRegistrar::class)->group(ManageApiSettings::group());
    }
}
