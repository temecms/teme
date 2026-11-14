<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\Operations\Input\ReviewDecisionInput;
use App\Domain\Contents\ReviewDecisions;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Returns a pending review to its writer with a note saying why, which the
 * writer sees in the editor until they submit again. A draft in review goes
 * back to being a draft; a live article stays published.
 */
final class ReturnForChanges extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var ReviewDecisionInput $input */
        ReviewDecisions::authorize($input);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ReviewDecisionInput $input */
        if (trim((string) $input->note) === '') {
            throw ValidationException::withMessages(['note' => __('Say what needs to change.')]);
        }

        ReviewDecisions::ensurePending($input->reviewId);
    }

    protected function perform(OperationInput $input): null
    {
        /** @var ReviewDecisionInput $input */
        [$content, $review] = ReviewDecisions::lock($input->reviewId);

        $review->update([
            'decision' => 'returned',
            'decided_by' => (int) $input->actorId(),
            'decided_at' => Date::now(),
            'note' => trim((string) $input->note),
        ]);

        if ($content->status === 'review') {
            $before = ContentState::of($content);
            $content->update(['status' => 'draft']);
            ContentCounts::apply($before, ContentState::of($content));
        }

        // Later (I-184): the submitter is told by email as well. For now the
        // admin is the only channel, because a notification system that only
        // reviews use is the wrong shape to build first.

        return null;
    }
}
