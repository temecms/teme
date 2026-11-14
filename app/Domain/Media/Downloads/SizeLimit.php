<?php

declare(strict_types=1);

namespace App\Domain\Media\Downloads;

/**
 * Guzzle's progress callback for a download: once the size a site announces,
 * or the bytes received, pass the limit, it answers true, which stops the
 * transfer in Guzzle's curl handlers, and remembers why.
 */
final class SizeLimit
{
    public bool $exceeded = false;

    public function __construct(private readonly int $maxBytes) {}

    public function __invoke(int $expected, int $received): bool
    {
        if ($expected > $this->maxBytes || $received > $this->maxBytes) {
            $this->exceeded = true;
        }

        return $this->exceeded;
    }
}
