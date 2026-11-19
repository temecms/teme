<?php

/**
 * How a restore ended: its sentence and notes, the restore's run and its safety backup, whether the site is open, and how far it got.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

final readonly class RestoreResult
{
    /**
     * @param  list<string>  $notes
     */
    public function __construct(
        public bool $succeeded,
        public string $sentence,
        public ?string $restoreRunId,
        public ?string $safetyRunId,
        public array $notes,
        public bool $siteOpen,
        public RestoreStage $stage,
    ) {}
}
