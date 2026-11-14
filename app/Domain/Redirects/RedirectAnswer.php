<?php

declare(strict_types=1);

namespace App\Domain\Redirects;

/**
 * What a redirect answers for an address: which redirect, its status, and
 * where it sends readers, null for 410 and 451.
 */
final readonly class RedirectAnswer
{
    public function __construct(
        public string $redirectId,
        public int $status,
        public ?string $location,
    ) {}
}
