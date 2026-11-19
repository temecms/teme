<?php

/**
 * What the site answers at an address, found in-process: its status, where
 * it sends readers if anywhere, and what answered: a page, an address's own
 * redirect, a redirect, or nothing.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects;

final readonly class SiteAnswer
{
    public function __construct(
        public int $status,
        public ?string $location,
        public string $by,
    ) {}
}
