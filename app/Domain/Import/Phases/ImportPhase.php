<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases;

use App\Domain\Import\ImportRunKind;

/**
 * One phase of an import, run in its order (I-333): a list phase or a step.
 * Registered by container tag under ImportPhases::TAG, from a provider's
 * register(); made for every job, so its constructor reads nothing.
 */
interface ImportPhase
{
    /** Lower-case words joined by hyphens, unique among the phases: `users`. */
    public function key(): string;

    /** Where it runs: core's at the hundreds, a part's follow-up step at a ten inside its hundred, a plugin's at a number that isn't a multiple of ten (I-349). */
    public function order(): int;

    /** What people read in the run's progress: "People". */
    public function label(): string;

    /** Whether a run of this kind does this phase. */
    public function runsIn(ImportRunKind $kind): bool;
}
