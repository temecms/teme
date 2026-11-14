<?php

declare(strict_types=1);

namespace App\Domain\Ai\Asking\Operations;

use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\Asking\AiSuggestions;
use App\Domain\Ai\Asking\Operations\Input\DiscardSuggestionInput;
use App\Domain\Ai\Asking\Suggestion;
use App\Domain\Ai\Models\AiActivity;
use App\Domain\Ai\Operations\Input\SettleAiActivityInput;
use App\Domain\Ai\Operations\SettleAiActivity;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Cancels a request or puts a suggestion aside (I-317): one still working is
 * marked cancelled, so its job asks no model, or discards the answer; a
 * ready one is settled as discarded; a failed one is forgotten. Only the
 * person who asked may.
 */
final class DiscardSuggestion extends BaseOperation
{
    public function __construct(
        private readonly AiSuggestions $suggestions,
        private readonly SettleAiActivity $settle,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var DiscardSuggestionInput $input */
        $askedBy = $this->suggestions->find($input->id)->userId
            ?? AiActivity::query()->whereKey($input->id)->value('user_id');

        if ($input->actorId() === null || $askedBy === null || (string) $askedBy !== $input->actorId()) {
            throw new AuthorizationException('Only the person who asked puts a suggestion aside.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var DiscardSuggestionInput $input */
        if (preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $input->id) !== 1) {
            throw new InvalidArgumentException('A suggestion\'s request id is a lower-case ULID.');
        }
    }

    protected function perform(OperationInput $input): null
    {
        /** @var DiscardSuggestionInput $input */
        if ($this->suggestions->find($input->id)?->state === Suggestion::WORKING) {
            // The job looks for this before it asks a model, and once it has an answer.
            $this->suggestions->cancel($input->id);

            return null;
        }

        if (AiActivity::query()->whereKey($input->id)->value('outcome') === AiOutcome::Suggested) {
            $this->settle->execute(new SettleAiActivityInput($input->id, AiOutcome::Discarded, null, $input->actorId()));
        }

        // After commit (rule 6): a put-aside that rolled back leaves the suggestion waiting.
        DB::afterCommit(fn () => $this->suggestions->forget($input->id));

        return null;
    }
}
