<?php

declare(strict_types=1);

namespace App\Domain\Media\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What RecordMediaMoveProgress needs: the run, the last media ID a chunk
 * handled in full, the chunk's counts and the problems it found.
 */
final readonly class RecordMediaMoveProgressInput implements OperationInput
{
    /**
     * @param  list<array{id: string, role: string, path: string, reason: string}>  $problems
     */
    public function __construct(
        public string $runId,
        public string $cursor,
        public int $itemsDone,
        public int $filesCopied,
        public int $filesPresent,
        public int $filesDeleted,
        public int $bytesCopied,
        public int $holes,
        public int $failures,
        public array $problems,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
