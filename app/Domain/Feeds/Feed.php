<?php

declare(strict_types=1);

namespace App\Domain\Feeds;

use DateTimeInterface;

/**
 * One feed as a format draws it: the channel's title, the page it is for, its own address, description and language, when it last changed (null while it holds nothing), its items, and the `X-Robots-Tag` it answers with. Values only: nothing in it is RSS.
 */
final readonly class Feed
{
    /**
     * @param  list<FeedItem>  $items
     */
    public function __construct(
        public string $title,
        public string $link,
        public string $selfUrl,
        public string $description,
        public string $language,
        public ?DateTimeInterface $updated,
        public array $items,
        public string $robots,
    ) {}
}
