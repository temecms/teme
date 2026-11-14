<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\NamesRestorePlace;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Queries\BackupPlaces;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\Restore\RestorePoints;
use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Backups\Tools\PlaceRepositories;
use App\Domain\Backups\Tools\ResticResult;
use App\Domain\Backups\Tools\SnapshotRun;
use App\Domain\Backups\Tools\SshKeyscan;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Teme\Contracts\Settings\SettingsReader;

/**
 * teme:backup:list — the backups kept at a place, newest first, by run ID, to choose one to restore (docs/architecture/backups.md).
 */
final class ListBackups extends Command
{
    use NamesRestorePlace;

    /** @var string */
    protected $signature = 'teme:backup:list {--place= : The place, by name or ID; the first in use when left out}'.self::PLACE_OPTIONS;

    /** @var string */
    protected $description = 'List the backups kept at a place, newest first, to choose one to restore';

    public function handle(BackupSetup $setup, BackupPlaces $places, BackupSnapshots $snapshots, SettingsReader $settings, SshKeyscan $keyscan): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $named = $this->namedPlace($keyscan, false);

        if ($named === false) {
            return self::FAILURE;
        }

        if ($named['from'] !== null) {
            $from = $named['from'];

            if ($from->problems() !== []) {
                foreach ($from->problems() as $problem) {
                    $this->error($problem);
                }

                return self::FAILURE;
            }

            $title = $from->name();
            $runs = PlaceRepositories::during(fn (PlaceRepositories $open): array|ResticResult => $snapshots->runs($open->repository($from, (string) $named['key'])));
        } else {
            $candidates = $setup->placesInUse();

            if ($candidates->isEmpty()) {
                $this->error(RestorePoints::NO_PLACE);

                return self::FAILURE;
            }

            $name = $this->option('place');
            $place = $name === null
                ? $candidates->first()
                : $candidates->first(fn (BackupDestination $one): bool => $one->id === $name || mb_strtolower($one->name) === mb_strtolower((string) $name));

            if ($place === null) {
                $this->error("There’s no place called “{$name}”.");

                return self::FAILURE;
            }

            $key = $places->machineKey();

            if ($key === null) {
                $this->error('Backups aren’t set up: there’s no machine key.');

                return self::FAILURE;
            }

            $title = $place->name;
            $runs = PlaceRepositories::during(fn (PlaceRepositories $open): array|ResticResult => $snapshots->runs($open->repository($place, $key)));
        }

        if (! is_array($runs)) {
            $this->error($runs->sentence($title));

            return self::FAILURE;
        }

        $runs = array_values(array_filter($runs, fn (SnapshotRun $run): bool => $run->runId !== ''));
        usort($runs, fn (SnapshotRun $a, SnapshotRun $b): int => $b->time <=> $a->time);

        if ($runs === []) {
            $this->line("{$title} holds no backups yet.");

            return self::SUCCESS;
        }

        $zone = $settings->string('general', 'timezone');
        $this->line("Backups at {$title}, newest first:");

        foreach ($runs as $run) {
            $this->line("{$run->runId}  {$run->time->setTimezone($zone)->format('Y-m-d H:i')}".($run->complete() ? '' : '  (incomplete: it can’t be restored)'));
        }

        return self::SUCCESS;
    }
}
