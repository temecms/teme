<?php

/**
 * A request's query parameters the API refuses, each with why, all in one
 * answer (invalid_parameter): a route's own checks add theirs to the same
 * list before throwing, so a client hears every problem at once. A
 * client's mistake, never reported.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Api;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class InvalidParameters extends RuntimeException implements ShouldntReport
{
    /**
     * @param  array<string, string>  $problems  parameter => why it was refused
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(ApiWords::invalidParameters());
    }

    /**
     * These problems and those given, the earlier kept for a parameter named twice.
     *
     * @param  array<string, string>  $more
     */
    public function with(array $more): self
    {
        return new self($this->problems + $more);
    }
}
