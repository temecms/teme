<?php

/**
 * The kinds of thing a reader can hand over: a post, a page, a term, a
 * person, a media item, a comment or a menu, in Teme's words.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Import;

/**
 * The item kinds a reader maps its items to. A reader uses only these, so
 * core knows where each item goes.
 *
 * @since 1.8.0
 */
final class ImportKind
{
    /** @since 1.8.0 */
    public const string POST = 'post';

    /** @since 1.8.0 */
    public const string PAGE = 'page';

    /** @since 1.8.0 */
    public const string TERM = 'term';

    /** @since 1.8.0 */
    public const string PERSON = 'person';

    /** @since 1.8.0 */
    public const string MEDIA = 'media';

    /** @since 1.8.0 */
    public const string COMMENT = 'comment';

    /** @since 1.8.0 */
    public const string MENU = 'menu';

    /** @since 1.8.0 */
    public const string MENU_ITEM = 'menu-item';

    /**
     * Every kind a reader may map an item to.
     *
     * @return list<string>
     *
     * @since 1.8.0
     */
    public static function all(): array
    {
        return [self::POST, self::PAGE, self::TERM, self::PERSON, self::MEDIA, self::COMMENT, self::MENU, self::MENU_ITEM];
    }
}
