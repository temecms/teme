<?php

declare(strict_types=1);

namespace Teme\Contracts\Insertions;

use InvalidArgumentException;

/**
 * Where on a public page an insertion sits: the head, the start or end of
 * the body, between the items of a list, before or after a theme's region,
 * or fixed to an edge of the screen. A place the page doesn't have draws
 * nothing.
 *
 * @since 0.9.0
 */
final class PagePosition
{
    /** @since 0.9.0 */
    public const string HEAD = 'head';

    /** @since 0.9.0 */
    public const string BODY_START = 'body_start';

    /** @since 0.9.0 */
    public const string BODY_END = 'body_end';

    /** @since 0.9.0 */
    public const string AFTER_ITEM = 'after_item';

    /** @since 0.9.0 */
    public const string EVERY_ITEMS = 'every_items';

    /** @since 0.9.0 */
    public const string REGION = 'region';

    /** @since 0.9.0 */
    public const string FIXED = 'fixed';

    /**
     * The furthest item of a list afterItem() reaches.
     *
     * @since 0.9.0
     */
    public const int LAST_ITEM = 100;

    /**
     * The most times one everyItems insertion is drawn on a list; a larger
     * ceiling is held to this.
     *
     * @since 0.9.0
     */
    public const int EVERY_ITEMS_MAX = 5;

    /**
     * The edges a fixed insertion may hold.
     *
     * @since 0.9.0
     */
    public const array EDGES = ['bottom', 'top', 'left', 'right'];

    /**
     * The widths, in pixels, a side rail may take; the first when none is given.
     *
     * @since 0.9.0
     */
    public const array RAIL_WIDTHS = [160, 300];

    /**
     * @param  array{n?: int, max?: int, slug?: string, where?: string, edge?: string, width?: int}  $args
     */
    private function __construct(
        public readonly string $kind,
        public readonly array $args = [],
    ) {}

    /**
     * The end of the page's head: script sources, a few meta names,
     * preconnect links and approved scripts only.
     *
     * @since 0.9.0
     */
    public static function head(): self
    {
        return new self(self::HEAD);
    }

    /**
     * The start of the body, after the skip link, above the theme's header.
     *
     * @since 0.9.0
     */
    public static function bodyStart(): self
    {
        return new self(self::BODY_START);
    }

    /**
     * The end of the body, before core's scripts.
     *
     * @since 0.9.0
     */
    public static function bodyEnd(): self
    {
        return new self(self::BODY_END);
    }

    /**
     * After the $n-th item of a list, 1 to LAST_ITEM; never after its last.
     *
     * @since 0.9.0
     */
    public static function afterItem(int $n): self
    {
        if ($n < 1 || $n > self::LAST_ITEM) {
            throw new InvalidArgumentException('afterItem needs an item number from 1 to '.self::LAST_ITEM.'.');
        }

        return new self(self::AFTER_ITEM, ['n' => $n]);
    }

    /**
     * After every $n-th item of a list, at most $max times, and never more
     * than EVERY_ITEMS_MAX.
     *
     * @since 0.9.0
     */
    public static function everyItems(int $n, int $max = 3): self
    {
        if ($n < 1) {
            throw new InvalidArgumentException('everyItems needs a positive step.');
        }

        if ($max < 1) {
            throw new InvalidArgumentException('everyItems needs a positive ceiling.');
        }

        return new self(self::EVERY_ITEMS, ['n' => $n, 'max' => min($max, self::EVERY_ITEMS_MAX)]);
    }

    /**
     * Before or after a region the active theme declares, also when the
     * region is empty, on pages whose template draws it.
     *
     * @param  string  $where  `before` or `after`
     *
     * @since 0.9.0
     */
    public static function region(string $slug, string $where = 'after'): self
    {
        if (preg_match('/^[a-z0-9-]{1,64}$/', $slug) !== 1) {
            throw new InvalidArgumentException("A region's slug [{$slug}] isn't one.");
        }

        if ($where !== 'before' && $where !== 'after') {
            throw new InvalidArgumentException('A region insertion goes before or after it.');
        }

        return new self(self::REGION, ['slug' => $slug, 'where' => $where]);
    }

    /**
     * Fixed to an edge of the screen: `bottom` or `top`, full width, or a
     * `left` or `right` rail of a width in RAIL_WIDTHS, shown only where the
     * screen has room beside the content. Core draws its label and a close
     * button; one per edge on a page.
     *
     * @param  int  $width  a rail's width; 0 for the first of RAIL_WIDTHS; ignored at the top and bottom
     *
     * @since 0.9.0
     */
    public static function fixed(string $edge, int $width = 0): self
    {
        if (! in_array($edge, self::EDGES, true)) {
            throw new InvalidArgumentException('A fixed insertion holds the bottom, top, left or right edge.');
        }

        if ($edge === 'top' || $edge === 'bottom') {
            return new self(self::FIXED, ['edge' => $edge, 'width' => 0]);
        }

        if ($width !== 0 && ! in_array($width, self::RAIL_WIDTHS, true)) {
            throw new InvalidArgumentException('A rail is '.implode(' or ', self::RAIL_WIDTHS).' pixels wide.');
        }

        return new self(self::FIXED, ['edge' => $edge, 'width' => $width === 0 ? self::RAIL_WIDTHS[0] : $width]);
    }
}
