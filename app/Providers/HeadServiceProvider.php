<?php

/**
 * Registers the head's core parts (I-290): Search and social's titles
 * section, the page's head values, resolved once a page, the page's content
 * security policy, started afresh for each, and the head's tags beside robots.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\TitlesAndListing;
use App\Domain\Site\Document\DocumentMeta;
use App\Domain\Site\Head\HeadTags;
use App\Domain\Site\Head\PageHead;
use App\Domain\Site\Head\SiteIcons;
use App\Domain\Site\Policy\PagePolicy;
use Illuminate\Support\ServiceProvider;

final class HeadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([TitlesAndListing::class], SearchAndSocial::SECTIONS);
        $this->app->scoped(PageHead::class);
        $this->app->scoped(PagePolicy::class);
        $this->app->tag([HeadTags::class, SiteIcons::class], DocumentMeta::CONTRIBUTORS);
    }
}
