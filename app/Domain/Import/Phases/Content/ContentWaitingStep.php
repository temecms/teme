<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Content;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Operations\Input\MarkContentWaitingInput;
use App\Domain\Import\Operations\MarkContentWaiting;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;

/**
 * Before posts and pages, after the media (I-338): when this run brought
 * media, the posts and pages whose media was missing become work again, so
 * the content phase converts them with it in the same run. Full runs only,
 * where the content list sends every item.
 */
final readonly class ContentWaitingStep implements StepPhase
{
    public function key(): string
    {
        return 'content-waiting';
    }

    public function order(): int
    {
        return 490;
    }

    public function label(): string
    {
        return 'Posts waiting for their media';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return $kind === ImportRunKind::Full;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        app(MarkContentWaiting::class)->execute(new MarkContentWaitingInput($context->sourceId, $context->runId, $context->actorId()));

        return StepResult::done();
    }
}
