<?php

/**
 * At a run's end, the counts the import kept item by item checked once and
 * fixed where they drifted (I-141). Search text, links and media uses are
 * written in each item's own transaction, so nothing else is rebuilt; the
 * run's one purge is its own.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Finish;

use App\Domain\Contents\Operations\Input\RebuildImportedCountsInput;
use App\Domain\Contents\Operations\RebuildImportedCounts;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;

final readonly class RecountStep implements StepPhase
{
    public function key(): string
    {
        return 'recount';
    }

    public function order(): int
    {
        return 1210;
    }

    public function label(): string
    {
        return 'Counts';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        app(RebuildImportedCounts::class)->execute(new RebuildImportedCountsInput($context->actorId()));

        return StepResult::done();
    }
}
