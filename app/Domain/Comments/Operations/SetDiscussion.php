<?php

declare(strict_types=1);

namespace App\Domain\Comments\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Comments\Discussion;
use App\Domain\Comments\Operations\Input\SetDiscussionInput;
use App\Domain\Contents\Models\Content;
use App\Domain\Settings\Modules;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

/**
 * Sets whether an item takes comments, Open, Closed or Hidden, for whoever
 * may publish it (I-571): from the editor's Discussion row, Quick edit and
 * Bulk edit. Live at once, outside any revision, and never an edit, so when
 * the item was last updated stays as it was.
 */
final class SetDiscussion extends BaseOperation
{
    public function __construct(private readonly ContentTypeRegistry $types) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var SetDiscussionInput $input */
        $user = app(Actors::class)->find($input->actorId());
        $content = Content::query()->findOrFail($input->contentId);

        // Whoever may publish the item (I-571): a contributor's change would
        // reach readers past review, since nothing here is reviewed.
        if ($user === null || ! Gate::forUser($user)->allows('publish', $content)) {
            throw new AuthorizationException('Not permitted to set this item’s discussion.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SetDiscussionInput $input */
        if (! Modules::on(Modules::COMMENTS)) {
            throw ValidationException::withMessages(['discussion' => __('Comments are switched off.')]);
        }

        $type = (string) Content::query()->findOrFail($input->contentId)->type;

        if (! $this->types->has($type) || $this->types->get($type)->comments === null) {
            throw ValidationException::withMessages(['discussion' => __(':items take no comments.', [
                'items' => $this->types->has($type) ? $this->types->get($type)->plural : __('Items of this type'),
            ])]);
        }

        if (Discussion::tryFrom($input->discussion) === null) {
            throw ValidationException::withMessages(['discussion' => __('Choose Open, Closed or Hidden.')]);
        }
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var SetDiscussionInput $input */
        $content = Content::query()->lockForUpdate()->findOrFail($input->contentId);
        $wanted = Discussion::from($input->discussion);

        if ($content->comment_status === $wanted) {
            return false;
        }

        // The query builder, not save() or the Eloquent builder, which would
        // both set updated_at: a discussion change isn't an edit.
        DB::table('contents')->where('id', $content->id)->update(['comment_status' => $wanted->value]);

        // The item's page and its later comment pages draw the thread and the
        // form, and carry its content tag, so they are removed, never served
        // stale. A draft shows readers nothing yet.
        if ($content->status === 'published') {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, (string) $content->id, [PageTags::content((string) $content->id)]));
        }

        return true;
    }
}
