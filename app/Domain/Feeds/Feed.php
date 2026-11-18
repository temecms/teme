<?php

/**
 * One feed as a format draws it: the channel's title, the page it is for, its own address, description and language, when it last changed (null while it holds nothing), its items, the `X-Robots-Tag` it answers with, and the links plugins add after its own. Values only: nothing in it is RSS.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds;

use DateTimeInterface;

final readonly class Feed
{
    /**
     * @param  list<FeedItem>  $items
     * @param  list<array{rel: string, href: string}>  $links  plugins' links, each an absolute https address (docs/plans/plugin-sockets-1.md, A3)
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
        public array $links = [],
    ) {}
}
