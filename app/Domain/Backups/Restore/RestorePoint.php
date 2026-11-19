<?php

/**
 * A plan, and what carrying it out needs: the place's repository, the run, its manifest and APP_KEY, the media's folders, and the places this site knew before, and whether the place was named on the command line. Lives only inside one PlaceRepositories call.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

use App\Domain\Backups\Manifest;
use App\Domain\Backups\Tools\ResticRepository;
use App\Domain\Backups\Tools\SnapshotRun;

final readonly class RestorePoint
{
    /**
     * @param  list<array{role: string, from: string, to: string}>  $mediaPairs
     * @param  list<array{name: string, repository_id: string}>  $placesBefore
     */
    public function __construct(
        public RestorePlan $plan,
        public ResticRepository $repository,
        public SnapshotRun $run,
        public Manifest $manifest,
        public BackupAppKey $appKey,
        public ?string $placeRepositoryId,
        public array $mediaPairs,
        public array $placesBefore,
        public bool $named = false,
    ) {}
}
