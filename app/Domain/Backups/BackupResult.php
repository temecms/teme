<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * How a backup or a prune ended: its run; whether it did its work, and its
 * sentence when it didn't; where it landed and how many media files it
 * holds; how many files the database names it lacks because this server
 * lacked them, whether that was its only hole, and a media folder that
 * wasn't there at all; and the problems of the
 * steps after it (a copy, a check, a place's prune), which never undo it.
 */
final readonly class BackupResult
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(
        public string $runId,
        public bool $succeeded,
        public ?string $sentence,
        public array $problems = [],
        public ?string $place = null,
        public int $mediaFiles = 0,
        public int $missingFiles = 0,
        public bool $otherHoles = false,
        public ?string $absentMediaFolder = null,
    ) {}

    /**
     * Whether the run's missing files look like media that isn't there at
     * all rather than files lost one by one: every file the database names
     * is missing, or a media folder they're on wasn't on this server.
     */
    public function mediaLooksUnmounted(): bool
    {
        return $this->missingFiles > 0 && ($this->missingFiles === $this->mediaFiles || $this->absentMediaFolder !== null);
    }

    /**
     * Whether the run failed with one hole only: media files the database
     * names that weren't on this server, which a restore's safety backup
     * may have. False for a run that succeeded or was refused.
     */
    public function onlyMissingFiles(): bool
    {
        return ! $this->succeeded && $this->missingFiles > 0 && ! $this->otherHoles;
    }

    /**
     * Whether everything went well: the work, and every step after it.
     */
    public function allWell(): bool
    {
        return $this->succeeded && $this->problems === [];
    }
}
