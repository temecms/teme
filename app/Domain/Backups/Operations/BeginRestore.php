<?php

declare(strict_types=1);

namespace App\Domain\Backups\Operations;

use App\Domain\Backups\Operations\Concerns\AuthorizesRestores;
use App\Domain\Backups\Operations\Input\BeginRestoreInput;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Teme\Contracts\Operations\OperationInput;

/**
 * Records that a restore begins, in this database's security log, before anything is written: a restore that stops early is in the log it leaves.
 */
final class BeginRestore extends BaseOperation
{
    use AuthorizesRestores;

    public function __construct(private readonly SecurityLog $log) {}

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): null
    {
        /** @var BeginRestoreInput $input */
        $this->log->record(SecurityEventType::BackupRestoreStarted, $input->actorId, context: [
            'run' => $input->runId,
            'place' => $input->place,
            'made_at' => $input->madeAt->utc()->toIso8601String(),
        ]);

        return null;
    }
}
