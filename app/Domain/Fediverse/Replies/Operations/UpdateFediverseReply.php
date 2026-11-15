<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Replies\Operations;

use App\Domain\Comments\CommentPurges;
use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\Models\Comment;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Replies\Operations\Concerns\WrittenByTheSender;
use App\Domain\Fediverse\Replies\Operations\Input\FediverseReplyInput;
use App\Domain\Fediverse\Replies\RemoteWords;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Changes a fediverse reply's words when its writer edits it: the comment
 * keeps its place and its status, and its words become the edited reply's
 * as the restricted comment document. Only the account that wrote it may.
 * Returns the comment's ID.
 */
final class UpdateFediverseReply extends BaseOperation
{
    use WrittenByTheSender;

    protected function authorize(OperationInput $input): void
    {
        /** @var FediverseReplyInput $input */
        $remote = $this->authorizeSender($input);
        $object = FediverseObject::query()->whereKey($input->uri())->where('kind', FediverseObject::REPLY)->first();

        if ($object !== null && $object->remote_actor_id !== $remote->id) {
            throw new AuthorizationException('Only the account that wrote a reply changes it.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var FediverseReplyInput $input */
        if (! FediverseObject::query()->whereKey($input->uri())->where('kind', FediverseObject::REPLY)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['object' => 'No reply of this site’s is at that address.']);
        }

        if (app(RemoteWords::class)->document(is_string($input->object['content'] ?? null) ? $input->object['content'] : '') === null) {
            throw ValidationException::withMessages(['object' => 'The reply holds no words, or too many.']);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var FediverseReplyInput $input */
        $object = FediverseObject::query()->whereKey($input->uri())->lockForUpdate()->firstOrFail();
        $comment = Comment::query()->lockForUpdate()->findOrFail($object->comment_id);
        $document = app(RemoteWords::class)->document((string) $input->object['content']) ?? throw ValidationException::withMessages(['object' => 'The reply holds no words.']);

        if ($comment->body === null) {
            return $comment->id;
        }

        $comment->forceFill(['body' => $document])->save();

        if ($comment->status === CommentStatus::APPROVED) {
            $this->purgeAfterCommit(CommentPurges::changed($comment->content_id, $comment->id));
        }

        return $comment->id;
    }
}
