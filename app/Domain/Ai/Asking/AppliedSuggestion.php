<?php

declare(strict_types=1);

namespace App\Domain\Ai\Asking;

/**
 * What Use did: applied, with the revision it made (none for an image), or
 * found out of date, with the sentence the person reads.
 */
final readonly class AppliedSuggestion
{
    public function __construct(
        public bool $applied,
        public ?string $revisionId = null,
        public ?string $sentence = null,
    ) {}
}
