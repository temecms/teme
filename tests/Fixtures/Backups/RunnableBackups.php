<?php

declare(strict_types=1);

namespace Tests\Fixtures\Backups;

use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Backups\Tools\SnapshotRun;
use App\Domain\Settings\Models\SettingsGroupRecord;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Backups as the keys-and-places part leaves them, written straight to the
 * tables: folder places, each a real restic repository the machine key
 * opens, the machine key stored in the backups group, a recovery key
 * recorded on the first place, and both media disks faked. delete() in
 * afterEach removes every repository it made.
 */
final class RunnableBackups
{
    /**
     * @param  list<BackupDestination>  $places
     * @param  list<TemporaryRepository>  $repositories
     */
    private function __construct(public readonly array $places, public readonly array $repositories) {}

    /**
     * $count folder places, oldest first, set up unless $setUp is false.
     */
    public static function atFolders(int $count = 1, bool $setUp = true): self
    {
        // The machine key is the fixture's repository key, so it opens every place.
        SettingsGroupRecord::query()->updateOrCreate(['group_key' => BackupSettings::GROUP], ['secrets' => ['machine_key' => TemporaryRepository::KEY], 'version' => 1]);
        Storage::fake((string) config('media.disk'));
        Storage::fake((string) config('media.originals_disk'));

        [$places, $repositories] = [[], []];

        for ($index = 0; $index < $count; $index++) {
            $repository = $repositories[] = TemporaryRepository::make();
            $place = $places[] = BackupRecords::destination([
                'name' => 'Place '.($index + 1),
                'location' => ['path' => $repository->folder],
                'repository_id' => $repository->id,
                'created_at' => now()->subMinutes(10 - $index),
            ]);
            BackupRecords::key($place);

            if ($setUp && $index === 0) {
                BackupRecords::key($place, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key']);
            }
        }

        return new self($places, $repositories);
    }

    /**
     * Teme's runs at place $index, as restic lists them, newest first.
     *
     * @return list<SnapshotRun>
     */
    public function runsAt(int $index): array
    {
        $runs = app(BackupSnapshots::class)->runs($this->repositories[$index]->repository);

        if (! is_array($runs)) {
            throw new RuntimeException('restic couldn’t list a test repository: '.$runs->detail());
        }

        return $runs;
    }

    public function delete(): void
    {
        foreach ($this->repositories as $repository) {
            $repository->delete();
        }
    }
}
