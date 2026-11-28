<?php

/**
 * Changes a fediverse reply's words when its writer edits it: the comment
 * keeps its place, and its words become the edited reply's as the
 * restricted comment document. A shown reply goes through the bound
 * moderator again and is held when it doesn't pass or its links changed;
 * edits count toward the account's replies an hour. Only the account that
 * wrote it may. Returns the comment's ID.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Replies\Operations;

use App\Domain\Addresses\Addresses;
use App\Domain\Comments\Checks\CommentChecks;
use App\Domain\Comments\CommentEmails;
use App\Domain\Comments\CommentPurges;
use App\Domain\Comments\CommentStats;
use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\CommentText;
use App\Domain\Comments\Models\Comment;
use App\Domain\Contents\Models\Content;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Fediverse\Replies\Operations\Concerns\WrittenByTheSender;
use App\Domain\Fediverse\Replies\Operations\Input\FediverseReplyInput;
use App\Domain\Fediverse\Replies\RemoteWords;
use App\Domain\Hooks\Payloads;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Comments\CommentCandidate;
use Teme\Contracts\Comments\CommentModerator;
use Teme\Contracts\Comments\CommentStatusChanged;
use Teme\Contracts\Comments\ModerationVerdict;
use Teme\Contracts\Operations\OperationInput;

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
        self::admit($input, record: true);
    }

    /**
     * The checks an edit must pass before it is saved, and nothing is written:
     * the reply it changes, that its writer made it, its words and the hourly
     * limit. With $record the limit counts this edit; without it the limit is
     * only read, so a plugin is asked only about an edit that will be judged.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public static function admit(FediverseReplyInput $input, bool $record): void
    {
        $object = FediverseObject::query()->whereKey($input->uri())->where('kind', FediverseObject::REPLY)->whereNull('deleted_at')->first();

        if ($object === null) {
            throw ValidationException::withMessages(['object' => 'No reply of this site’s is at that address.']);
        }

        if ($object->remote_actor_id !== $input->remoteActorId) {
            throw new AuthorizationException('Only the account that wrote a reply changes it.');
        }

        if (app(RemoteWords::class)->document(is_string($input->object['content'] ?? null) ? $input->object['content'] : '') === null) {
            throw ValidationException::withMessages(['object' => 'The reply holds no words, or too many.']);
        }

        ReceiveFediverseReply::count(RemoteActor::query()->findOrFail($input->remoteActorId), $record);
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

        $links = CommentText::links($comment->body);
        $comment->forceFill(['body' => $document])->save();

        if ($comment->status !== CommentStatus::APPROVED) {
            return $comment->id;
        }

        $verdict = $this->judge($comment, RemoteActor::query()->findOrFail($input->remoteActorId), $input->checked);

        if ($verdict->status === ModerationVerdict::APPROVED && CommentText::links($document) === $links) {
            $this->purgeAfterCommit(CommentPurges::changed($comment->content_id, $comment->id));

            return $comment->id;
        }

        // Changed into something the moderator won't pass, or with new links: held again.
        $comment->forceFill([
            'status' => CommentStatus::PENDING,
            'status_changed_at' => now()->format('Y-m-d H:i:s.uP'),
            'moderation' => ['by' => app(CommentModerator::class)->name(), 'status' => CommentStatus::PENDING, 'reason' => $verdict->reason, 'signals' => (object) [...$verdict->signals, 'edited' => true]],
        ])->save();
        CommentStats::apply($comment, CommentStatus::APPROVED, CommentStatus::PENDING);
        CommentEmails::waiting($comment, null);
        $this->purgeAfterCommit(CommentPurges::left($comment->content_id, $comment->id));

        $id = $comment->id;
        $item = Content::withTrashed()->findOrFail($comment->content_id);
        $this->notifyAfterCommit(CommentStatusChanged::class, fn (): CommentStatusChanged => new CommentStatusChanged($id, Payloads::item($item), CommentStatus::APPROVED, CommentStatus::PENDING, null));

        return $comment->id;
    }

    /**
     * The comment an edit would leave, as a plugin's check reads it: built with no writes, so
     * InboxReplies can ask the check before the transaction. $document is the edit's new words, as
     * RemoteWords reads them; the stored body is used when none is given.
     *
     * @param  array<string, mixed>|null  $document
     */
    public static function candidate(Comment $comment, RemoteActor $remote, ?array $document = null): CommentCandidate
    {
        $item = Content::withTrashed()->findOrFail($comment->content_id);
        $body = $document ?? $comment->body;

        return new CommentCandidate(
            contentId: $comment->content_id,
            parentId: $comment->parent_id,
            text: CommentText::plain($body),
            links: CommentText::links($body),
            authorName: $comment->author_name,
            authorEmail: null,
            authorUrl: $comment->author_url,
            authorUserId: null,
            ip: null,
            userAgent: null,
            returning: ReceiveFediverseReply::returning($remote),
            itemUrl: Addresses::urlFor($item),
        );
    }

    /**
     * The bound moderator's verdict on the edited words, with a plugin's check, asked before the transaction, merged strictest first.
     */
    private function judge(Comment $comment, RemoteActor $remote, ?ModerationVerdict $checked): ModerationVerdict
    {
        $moderator = app(CommentModerator::class);

        $verdict = ReceiveFediverseReply::known($moderator->name(), $moderator->judge(self::candidate($comment, $remote)));

        return CommentChecks::strictest($checked, $verdict) ?? $verdict;
    }
}
