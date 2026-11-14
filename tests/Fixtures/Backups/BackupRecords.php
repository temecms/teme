<?php

declare(strict_types=1);

namespace Tests\Fixtures\Backups;

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Models\BackupKey;
use App\Domain\Backups\Models\BackupRun;

/**
 * Rows of the three backup tables, written straight to the database for a
 * test that needs a place, a run or a key to exist. Application code writes
 * them through the backup Operations only.
 */
final class BackupRecords
{
    /**
     * A place in use: a folder by default, with a fresh repository ID, so
     * two in one test never clash.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function destination(array $overrides = []): BackupDestination
    {
        $kind = $overrides['kind'] ?? BackupDestinationKind::Folder;
        $kind = $kind instanceof BackupDestinationKind ? $kind : BackupDestinationKind::from((string) $kind);

        return BackupDestination::query()->create([
            'name' => 'Place '.bin2hex(random_bytes(3)),
            'location' => match ($kind) {
                BackupDestinationKind::Folder => ['path' => '/var/backups/teme'],
                BackupDestinationKind::S3 => ['endpoint' => 'https://s3.example.com', 'bucket' => 'teme-backups', 'path' => 'site'],
                BackupDestinationKind::Sftp => ['host' => 'backups.example.com', 'user' => 'teme', 'path' => '/srv/teme'],
            },
            'credentials' => match ($kind) {
                BackupDestinationKind::Folder => null,
                BackupDestinationKind::S3 => ['access_key_id' => 'AKIATEST', 'secret_access_key' => 'test-secret'],
                BackupDestinationKind::Sftp => ['private_key' => 'test-private-key'],
            },
            'off_server' => $kind->isOffServer(),
            'repository_id' => bin2hex(random_bytes(32)),
            'state' => BackupDestinationState::Ready,
            ...$overrides,
            'kind' => $kind,
        ]);
    }

    /**
     * A finished nightly backup by default, five minutes long.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function run(array $overrides = []): BackupRun
    {
        return BackupRun::query()->create([
            'kind' => BackupRunKind::Backup,
            'state' => BackupRunState::Finished,
            'started_by' => BackupTrigger::Schedule,
            'figures' => [],
            'started_at' => now()->subMinutes(5),
            'ended_at' => now(),
            ...$overrides,
        ]);
    }

    /**
     * A key in use on $place: the machine key by default.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function key(BackupDestination $place, array $overrides = []): BackupKey
    {
        return BackupKey::query()->create([
            'destination_id' => $place->id,
            'restic_key_id' => bin2hex(random_bytes(32)),
            'role' => BackupKeyRole::Machine,
            'label' => 'Machine key',
            'added_at' => now(),
            ...$overrides,
        ]);
    }
}
