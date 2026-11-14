<?php

declare(strict_types=1);

namespace App\Domain\Ai\Operations;

use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\Exceptions\ActivityAlreadySettled;
use App\Domain\Ai\Models\AiActivity;
use App\Domain\Ai\Operations\Input\SettleAiActivityInput;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Settles a suggestion (I-317): used, with the revision it made, or none for
 * a media item; discarded; or found out of date when the person tried to use
 * it. Only the person who asked settles it, and only once.
 */
final class SettleAiActivity extends BaseOperation
{
    private const array SETTLED = [AiOutcome::Applied, AiOutcome::Discarded, AiOutcome::Stale];

    protected function authorize(OperationInput $input): void
    {
        /** @var SettleAiActivityInput $input */
        // Who asked never changes, so this read needs no lock.
        $askedBy = AiActivity::query()->whereKey($input->activityId)->value('user_id');

        if ($input->actorId() === null || $askedBy === null || (string) $askedBy !== $input->actorId()) {
            throw new AuthorizationException('Only the person who asked settles a suggestion.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SettleAiActivityInput $input */
        if (! in_array($input->outcome, self::SETTLED, true)) {
            throw new InvalidArgumentException('A suggestion is settled as used, discarded or out of date.');
        }

        if ($input->revisionId !== null && ($input->outcome !== AiOutcome::Applied || ! Str::isUlid($input->revisionId))) {
            throw new InvalidArgumentException('Only a used suggestion names the revision it made.');
        }
    }

    protected function perform(OperationInput $input): null
    {
        /** @var SettleAiActivityInput $input */
        // The lock comes before the outcome is read, so two settlings can't both pass.
        $row = AiActivity::query()->lockForUpdate()->findOrFail($input->activityId);

        if ($row->outcome !== AiOutcome::Suggested) {
            throw new ActivityAlreadySettled;
        }

        if ($input->revisionId !== null) {
            $ofThisItem = $row->subject_type === AiSubjectType::Content
                && ContentRevision::query()->whereKey($input->revisionId)->where('content_id', $row->subject_id)->exists();

            if (! $ofThisItem) {
                throw new InvalidArgumentException("The revision [{$input->revisionId}] isn't one of this item's.");
            }
        }

        $row->update(['outcome' => $input->outcome, 'revision_id' => $input->revisionId]);

        return null;
    }
}
