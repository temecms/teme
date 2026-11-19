<?php

/**
 * Builds the admin's menu registry with core's fixed entries and the
 * Settings hub. The content types and taxonomies are not registered here:
 * AdminNavigation adds them from their registries on every call. It also
 * shares the shell's `brand` prop, and binds where plugins' Tools links
 * and failed status checks go, and how the outbound door looks a host up
 * (I-3353).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Comments\CommentModeration;
use App\Domain\Comments\Queries\CommentsWaiting;
use App\Domain\Navigation\NavEntry;
use App\Domain\Navigation\NavigationRegistry;
use App\Domain\Navigation\NavSection;
use App\Domain\Navigation\Queries\ShellBrand;
use App\Domain\Navigation\SettingsHub;
use App\Domain\Plugins\Contributions\ToolLinks;
use App\Domain\Plugins\Doors\DnsResolver;
use App\Domain\Plugins\Doors\HostResolver;
use App\Domain\Plugins\PluginAccess;
use App\Domain\Settings\Modules;
use App\Domain\Types\TypeAccess;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

final class NavigationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NavigationRegistry::class);
        // Filled as plugins start at boot (I-3353).
        $this->app->singleton(ToolLinks::class);
        $this->app->bind(HostResolver::class, DnsResolver::class);
    }

    public function boot(): void
    {
        $registry = $this->app->make(NavigationRegistry::class);

        $registry->hub('settings', $this->app->make(SettingsHub::class));

        // Items appear in the slice that builds them, and only for people who
        // may use them — never as placeholders (I-081).
        $registry->register(new NavEntry('dashboard', 'Home', 'home', NavSection::Home, 100, route: 'dashboard'));
        $registry->register(new NavEntry('media', 'Media', 'image', NavSection::Content, 250, route: 'media.index', ability: 'media.upload'));
        // After Media and before the taxonomies (300 on); 1.6e's Regions follow it (I-395).
        $registry->register(new NavEntry('navigation', 'Navigation', 'list-tree', NavSection::Content, 260, route: 'navigation.index', ability: 'navigation.manage'));
        $registry->register(new NavEntry('regions', 'Regions', 'layout-template', NavSection::Content, 270, route: 'regions.index', ability: 'regions.manage'));
        // After Regions and before the taxonomies (300 on), as v9 puts it; whoever looks after comments, while they're switched on (1.8c's D18).
        $registry->register(new NavEntry('comments', 'Comments', 'message-square', NavSection::Content, 280, route: 'comments.index', ability: CommentModeration::LOOK_AFTER, module: Modules::COMMENTS, badge: CommentsWaiting::class));
        $registry->register(new NavEntry('users', 'People', 'users', NavSection::Site, 100, route: 'users.index', ability: 'users.manage'));
        // Site › Themes, before People, as the adopted prototype draws it (docs/architecture/admin-design.md).
        $registry->register(new NavEntry('themes', 'Themes', 'brush', NavSection::Site, 50, route: 'themes.index', ability: 'settings.design.manage'));
        // Site › Plugins, beside Themes (I-3356).
        $registry->register(new NavEntry('plugins', 'Plugins', 'plug', NavSection::Site, 60, route: 'plugins.index', ability: PluginAccess::MANAGE));
        // Site › Types, between Themes and People (I-3302).
        $registry->register(new NavEntry('types', 'Types', 'shapes', NavSection::Site, 75, route: 'types.index', ability: TypeAccess::MANAGE));
        // Site › Taxonomies, between Types and People (I-3319).
        $registry->register(new NavEntry('taxonomies', 'Taxonomies', 'tags', NavSection::Site, 80, route: 'taxonomies.index', ability: TypeAccess::MANAGE));
        $registry->register(new NavEntry('settings', 'Settings', 'settings', NavSection::Site, 900, hub: 'settings'));

        // The shell's mark and its line under the site's name, on every Inertia response, sign-in screens included.
        // Shared as each request finds its route, so a server that clears Inertia's shared props between requests keeps it.
        Event::listen(RouteMatched::class, fn () => Inertia::share('brand', fn (): array => $this->app->make(ShellBrand::class)->forViewer(Auth::check())));
    }
}
