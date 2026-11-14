<?php

declare(strict_types=1);

namespace App\Domain\Media;

/**
 * The result of recording variants: the paths the item no longer uses, which
 * the caller deletes once the record has committed.
 */
final readonly class RecordedVariants
{
    /**
     * @param  list<string>  $obsoletePaths
     */
    public function __construct(public array $obsoletePaths) {}
}
