<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\PlainText;
use App\Domain\Contents\DraftChecks;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentAutosave;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\SaveAutosaveInput;
use App\Domain\Contents\Queries\EditorPresence;
use App\Domain\Contents\SaveAutosaveResult;
use App\Domain\Fields\FieldValidator;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Teme\Contracts\Operations\OperationInput;

/**
 * Keeps one person's draft of one article while they type (I-181, decision
 * 1A): one row per person per article, updated in place, never a revision.
 * There is no lock (decision 2A). If the article moved on since the draft's
 * base, the save is refused, the person's own row is dropped so their work is
 * never reapplied over someone else's, and they are told. A save one editor
 * session numbered below the one it last kept is older words arriving late:
 * it is answered as saved and writes nothing.
 */
final class SaveAutosave extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var SaveAutosaveInput $input */
        $user = User::find($input->actorId());
        $content = Content::findOrFail($input->contentId);

        if ($user === null || ! Gate::forUser($user)->allows('update', $content)) {
            throw new AuthorizationException('Not permitted to edit this content.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SaveAutosaveInput $input */
        // The person is mid-sentence: a card they have just inserted may not be
        // filled in yet. The checkpoint that makes it a revision checks it all.
        DraftChecks::validate($input->contentId, $input->title, $input->document, $input->fields, $input->terms, allowUnfinishedCards: true);
    }

    protected function perform(OperationInput $input): SaveAutosaveResult
    {
        /** @var SaveAutosaveInput $input */
        $content = Content::lockForUpdate()->findOrFail($input->contentId);
        $actorId = (string) $input->actorId();
        $sequence = $input->editorSession === null || $input->saveNumber === null
            ? null
            : ['editor' => $input->editorSession, 'number' => $input->saveNumber];

        // A save of this editor session's that a newer one has overtaken,
        // such as one sent before an explicit save's answer and delivered
        // after it: its words are already kept, so it writes nothing, and
        // never drops the newer row as stale.
        $kept = ContentAutosave::query()->where('content_id', $content->id)->where('user_id', (int) $actorId)->first();

        if ($sequence !== null && $kept !== null && ($kept->save_sequence['editor'] ?? null) === $sequence['editor'] && ($kept->save_sequence['number'] ?? 0) >= $sequence['number']) {
            return new SaveAutosaveResult($kept->saved_at->toIso8601String(), false, $content->working_revision_id, EditorPresence::othersIn($content->id, $actorId));
        }

        if ($input->basedOnRevisionId !== $content->working_revision_id) {
            ContentAutosave::query()->where('content_id', $content->id)->where('user_id', (int) $actorId)->delete();

            return new SaveAutosaveResult(null, true, $content->working_revision_id, EditorPresence::othersIn($content->id, $actorId));
        }

        // What is kept is what a checkpoint will store: the normalised
        // document and the fields as the field registry returns them.
        $links = [];
        $document = DocumentNormaliser::normalise($input->document, $links);
        $fields = app(FieldValidator::class)->validate($content->type, $input->fields, (array) ContentRevision::query()->whereKey($content->working_revision_id)->value('fields'));
        $savedAt = now();

        // upsert() writes rows as they are, without casts, so the JSON
        // columns are encoded here.
        ContentAutosave::query()->upsert(
            [[
                'id' => (string) Str::ulid(),
                'content_id' => $content->id,
                'user_id' => (int) $actorId,
                'title' => trim($input->title),
                'document' => json_encode($document),
                'fields' => json_encode($fields),
                'terms' => json_encode(array_values(array_unique($input->terms))),
                'based_on_revision_id' => $content->working_revision_id,
                'word_count' => PlainText::words($document),
                'saved_at' => $savedAt,
                'save_sequence' => $sequence === null ? null : json_encode($sequence),
            ]],
            uniqueBy: ['content_id', 'user_id'],
            update: ['title', 'document', 'fields', 'terms', 'based_on_revision_id', 'word_count', 'saved_at', 'save_sequence'],
        );

        return new SaveAutosaveResult($savedAt->toIso8601String(), false, $content->working_revision_id, EditorPresence::othersIn($content->id, $actorId), links: $links);
    }
}
