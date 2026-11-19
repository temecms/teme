<?php

/**
 * One link an article draws, as ArticleLinks hands it to plugins: its absolute
 * address, its host, whether it leads outside the site, and the words it is
 * drawn on.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

/**
 * One link an article draws, as ArticleLinks hands it to plugins: its
 * absolute address, its host, whether it leads outside the site, and the
 * words it is drawn on.
 *
 * @since 1.0.0
 */
final readonly class OutboundLink
{
    /**
     * @param  string  $href  the absolute address it is drawn with
     * @param  string  $host  the address's host, in lower case
     * @param  bool  $external  whether it leads outside this site
     * @param  string  $text  the words it is drawn on
     *
     * @since 1.0.0
     */
    public function __construct(
        public string $href,
        public string $host,
        public bool $external,
        public string $text,
    ) {}
}
