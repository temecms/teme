<?php

/**
 * A plugin granted pages.head that answers the title of a public page.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Head;

/**
 * A plugin granted pages.head that answers the title of a public page: the
 * words in `<title>`. The social title and the structured data's name follow
 * it, so the page names itself one way everywhere. Asked within 100 ms.
 * One plugin answers a page: the first enabled that gives a title.
 *
 * @since next
 */
interface HasPageTitle
{
    /** The longest title core accepts, in characters. @since next */
    public const int MOST_CHARACTERS = 200;

    /**
     * The page's title, as plain text of 1 to 200 characters, or null to
     * leave it to core, whose own title is given. An empty or longer answer
     * is refused, recorded as the plugin's fault, and core's title is used.
     *
     * @since next
     */
    public function pageTitle(HeadPage $page, string $coreTitle): ?string;
}
