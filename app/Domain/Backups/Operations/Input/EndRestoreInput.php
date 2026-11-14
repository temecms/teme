<?php

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What EndRestore needs: the restore's run if one was written, the backup's run, how it ended, its sentence and figures, and who restores.
 */
final readonly class EndRestoreInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $figures
     */
    public function __construct(
        public ?string $restoreRunId,
        public string $sourceRunId,
        public bool $succeeded,
        public ?string $message = null,
        public array $figures = [],
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
