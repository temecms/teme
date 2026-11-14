<?php

declare(strict_types=1);

namespace App\Domain\Backups\Tools;

use Carbon\CarbonImmutable;

/**
 * One backup run as a place holds it: its ID, when its first part was made,
 * each part's snapshot, and every snapshot it has.
 */
final readonly class SnapshotRun
{
    /**
     * @param  array<string, string>  $parts  part => snapshot ID
     * @param  list<string>  $ids  every snapshot of the run
     */
    public function __construct(
        public string $runId,
        public CarbonImmutable $time,
        public array $parts,
        public array $ids,
    ) {}

    /**
     * Whether all four parts are there: a run is complete exactly when its
     * manifest, written last, is (I-362).
     */
    public function complete(): bool
    {
        return array_diff(BackupSnapshots::PARTS, array_keys($this->parts)) === [];
    }
}
