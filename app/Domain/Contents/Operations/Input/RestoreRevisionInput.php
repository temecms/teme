<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * An older revision to bring back as the article's working version.
 */
final readonly class RestoreRevisionInput implements OperationInput
{
    public function __construct(
        public string $contentId,
        public string $revisionId,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
