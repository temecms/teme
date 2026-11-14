<?php

declare(strict_types=1);

namespace App\Domain\Comments;

/**
 * What an Empty press, or one of its queued parts, did (1.8c′'s D5): the
 * comments deleted permanently, those of them kept as tombstones, those that
 * had changed and couldn't be, and whether any are left.
 */
final readonly class EmptyingResult
{
    public function __construct(
        public int $deleted,
        public int $tombstones,
        public int $failed,
        public bool $more,
    ) {}
}
