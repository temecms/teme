<?php

/**
 * Registers core's head contributor, the robots meta, and Settings › Search
 * and social: its first section, The whole site, the sections and head
 * entries Search and social's own screen adds, and, once every provider has
 * registered its own sections, the group they make.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Api\Delivery\DeliverySection;
use App\Domain\Redirects\NotFoundLogSection;
use App\Domain\Seo\DefaultImageCard;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SiteVerification;
use App\Domain\Seo\SocialCards;
use App\Domain\Seo\TitlesAndListing;
use App\Domain\Seo\VerificationTags;
use App\Domain\Seo\WholeSite;
use App\Domain\Settings\Events\SettingsChanged;
use App\Domain\Settings\SettingsCheck;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Site\Document\DocumentMeta;
use App\Domain\Site\Head\RobotsMeta;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Teme\Contracts\Settings\SettingsGroup;

final class SeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([RobotsMeta::class], DocumentMeta::CONTRIBUTORS);
        $this->app->tag([WholeSite::class], SearchAndSocial::SECTIONS);
        $this->app->tag([SocialCards::class], SearchAndSocial::SECTIONS);
        $this->app->tag([NotFoundLogSection::class], SearchAndSocial::SECTIONS);
        // The API's and llms.txt's switches (I-713).
        $this->app->tag([DeliverySection::class], SearchAndSocial::SECTIONS);
        $this->app->tag([SiteVerification::class], SearchAndSocial::SECTIONS);
        $this->app->tag([SiteVerification::class], SettingsCheck::TAG);
        $this->app->tag([VerificationTags::class], DocumentMeta::CONTRIBUTORS);
        $this->app->tag([TitlesAndListing::class], SettingsCheck::TAG);
    }

    public function boot(): void
    {
        // Built when read, and again when the content types change (I-3296).
        // Every provider's register() has run by now, so every section is tagged.
        $this->app->make(SettingsRegistry::class)->derive(
            SearchAndSocial::KEY,
            fn (): SettingsGroup => SearchAndSocial::group(app()->tagged(SearchAndSocial::SECTIONS)),
            fn (): string => app(ContentTypeRegistry::class)->stamp().app(TaxonomyRegistry::class)->stamp(),
        );
        Event::listen(SettingsChanged::class, DefaultImageCard::class);
    }
}
