<?php

declare(strict_types=1);

namespace App\Domain\Ai\Tasks\AltText\Operations;

use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\Exceptions\ActivityAlreadySettled;
use App\Domain\Ai\Models\AiActivity;
use App\Domain\Ai\Tasks\AltText\Operations\Input\RecordAltTextChangeInput;
use App\Domain\Media\Models\Media;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Keeps an image's alt text from before and after a person used AI's
 * suggestion for it (I-1312), so an administrator can put the old one back:
 * the one exception to the record keeping figures only. Run inside
 * ApplyAiSuggestion, before the request is settled, by the person who asked.
 */
final class RecordAltTextChange extends BaseOperation
{
    /** As long as an image's alt text can be (UpdateMediaDetails). */
    private const int LONGEST = 1000;

    public function __construct(private readonly Actors $actors) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var RecordAltTextChangeInput $input */
        $user = $this->actors->find($input->actorId());
        $row = AiActivity::query()->find($input->activityId);
        $media = Media::withTrashed()->find($input->mediaId);

        if ($user === null || $row === null || $media === null || $row->user_id !== $user->id
            || $row->subject_type !== AiSubjectType::Media || $row->subject_id !== $media->id
            || ! Gate::forUser($user)->allows('update', $media)) {
            throw new AuthorizationException('Only the person who asked keeps the alt text their suggestion changed.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RecordAltTextChangeInput $input */
        if (trim($input->after) === '' || mb_strlen(trim($input->after)) > self::LONGEST
            || ($input->before !== null && mb_strlen($input->before) > self::LONGEST)) {
            throw new InvalidArgumentException('An alt text change keeps new alt text, and each side is at most 1,000 characters.');
        }
    }

    protected function perform(OperationInput $input): null
    {
        /** @var RecordAltTextChangeInput $input */
        // The row's lock first, so a request settled meanwhile keeps no change.
        $row = AiActivity::query()->lockForUpdate()->findOrFail($input->activityId);

        if ($row->outcome !== AiOutcome::Suggested) {
            throw new ActivityAlreadySettled;
        }

        $before = $input->before === null || trim($input->before) === '' ? null : trim($input->before);

        DB::table('ai_alt_text_changes')->insert([
            'activity_id' => $input->activityId,
            'media_id' => $input->mediaId,
            'alt_before' => $before,
            'alt_after' => trim($input->after),
            'created_at' => now(),
        ]);

        return null;
    }
}
