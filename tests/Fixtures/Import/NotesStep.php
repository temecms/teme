<?php

declare(strict_types=1);

namespace Tests\Fixtures\Import;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportStopped;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;

/**
 * A step for the engine's own tests, in full runs only: it reads the old
 * site's settings, notes its title, and finishes in a second job at a
 * cursor; it stops the run when a test's choice says so.
 */
final class NotesStep implements StepPhase
{
    public function key(): string
    {
        return 'fixture-notes';
    }

    public function order(): int
    {
        return 1205;
    }

    public function label(): string
    {
        return 'Notes';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return $kind === ImportRunKind::Full;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        if ($context->choice('stop-step') === true) {
            throw ImportStopped::because('fixture-step-stop', 'Stopped by the fixture step.');
        }

        if ($cursor === null) {
            $title = (string) ($reader->whole('settings')['options']['blogname'] ?? '');

            return StepResult::continueAt('2', [new ImportMessage('site.title', "The old site is called {$title}.")]);
        }

        return StepResult::done([new ImportMessage('site.read', "Read in two jobs, the second at {$cursor}.")]);
    }
}
