<?php

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * An HTTP error on a public page, as its template sees it, as `$page`: the
 * status code. A placeholder until 1.6c's full view models.
 */
final readonly class ErrorView
{
    /**
     * @param  int  $code  the HTTP status
     */
    public function __construct(public int $code) {}
}
