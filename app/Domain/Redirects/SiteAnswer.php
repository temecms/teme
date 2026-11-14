<?php

declare(strict_types=1);

namespace App\Domain\Redirects;

/**
 * What the site answers at an address, found in-process: its status, where
 * it sends readers if anywhere, and what answered: a page, an address's own
 * redirect, a redirect, or nothing.
 */
final readonly class SiteAnswer
{
    public function __construct(
        public int $status,
        public ?string $location,
        public string $by,
    ) {}
}
