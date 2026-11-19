<?php

/**
 * teme:backup:setup: sets backups up at the server, as the operator
 * (I-2401): the first place, then the recovery key shown once and two of its
 * groups typed back. A person must run it: it never runs without one.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\NewPlace;
use App\Domain\Backups\Operations\ConfirmRecoveryKey;
use App\Domain\Backups\Operations\Input\ConfirmRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\SetUpBackupsInput;
use App\Domain\Backups\Operations\SetUpBackups;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\ShownRecoveryKey;
use App\Domain\Backups\Tools\SftpKeyPair;
use App\Domain\Backups\Tools\SshKeyscan;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class ManageBackupSetup extends Command
{
    private const int TRIES = 3;

    /** @var string */
    protected $signature = 'teme:backup:setup';

    /** @var string */
    protected $description = 'Set backups up: choose the first place, and keep the recovery key Teme shows once';

    public function handle(BackupSetup $setup, SshKeyscan $keyscan, SetUpBackups $setUp, ConfirmRecoveryKey $confirm): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('teme:backup:setup shows a recovery key once and asks for part of it back, so a person must run it at a terminal.');

            return self::FAILURE;
        }

        // The operator, as teme:trash:purge is (docs/architecture/operations.md).
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        try {
            if ($setup->isFinished()) {
                $this->line('Backups are already set up. See php artisan teme:backup:destinations.');

                return self::SUCCESS;
            }

            $unfinished = $setup->unfinishedPlace();

            if ($unfinished !== null) {
                $this->line("Backups are being set up at {$unfinished->name}, but the recovery key wasn’t confirmed.");

                if (! $this->confirm("Show a new recovery key for {$unfinished->name}?", true)) {
                    $this->line("Nothing changed. To start again somewhere else, run php artisan teme:backup:destinations remove {$unfinished->name}.");

                    return self::SUCCESS;
                }

                $place = null;
                $name = $unfinished->name;
                $isFolder = $unfinished->kind === BackupDestinationKind::Folder;
            } else {
                $place = $this->askForPlace($keyscan);

                if ($place === null) {
                    return self::FAILURE;
                }

                $name = $place->name();
                $isFolder = $place->kind() === BackupDestinationKind::Folder;
            }

            $shown = $setUp->execute(new SetUpBackupsInput($place));
            $this->showKey($shown);

            if (! $this->typeGroupsBack($shown, $confirm)) {
                $this->error('Not confirmed. Run php artisan teme:backup:setup again to see a new key.');

                return self::FAILURE;
            }
        } catch (ValidationException $refused) {
            foreach ($refused->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->line("Backups are set up at {$name}.");

        if ($isFolder) {
            $this->line("{$name} is on this server, so a backup won’t survive losing it. Add a place off it with php artisan teme:backup:destinations add.");
        }

        $this->line('Clear this terminal’s scrollback, so the key isn’t left on screen.');

        return self::SUCCESS;
    }

    /**
     * Asks where backups are kept; null when the person doesn't trust the SFTP server's fingerprint.
     *
     * @throws ValidationException
     */
    private function askForPlace(SshKeyscan $keyscan): ?NewPlace
    {
        $kinds = ['A folder on this server', 'S3-compatible storage', 'An SFTP server'];
        $kind = $this->choice('Where should backups be kept?', $kinds, 0);

        if ($kind === $kinds[0]) {
            $name = (string) $this->ask('Name this place', 'This server');

            return NewPlace::folder($name, (string) $this->ask('Folder', BackupSetup::suggestedFolder()));
        }

        $name = (string) $this->ask('Name this place', 'Off-site storage');

        if ($kind === $kinds[1]) {
            return NewPlace::s3(
                $name,
                (string) $this->ask('Storage address (https://…)'),
                (string) $this->ask('Bucket'),
                $this->ask('Folder in the bucket (optional)'),
                $this->ask('Region (optional)'),
                (string) $this->secret('Access key ID'),
                (string) $this->secret('Secret access key'),
            );
        }

        $host = (string) $this->ask('Server');
        $port = (int) $this->ask('Port', '22');
        $user = (string) $this->ask('User');
        $path = (string) $this->ask('Folder on the server');
        $pair = SftpKeyPair::generate();

        $this->line("Add this public key to {$user}’s authorized_keys on {$host}, then press Enter:");
        $this->line($pair->publicKey());
        $this->ask('');

        $hostKey = $keyscan->hostKey($host, $port);

        if (! $this->confirm("The server’s fingerprint is {$hostKey->fingerprint()}. Is that the server you expect?", false)) {
            $this->line("Nothing changed. Check the fingerprint with whoever runs {$host}, then run this again.");

            return null;
        }

        return NewPlace::sftp($name, $host, $port, $user, $path, $pair, $hostKey);
    }

    /**
     * Shows the key once, in the clear, and never through a log (I-2401).
     */
    private function showKey(ShownRecoveryKey $shown): void
    {
        $this->line('');
        $this->line('Your recovery key');
        $this->line('');
        $this->line('  '.$shown->key->value());
        $this->line('');
        $this->line('Write it down or print it, and keep it somewhere other than this server. Teme keeps no copy.');
        $this->line('If this server is lost, this key is the only way to open your backups. Anyone who has it and a copy of them can read them.');
        $this->line('To open them with restic itself, type it exactly as shown, with its hyphens.');
        $this->line('');
    }

    /**
     * Asks for the two groups, up to three times.
     */
    private function typeGroupsBack(ShownRecoveryKey $shown, ConfirmRecoveryKey $confirm): bool
    {
        [$first, $second] = $shown->challenge->positions;

        for ($try = 0; $try < self::TRIES; $try++) {
            $groups = [
                $first => (string) $this->secret("Type group {$first} of your recovery key"),
                $second => (string) $this->secret("Type group {$second} of your recovery key"),
            ];

            try {
                $confirm->execute(new ConfirmRecoveryKeyInput($shown->challenge, $groups));

                return true;
            } catch (ValidationException $refused) {
                if (! array_key_exists('groups', $refused->errors())) {
                    throw $refused;
                }

                $this->error($refused->errors()['groups'][0]);
            }
        }

        return false;
    }
}
