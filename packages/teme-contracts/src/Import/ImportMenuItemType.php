<?php

/**
 * What a menu item points at, in Teme's words: a piece of content, a term or
 * a plain link. A reader names one of these, and core translates it.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Import;

/**
 * The menu item types a reader may give an item.
 *
 * @since next
 */
final class ImportMenuItemType
{
    /** A post or page, named by the item's objectId. @since next */
    public const string CONTENT = 'content';

    /** A term, named by the item's objectId. @since next */
    public const string TERM = 'term';

    /** A link to the address in fields.url. @since next */
    public const string LINK = 'link';

    /**
     * Every menu item type a reader may give.
     *
     * @return list<string>
     *
     * @since next
     */
    public static function all(): array
    {
        return [self::CONTENT, self::TERM, self::LINK];
    }
}
