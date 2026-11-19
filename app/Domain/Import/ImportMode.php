<?php

/**
 * Whether the work in hand is an import run's (I-332): while it is, each
 * Operation's purge of the page cache is held, and the run ends with one
 * purge of everything. Only the engine's jobs enter it, for a run that is
 * running; it is bound scoped, so it ends with the job.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Import\Models\ImportRun;
use Closure;
use LogicException;

final class ImportMode
{
    private ?string $runId = null;

    /**
     * Runs $work in import mode for this run, and leaves the mode whatever
     * happens.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     *
     * @param-immediately-invoked-callable  $work
     *
     * @return T
     *
     * @throws LogicException for a run that isn't running, or when the mode is already on
     */
    public function during(string $runId, Closure $work): mixed
    {
        if ($this->runId !== null) {
            throw new LogicException("Import mode is already on, for run [{$this->runId}].");
        }

        $running = ImportRun::query()->whereKey($runId)->where('state', ImportRunState::Running->value)->exists();

        if (! $running) {
            throw new LogicException('Only a running import can enter import mode.');
        }

        $this->runId = $runId;

        try {
            return $work();
        } finally {
            $this->runId = null;
        }
    }

    /**
     * Whether a run's work is in hand.
     */
    public function active(): bool
    {
        return $this->runId !== null;
    }

    /**
     * The run whose work is in hand, or null outside one.
     */
    public function runId(): ?string
    {
        return $this->runId;
    }
}
