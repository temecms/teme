<?php

/**
 * Reverts a published item to a draft, so the public site stops showing it.
 * Anyone who may publish the item may unpublish it. Its revisions and its
 * published date are kept.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

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
use App\Domain\Contents\Models\ScheduledPublication;
use App\Domain\Contents\Operations\Concerns\FollowsTree;
use App\Domain\Contents\Operations\Concerns\PurgesTranslations;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\SearchText;
use App\Domain\Hooks\Payloads;
use App\Domain\Media\MediaProjection;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Taxonomy\TermProjection;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Content\ContentUnpublished;
use Teme\Contracts\Operations\OperationInput;

final class UnpublishContent extends BaseOperation
{
    use FollowsTree;
    use PurgesTranslations;

    protected function authorize(OperationInput $input): void
    {
        /** @var ContentActionInput $input */
        $user = app(Actors::class)->find($input->actorId());
        $content = Content::findOrFail($input->contentId);
        $this->treeType = (string) $content->type;

        if ($user === null || ! Gate::forUser($user)->allows('publish', $content)) {
            throw new AuthorizationException('Not permitted to unpublish this content.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ContentActionInput $input */
        if (Content::findOrFail($input->contentId)->status !== 'published') {
            throw ValidationException::withMessages(['content' => __('Only a published item can be reverted to a draft.')]);
        }

        // The home page and the posts page stay until another is chosen (#36).
        SitePages::refuseTakingOff($input->contentId);
    }

    protected function perform(OperationInput $input): null
    {
        /** @var ContentActionInput $input */
        $nests = $this->takeTree();
        $content = Content::lockForUpdate()->findOrFail($input->contentId);
        $this->noteChain($content, $nests);
        $before = ContentState::of($content);
        // What the public saw of it, read under the lock (I-263).
        $shown = Footprints::content($content);
        $formerUrl = $this->listening(ContentUnpublished::class) ? Addresses::urlFor($content) : null;

        // A scheduled update of a live article would publish it again.
        ScheduledPublication::cancelPendingFor($content->id, 'unpublished');

        $content->update(['status' => 'draft', 'scheduled_for' => null]);

        ContentCounts::apply($before, ContentState::of($content));
        TermProjection::sync($content);
        MediaProjection::sync($content);
        LinkProjection::sync($content);
        SearchText::sync($content);
        app(PathProjection::class)->syncContent($content);
        $this->followTree($content, $input->actorId());

        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, (string) $content->id, [...$shown, ...Footprints::content($content), ...self::groupTags($content)]));

        $actorId = $input->actorId();
        $this->notifyAfterCommit(ContentUnpublished::class, fn (): ContentUnpublished => new ContentUnpublished(Payloads::item($content), $formerUrl, $actorId));

        return null;
    }
}
