<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backups\Operations\AddRecoveryKey;
use App\Domain\Backups\Operations\CheckRecoveryKey;
use App\Domain\Backups\Operations\ConfirmRecoveryKey;
use App\Domain\Backups\Operations\ExtendRecoveryKey;
use App\Domain\Backups\Operations\Input\AddRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\CheckRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\ConfirmRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\ExtendRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\ReplaceRecoveryKeyInput;
use App\Domain\Backups\Operations\ReplaceRecoveryKey;
use App\Domain\Backups\RecoveryKey;
use App\Domain\Backups\RecoveryKeyConfirmed;
use App\Domain\Backups\ShownRecoveryKey;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * teme:backup:key: checks, adds, extends and replaces the recovery keys that
 * open the backups, as the operator (I-359). Every action shows or asks for a
 * key, so a person must run it.
 */
final class ManageBackupKeys extends Command
{
    private const int TRIES = 3;

    /** @var string */
    protected $signature = 'teme:backup:key {action : check, add, extend or replace} {label? : The recovery key\'s name, for add and replace}';

    /** @var string */
    protected $description = 'Check, add, extend and replace the recovery keys that open your backups';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        if (! in_array($action, ['check', 'add', 'extend', 'replace'], true)) {
            $this->error('Choose check, add, extend or replace.');

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->error('This needs a person at the terminal: it asks for a recovery key or a password.');

            return self::FAILURE;
        }

        // The operator, as teme:trash:purge is (docs/architecture/operations.md).
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        try {
            return match ($action) {
                'check' => $this->check(),
                'extend' => $this->extend(),
                default => $this->showAndConfirm($action),
            };
        } catch (ValidationException $refused) {
            foreach ($refused->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }
    }

    private function check(): int
    {
        $result = app(CheckRecoveryKey::class)->execute(new CheckRecoveryKeyInput($this->askForKey()));

        if ($result->label !== null) {
            $this->line("It is {$result->label}.");
        }

        foreach ($result->opens as $place) {
            $this->line("Opens the backups at {$place}.");
        }

        foreach ($result->doesNotOpen as $place) {
            $this->line("Doesn’t open the backups at {$place}.");
        }

        return $result->doesNotOpen === [] ? self::SUCCESS : self::FAILURE;
    }

    private function extend(): int
    {
        $result = app(ExtendRecoveryKey::class)->execute(new ExtendRecoveryKeyInput($this->askForKey()));

        $this->line("{$result['label']} now opens the backups at ".implode(', ', $result['places']).' too.');

        return self::SUCCESS;
    }

    private function showAndConfirm(string $action): int
    {
        $label = trim((string) $this->argument('label'));

        if ($action === 'replace' && ! $this->confirm("Teme will make a new key for {$label}. The old one keeps working until you’ve typed part of the new one back. Go on?", false)) {
            return self::SUCCESS;
        }

        $shown = $action === 'add'
            ? app(AddRecoveryKey::class)->execute(new AddRecoveryKeyInput($label))
            : app(ReplaceRecoveryKey::class)->execute(new ReplaceRecoveryKeyInput($label));

        $this->showKey($shown, $label);
        $confirmed = $this->typeGroupsBack($shown);

        if ($confirmed === null) {
            $this->error('Not confirmed. Run php artisan teme:backup:key '.$action.' again to see a new key.');

            return self::FAILURE;
        }

        if ($action === 'add') {
            $this->line("{$label} now opens the backups at every place.");

            return self::SUCCESS;
        }

        $this->line("{$label} is replaced: the old key no longer opens the backups.");

        foreach ($confirmed->oldKeyKeptAt as $place) {
            $this->line("The old key still opens {$place}, which didn’t answer. Replace {$label} again once it does.");
        }

        return self::SUCCESS;
    }

    private function askForKey(): RecoveryKey
    {
        return RecoveryKey::fromTyped((string) $this->secret('Your recovery key'));
    }

    /**
     * Shows the key once, in the clear, and never through a log (I-2401).
     */
    private function showKey(ShownRecoveryKey $shown, string $label): void
    {
        $this->line('');
        $this->line("Your new recovery key, {$label}");
        $this->line('');
        $this->line('  '.$shown->key->value());
        $this->line('');
        $this->line('Write it down or print it, and keep it somewhere other than this server. Teme keeps no copy.');
        $this->line('If this server is lost, this key is the only way to open your backups. Anyone who has it and a copy of them can read them.');
        $this->line('To open them with restic itself, type it exactly as shown, with its hyphens.');
        $this->line('');
    }

    /**
     * Asks for the two groups, up to three times; null when none matched.
     */
    private function typeGroupsBack(ShownRecoveryKey $shown): ?RecoveryKeyConfirmed
    {
        [$first, $second] = $shown->challenge->positions;

        for ($try = 0; $try < self::TRIES; $try++) {
            $groups = [
                $first => (string) $this->secret("Type group {$first} of your recovery key"),
                $second => (string) $this->secret("Type group {$second} of your recovery key"),
            ];

            try {
                return app(ConfirmRecoveryKey::class)->execute(new ConfirmRecoveryKeyInput($shown->challenge, $groups));
            } catch (ValidationException $refused) {
                if (! array_key_exists('groups', $refused->errors())) {
                    throw $refused;
                }

                $this->error($refused->errors()['groups'][0]);
            }
        }

        return null;
    }
}
