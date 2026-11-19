<?php

/**
 * Asks the plugin and theme directory for its index and records what came:
 * the index once its signature, number and expiry are checked, or the
 * reason it was refused, keeping the last good one. Nothing is asked while
 * the directory is switched off.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Directory;

use App\Domain\Extensions\Operations\Input\RecordDirectoryIndexInput;
use App\Domain\Extensions\Operations\RecordDirectoryIndex;
use App\Domain\Extensions\Queries\DirectoryPackages;
use Carbon\CarbonImmutable;

final readonly class RefreshDirectory
{
    /** How long a kept index is read before the directory is asked again on a search. */
    public const int FRESH_MINUTES = 60;

    public function __construct(
        private DirectoryReader $reader,
        private DirectoryPackages $packages,
        private RecordDirectoryIndex $record,
    ) {}

    /**
     * Asks and records, as the operator when $actorId is null. Returns the
     * reason the index was refused, or null when one was kept; null too,
     * having asked nothing, when the directory is off.
     */
    public function run(?string $actorId = null): ?string
    {
        if (! DirectorySettings::enabled()) {
            return null;
        }

        $now = CarbonImmutable::now();

        try {
            $found = $this->reader->index($this->packages->state()['sequence'], $now);
            $this->record->execute(new RecordDirectoryIndexInput($found['document'], null, $now, $actorId));

            return null;
        } catch (DirectoryRefused $refused) {
            $this->record->execute(new RecordDirectoryIndexInput(null, $refused->getMessage(), $now, $actorId));

            return $refused->getMessage();
        }
    }

    /**
     * Asks only when the directory was last asked more than an hour ago, or never.
     */
    public function ifStale(?string $actorId = null): void
    {
        $checked = $this->packages->state()['checked'];

        if ($checked === null || CarbonImmutable::parse($checked)->addMinutes(self::FRESH_MINUTES)->isPast()) {
            $this->run($actorId);
        }
    }
}
