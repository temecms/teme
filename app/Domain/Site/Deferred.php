<?php

/**
 * A value a view model computes the first time a template reads it, and only
 * then.
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site;

use Closure;

/**
 * A value that costs queries or drawing, held as the work to do and done at
 * most once, when a template first reads it. A page that never reads it never
 * pays for it, and it tags the page and records its cards while the page is
 * being drawn, as the value read there would.
 */
final class Deferred
{
    private bool $done = false;

    private mixed $value = null;

    /**
     * @param  Closure(): mixed  $compute  the work, run on the first read
     */
    public function __construct(private readonly Closure $compute) {}

    /**
     * The value, computed on the first call and kept.
     */
    public function value(): mixed
    {
        if (! $this->done) {
            $this->value = ($this->compute)();
            $this->done = true;
        }

        return $this->value;
    }
}
