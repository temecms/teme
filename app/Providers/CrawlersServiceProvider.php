<?php

/**
 * Registers what crawlers read beside the pages: robots.txt's section of
 * Search and social, which checks its own lines when saved (I-300), and
 * the sitemaps' providers. In register(), as every part registers.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\RobotsTxt\RobotsTxtSection;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Settings\SettingsCheck;
use App\Domain\Sitemaps\ArchivesSitemap;
use App\Domain\Sitemaps\AuthorsSitemap;
use App\Domain\Sitemaps\HomeSitemap;
use App\Domain\Sitemaps\ItemsSitemap;
use App\Domain\Sitemaps\SitemapProvider;
use App\Domain\Sitemaps\TermsSitemap;
use Illuminate\Support\ServiceProvider;

final class CrawlersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([RobotsTxtSection::class], SearchAndSocial::SECTIONS);
        $this->app->tag([RobotsTxtSection::class], SettingsCheck::TAG);
        $this->app->tag([HomeSitemap::class, ArchivesSitemap::class, ItemsSitemap::class, TermsSitemap::class, AuthorsSitemap::class], SitemapProvider::TAG);
    }
}
