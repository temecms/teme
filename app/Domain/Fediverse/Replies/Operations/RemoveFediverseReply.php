<?php

/**
 * Takes a fediverse reply off the site when its writer deletes it: the
 * comment moves to the trash, where moderators decide as for any other,
 * and the reply's address is marked deleted. Only the account that wrote
 * it may. Returns the comment's ID.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Replies\Operations;

use App\Domain\Comments\CommentPurges;
use App\Domain\Comments\CommentStats;
use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\Models\Comment;
use App\Domain\Contents\Models\Content;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Replies\Operations\Concerns\WrittenByTheSender;
use App\Domain\Fediverse\Replies\Operations\Input\FediverseReplyInput;
use App\Domain\Hooks\Payloads;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Comments\CommentStatusChanged;
use Teme\Contracts\Operations\OperationInput;

final class RemoveFediverseReply extends BaseOperation
{
    use WrittenByTheSender;

    protected function authorize(OperationInput $input): void
    {
        /** @var FediverseReplyInput $input */
        $remote = $this->authorizeSender($input);
        $object = FediverseObject::query()->whereKey($input->uri())->where('kind', FediverseObject::REPLY)->first();

        if ($object !== null && $object->remote_actor_id !== $remote->id) {
            throw new AuthorizationException('Only the account that wrote a reply takes it back.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var FediverseReplyInput $input */
        if (! FediverseObject::query()->whereKey($input->uri())->where('kind', FediverseObject::REPLY)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['object' => 'No reply of this site’s is at that address.']);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var FediverseReplyInput $input */
        $object = FediverseObject::query()->whereKey($input->uri())->lockForUpdate()->firstOrFail();
        $comment = Comment::query()->lockForUpdate()->findOrFail($object->comment_id);
        $before = (string) $comment->status;

        FediverseObject::query()->whereKey($object->uri)->update(['deleted_at' => now()]);

        if (in_array($before, [CommentStatus::TRASH, CommentStatus::REMOVED], true)) {
            return $comment->id;
        }

        $comment->forceFill([
            'status' => CommentStatus::TRASH,
            'previous_status' => $before,
            'status_changed_at' => now()->format('Y-m-d H:i:s.uP'),
        ])->save();

        CommentStats::apply($comment, $before, CommentStatus::TRASH);

        if ($before === CommentStatus::APPROVED) {
            $this->purgeAfterCommit(CommentPurges::left($comment->content_id, $comment->id));
        }

        [$commentId, $contentId] = [(string) $comment->id, (string) $comment->content_id];
        $this->notifyAfterCommit(CommentStatusChanged::class, fn (): CommentStatusChanged => new CommentStatusChanged($commentId, Payloads::item(Content::withTrashed()->findOrFail($contentId)), $before, CommentStatus::TRASH, null));

        return $comment->id;
    }
}
