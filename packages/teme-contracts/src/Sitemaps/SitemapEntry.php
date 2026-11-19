<?php

/**
 * One address a plugin's sitemap lists: a path on the site, or an item by its
 * ID.
 *
 * @package Teme
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Sitemaps;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One address a plugin's sitemap lists: a path on the site, or an item by
 * its ID. Core makes every address from the site's address when the page
 * is drawn, and leaves out an item that isn't published for everyone.
 *
 * @since 1.1.0
 */
final readonly class SitemapEntry
{
    private function __construct(
        public ?string $path,
        public ?string $itemId,
        public ?DateTimeImmutable $lastModified,
    ) {}

    /**
     * A path on the site, starting with a single /, with no host.
     *
     * @throws InvalidArgumentException for anything but such a path, or one over 2,000 characters
     *
     * @since 1.1.0
     */
    public static function path(string $path, ?DateTimeImmutable $lastModified = null): self
    {
        if (preg_match('~^/(?!/)[A-Za-z0-9_.\~!$&\'()*+,;=:@/%-]*$~D', $path) !== 1 || strlen($path) > 2_000) {
            throw new InvalidArgumentException('A sitemap entry’s path starts with a single / and holds only the characters an address path may.');
        }

        return new self($path, null, $lastModified);
    }

    /**
     * An item, by its ID: listed at its address, with when it last changed,
     * while it is published for everyone and not hidden from search engines.
     *
     * @throws InvalidArgumentException for an empty or over-long ID
     *
     * @since 1.1.0
     */
    public static function item(string $itemId): self
    {
        if ($itemId === '' || strlen($itemId) > 64) {
            throw new InvalidArgumentException('A sitemap entry needs the ID of the item it lists.');
        }

        return new self(null, $itemId, null);
    }
}
