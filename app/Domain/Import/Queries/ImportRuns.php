<?php

declare(strict_types=1);

namespace App\Domain\Import\Queries;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Support\Security\JobActor;
use Carbon\CarbonInterface;

/**
 * The import runs read (I-332): the one running, the latest, the last
 * finished, and what a run's phases are given.
 */
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
        return ImportRun::query()->where('source_id', $sourceId)->orderByDesc('started_at')->orderByDesc('id')->first();
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
        );
    }
}
