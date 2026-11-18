<?php

/**
 * What Use did: applied, with the revision it made (none for an image), or
 * found out of date, with the sentence the person reads.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Asking;

final readonly class AppliedSuggestion
{
    public function __construct(
        public bool $applied,
        public ?string $revisionId = null,
        public ?string $sentence = null,
    ) {}
}
