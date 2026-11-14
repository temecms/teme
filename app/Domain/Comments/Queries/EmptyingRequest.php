<?php

declare(strict_types=1);

namespace App\Domain\Comments\Queries;

use Carbon\CarbonImmutable;

/**
 * What an Empty spam or Empty trash press covers (1.8c′'s D4): who pressed it,
 * the view, the item and search the list was narrowed by, and the cut-off,
 * when the list was opened.
 */
final readonly class EmptyingRequest
{
    public function __construct(
        public int $viewerId,
        public string $view,
        public ?string $itemId,
        public ?string $search,
        public CarbonImmutable $cutoff,
    ) {}
}
