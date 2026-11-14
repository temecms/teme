<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Feeds\Cards\PlainButton;
use App\Domain\Feeds\Cards\PlainCallout;
use App\Domain\Feeds\Cards\PlainEmbed;
use App\Domain\Feeds\Cards\PlainGallery;
use App\Domain\Feeds\Cards\PlainImage;
use App\Domain\Feeds\Cards\PlainSnippet;
use App\Domain\Feeds\FeedCards;
use App\Domain\Feeds\FeedFormat;
use App\Domain\Feeds\FeedLinks;
use App\Domain\Feeds\FeedsSection;
use App\Domain\Feeds\Rss2;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Site\Document\DocumentMeta;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the feeds: the plain version of every card, the format a feed is
 * written in, the discovery links and the Feeds section of Search and social.
 */
final class FeedsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([PlainImage::class, PlainGallery::class, PlainEmbed::class, PlainButton::class, PlainCallout::class, PlainSnippet::class], FeedCards::RENDERERS);
        $this->app->scoped(FeedCards::class, fn (Application $app): FeedCards => new FeedCards($app->tagged(FeedCards::RENDERERS)));
        $this->app->tag([FeedsSection::class], SearchAndSocial::SECTIONS);
        $this->app->bind(FeedFormat::class, Rss2::class);
        $this->app->tag([FeedLinks::class], DocumentMeta::CONTRIBUTORS);

        // Later (I-862): IndexNow and WebSub ping from a listener on publish, after the commit; a plugin can add them.
    }
}
