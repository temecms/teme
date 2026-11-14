<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Content;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Operations\Input\MarkVisibilityWaitingInput;
use App\Domain\Import\Operations\MarkVisibilityWaiting;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;

/**
 * Before posts and pages, after the media: the posts and pages an import
 * made before Teme kept items private or protected become work again, once,
 * so the content phase brings them as WordPress had them (I-2643). Full
 * runs only, where the content list sends every item.
 */
final readonly class ContentVisibilityStep implements StepPhase
{
    public function key(): string
    {
        return 'content-visibility';
    }

    public function order(): int
    {
        return 480;
    }

    public function label(): string
    {
        return 'Private and protected posts brought again';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return $kind === ImportRunKind::Full;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        app(MarkVisibilityWaiting::class)->execute(new MarkVisibilityWaitingInput($context->sourceId, $context->actorId()));

        return StepResult::done();
    }
}
