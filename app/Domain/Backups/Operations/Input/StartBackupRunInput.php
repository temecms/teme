<?php

/**
 * What StartBackupRun needs: the run's kind, what started it, the run it is a
 * step of and the place it works at, if any, and who is asking (null for the
 * operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Input;

use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupTrigger;
use Teme\Contracts\Operations\OperationInput;

final readonly class StartBackupRunInput implements OperationInput
{
    public function __construct(
        public BackupRunKind $kind,
        public BackupTrigger $startedBy,
        public ?string $parentId = null,
        public ?string $destinationId = null,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
