<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use App\Domain\Import\ImportOutcome;
use Teme\Contracts\Operations\OperationInput;

/**
 * What RecordOutsideImage needs: the source and run, the image's key, what
 * became of it, the media item if one was made, its host, and who is importing.
 */
final readonly class RecordOutsideImageInput implements OperationInput
{
    public function __construct(
        public string $sourceId,
        public string $runId,
        public string $key,
        public ImportOutcome $outcome,
        public ?string $mediaId,
        public string $host,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
