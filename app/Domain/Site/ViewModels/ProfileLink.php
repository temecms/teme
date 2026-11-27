<?php

/**
 * A link a person chose to show on their public author page.
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * A link a person chose to show on their public author page.
 */
final readonly class ProfileLink
{
    /**
     * @param  string  $label  what the link is called, as the person wrote it
     * @param  string  $url  the address it leads to, an https:// or http:// address on another site
     */
    public function __construct(
        public string $label,
        public string $url,
    ) {}
}
