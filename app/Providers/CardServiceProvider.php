<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Addresses\PathLinkResolver;
use App\Domain\Cards\CardMedia;
use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\InternalLinks;
use App\Domain\Cards\LinkResolver;
use App\Domain\Cards\Types\AudioCard;
use App\Domain\Cards\Types\ButtonCard;
use App\Domain\Cards\Types\CalloutCard;
use App\Domain\Cards\Types\EmbedCard;
use App\Domain\Cards\Types\FeaturedPostsCard;
use App\Domain\Cards\Types\GalleryCard;
use App\Domain\Cards\Types\ImageCard;
use App\Domain\Cards\Types\ImportedContentCard;
use App\Domain\Cards\Types\SiteCodeCard;
use App\Domain\Cards\Types\SnippetCard;
use App\Domain\Cards\Types\VideoCard;
use App\Domain\Feeds\Cards\PlainAudio;
use App\Domain\Feeds\Cards\PlainFeaturedPosts;
use App\Domain\Feeds\Cards\PlainImported;
use App\Domain\Feeds\Cards\PlainSiteCode;
use App\Domain\Feeds\Cards\PlainVideo;
use App\Domain\Feeds\FeedCards;
use Illuminate\Support\ServiceProvider;

/**
 * Builds the card registry with core's cards, binds the lookup of their
 * media, one per request or job, and binds the resolver that turns an
 * internal link's content ID into an address at render time.
 */
final class CardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CardRegistry::class, function (): CardRegistry {
            $registry = new CardRegistry;

            foreach ([ImageCard::class, GalleryCard::class, EmbedCard::class, CalloutCard::class, SnippetCard::class, ButtonCard::class, AudioCard::class, VideoCard::class, ImportedContentCard::class, SiteCodeCard::class, FeaturedPostsCard::class] as $card) {
                $registry->register(new $card);
            }

            return $registry;
        });

        // The cards' plain versions in feeds (I-861), registered beside them.
        $this->app->tag([PlainAudio::class, PlainVideo::class, PlainImported::class, PlainSiteCode::class, PlainFeaturedPosts::class], FeedCards::RENDERERS);

        // What a request or job has read of the media its cards show, kept
        // for the rest of it and forgotten with it (issue 30): the queue
        // worker forgets scoped instances between jobs, Octane between
        // requests, and PageWarmer between the pages it draws.
        $this->app->scoped(CardMedia::class);

        // Internal links lead to the target's current address (rule 9).
        $this->app->bind(LinkResolver::class, PathLinkResolver::class);

        // A path on this site leads back to its article (rule 9).
        $this->app->bind(InternalLinks::class, PathLinkResolver::class);
    }
}
