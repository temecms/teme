<?php

/**
 * Wires themes: discovery, the active theme and its composers, once per
 * request or job; core's own views under `teme::`; the `design` settings
 * group, and each theme's `theme.{code}` group of its own settings and
 * navigation slots; and the theme cache, which `optimize` builds and
 * `optimize:clear` removes. Boot reads no setting: `theme::` is pointed when
 * the active theme is first read.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Cards\CardUsage;
use App\Domain\Design\SiteIconSizes;
use App\Domain\Design\ThemeSettings;
use App\Domain\Menus\SlotMenus;
use App\Domain\Regions\RegionDrawing;
use App\Domain\Regions\Regions;
use App\Domain\Settings\Events\SettingsChanged;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Site\PageWarnings;
use App\Domain\Status\Checks\NavigationSlotsBound;
use App\Domain\Status\Checks\ThemeUsable;
use App\Domain\Status\StatusChecks;
use App\Domain\Themes\ActiveTheme;
use App\Domain\Themes\ComposerRegistry;
use App\Domain\Themes\DesignSettingsGroup;
use App\Domain\Themes\ThemeAssets;
use App\Domain\Themes\ThemeRegistry;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class ThemeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ThemeRegistry::class);
        $this->app->scoped(ActiveTheme::class);
        $this->app->scoped(ComposerRegistry::class);
        $this->app->scoped(PageWarnings::class);
        $this->app->scoped(CardUsage::class);
        $this->app->scoped(SlotMenus::class);
        $this->app->scoped(Regions::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(resource_path('views/teme'), 'teme');

        // <x-teme::image> and the others are classes (I-450); without this,
        // Blade finds only the anonymous view teme::components.image.
        Blade::componentNamespace('App\\View\\Components\\Teme', 'teme');

        // The active theme's built assets, where a template's <head> asks.
        Blade::directive('temeThemeAssets', fn (): string => '<?php echo app('.ThemeAssets::class.'::class)->tags(); ?>');

        // @regionHasContent('sidebar') … @endregionHasContent: true when <x-teme::region name="sidebar"> would draw anything, the answer it draws from.
        Blade::if('regionHasContent', fn (string $name): bool => $this->app->make(RegionDrawing::class)->hasContent($name));

        // One dispatcher for every theme view: a composer registered on its
        // own couldn't be taken back when the active theme changes.
        View::composer('theme::*', fn (ViewContract $view) => $this->app->make(ComposerRegistry::class)->compose($view));

        DesignSettingsGroup::register($this->app->make(SettingsRegistry::class), $this->app->make(ThemeRegistry::class));

        // Each theme's own settings and navigation slots, as its
        // theme.{code} settings group.
        $this->app->make(ThemeSettings::class)->register($this->app->make(ThemeRegistry::class));

        // Favicon sizes for design.site_icon, queued after publish (issue 388).
        Event::listen(SettingsChanged::class, SiteIconSizes::class);

        // Administrators are told when the site's theme can't draw it.
        $this->app->make(StatusChecks::class)->register(ThemeUsable::class);

        // Editors are told when a place the theme draws a menu in has none.
        $this->app->make(StatusChecks::class)->register(NavigationSlotsBound::class);

        $this->optimizes(optimize: 'teme:themes:cache', clear: 'teme:themes:clear', key: 'teme-themes');
    }
}
