<?php

/**
 * A term as a link: its name, its address, and its taxonomy's key.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * A term as a link: its name, its address, and its taxonomy's key.
 */
final readonly class TermLink
{
    /**
     * @param  string  $name  the term's name
     * @param  string  $url  the term's address
     * @param  string  $taxonomy  the key of the term's taxonomy
     */
    public function __construct(
        public string $name,
        public string $url,
        public string $taxonomy,
    ) {}
}
