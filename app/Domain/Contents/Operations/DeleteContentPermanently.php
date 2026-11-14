<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\Footprints;
use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Concerns\FollowsTree;
use App\Domain\Contents\Operations\Concerns\PurgesTranslations;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Tree\SubtreeJobKind;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Content\ContentDeleted;
use Teme\Contracts\Operations\OperationInput;

/**
 * Deletes a trashed item and all its revisions. Only items already in the
 * trash can be deleted, by someone who may remove them or by the operator's
 * scheduled purge. The pages under it move up to its own parent (I-3363).
 */
final class DeleteContentPermanently extends BaseOperation
{
    use FollowsTree;
    use PurgesTranslations;

    protected function authorize(OperationInput $input): void
    {
        /** @var ContentActionInput $input */
        if ($input->actorId() === null) {
            if (Operator::isPresent()) {
                return;
            }

            throw new AuthorizationException('Not permitted to delete this content.');
        }

        $user = User::find($input->actorId());
        $content = Content::withTrashed()->findOrFail($input->contentId);

        if ($user === null || ! Gate::forUser($user)->allows('delete', $content)) {
            throw new AuthorizationException('Not permitted to delete this content.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ContentActionInput $input */
        $content = Content::withTrashed()->findOrFail($input->contentId);
        $this->treeType = (string) $content->type;

        self::refuse($content);
    }

    protected function perform(OperationInput $input): null
    {
        /** @var ContentActionInput $input */
        $nests = $this->takeTree();
        $content = Content::withTrashed()->lockForUpdate()->findOrFail($input->contentId);

        // Again under the locks: a restore, or a move of its branch, may have come in between.
        self::refuse($content);

        ContentCounts::apply(ContentState::of($content), null);

        // What a listener is told of it, read before it goes.
        [$id, $type, $title, $actorId] = [(string) $content->id, (string) $content->type, (string) $content->title, $input->actorId()];
        $group = self::groupTags($content);

        // contents and content_revisions point at each other. Clear this
        // row's pointers first, so the cascade that deletes its revisions
        // does not also try to set-null a row this statement is deleting —
        // PostgreSQL refuses that ("tuple to be updated was already modified
        // by an operation triggered by the current command").
        $content->forceFill(['published_revision_id' => null, 'working_revision_id' => null])->saveQuietly();

        // The pages under it take its place, keeping their order; it was in the trash, so no address moves.
        if ($nests) {
            $this->moveChildrenUp($content);
        }

        // Revisions go with it: content_revisions.content_id cascades.
        $content->forceDelete();

        app(PathProjection::class)->forget('content', (string) $content->id);

        // The other versions keep the group; revisions translated from its own forget them (the foreign key, D11).
        if ($group !== []) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, $id, $group));
        }

        $this->notifyAfterCommit(ContentDeleted::class, fn (): ContentDeleted => new ContentDeleted($id, $type, $title, $actorId));

        return null;
    }

    /**
     * Puts the pages under the item under its own parent, and tells the page
     * cache after commit: each moved page's own page, and the new parent's,
     * which now lists them.
     */
    private function moveChildrenUp(Content $content): void
    {
        // Those in the trash move too; they show readers nothing, so they have nothing to set aside.
        $children = Content::query()->where('parent_id', $content->id)->get();
        DB::table('contents')->where('parent_id', $content->id)->update(['parent_id' => $content->parent_id]);

        $before = Footprints::contents($children);
        $after = Footprints::contents($children->each(fn (Content $child): Content => $child->setAttribute('parent_id', $content->parent_id)));

        foreach ($children as $child) {
            $id = (string) $child->id;
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, $id, array_values(array_unique([...$before[$id] ?? [], ...$after[$id] ?? []]))));
        }
    }

    /**
     * Refuses an item not in the trash, or one whose branch is still moving.
     */
    private static function refuse(Content $content): void
    {
        if (! $content->trashed()) {
            throw ValidationException::withMessages(['content' => __('Move it to the trash first.')]);
        }

        // The move finds the pages it hasn't reached by walking down from this one.
        $types = app(ContentTypeRegistry::class);

        if ($types->has((string) $content->type) && $types->get((string) $content->type)->hierarchical && SubtreeJobKind::isMoving((string) $content->id)) {
            throw ValidationException::withMessages(['content' => __("The pages under it are still moving. Delete it once the jobs indicator says they've moved.")]);
        }
    }
}
