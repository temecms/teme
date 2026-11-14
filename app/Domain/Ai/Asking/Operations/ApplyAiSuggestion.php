<?php

declare(strict_types=1);

namespace App\Domain\Ai\Asking\Operations;

use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\Asking\AiSuggestions;
use App\Domain\Ai\Asking\AiTargets;
use App\Domain\Ai\Asking\AppliedSuggestion;
use App\Domain\Ai\Asking\Operations\Input\ApplyAiSuggestionInput;
use App\Domain\Ai\Asking\Suggestion;
use App\Domain\Ai\Asking\TextBlocks;
use App\Domain\Ai\Exceptions\ActivityAlreadySettled;
use App\Domain\Ai\Models\AiActivity;
use App\Domain\Ai\Operations\Input\SettleAiActivityInput;
use App\Domain\Ai\Operations\SettleAiActivity;
use App\Domain\Ai\Tasks\AltText\Operations\Input\RecordAltTextChangeInput;
use App\Domain\Ai\Tasks\AltText\Operations\RecordAltTextChange;
use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Uses a suggestion the person chose (I-317). Refused when its target
 * changed since the asking; otherwise written through the same path as
 * every edit: a text block as a new working revision marked as the
 * assistant's and attributed to the person, an image's alt text through
 * UpdateMediaDetails. Then the activity row is settled. Only the person who
 * asked may use it, while they may still change what it is about.
 */
final class ApplyAiSuggestion extends BaseOperation
{
    public const string BLOCK_CHANGED = 'This paragraph changed after you asked. Ask again.';

    public const string ALT_CHANGED = 'This image’s alt text changed after you asked. Ask again.';

    public const string EXPIRED = 'This suggestion has expired. Ask again.';

    public const string FIELD_CHANGED = 'This field changed after you asked. Ask again.';

    public const string FIELDS_CHANGED = 'Some of these fields changed after you asked. Use the others one at a time, or ask again.';

    public function __construct(
        private readonly Actors $actors,
        private readonly AiSuggestions $suggestions,
        private readonly SettleAiActivity $settle,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var ApplyAiSuggestionInput $input */
        $user = $this->actors->find($input->actorId());
        $row = AiActivity::query()->find($input->id);
        $subject = null;

        if ($row !== null) {
            $subject = $row->subject_type === AiSubjectType::Content
                ? Content::query()->find($row->subject_id)
                : Media::withTrashed()->find($row->subject_id);
        }

        // ai.use and the subject's own right, again, as when asking (I-321).
        if ($user === null || $row === null || $subject === null || $row->user_id !== $user->id
            || ! $user->can('ai.use') || ! Gate::forUser($user)->allows('update', $subject)) {
            throw new AuthorizationException('Only the person who asked uses a suggestion, while they may change what it is about.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ApplyAiSuggestionInput $input */
        if ($input->path !== null && preg_match(TextBlocks::PATH, $input->path) !== 1) {
            throw new InvalidArgumentException('AI can be asked about a paragraph or a heading, by its path.');
        }

        if (($input->path === null) !== ($input->block === null)) {
            throw new InvalidArgumentException('This change isn\'t the suggestion in the place it was asked about.');
        }
    }

    protected function perform(OperationInput $input): AppliedSuggestion
    {
        /** @var ApplyAiSuggestionInput $input */
        // The row's lock comes first, so a second Use waits, then finds it settled.
        $row = AiActivity::query()->lockForUpdate()->findOrFail($input->id);

        // A suggestion of several fields is used a field or more at a time (I-1313).
        if ($input->fields !== null) {
            return $this->useFields($input, $row, $input->fields);
        }

        if ($row->outcome !== AiOutcome::Suggested) {
            throw new ActivityAlreadySettled;
        }

        $suggestion = $this->suggestions->find($input->id);

        if ($suggestion === null || $suggestion->state !== Suggestion::READY) {
            return $this->outOfDate($input, self::EXPIRED);
        }

        if ($suggestion->subject->type === AiSubjectType::Media) {
            $applied = $this->useAltText($input, $suggestion);
        } elseif ($suggestion->subject->target !== null) {
            $applied = $this->useInBlock($input, $suggestion);
        } else {
            throw new InvalidArgumentException('This suggestion has no target Teme can use.');
        }

        // Settled either way, so forgotten, after commit (rule 6).
        DB::afterCommit(fn () => $this->suggestions->forget($input->id));

        return $applied;
    }

    /**
     * A text block: refused when the block the editor names no longer holds
     * the text asked about; written when the editor's block is exactly the
     * suggestion in exactly that place.
     */
    private function useInBlock(ApplyAiSuggestionInput $input, Suggestion $suggestion): AppliedSuggestion
    {
        $content = Content::query()->lockForUpdate()->findOrFail($suggestion->subject->id);

        // Saved elsewhere since the editor's last save: the editor's own stale dialog.
        if ($content->working_revision_id !== $input->expectedRevisionId) {
            throw StaleRevisionException::for((string) $input->expectedRevisionId, $content->working_revision_id);
        }

        $revision = ContentRevision::query()->findOrFail($content->working_revision_id);
        $document = (array) $revision->document;
        $current = $input->path === null ? null : TextBlocks::at($document, $input->path);

        if ($current === null || ! hash_equals((string) $suggestion->hash, TextBlocks::hash($current))) {
            return $this->outOfDate($input, self::BLOCK_CHANGED);
        }

        $from = $suggestion->subject->options['from'] ?? null;
        $to = $suggestion->subject->options['to'] ?? null;
        $expected = is_int($from) && is_int($to)
            ? TextBlocks::splice(TextBlocks::text($current), $from, $to, $suggestion->values['text'] ?? '')
            : null;

        if ($input->block === null || $expected === null || ! TextBlocks::sameKind($current, $input->block) || TextBlocks::text($input->block) !== $expected) {
            throw new InvalidArgumentException('This change isn\'t the suggestion in the place it was asked about.');
        }

        /** @var Content $saved */
        $saved = app(SaveDraft::class)->execute(new SaveDraftInput(
            $content->id,
            (string) $revision->title,
            TextBlocks::replace($document, (string) $input->path, $input->block),
            (array) $revision->fields,
            $content->working_revision_id,
            $input->actorId(),
            $revision->terms,
            kind: 'ai',
        ));

        $this->settle->execute(new SettleAiActivityInput($input->id, AiOutcome::Applied, $saved->working_revision_id, $input->actorId()));

        return new AppliedSuggestion(true, $saved->working_revision_id);
    }

    /**
     * Some of a suggestion's fields (I-1313): refused, writing and settling
     * nothing, when one of them changed since the revision asked about;
     * otherwise written as a new working revision marked as the assistant's.
     * The first Use settles the request with that revision; the fields not
     * yet used stay usable while the suggestion lasts.
     *
     * @param  list<string>  $handles
     */
    private function useFields(ApplyAiSuggestionInput $input, AiActivity $row, array $handles): AppliedSuggestion
    {
        $suggestion = $this->suggestions->find($input->id);

        // Expired: the first Use settles it out of date; a later one finds it settled already.
        if ($suggestion === null || $suggestion->state !== Suggestion::READY) {
            return $row->outcome === AiOutcome::Suggested ? $this->outOfDate($input, self::EXPIRED) : throw new ActivityAlreadySettled;
        }

        if ($row->outcome !== AiOutcome::Suggested && $row->outcome !== AiOutcome::Applied) {
            throw new ActivityAlreadySettled;
        }

        if ($suggestion->subject->type !== AiSubjectType::Content || $suggestion->subject->target !== null) {
            throw new InvalidArgumentException('This suggestion has no fields to use.');
        }

        if ($handles === [] || count(array_unique($handles)) !== count($handles)) {
            throw new InvalidArgumentException('A field suggestion is used by naming each of its fields once.');
        }

        foreach ($handles as $handle) {
            // Used already, or never proposed.
            if (! array_key_exists($handle, $suggestion->values)) {
                throw new ActivityAlreadySettled;
            }
        }

        $content = Content::query()->lockForUpdate()->findOrFail($suggestion->subject->id);

        // Saved elsewhere since the editor's last save: the editor's own stale dialog.
        if ($content->working_revision_id !== $input->expectedRevisionId) {
            throw StaleRevisionException::for((string) $input->expectedRevisionId, $content->working_revision_id);
        }

        $working = ContentRevision::query()->findOrFail($content->working_revision_id);
        $asked = ContentRevision::query()->where('content_id', $content->id)->findOrFail((string) $suggestion->subject->revisionId);
        $current = (array) $working->fields;
        $fields = $current;
        $keys = [];

        foreach (app(FieldRegistry::class)->forType($content->type) as $field) {
            $keys[$field->handle] = $field->key;
        }

        foreach ($handles as $handle) {
            $key = $keys[$handle] ?? throw new InvalidArgumentException("This item has no field [{$handle}].");

            // Changed since the asking: nothing is written or settled, so the other fields stay usable.
            if (AiTargets::fieldText($current, $key) !== AiTargets::fieldText((array) $asked->fields, $key)) {
                return new AppliedSuggestion(false, null, count($handles) === 1 ? self::FIELD_CHANGED : self::FIELDS_CHANGED);
            }

            $fields[$key] = $suggestion->values[$handle];
        }

        /** @var Content $saved */
        $saved = app(SaveDraft::class)->execute(new SaveDraftInput(
            $content->id,
            (string) $working->title,
            (array) $working->document,
            $fields,
            $content->working_revision_id,
            $input->actorId(),
            $working->terms,
            kind: 'ai',
        ));

        if ($row->outcome === AiOutcome::Suggested) {
            $this->settle->execute(new SettleAiActivityInput($input->id, AiOutcome::Applied, $saved->working_revision_id, $input->actorId()));
        }

        $left = array_diff_key($suggestion->values, array_flip($handles));

        // After commit: a Use that rolled back leaves the suggestion as it was.
        DB::afterCommit(function () use ($input, $suggestion, $left): void {
            if ($left === []) {
                $this->suggestions->forget($input->id);
            } else {
                $this->suggestions->ready($input->id, $left, $suggestion->hash, $suggestion->sentTo);
            }
        });

        return new AppliedSuggestion(true, $saved->working_revision_id);
    }

    /**
     * An image's alt text, through its details' own Operation, the rest of
     * them as they stand.
     */
    private function useAltText(ApplyAiSuggestionInput $input, Suggestion $suggestion): AppliedSuggestion
    {
        $media = Media::withTrashed()->lockForUpdate()->findOrFail($suggestion->subject->id);

        if (! hash_equals((string) $suggestion->hash, AiTargets::altHash($media->alt))) {
            return $this->outOfDate($input, self::ALT_CHANGED);
        }

        app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput(
            $media->id,
            $input->actorId(),
            $media->title,
            $suggestion->values['text'] ?? null,
            $media->caption,
            $media->credit,
            $media->focal_x,
            $media->focal_y,
        ));

        // The alt text from before and after, kept for an administrator (I-1312).
        app(RecordAltTextChange::class)->execute(new RecordAltTextChangeInput($input->id, $media->id, $media->alt, $suggestion->values['text'] ?? '', $input->actorId()));

        $this->settle->execute(new SettleAiActivityInput($input->id, AiOutcome::Applied, null, $input->actorId()));

        return new AppliedSuggestion(true);
    }

    /**
     * Settled as out of date, and committed: the person reads why.
     */
    private function outOfDate(ApplyAiSuggestionInput $input, string $sentence): AppliedSuggestion
    {
        $this->settle->execute(new SettleAiActivityInput($input->id, AiOutcome::Stale, null, $input->actorId()));

        return new AppliedSuggestion(false, null, $sentence);
    }
}
