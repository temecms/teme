<?php

/**
 * An HTTP error on a public page, as its template sees it, as `$page`: the
 * status code.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * An HTTP error on a public page, as its template sees it, as `$page`: the
 * status code.
 */
final readonly class ErrorView
{
    /**
     * @param  int  $code  the HTTP status
     */
    public function __construct(public int $code) {}
}
