<?php

/**
 * What a composer may know about the view it's composing.
 *
 * @package Teme
 * @since   0.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Themes;

/**
 * What a composer may know about the view it's composing. Read-only.
 *
 * @since 0.1.0
 */
interface ViewContext
{
    /** The view's name relative to the theme, such as "templates.single". @since 0.1.0 */
    public function view(): string;

    /** The page kind: latest (the latest posts), single, archive, taxonomy, author, search, date, notfound, error, comments (a later page of an item's comments), respond (core's page about writing a comment) or protected (an item behind a password); '' for a view drawn with no page. @since 0.1.0 */
    public function kind(): string;

    /** Whether the page is the site's home page, the latest posts or a page, whichever the site shows at its address, or one of its numbered pages. @since 0.1.0 */
    public function isFront(): bool;

    /** The locale of the page being drawn: the site's language today, the item's own once items have one. @since 0.1.0 */
    public function locale(): string;

    /** Whether the view already has this data key. @since 0.1.0 */
    public function has(string $key): bool;

    /** An existing data value, or null. @since 0.1.0 */
    public function get(string $key): mixed;
}
