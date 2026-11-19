<?php

/**
 * The feeds a change touched, read from the tags the Operation gave the
 * page cache (docs/plans/plugin-sockets-1.md, A4): a list a main feed
 * holds, a type's archive, a term's or an author's, each as an absolute
 * address. Holds nothing.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds;

use App\Domain\Addresses\ListingTypes;
use App\Domain\Addresses\Paths;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Addresses\TypeArchives;
use App\Domain\Cache\PageTags;
use App\Domain\Site\CoreSiteInfo;
use Teme\Contracts\Feeds\FeedsChanged;

final readonly class ChangedFeeds
{
    public function __construct(
        private CoreSiteInfo $site,
        private FeedUrls $urls,
    ) {}

    /**
     * Returns the notification for these tags, or null when none names a feed.
     *
     * @param  list<string>  $tags
     */
    public function from(array $tags): ?FeedsChanged
    {
        if (in_array(PageTags::EVERYTHING, $tags, true) || in_array(PageTags::SETTINGS, $tags, true)) {
            return new FeedsChanged($this->site->feeds(), true);
        }

        $urls = [];

        foreach ($tags as $tag) {
            foreach ($this->feeds($tag) as $url) {
                $urls[$url] = true;
            }

            if (count($urls) > FeedsChanged::MOST) {
                return new FeedsChanged($this->site->feeds(), true);
            }
        }

        return $urls === [] ? null : new FeedsChanged(array_keys($urls));
    }

    /**
     * The feeds one tag names.
     *
     * @return list<string>
     */
    private function feeds(string $tag): array
    {
        $parts = explode(':', $tag);

        if ($parts[0] === 'list' && count($parts) === 2) {
            $archive = TypeArchives::path($parts[1]);

            return [
                ...(in_array($parts[1], ListingTypes::onHome(), true) ? $this->site->feeds() : []),
                ...($archive !== null ? [$this->feed($archive)] : []),
            ];
        }

        $path = match (true) {
            $parts[0] === 'term' && count($parts) === 3 => Paths::current('term', $parts[2]),
            $parts[0] === 'author' && count($parts) === 2 => Paths::current('user', $parts[1]),
            default => null,
        };

        return $path === null || $path === '' ? [] : [$this->feed($path)];
    }

    private function feed(string $path): string
    {
        return $this->urls->absolute(PublicRequestPath::encode('/'.trim($path, '/').'/feed/'));
    }
}
