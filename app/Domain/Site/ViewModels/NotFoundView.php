<?php

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * An address with nothing at it, as its template sees it, as `$page`.
 */
final readonly class NotFoundView
{
    /**
     * @param  string  $path  the address that was asked for
     */
    public function __construct(public string $path) {}
}
