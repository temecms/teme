<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\PathProjection;
use App\Domain\Addresses\SitePages;
use App\Domain\Cache\Footprints;
use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\LinkProjection;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentAutosave;
use App\Domain\Contents\Models\ContentReview;
use App\Domain\Contents\Models\ScheduledPublication;
use App\Domain\Contents\Operations\Concerns\FollowsTree;
use App\Domain\Contents\Operations\Concerns\PurgesTranslations;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\SearchText;
use App\Domain\Hooks\Payloads;
use App\Domain\Media\MediaProjection;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Taxonomy\TermProjection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Content\ContentTrashed;
use Teme\Contracts\Operations\OperationInput;

/**
 * Moves an item to the trash. Its status is kept, so restoring returns it
 * exactly as it was, and its slug is freed for new content (I-142). Trashed
 * items are deleted for good after the retention period.
 */
final class TrashContent extends BaseOperation
{
    use FollowsTree;
    use PurgesTranslations;

    protected function authorize(OperationInput $input): void
    {
        /** @var ContentActionInput $input */
        $user = User::find($input->actorId());
        $content = Content::withTrashed()->findOrFail($input->contentId);

        $this->treeType = (string) $content->type;

        if ($user === null || ! Gate::forUser($user)->allows('delete', $content)) {
            throw new AuthorizationException('Not permitted to move this content to the trash.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ContentActionInput $input */
        // The home page and the posts page stay until another is chosen (#36).
        SitePages::refuseTakingOff($input->contentId);
    }

    protected function perform(OperationInput $input): null
    {
        /** @var ContentActionInput $input */
        $nests = $this->takeTree();
        $content = Content::withTrashed()->lockForUpdate()->findOrFail($input->contentId);
        $this->noteChain($content, $nests);

        if ($content->trashed()) {
            return null;
        }

        $before = ContentState::of($content);
        // What the public saw of it, read under the lock (I-263).
        $shown = Footprints::content($content);
        $formerUrl = $this->listening(ContentTrashed::class) ? Addresses::urlFor($content) : null;

        // Trash keeps the status, so a restored item must not claim a
        // schedule or a review it no longer has: both end here, and a
        // scheduled item or one in review is a draft again.
        ScheduledPublication::cancelPendingFor($content->id, 'trashed');
        ContentReview::closePendingFor($content->id, 'withdrawn', $input->actorId());

        $waiting = in_array($content->status, ['scheduled', 'review'], true);

        if ($waiting || $content->scheduled_for !== null) {
            $content->update([
                'scheduled_for' => null,
                'status' => $waiting ? 'draft' : $content->status,
            ]);
        }

        $content->delete();

        // Unsaved work in a trashed article has nowhere to go.
        ContentAutosave::query()->where('content_id', $content->id)->delete();

        ContentCounts::apply($before, ContentState::of($content));
        TermProjection::sync($content);
        MediaProjection::sync($content);
        LinkProjection::sync($content);
        SearchText::sync($content);
        app(PathProjection::class)->syncContent($content);
        $this->followTree($content, $input->actorId());

        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, (string) $content->id, [...$shown, ...Footprints::content($content), ...self::groupTags($content)]));

        $actorId = $input->actorId();
        $this->notifyAfterCommit(ContentTrashed::class, fn (): ContentTrashed => new ContentTrashed(Payloads::item($content), $formerUrl, $actorId));

        return null;
    }
}
