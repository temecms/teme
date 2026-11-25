<?php

/**
 * Gathers one person's autosave into a revision — title, document, fields
 * and terms together — through SaveDraft's own path, then clears the
 * autosave in the same transaction. This is how the history stays readable:
 * a revision per checkpoint, not per keystroke. With no autosave it writes
 * nothing. An autosave whose base is no longer the working revision is
 * refused by SaveDraft, and nothing is written. A draft address travels in
 * the same transaction after the revision, checked before any write.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentAutosave;
use App\Domain\Contents\Operations\Input\CheckpointDraftInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Slugs;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Teme\Contracts\Operations\OperationInput;

final class CheckpointDraft extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var CheckpointDraftInput $input */
        $user = User::find($input->actorId());
        $content = Content::findOrFail($input->contentId);

        if ($user === null || ! Gate::forUser($user)->allows('update', $content)) {
            throw new AuthorizationException('Not permitted to edit this content.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var CheckpointDraftInput $input */
        if ($input->slug === null) {
            return;
        }

        $content = Content::query()->findOrFail($input->contentId);
        Slugs::assertUsable((string) $content->type, $input->slug, (string) $content->id, $content->language);
    }

    /**
     * The ID of the revision the checkpoint wrote, or of the working revision
     * when there was nothing to gather.
     */
    protected function perform(OperationInput $input): ?string
    {
        /** @var CheckpointDraftInput $input */
        $content = Content::lockForUpdate()->findOrFail($input->contentId);
        $autosave = ContentAutosave::query()->where('content_id', $content->id)
            ->where('user_id', (int) $input->actorId())->first();

        $revisionId = $content->working_revision_id;

        if ($autosave !== null) {
            $saved = app(SaveDraft::class)->execute(new SaveDraftInput(
                $content->id,
                $autosave->title,
                $autosave->document,
                $autosave->fields,
                $autosave->based_on_revision_id,
                $input->actorId(),
                $autosave->terms,
                kind: 'checkpoint',
            ));

            $autosave->delete();
            $revisionId = $saved->working_revision_id;
        }

        if ($input->slug !== null) {
            app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
                contentId: $input->contentId,
                expectedRevisionId: (string) $revisionId,
                actorId: $input->actorId(),
                slug: $input->slug,
            ));
        }

        return $revisionId;
    }
}
