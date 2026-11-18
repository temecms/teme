<?php

/**
 * What a restore will do, found by the preflight and shown before the person confirms: the backup, its place and size, whether a safety backup comes first, and anything to know.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

use Carbon\CarbonImmutable;

final readonly class RestorePlan
{
    /**
     * @param  list<string>  $notes
     * @param  array{run: string, safety: string|null, safety_place: array{name: string, kind: string, location: array<string, string|int>, repository_id: string}|null, safety_missing?: int, since: string}|null  $unfinished
     */
    public function __construct(
        public string $runId,
        public CarbonImmutable $madeAt,
        public string $made,
        public string $place,
        public string $teme,
        public int $tables,
        public int $rows,
        public int $mediaFiles,
        public int $mediaBytes,
        public bool $safetyBackup,
        public ?array $unfinished,
        public array $notes,
    ) {}
}
