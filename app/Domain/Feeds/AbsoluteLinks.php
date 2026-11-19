<?php

/**
 * The link resolver a feed's body is drawn with: the same addresses the page
 * links to, made absolute, so a link in prose and a button's article work in
 * a feed reader.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds;

use App\Domain\Cards\LinkResolver;

final readonly class AbsoluteLinks implements LinkResolver
{
    public function __construct(private LinkResolver $links, private FeedUrls $urls) {}

    public function url(string $contentId): ?string
    {
        $url = $this->links->url($contentId);

        return $url === null ? null : $this->urls->absolute($url);
    }

    public function path(string $path): string
    {
        return $this->urls->absolute($path);
    }

    public function target(string $kind, string $id): ?string
    {
        $url = $this->links->target($kind, $id);

        return $url === null ? null : $this->urls->absolute($url);
    }
}
