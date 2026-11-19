<?php

/**
 * Brings an older revision back by writing a new one from it, through
 * SaveDraft's own path, so history only ever grows: the older revision is
 * read, never changed or removed. It publishes nothing.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentAutosave;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\RestoreRevisionInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Themes\TemplateChoice;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class RestoreRevision extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var RestoreRevisionInput $input */
        $user = User::find($input->actorId());
        $content = Content::findOrFail($input->contentId);

        if ($user === null || ! Gate::forUser($user)->allows('update', $content)) {
            throw new AuthorizationException('Not permitted to edit this content.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RestoreRevisionInput $input */
        $ownsRevision = ContentRevision::query()
            ->where('content_id', $input->contentId)
            ->whereKey($input->revisionId)
            ->exists();

        if (! $ownsRevision) {
            throw ValidationException::withMessages([
                'revisionId' => __('That revision belongs to another article.'),
            ]);
        }
    }

    protected function perform(OperationInput $input): Content
    {
        /** @var RestoreRevisionInput $input */
        $content = Content::lockForUpdate()->findOrFail($input->contentId);
        $revision = ContentRevision::query()->where('content_id', $content->id)->findOrFail($input->revisionId);

        // SaveDraft refuses a term it has not seen on the working revision
        // when it no longer exists, so the ones deleted since are left out.
        $wanted = array_map('strval', $revision->terms);
        $existing = $wanted === [] ? [] : DB::table('terms')->whereIn('id', $wanted)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
        $terms = array_values(array_intersect($wanted, $existing));

        // Nor would it take back a template the current theme no longer
        // offers; the working revision's stands in for it (I-443).
        $working = (array) ContentRevision::query()->whereKey($content->working_revision_id)->value('fields');
        $fields = TemplateChoice::kept($content->type, (array) $revision->fields, $working);

        $restored = app(SaveDraft::class)->execute(new SaveDraftInput(
            $content->id,
            (string) $revision->title,
            (array) $revision->document,
            $fields,
            $content->working_revision_id,
            $input->actorId(),
            $terms,
            kind: 'restore',
            restoredFromId: $revision->id,
        ));

        // The actor's own unsaved work was based on the revision this replaces;
        // left in place, it would come back over the restore.
        ContentAutosave::query()->where('content_id', $content->id)
            ->where('user_id', (int) $input->actorId())
            ->delete();

        return $restored;
    }
}
