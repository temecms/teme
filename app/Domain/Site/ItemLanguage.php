<?php

/**
 * Draws what a reader gets about one item in that item's language (I-3360):
 * for the core addresses that answer for an item outside its own page, such
 * as a comment posted on it, its password form or its preview, so their
 * words and numbers are the page's.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Languages\PageLanguage;
use App\Domain\Languages\SiteLanguages;

final class ItemLanguage
{
    /**
     * Sets the page's language, and with it the locale, to the item's own.
     */
    public static function use(string $contentId): void
    {
        $tag = app(SiteLanguage::class)->forContent($contentId);

        if (app(SiteLanguages::class)->has($tag)) {
            app(PageLanguage::class)->use($tag);
        }
    }

    /**
     * Sets the page's language, and with it the locale, to the one a site
     * address is under: a shown language's prefix, else the default.
     */
    public static function useAddress(string $path): void
    {
        $languages = app(SiteLanguages::class);
        $first = explode('/', trim($path, '/'), 2)[0];
        $tag = $first === '' ? null : $languages->byPrefix($first);

        app(PageLanguage::class)->use($tag !== null && $languages->isShown($languages->stored($tag)) ? $tag : $languages->defaultTag());
    }
}
