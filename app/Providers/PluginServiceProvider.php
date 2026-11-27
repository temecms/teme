<?php

/**
 * Wires plugins (I-3353): discovery, filled once a process; the seams to
 * the hooks, the doors and the admin's lists; the plugin cache, which
 * `optimize` builds and `optimize:clear` removes; the Status check; and
 * the enabled plugins, started as it boots. Last among the providers, so
 * every registry a plugin adds to is already there.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Contents\PluginWrites;
use App\Domain\Jobs\JobKinds;
use App\Domain\Plugins\Columns\ColumnClock;
use App\Domain\Plugins\Columns\SystemClock;
use App\Domain\Plugins\Console\PluginCommands;
use App\Domain\Plugins\Contributions\Contributions;
use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Contributions\RecordedStatusFaults;
use App\Domain\Plugins\Contributions\StatusFaults;
use App\Domain\Plugins\Doors\CurrentJobActor;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\Images\ImageServices;
use App\Domain\Plugins\Jobs\PluginJobKind;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Seams\ContributionsCheck;
use App\Domain\Plugins\Seams\DispatcherSubscriptions;
use App\Domain\Plugins\Seams\HookSubscriptions;
use App\Domain\Plugins\Seams\PluginContributions;
use App\Domain\Plugins\Seams\PluginServices;
use App\Domain\Plugins\Seo\PluginHead;
use App\Domain\Plugins\Seo\PluginSitemaps;
use App\Domain\Plugins\Seo\SlowSockets;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use App\Domain\Site\Document\DocumentMeta;
use App\Domain\Sitemaps\SitemapProvider;
use App\Domain\Status\Checks\PluginsRunning;
use App\Domain\Status\StatusChecks;
use Illuminate\Console\Application as Console;
use Illuminate\Support\ServiceProvider;

final class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PluginRegistry::class);
        $this->app->singleton(LoadedPlugins::class);
        $this->app->scoped(PluginCommands::class);
        // Scoped: holds the job actor for one job/request only (I-087).
        $this->app->scoped(CurrentJobActor::class);
        $this->app->scoped(PluginWrites::class);
        // Scoped: the compiled list changes when a plugin is enabled or
        // disabled in this request; Octane and the queue forget it between.
        $this->app->scoped(PublicRouteRegistry::class);
        // Scoped: which plugins ran over a search socket's budget in this request or job.
        $this->app->scoped(SlowSockets::class);
        // Scoped: finds the image service's driver once a request or job.
        $this->app->scoped(ImageServices::class);

        $this->app->singleton(HookSubscriptions::class, DispatcherSubscriptions::class);

        $this->app->bind(PluginServices::class, PluginDoors::class);
        $this->app->bind(PluginContributions::class, Contributions::class);
        $this->app->bind(ContributionsCheck::class, ContributionsValidator::class);
        $this->app->bind(ColumnClock::class, SystemClock::class);

        // Over NavigationServiceProvider's binding: this provider registers last.
        $this->app->bind(StatusFaults::class, RecordedStatusFaults::class);

        $this->app->tag([PluginJobKind::class], JobKinds::TAG);

        // Plugins' head tags, structured data and sitemaps (docs/guides/developers/plugins/seo.md).
        $this->app->tag([PluginHead::class], DocumentMeta::CONTRIBUTORS);
        $this->app->tag([PluginSitemaps::class], SitemapProvider::TAG);
    }

    public function boot(): void
    {
        $this->optimizes(optimize: 'teme:plugins:cache', clear: 'teme:plugins:clear', key: 'teme-plugins');
        $this->app->make(StatusChecks::class)->register(PluginsRunning::class);

        // The console is built after every provider has booted, so by then each plugin that started has kept its commands.
        if ($this->app->runningInConsole()) {
            Console::starting(function (Console $artisan): void {
                // Starting callbacks outlive an application in a long-lived process, so each acts for its own.
                if ($artisan->getLaravel() === $this->app) {
                    $this->app->make(PluginCommands::class)->addTo($artisan);
                }
            });
        }

        $this->app->make(PluginLoader::class)->load();
    }
}
