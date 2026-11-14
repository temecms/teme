<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Languages\LanguagesGroup;
use App\Domain\Languages\PageLanguage;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Site\SiteLanguage;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\ServiceProvider;
use Teme\Contracts\Settings\SettingsGroup;

/**
 * Wires the site's languages (I-3360): the `languages` settings group,
 * built from the types and taxonomies when read, and the classes that
 * answer what language the site and a page are in.
 */
final class LanguageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(SiteLanguages::class);
        // Remembers each item's language for one request.
        $this->app->scoped(SiteLanguage::class);
        // Set once per request, so a second request in one process starts unset.
        $this->app->scoped(PageLanguage::class);
    }

    public function boot(): void
    {
        // Built when read, and again when the types or taxonomies change (I-3296).
        $this->app->make(SettingsRegistry::class)->derive(
            SiteLanguages::GROUP,
            fn (): SettingsGroup => LanguagesGroup::group(app(ContentTypeRegistry::class), app(TaxonomyRegistry::class)),
            fn (): string => app(ContentTypeRegistry::class)->stamp().app(TaxonomyRegistry::class)->stamp(),
        );
    }
}
