<?php

/**
 * After the comments phase, every item's comment counts, conversations and
 * last comment rebuilt once from the rows (I-273), never per comment.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Comments;

use App\Domain\Comments\Operations\Input\RebuildCommentStatsInput;
use App\Domain\Comments\Operations\RebuildCommentStats;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;

final readonly class CommentCountsStep implements StepPhase
{
    public function key(): string
    {
        return 'comment-counts';
    }

    public function order(): int
    {
        return 780;
    }

    public function label(): string
    {
        return 'Comment counts';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        app(RebuildCommentStats::class)->execute(new RebuildCommentStatsInput($context->actorId()));

        return StepResult::done();
    }
}
