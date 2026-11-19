<?php

/**
 * Turns what a link stores into the address it is drawn with now: an
 * article's ID, a term's, a person's or a library file's, and a path on this
 * site. Internal links store the ID and never the URL (rule 9), so this is
 * asked at render time.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards;

interface LinkResolver
{
    /**
     * The item's address, or null when it no longer exists or is not public.
     */
    public function url(string $contentId): ?string;

    /**
     * The address a link to this path on the site is drawn with: the path
     * itself on the site's own pages, made absolute where a reader has no
     * page to resolve it against, as in a feed (I-1669).
     */
    public function path(string $path): string;

    /**
     * The address a link by ID is drawn with now (rule 9), or null when its
     * target has none: an article's, as url() gives it; a term's or an
     * author's page; a library file, an image at its widest size since its
     * original is private (I-1702). $kind is a key of
     * DocumentSchema::LINK_TARGETS.
     */
    public function target(string $kind, string $id): ?string;
}
