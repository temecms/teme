<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Plugins\Contributions\Contributions;
use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Contributions\RecordedStatusFaults;
use App\Domain\Plugins\Contributions\StatusFaults;
use App\Domain\Plugins\Doors\CurrentJobActor;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Seams\ContributionsCheck;
use App\Domain\Plugins\Seams\DispatcherSubscriptions;
use App\Domain\Plugins\Seams\HookSubscriptions;
use App\Domain\Plugins\Seams\PluginContributions;
use App\Domain\Plugins\Seams\PluginServices;
use App\Domain\Status\Checks\PluginsRunning;
use App\Domain\Status\StatusChecks;
use Illuminate\Support\ServiceProvider;

/**
 * Wires plugins (I-3353): discovery, filled once a process; the seams to
 * the hooks, the doors and the admin's lists; the plugin cache, which
 * `optimize` builds and `optimize:clear` removes; the Status check; and
 * the enabled plugins, started as it boots. Last among the providers, so
 * every registry a plugin adds to is already there.
 */
final class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PluginRegistry::class);
        $this->app->singleton(LoadedPlugins::class);
        $this->app->singleton(CurrentJobActor::class);

        $this->app->singleton(HookSubscriptions::class, DispatcherSubscriptions::class);

        $this->app->bind(PluginServices::class, PluginDoors::class);
        $this->app->bind(PluginContributions::class, Contributions::class);
        $this->app->bind(ContributionsCheck::class, ContributionsValidator::class);

        // Over NavigationServiceProvider's binding: this provider registers last.
        $this->app->bind(StatusFaults::class, RecordedStatusFaults::class);
    }

    public function boot(): void
    {
        $this->optimizes(optimize: 'teme:plugins:cache', clear: 'teme:plugins:clear', key: 'teme-plugins');
        $this->app->make(StatusChecks::class)->register(PluginsRunning::class);

        $this->app->make(PluginLoader::class)->load();
    }
}
