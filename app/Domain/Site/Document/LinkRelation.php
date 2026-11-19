<?php

/**
 * The `rel` values a head link may carry: what the page is and where its
 * other forms are, never something the browser loads. Styles and scripts
 * reach a page through their own channels (docs/architecture/plugins.md); a
 * relation another part needs is added here, with its reason.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Document;

enum LinkRelation: string
{
    case Canonical = 'canonical';
    case Prev = 'prev';
    case Next = 'next';
    case Alternate = 'alternate';

    /**
     * Whether a page has one link of this kind at most: every kind but
     * alternate, of which a page has one an address, such as its feeds.
     */
    public function single(): bool
    {
        return $this !== self::Alternate;
    }
}
