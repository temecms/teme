<?php

declare(strict_types=1);

namespace App\Domain\Ai\Tasks\AltText\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What RecordAltTextChange needs: the request whose suggestion was used, its image, the alt text from before and after, and the person who used it.
 */
final readonly class RecordAltTextChangeInput implements OperationInput
{
    public function __construct(
        public string $activityId,
        public string $mediaId,
        public ?string $before,
        public string $after,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
