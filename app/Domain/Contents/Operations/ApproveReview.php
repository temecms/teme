<?php

/**
 * Approves a pending review, which publishes the revision that was submitted
 * — never the one typed since, and never anyone's autosave — now, or at a
 * chosen time (I-437). Whoever may publish the article decides.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\ReviewDecisionInput;
use App\Domain\Contents\Operations\Input\SchedulePublicationInput;
use App\Domain\Contents\ReviewDecisions;
use App\Domain\Contents\Tree\SubtreeAddresses;
use App\Domain\Support\Operations\BaseOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Throwable;

final class ApproveReview extends BaseOperation
{
    /** The article's type, read as it is authorized, so perform() takes its tree first. */
    private ?string $type = null;

    protected function authorize(OperationInput $input): void
    {
        /** @var ReviewDecisionInput $input */
        $this->type = (string) ReviewDecisions::authorize($input)->type;
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ReviewDecisionInput $input */
        ReviewDecisions::ensurePending($input->reviewId);

        // The same rule SchedulePublication applies, checked before anything
        // is decided, so a time in the past approves nothing.
        if ($input->runAt !== null && ! self::inFuture($input->runAt)) {
            throw ValidationException::withMessages(['runAt' => __('Choose a time in the future.')]);
        }
    }

    /**
     * When the approved revision was submitted, and whether the draft has
     * moved on since, so the admin can say so plainly.
     *
     * @return array{submittedAt: string, newerChanges: bool, scheduledFor: string|null}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ReviewDecisionInput $input */
        // The tree before any row, as the publish will take it (I-3363).
        app(SubtreeAddresses::class)->lock($this->type);
        [$content, $review] = ReviewDecisions::lock($input->reviewId);

        // Recorded first, so PublishRevision finds no pending review to close.
        $review->update([
            'decision' => 'approved',
            'decided_by' => (int) $input->actorId(),
            'decided_at' => Date::now(),
        ]);

        if ($input->runAt === null) {
            app(PublishRevision::class)->execute(new PublishRevisionInput(
                $content->id, (string) $review->revision_id, $input->actorId(), clearAutosave: false,
            ));
        } else {
            // Approve and schedule (I-437): the submitted revision, scheduled by
            // the reviewer through the same path a schedule always takes.
            app(SchedulePublication::class)->execute(new SchedulePublicationInput(
                $content->id, (string) $review->revision_id, 'publish', $input->runAt, $input->actorId(),
            ));
        }

        return [
            'submittedAt' => $review->submitted_at->toIso8601String(),
            'newerChanges' => $content->working_revision_id !== $review->revision_id,
            'scheduledFor' => $input->runAt,
        ];
    }

    private static function inFuture(string $value): bool
    {
        try {
            return CarbonImmutable::parse($value)->isAfter(Date::now());
        } catch (Throwable) {
            return false;
        }
    }
}
