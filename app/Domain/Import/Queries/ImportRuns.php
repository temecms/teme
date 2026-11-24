<?php

/**
 * The import runs read (I-332): the one running, the latest, the last
 * finished, the picture pass that follows one, and what a run's phases are
 * given. The latest and the last finished are never a picture pass, which
 * belongs to the run it follows (#716).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Queries;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Support\Security\JobActor;
use Carbon\CarbonInterface;

final class ImportRuns
{
    /**
     * The source's running import, or null when none runs.
     */
    public function running(string $sourceId): ?ImportRun
    {
        return ImportRun::query()->where('source_id', $sourceId)->where('state', ImportRunState::Running->value)->first();
    }

    /**
     * The source's most recently started import, or null when it has had none.
     */
    public function latest(string $sourceId): ?ImportRun
    {
        return ImportRun::query()->where('source_id', $sourceId)->where('kind', '<>', ImportRunKind::MediaPass->value)->orderByDesc('started_at')->orderByDesc('id')->first();
    }

    /**
     * The latest picture pass that follows this run, or null when it has none.
     *
     * @since next
     */
    public function passOf(string $runId): ?ImportRun
    {
        return ImportRun::query()->where('follows_run_id', $runId)->where('kind', ImportRunKind::MediaPass->value)->orderByDesc('started_at')->orderByDesc('id')->first();
    }

    /**
     * The source's latest finished import (one started before $before when
     * given), or null when none has finished.
     */
    public function lastFinished(string $sourceId, ?CarbonInterface $before = null): ?ImportRun
    {
        return ImportRun::query()
            ->where('source_id', $sourceId)
            ->where('state', ImportRunState::Finished->value)
            ->where('kind', '<>', ImportRunKind::MediaPass->value)
            ->when($before !== null, fn ($query) => $query->where('started_at', '<', $before))
            ->orderByDesc('started_at')->orderByDesc('id')
            ->first();
    }

    /**
     * What the phases of this run are given (I-345): for a catch-up, what
     * changed after an hour before the last finished run's high-water mark,
     * an hour for clocks that differ, which costs reads, never writes.
     */
    public function context(ImportRun $run, JobActor $actor): ImportContext
    {
        $after = null;

        if ($run->kind === ImportRunKind::CatchUp) {
            $after = $this->lastFinished($run->source_id, $run->started_at)?->high_water_at->subHour();
        }

        return new ImportContext(
            (string) $run->id,
            (string) $run->source_id,
            $run->kind,
            $actor,
            $after,
            (int) ($run->options['pause_ms'] ?? 1000),
            $run->choices,
            $run->file_id === null ? null : (string) $run->file_id,
            // A run started before #701 holds no lanes, and reads its media in one.
            (int) ($run->options['media_lanes'] ?? 1),
        );
    }
}
