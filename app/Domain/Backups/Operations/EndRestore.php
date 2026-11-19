<?php

/**
 * Records how a restore ended: its run finished or failed, with its figures and its sentence, and the end in the security log.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations;

use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\Models\BackupRun;
use App\Domain\Backups\Operations\Concerns\AuthorizesRestores;
use App\Domain\Backups\Operations\Input\EndRestoreInput;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Carbon\CarbonImmutable;
use Teme\Contracts\Operations\OperationInput;

final class EndRestore extends BaseOperation
{
    use AuthorizesRestores;

    public function __construct(private readonly SecurityLog $log) {}

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): null
    {
        /** @var EndRestoreInput $input */
        $run = $input->restoreRunId === null ? null : BackupRun::query()->lockForUpdate()->find($input->restoreRunId);

        if ($run !== null && $run->state === BackupRunState::Running) {
            $run->update([
                'state' => $input->succeeded ? BackupRunState::Finished : BackupRunState::Failed,
                'ended_at' => CarbonImmutable::now()->max($run->started_at),
                'figures' => $input->figures,
                'message' => $input->message === null ? null : mb_strimwidth($input->message, 0, 2000, '…'),
            ]);
        }

        $this->log->record(SecurityEventType::BackupRestoreEnded, $input->actorId, context: [
            'run' => $input->sourceRunId,
            'restore' => $input->restoreRunId,
            'outcome' => $input->succeeded ? 'finished' : 'failed',
        ]);

        return null;
    }
}
