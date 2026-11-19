<?php

/**
 * After the people, before anything else (I-2998): when this run made a
 * person or first matched one, the posts credited to the fallback owner
 * become work again, so the next full run gives each to its author. Every
 * kind of run, since a catch-up's people need their posts marked for the
 * full run after it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Users;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Operations\Input\MarkAuthorsWaitingInput;
use App\Domain\Import\Operations\MarkAuthorsWaiting;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;

final readonly class AuthorsWaitingStep implements StepPhase
{
    public function key(): string
    {
        return 'authors-waiting';
    }

    public function order(): int
    {
        return 210;
    }

    public function label(): string
    {
        return 'Posts waiting for their author';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        app(MarkAuthorsWaiting::class)->execute(new MarkAuthorsWaitingInput($context->sourceId, $context->runId, $context->actorId()));

        return StepResult::done();
    }
}
