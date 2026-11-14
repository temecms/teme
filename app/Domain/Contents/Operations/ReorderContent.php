<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\ReorderContentInput;
use App\Domain\Contents\Tree\TreeLock;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

/**
 * Moves an item one step up or down among its siblings (I-3363): the
 * items of its type under the same parent, or at the top, not in the trash.
 * The whole list is numbered 0, 1, 2 again in one statement; no address
 * moves and no revision is written, since an order is not an edit.
 */
final class ReorderContent extends BaseOperation
{
    /** The most siblings renumbered a step at a time. */
    public const int MOST = 1000;

    protected function authorize(OperationInput $input): void
    {
        /** @var ReorderContentInput $input */
        $user = app(Actors::class)->find($input->actorId());
        $content = Content::withTrashed()->findOrFail($input->contentId);

        // It renumbers its siblings, which are other people's pages too.
        if ($user === null || ! $user->can(app(ContentTypeRegistry::class)->ability((string) $content->type, 'edit.any'))) {
            throw new AuthorizationException('Not permitted to reorder this content.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ReorderContentInput $input */
        $content = Content::withTrashed()->findOrFail($input->contentId);
        $type = app(ContentTypeRegistry::class)->get((string) $content->type);

        if (! $type->hierarchical) {
            throw ValidationException::withMessages(['parent' => __(':plural cannot have a parent.', ['plural' => $type->plural])]);
        }

        if ($content->trashed()) {
            throw ValidationException::withMessages(['content' => __('Restore it from the trash first.')]);
        }

        if (! in_array($input->direction, ['up', 'down'], true)) {
            throw ValidationException::withMessages(['direction' => __('Move it up or down.')]);
        }
    }

    protected function perform(OperationInput $input): null
    {
        /** @var ReorderContentInput $input */
        $type = (string) Content::withTrashed()->whereKey($input->contentId)->value('type');
        TreeLock::take($type);

        $content = Content::query()->lockForUpdate()->findOrFail($input->contentId);
        $parentId = $content->parent_id;

        $siblings = Content::query()
            ->where('type', $type)
            ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parentId))
            ->orderBy('position')
            ->orderBy('id')
            ->limit(self::MOST + 1)
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        if (count($siblings) > self::MOST) {
            throw ValidationException::withMessages(['direction' => __('There are too many pages here to move one step at a time. Set its Order in Quick edit instead.')]);
        }

        $at = (int) array_search((string) $content->id, $siblings, true);

        if ($input->direction === 'up' && $at === 0) {
            throw ValidationException::withMessages(['direction' => __('It is already first.')]);
        }

        if ($input->direction === 'down' && $at === count($siblings) - 1) {
            throw ValidationException::withMessages(['direction' => __('It is already last.')]);
        }

        $with = $input->direction === 'up' ? $at - 1 : $at + 1;
        [$siblings[$at], $siblings[$with]] = [$siblings[$with], $siblings[$at]];

        // Rows already in their place are left alone, and updated_at stays: an order is not an edit.
        DB::update(<<<'SQL'
            UPDATE contents SET position = v.ord - 1
            FROM unnest(?::char(26)[]) WITH ORDINALITY AS v(id, ord)
            WHERE contents.id = v.id AND contents.position <> v.ord - 1
            SQL, ['{'.implode(',', $siblings).'}']);

        if ($parentId !== null) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, $parentId, [PageTags::childrenOf($parentId)]));
        }

        return null;
    }
}
