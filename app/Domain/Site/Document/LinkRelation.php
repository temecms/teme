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
     * The site's favicon and sized icons. Core only: HeadCleaner drops a
     * plugin's icon links (issue 388).
     *
     * @since 1.8.0
     */
    case Icon = 'icon';

    /**
     * The icon a home screen uses. Core only (issue 388).
     *
     * @since 1.8.0
     */
    case AppleTouchIcon = 'apple-touch-icon';

    /**
     * The web app manifest that names the 192 and 512 icons. Core only.
     *
     * @since 1.8.0
     */
    case Manifest = 'manifest';

    /**
     * Whether a page has one link of this kind at most: every kind but
     * alternate and icon, of which a page has one an address or size.
     */
    public function single(): bool
    {
        return ! in_array($this, [self::Alternate, self::Icon], true);
    }
}
