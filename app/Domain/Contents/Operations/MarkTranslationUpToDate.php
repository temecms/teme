<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\MarkTranslationUpToDateInput;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Marks a translation up to date with its source (I-3360, D9): a new
 * working revision, a copy of the current one, recording the source's
 * published revision (or its working one, for an unpublished source the
 * person may edit) as the one it was translated from. Revisions are never
 * edited in place, and nothing public changes.
 */
final class MarkTranslationUpToDate extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var MarkTranslationUpToDateInput $input */
        $actor = User::query()->find($input->actorId());
        $version = Content::query()->findOrFail($input->contentId);

        if ($actor === null || ! Gate::forUser($actor)->allows('update', $version)) {
            throw new AuthorizationException('Not permitted to edit this content.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var MarkTranslationUpToDateInput $input */
        self::source(Content::query()->findOrFail($input->contentId), (string) $input->actorId());
    }

    protected function perform(OperationInput $input): Content
    {
        /** @var MarkTranslationUpToDateInput $input */
        $version = Content::query()->lockForUpdate()->findOrFail($input->contentId);

        if ($version->working_revision_id !== $input->expectedRevisionId) {
            throw StaleRevisionException::for($input->expectedRevisionId, $version->working_revision_id);
        }

        $copy = ContentRevision::query()->findOrFail($version->working_revision_id)->replicate(['id', 'created_at']);
        $copy->source_revision_id = self::source($version, (string) $input->actorId());
        $copy->author_id = (int) $input->actorId();
        $copy->kind = 'checkpoint';
        $copy->restored_from_id = null;
        $copy->save();

        $version->update(['working_revision_id' => $copy->id]);

        return $version->refresh();
    }

    /**
     * The source's revision the version is now up to date with.
     *
     * @throws ValidationException when the version has no live source
     * @throws AuthorizationException when only an unpublished draft the person can't open would do
     */
    private static function source(Content $version, string $actorId): string
    {
        $from = ContentRevision::query()->whereKey($version->working_revision_id)->value('source_revision_id');
        $sourceId = $from === null ? null : ContentRevision::query()->whereKey($from)->value('content_id');
        $source = $sourceId === null ? null : Content::query()->find($sourceId);

        if ($source === null) {
            throw ValidationException::withMessages(['source' => __('The version it was translated from is gone.')]);
        }

        if ($source->published_revision_id !== null) {
            return (string) $source->published_revision_id;
        }

        $actor = User::query()->find($actorId);

        if ($actor === null || ! Gate::forUser($actor)->allows('update', $source)) {
            $languages = app(SiteLanguages::class);

            throw new AuthorizationException(__('The :language version isn\'t published yet. Ask someone who can edit it, or wait until it is.', ['language' => $languages->label($languages->of($source->language))]));
        }

        return (string) $source->working_revision_id;
    }
}
