<?php

/**
 * What the caller knows of the WordPress item being converted, beyond its
 * blocks (I-1704): its own ID, and what each active shortcode in its content
 * drew on the old site.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert;

final readonly class ConversionItem
{
    /**
     * @param  int  $id  the WordPress post's ID, 0 when unknown
     * @param  list<array{open: string, html: string}>  $shortcodes  each active shortcode's opening tag exactly as post_content holds it, and what do_shortcode() drew of the whole shortcode, in the order they appear
     */
    public function __construct(public int $id = 0, public array $shortcodes = []) {}
}
