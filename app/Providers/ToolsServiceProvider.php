<?php

/**
 * Adds the Tools section to the admin's menu (I-467): its entry, between
 * Users and Settings, and the hub that lists the tools a person may open.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Navigation\NavEntry;
use App\Domain\Navigation\NavigationRegistry;
use App\Domain\Navigation\NavSection;
use App\Domain\Navigation\ToolsHub;
use Illuminate\Support\ServiceProvider;

final class ToolsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $registry = $this->app->make(NavigationRegistry::class);

        $registry->hub('tools', $this->app->make(ToolsHub::class));
        $registry->register(new NavEntry('tools', 'Tools', 'wrench', NavSection::Site, 800, hub: 'tools'));
    }
}
