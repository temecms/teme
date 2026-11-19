<?php

/**
 * What `MarkTranslationUpToDate` needs: the version, the revision the person
 * was looking at, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class MarkTranslationUpToDateInput implements OperationInput
{
    public function __construct(
        public string $contentId,
        public string $expectedRevisionId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
