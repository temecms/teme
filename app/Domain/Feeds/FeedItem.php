<?php

/**
 * One item of a feed: the text a feed reader's identifier is made of, its title, address, its main author's name, publication time, the names of its terms, its summary, and, in a feed that shows the full text, its body's HTML and its featured image; then the name of each person it credits, in order, the main one first (I-3366).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds;

use DateTimeInterface;

final readonly class FeedItem
{
    /**
     * @param  list<string>  $categories
     * @param  list<string>  $authors
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $url,
        public ?string $author,
        public DateTimeInterface $published,
        public array $categories,
        public string $summary,
        public ?string $html,
        public ?FeedImage $image,
        public array $authors = [],
    ) {}
}
