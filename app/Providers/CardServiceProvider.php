<?php

/**
 * Builds the card registry with core's cards, binds the lookup of their
 * media, one per request or job, and binds the resolver that turns an
 * internal link's content ID into an address at render time.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Addresses\PathLinkResolver;
use App\Domain\Cards\CardMedia;
use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\InternalLinks;
use App\Domain\Cards\LinkResolver;
use App\Domain\Cards\Plugin\PluginCardNode;
use App\Domain\Cards\Plugin\PluginCards;
use App\Domain\Cards\Theme\ThemeCardNode;
use App\Domain\Cards\Types\AudioCard;
use App\Domain\Cards\Types\ButtonCard;
use App\Domain\Cards\Types\CalloutCard;
use App\Domain\Cards\Types\EmbedCard;
use App\Domain\Cards\Types\FaqCard;
use App\Domain\Cards\Types\FeaturedPostsCard;
use App\Domain\Cards\Types\GalleryCard;
use App\Domain\Cards\Types\ImageCard;
use App\Domain\Cards\Types\ImportedContentCard;
use App\Domain\Cards\Types\SiteCodeCard;
use App\Domain\Cards\Types\SnippetCard;
use App\Domain\Cards\Types\StepsCard;
use App\Domain\Cards\Types\VideoCard;
use App\Domain\Feeds\Cards\PlainAudio;
use App\Domain\Feeds\Cards\PlainFaq;
use App\Domain\Feeds\Cards\PlainFeaturedPosts;
use App\Domain\Feeds\Cards\PlainImported;
use App\Domain\Feeds\Cards\PlainPluginCard;
use App\Domain\Feeds\Cards\PlainSiteCode;
use App\Domain\Feeds\Cards\PlainSteps;
use App\Domain\Feeds\Cards\PlainThemeCard;
use App\Domain\Feeds\Cards\PlainVideo;
use App\Domain\Feeds\FeedCards;
use Illuminate\Support\ServiceProvider;

final class CardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CardRegistry::class, function (): CardRegistry {
            $registry = new CardRegistry;

            foreach ([ImageCard::class, GalleryCard::class, EmbedCard::class, CalloutCard::class, SnippetCard::class, ButtonCard::class, AudioCard::class, VideoCard::class, ImportedContentCard::class, SiteCodeCard::class, FeaturedPostsCard::class, FaqCard::class, StepsCard::class, PluginCardNode::class, ThemeCardNode::class] as $card) {
                $registry->register(new $card);
            }

            return $registry;
        });

        // The cards' plain versions in feeds (I-861), registered beside them.
        $this->app->tag([PlainAudio::class, PlainVideo::class, PlainImported::class, PlainSiteCode::class, PlainPluginCard::class, PlainThemeCard::class, PlainFeaturedPosts::class, PlainFaq::class, PlainSteps::class], FeedCards::RENDERERS);

        // What a request or job has read of the media its cards show, kept
        // for the rest of it and forgotten with it (issue 30): the queue
        // worker forgets scoped instances between jobs, Octane between
        // requests, and PageWarmer between the pages it draws.
        $this->app->scoped(CardMedia::class);

        // Plugins' cards: their page's time budget is spent once a request (docs/guides/developers/plugins/cards.md).
        $this->app->scoped(PluginCards::class);

        // Internal links lead to the target's current address (rule 9).
        $this->app->bind(LinkResolver::class, PathLinkResolver::class);

        // A path on this site leads back to its article (rule 9).
        $this->app->bind(InternalLinks::class, PathLinkResolver::class);
    }
}
