<?php

/**
 * Says which places a recovery key opens, keeping nothing of it (I-359); an
 * Operation because it asks for the password again and leaves a security log
 * entry.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations;

use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\Operations\Concerns\ManagesBackups;
use App\Domain\Backups\Operations\Input\CheckRecoveryKeyInput;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\RecoveryKeyCheck;
use App\Domain\Backups\Tools\PlaceRepositories;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class CheckRecoveryKey extends BaseOperation implements RequiresElevation
{
    use ManagesBackups;

    public function __construct(
        private readonly BackupSetup $setup,
        private readonly BackupKeys $keys,
        private readonly SecurityLog $log,
    ) {}

    protected function validate(OperationInput $input): void
    {
        $this->ensureSetUp();
    }

    protected function perform(OperationInput $input): RecoveryKeyCheck
    {
        /** @var CheckRecoveryKeyInput $input */
        $typed = $input->recoveryKey->value();
        $opens = [];
        $doesNotOpen = [];
        $label = null;

        PlaceRepositories::during(function (PlaceRepositories $open) use ($typed, &$opens, &$doesNotOpen, &$label): void {
            foreach ($this->setup->placesInUse() as $place) {
                $resticKeyId = $this->keys->opensWith($open->repository($place, $typed), $place->name);

                if ($resticKeyId === null) {
                    $doesNotOpen[] = $place->name;

                    continue;
                }

                $opens[] = $place->name;
                $label ??= $place->keys()->where('role', BackupKeyRole::Recovery->value)->whereNull('removed_at')->where('restic_key_id', $resticKeyId)->value('label');
            }
        });

        $this->log->record(SecurityEventType::BackupRecoveryKeyChecked, $input->actorId, context: ['label' => $label, 'opened' => count($opens), 'of' => count($opens) + count($doesNotOpen)]);

        return new RecoveryKeyCheck($label, $opens, $doesNotOpen);
    }
}
