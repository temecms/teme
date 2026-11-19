<?php

/**
 * The result of recording variants: the paths the item no longer uses, which
 * the caller deletes once the record has committed.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media;

final readonly class RecordedVariants
{
    /**
     * @param  list<string>  $obsoletePaths
     */
    public function __construct(public array $obsoletePaths) {}
}
