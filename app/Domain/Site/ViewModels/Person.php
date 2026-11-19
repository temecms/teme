<?php

/**
 * A person an item credits, as a page shows them.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * A person an item credits, as a page shows them.
 */
final readonly class Person
{
    /**
     * @param  string  $name  the author's name
     * @param  string  $url  the address of their author page, site-relative; `''` when they have none
     * @param  string|null  $bio  the bio they wrote, or null
     */
    public function __construct(
        public string $name,
        public string $url,
        public ?string $bio,
    ) {}
}
