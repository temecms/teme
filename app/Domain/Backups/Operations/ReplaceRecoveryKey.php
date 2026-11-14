<?php

declare(strict_types=1);

namespace App\Domain\Backups\Operations;

use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\Operations\Concerns\ManagesBackups;
use App\Domain\Backups\Operations\Input\ReplaceRecoveryKeyInput;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\RecoveryKeyPurpose;
use App\Domain\Backups\RecoveryKeySource;
use App\Domain\Backups\ShownRecoveryKey;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * Shows a new key to replace a recovery key, added to every place in use;
 * ConfirmRecoveryKey records it and only then removes the old one, so the
 * backups are never left without it (I-359).
 */
final class ReplaceRecoveryKey extends BaseOperation implements RequiresElevation
{
    use ManagesBackups;

    public function __construct(
        private readonly BackupSetup $setup,
        private readonly BackupKeys $keys,
        private readonly RecoveryKeySource $source,
    ) {}

    protected function validate(OperationInput $input): void
    {
        /** @var ReplaceRecoveryKeyInput $input */
        $this->ensureSetUp();
        $this->ensureNotPaused();
        $this->recordedLabel($input->label);
    }

    protected function perform(OperationInput $input): ShownRecoveryKey
    {
        /** @var ReplaceRecoveryKeyInput $input */
        $this->holdKeys();

        // Another Operation may have changed the keys since validate() looked.
        $this->ensureSetUp();
        $this->ensureNotPaused();

        return $this->showNewKey($this->keys, $this->source, $this->recordedLabel($input->label), RecoveryKeyPurpose::Replace, $input->actorId);
    }

    /**
     * The label as recorded, found without regard to case.
     *
     * @throws ValidationException
     */
    private function recordedLabel(string $label): string
    {
        $label = trim($label);

        foreach ($this->setup->recoveryKeyLabels() as $recorded) {
            if (mb_strtolower($recorded) === mb_strtolower($label)) {
                return $recorded;
            }
        }

        throw ValidationException::withMessages(['label' => "There’s no recovery key called {$label}."]);
    }
}
