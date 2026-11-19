<?php

/**
 * An item as a link: its published title, its address, and its type's key.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * An item as a link: its published title, its address, and its
 * type's key.
 */
final readonly class ItemLink
{
    /**
     * @param  string  $title  the item's published title
     * @param  string  $url  the item's address, site-relative
     * @param  string  $type  the key of the item's type
     */
    public function __construct(
        public string $title,
        public string $url,
        public string $type,
    ) {}
}
