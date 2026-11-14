<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Cache\PageTags;
use App\Domain\Settings\DesignPreview;
use App\Domain\Settings\Modules;
use App\Domain\Settings\ReadingSettingsGroup;
use App\Domain\Settings\SecretStore;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Site\SiteLanguage;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Support\ServiceProvider;
use Teme\Contracts\Settings\SecretReader;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Settings\SettingsRegistrar;

/**
 * Registers the settings registry and its readers, and declares the General
 * settings group, the Reading group built from the content types, and the
 * modules group of module switches.
 */
final class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsRegistry::class);
        $this->app->alias(SettingsRegistry::class, SettingsRegistrar::class);
        $this->app->singleton(SettingsRepository::class);
        $this->app->alias(SettingsRepository::class, SettingsReader::class);
        $this->app->singleton(SecretStore::class);
        $this->app->alias(SecretStore::class, SecretReader::class);

        // One request's: the repository asks for it at each read, never
        // holding the first request's.
        $this->app->scoped(DesignPreview::class);
    }

    public function boot(): void
    {
        $this->app->make(SettingsRegistrar::class)->group(new SettingsGroup(
            key: 'general',
            label: 'General',
            description: 'The site’s name, tagline, administration email, time zone, language and brand colour.',
            section: 'general',
            ability: 'settings.general.manage',
            settings: [
                Setting::text('site_title', 'Site title', 'The name of the site, shown in the admin, in browser tabs and in feeds.', '0.1.0')
                    ->default('My Teme site')
                    ->required()
                    ->maxLength(120),
                Setting::text('tagline', 'Tagline', 'A short line describing the site, used by themes and in search results.', '0.1.0')
                    ->maxLength(255)
                    ->example('Stories about plants and the people who grow them'),
                Setting::email('admin_email', 'Administration email', 'Where Teme sends notices meant for the site\'s administrators.', '0.1.0'),
                Setting::timezone('timezone', 'Time zone', 'Used to show and schedule dates.', '0.1.0'),
                // Last: SettingsPageTest reads the fourth field as the time zone.
                Setting::select('language', 'Site language', 'The language your site is written in. It sets what search engines and screen readers are told, how dates read, and how search matches words. Changing it treats existing items as the new language and rebuilds search in the background.', '0.1.0', array_map(fn (array $language): string => $language['label'], (array) config('languages')))
                    ->default(SiteLanguage::DEFAULT),
            ],
            since: '0.1.0',
            cacheTags: [PageTags::SETTINGS],
        ));

        // Reading (I-3298): built when read, and again when the content types change.
        $this->app->make(SettingsRegistry::class)->derive(
            'reading',
            fn (): SettingsGroup => ReadingSettingsGroup::build(app(ContentTypeRegistry::class)),
            fn (): string => app(ContentTypeRegistry::class)->stamp(),
        );

        $this->app->make(SettingsRegistrar::class)->group(Modules::group());
    }
}
