<?php

/**
 * Throws away a region's unpublished changes: the person's own autosave,
 * and the working revision, which goes back to the published one, or, for
 * a region never published, to the empty one it was made with.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Regions\Operations;

use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Contents\LinkProjection;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentAutosave;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\SearchText;
use App\Domain\Hooks\Payloads;
use App\Domain\Media\MediaProjection;
use App\Domain\Regions\Operations\Input\DiscardRegionChangesInput;
use App\Domain\SiteCode\RegionCode;
use App\Domain\SiteCode\RegionCodeLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Taxonomy\TermProjection;
use App\Domain\Types\CoreTypes;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Content\ContentSaved;
use Teme\Contracts\Operations\OperationInput;

/**
 * Discard changes on Content › Regions. It moves the working pointer back,
 * writing no revision, so the region's state reads as it was; the revisions
 * left behind stay in its history. Nothing public changes, so nothing is
 * purged. A Custom HTML card's code that goes is logged as removed.
 */
final class DiscardRegionChanges extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var DiscardRegionChangesInput $input */
        $user = User::find($input->actorId());
        $content = Content::findOrFail($input->contentId);

        if ($user === null || ! Gate::forUser($user)->allows('update', $content)) {
            throw new AuthorizationException('Not permitted to edit this content.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var DiscardRegionChangesInput $input */
        if (Content::query()->whereKey($input->contentId)->value('type') !== CoreTypes::REGION) {
            throw ValidationException::withMessages(['contentId' => __('Only a region’s changes are discarded here.')]);
        }
    }

    protected function perform(OperationInput $input): Content
    {
        /** @var DiscardRegionChangesInput $input */
        $content = Content::lockForUpdate()->findOrFail($input->contentId);

        if ($content->working_revision_id !== $input->expectedWorkingRevisionId) {
            throw StaleRevisionException::for((string) $input->expectedWorkingRevisionId, $content->working_revision_id);
        }

        ContentAutosave::query()->where('content_id', $content->id)->where('user_id', (int) $input->actorId())->delete();

        // Never published: the first revision, the empty one CreateRegion made. ULIDs sort as they were made.
        $target = $content->published_revision_id ?? ContentRevision::query()->where('content_id', $content->id)->orderBy('id')->value('id');

        if ($target === null || $target === $content->working_revision_id) {
            return $content;
        }

        $before = ContentState::of($content);
        $from = (array) (ContentRevision::query()->whereKey($content->working_revision_id)->value('document') ?? []);
        $to = (array) (ContentRevision::query()->whereKey($target)->value('document') ?? []);

        $content->update(['working_revision_id' => $target]);
        ContentCounts::apply($before, ContentState::of($content));
        RegionCodeLog::saved(RegionCode::changes($from, $to), $input->actorId(), (string) $content->id, (string) $content->slug, (string) $target);

        TermProjection::sync($content);
        MediaProjection::sync($content, $input->actorId());
        LinkProjection::sync($content);
        SearchText::sync($content);

        $content->refresh();
        $revisionId = (string) $target;
        $actorId = $input->actorId();
        $this->notifyAfterCommit(ContentSaved::class, fn (): ContentSaved => new ContentSaved(Payloads::item($content), $revisionId, false, $actorId));

        return $content;
    }
}
