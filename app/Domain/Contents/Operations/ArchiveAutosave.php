<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\PlainText;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentAutosave;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\ArchiveAutosaveInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Teme\Contracts\Operations\OperationInput;

/**
 * Keeps an autosave nobody has touched for `editor.autosave_ttl_days` as a
 * revision in its article's history, dated when the work was done, and only
 * then removes the row (I-424). It is someone's work, so it is never simply
 * deleted. It does not move the working revision: this is history, not the
 * article's current state.
 */
final class ArchiveAutosave extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        if (! Operator::isPresent()) {
            throw new AuthorizationException('Only the operator archives abandoned autosaves.');
        }
    }

    protected function validate(OperationInput $input): void {}

    /**
     * The new revision's ID, or null when the autosave has gone or its owner
     * came back to it since it was listed.
     */
    protected function perform(OperationInput $input): ?string
    {
        /** @var ArchiveAutosaveInput $input */
        $contentId = ContentAutosave::query()->whereKey($input->autosaveId)->value('content_id');

        if ($contentId === null) {
            return null;
        }

        // Lock before any state check: the article, then the autosave.
        $content = Content::lockForUpdate()->find($contentId);
        $autosave = ContentAutosave::query()->lockForUpdate()->find($input->autosaveId);
        $cutoff = Date::now()->subDays((int) config('editor.autosave_ttl_days', 30));

        if ($content === null || $autosave === null || $autosave->saved_at->greaterThanOrEqualTo($cutoff)) {
            return null;
        }

        $document = DocumentNormaliser::normalise($autosave->document);

        $revision = ContentRevision::query()->create([
            'content_id' => $content->id,
            'document' => $document,
            'plain_text' => PlainText::of($document),
            'word_count' => PlainText::words($document),
            'fields' => $autosave->fields,
            'terms' => $autosave->terms,
            'title' => trim((string) $autosave->title),
            'author_id' => $autosave->user_id,
            'origin' => 'human',
            'kind' => 'checkpoint',
            // Still translated from what the working revision was (I-3360).
            'source_revision_id' => ContentRevision::query()->whereKey($content->working_revision_id)->value('source_revision_id'),
            // It sits in the history where the work was done.
            'created_at' => $autosave->saved_at,
        ]);

        $autosave->delete();

        return (string) $revision->id;
    }
}
