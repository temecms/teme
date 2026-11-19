<?php

/**
 * Shows a new recovery key once, added to every place in use, for
 * ConfirmRecoveryKey to record when two of its groups are typed back (I-359).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations;

use App\Domain\Backups\BackupKeys;
use App\Domain\Backups\Operations\Concerns\ManagesBackups;
use App\Domain\Backups\Operations\Input\AddRecoveryKeyInput;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\RecoveryKeyPurpose;
use App\Domain\Backups\RecoveryKeySource;
use App\Domain\Backups\ShownRecoveryKey;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class AddRecoveryKey extends BaseOperation implements RequiresElevation
{
    use ManagesBackups;

    public function __construct(
        private readonly BackupSetup $setup,
        private readonly BackupKeys $keys,
        private readonly RecoveryKeySource $source,
    ) {}

    protected function validate(OperationInput $input): void
    {
        /** @var AddRecoveryKeyInput $input */
        $this->ensureSetUp();
        $this->ensureNotPaused();
        $this->ensureLabelFree($input->label);
    }

    protected function perform(OperationInput $input): ShownRecoveryKey
    {
        /** @var AddRecoveryKeyInput $input */
        $this->holdKeys();

        // Another Operation may have changed the keys since validate() looked.
        $this->ensureSetUp();
        $this->ensureNotPaused();
        $this->ensureLabelFree($input->label);

        return $this->showNewKey($this->keys, $this->source, trim($input->label), RecoveryKeyPurpose::Add, $input->actorId);
    }

    /**
     * @throws ValidationException
     */
    private function ensureLabelFree(string $label): void
    {
        $label = trim($label);

        if ($label === '' || mb_strlen($label) > 100) {
            throw ValidationException::withMessages(['label' => 'Give the key a name of up to 100 characters, such as who holds it.']);
        }

        if (in_array(mb_strtolower($label), array_map('mb_strtolower', $this->setup->recoveryKeyLabels()), true)) {
            throw ValidationException::withMessages(['label' => "Another recovery key is already called {$label}."]);
        }
    }
}
