<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\NewPlace;
use App\Domain\Backups\Operations\AddBackupDestination;
use App\Domain\Backups\Operations\ChangeBackupDestination;
use App\Domain\Backups\Operations\Input\AddBackupDestinationInput;
use App\Domain\Backups\Operations\Input\ChangeBackupDestinationInput;
use App\Domain\Backups\Operations\Input\RemoveBackupDestinationInput;
use App\Domain\Backups\Operations\Input\TestBackupDestinationInput;
use App\Domain\Backups\Operations\RemoveBackupDestination;
use App\Domain\Backups\Operations\TestBackupDestination;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\RecoveryKey;
use App\Domain\Backups\Tools\SftpKeyPair;
use App\Domain\Backups\Tools\SshKeyscan;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * teme:backup:destinations: lists, adds, changes, tests and removes the places
 * backups are kept, as the operator (I-360). Adding and changing ask for a
 * recovery key or a password, so a person must run them.
 */
final class ManageBackupDestinations extends Command
{
    private const array ACTIONS = ['list', 'add', 'change', 'test', 'remove'];

    /** @var string */
    protected $signature = 'teme:backup:destinations {action=list : list, add, change, test or remove} {place? : The place\'s name or ID}';

    /** @var string */
    protected $description = 'List, add, change, test and remove the places backups are kept';

    public function handle(BackupSetup $setup, SshKeyscan $keyscan): int
    {
        $action = (string) $this->argument('action');

        if (! in_array($action, self::ACTIONS, true)) {
            $this->error('Choose list, add, change, test or remove.');

            return self::FAILURE;
        }

        if (in_array($action, ['add', 'change'], true) && ! $this->input->isInteractive()) {
            $this->error('This needs a person at the terminal: it asks for a recovery key or a password.');

            return self::FAILURE;
        }

        // The operator, as teme:trash:purge is (docs/architecture/operations.md).
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        try {
            return match ($action) {
                'list' => $this->listPlaces($setup),
                'add' => $this->addPlace($keyscan),
                default => $this->actOnPlace($action, $setup, $keyscan),
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

    private function listPlaces(BackupSetup $setup): int
    {
        $places = BackupDestination::query()->orderBy('created_at')->orderBy('id')->get();

        if ($places->isEmpty()) {
            $this->line('No places yet. Run php artisan teme:backup:setup.');

            return self::SUCCESS;
        }

        $this->table(['Name', 'Kind', 'Where', 'State', 'Last answered', 'Keys'], $places->map(fn (BackupDestination $place): array => [
            $place->name,
            match ($place->kind) {
                BackupDestinationKind::Folder => 'Folder',
                BackupDestinationKind::S3 => 'S3',
                BackupDestinationKind::Sftp => 'SFTP',
            },
            $this->whereIs($place),
            match ($place->state) {
                BackupDestinationState::Ready => 'Ready',
                BackupDestinationState::Failing => 'Failing: '.$place->last_error,
                BackupDestinationState::Removed => 'Removed',
            },
            $place->last_answered_at?->toDateTimeString() ?? '',
            implode(', ', $place->keys()->where('role', BackupKeyRole::Recovery->value)->whereNull('removed_at')->orderBy('label')->pluck('label')->all()),
        ])->all());

        return self::SUCCESS;
    }

    private function whereIs(BackupDestination $place): string
    {
        $location = $place->location;

        return match ($place->kind) {
            BackupDestinationKind::Folder => (string) ($location['path'] ?? ''),
            BackupDestinationKind::S3 => $location['endpoint'].'/'.$location['bucket'].(isset($location['path']) ? '/'.$location['path'] : ''),
            BackupDestinationKind::Sftp => $location['user'].'@'.$location['host'].':'.$location['port'].$location['path'],
        };
    }

    private function addPlace(SshKeyscan $keyscan): int
    {
        $place = $this->askForPlace($keyscan);

        if ($place === null) {
            return self::FAILURE;
        }

        $key = RecoveryKey::fromTyped((string) $this->secret('Your recovery key, to lock the new place with it too'));
        $added = app(AddBackupDestination::class)->execute(new AddBackupDestinationInput($place, $key));

        $this->line("Added {$place->name()}.");

        foreach ($added->labelsMissing as $label) {
            $this->line("{$label} doesn’t open {$place->name()} yet. Whoever holds it can run php artisan teme:backup:key extend.");
        }

        return self::SUCCESS;
    }

    private function actOnPlace(string $action, BackupSetup $setup, SshKeyscan $keyscan): int
    {
        $name = (string) $this->argument('place');
        $place = $setup->placesInUse()->first(fn (BackupDestination $each): bool => $each->id === $name || mb_strtolower($each->name) === mb_strtolower($name));

        if ($place === null) {
            $this->error($name === '' ? 'There’s no place called that in use.' : "There’s no place called {$name} in use.");

            return self::FAILURE;
        }

        if ($action === 'test') {
            $answer = app(TestBackupDestination::class)->execute(new TestBackupDestinationInput($place->id));
            $this->line($answer->sentence);

            return $answer->opened ? self::SUCCESS : self::FAILURE;
        }

        if ($action === 'remove') {
            if (! $this->confirm("Teme will stop using {$place->name}. The backups already there stay there, and only a recovery key opens them. Remove it?", false)) {
                return self::SUCCESS;
            }

            app(RemoveBackupDestination::class)->execute(new RemoveBackupDestinationInput($place->id));
            $this->line("Removed {$place->name}. Its backups are still there.");

            return self::SUCCESS;
        }

        return $this->changePlace($place, $keyscan);
    }

    private function changePlace(BackupDestination $place, SshKeyscan $keyscan): int
    {
        $name = (string) $this->ask('Name', $place->name);
        $location = $place->location;
        $credentials = null;

        if ($place->kind === BackupDestinationKind::Folder) {
            $location = ['path' => (string) $this->ask('Folder', (string) $location['path'])];
        } elseif ($place->kind === BackupDestinationKind::S3) {
            $stored = $place->credentials ?? [];
            $location = array_filter([
                'endpoint' => (string) $this->ask('Storage address (https://…)', (string) $location['endpoint']),
                'bucket' => (string) $this->ask('Bucket', (string) $location['bucket']),
                'path' => $this->ask('Folder in the bucket (optional)', (string) ($location['path'] ?? '')),
                'region' => $this->ask('Region (optional)', (string) ($location['region'] ?? '')),
            ], fn (mixed $value): bool => $value !== null && $value !== '');
            $secret = (string) $this->secret('Secret access key (leave empty to keep it)');
            $credentials = [
                'access_key_id' => (string) $this->ask('Access key ID', (string) ($stored['access_key_id'] ?? '')),
                'secret_access_key' => $secret === '' ? (string) ($stored['secret_access_key'] ?? '') : $secret,
            ];
        } else {
            $host = (string) $this->ask('Server', (string) $location['host']);
            $port = (int) $this->ask('Port', (string) $location['port']);
            $new = [
                'host' => $host,
                'port' => $port,
                'user' => (string) $this->ask('User', (string) $location['user']),
                'path' => (string) $this->ask('Folder on the server', (string) $location['path']),
            ];

            if ($host !== $location['host'] || $port !== (int) $location['port']) {
                $hostKey = $keyscan->hostKey($host, $port);

                if (! $this->confirm("The server’s fingerprint is {$hostKey->fingerprint()}. Is that the server you expect?", false)) {
                    $this->line("Nothing changed. Check the fingerprint with whoever runs {$host}, then run this again.");

                    return self::FAILURE;
                }

                $new['host_key'] = $hostKey->line();
            }

            $location = $new;
        }

        app(ChangeBackupDestination::class)->execute(new ChangeBackupDestinationInput($place->id, $name, $location, $credentials));
        $this->line("Changed {$name}.");

        return self::SUCCESS;
    }

    /**
     * Asks where the new place is; null when the person doesn't trust the SFTP server's fingerprint.
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
}
