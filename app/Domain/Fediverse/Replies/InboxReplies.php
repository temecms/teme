<?php

/**
 * Routes a verified Create, Update or Delete whose object is a reply to
 * one of the site's posts, or to a reply on one, to the reply Operations
 * (docs/plans/fediverse-publishing.md, D4). A reply the comment rules
 * refuse is dropped and logged; anything else is not a reply of the
 * site's, and is left to the plugins.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Replies;

use App\Domain\Comments\Checks\CommentChecks;
use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\Models\Comment;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Fediverse\Operations\Input\RecordRemoteActorInput;
use App\Domain\Fediverse\Operations\RecordRemoteActor;
use App\Domain\Fediverse\Remote\FetchedKeyOwners;
use App\Domain\Fediverse\Remote\FetchRefused;
use App\Domain\Fediverse\Remote\RemoteFetcher;
use App\Domain\Fediverse\Replies\Operations\Input\FediverseReplyInput;
use App\Domain\Fediverse\Replies\Operations\ReceiveFediverseReply;
use App\Domain\Fediverse\Replies\Operations\RemoveFediverseReply;
use App\Domain\Fediverse\Replies\Operations\UpdateFediverseReply;
use App\Domain\Fediverse\Signatures\VerifiedSender;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Comments\ModerationVerdict;

final readonly class InboxReplies
{
    public function __construct(private RemoteFetcher $fetcher) {}

    /**
     * Whether the activity was a reply of the site's, taken or dropped.
     *
     * @param  array<string, mixed>  $activity
     */
    public function handle(array $activity, VerifiedSender $sender): bool
    {
        $type = $activity['type'] ?? null;
        $object = $activity['object'] ?? null;

        if ($type === 'Create' && is_array($object) && is_string($object['inReplyTo'] ?? null) && ReplyTarget::of($object['inReplyTo']) !== null) {
            return $this->run(ReceiveFediverseReply::class, $object, $sender);
        }

        $uri = is_array($object) ? ($object['id'] ?? null) : $object;

        if (! in_array($type, ['Update', 'Delete'], true) || ! is_string($uri) || ! FediverseObject::query()->whereKey($uri)->where('kind', FediverseObject::REPLY)->exists()) {
            return false;
        }

        return $type === 'Update' && is_array($object)
            ? $this->run(UpdateFediverseReply::class, $object, $sender)
            : $this->run(RemoveFediverseReply::class, ['id' => $uri], $sender);
    }

    /**
     * Runs one reply Operation for the sender, logging a reply it refuses.
     *
     * @param  class-string<ReceiveFediverseReply|UpdateFediverseReply|RemoveFediverseReply>  $operation
     * @param  array<string, mixed>  $object
     */
    private function run(string $operation, array $object, VerifiedSender $sender): bool
    {
        $remote = $this->remote($sender);

        if ($remote === null) {
            Log::info('A fediverse reply was dropped: its writer could not be read.', ['actor' => $sender->actorUri]);

            return true;
        }

        try {
            app($operation)->execute(new FediverseReplyInput($remote->id, $object, $sender, checked: $this->checked($operation, $object, $remote)));
        } catch (ValidationException|AuthorizationException $refused) {
            Log::info('A fediverse reply was dropped.', [
                'actor' => $sender->actorUri,
                'reply' => is_string($object['id'] ?? null) ? $object['id'] : null,
                'why' => $refused instanceof ValidationException ? implode(' ', $refused->validator->errors()->all()) : $refused->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * A plugin's comment check on the reply, asked here, before the operation's transaction (docs/guides/developers/plugins/comment-check.md).
     * Nothing for a removal, or a reply the operation would refuse before it judges.
     *
     * @param  class-string  $operation
     * @param  array<string, mixed>  $object
     */
    private function checked(string $operation, array $object, RemoteActor $remote): ?ModerationVerdict
    {
        // Nothing is built, parsed or asked for a check when no plugin fills the comment-check slot.
        if (! app(CommentChecks::class)->wanted()) {
            return null;
        }

        $input = new FediverseReplyInput($remote->id, $object);

        if ($operation === ReceiveFediverseReply::class) {
            // The cheap refusals first, read-only: a reply refused for any of them is never asked about.
            try {
                ReceiveFediverseReply::admit($input, record: false);
            } catch (ValidationException) {
                return null;
            }

            $candidate = ReceiveFediverseReply::candidate($remote, $object);

            return $candidate === null ? null : app(CommentChecks::class)->verdict($candidate);
        }

        if ($operation === UpdateFediverseReply::class) {
            $commentId = is_string($object['id'] ?? null) ? FediverseObject::query()->whereKey($object['id'])->value('comment_id') : null;
            $comment = is_string($commentId) ? Comment::query()->find($commentId) : null;

            // Only an approved comment with words is judged on an edit (UpdateFediverseReply::perform); any other is held without a check.
            if ($comment === null || $comment->status !== CommentStatus::APPROVED || $comment->body === null) {
                return null;
            }

            try {
                UpdateFediverseReply::admit($input, record: false);
            } catch (ValidationException|AuthorizationException) {
                return null;
            }

            $document = is_string($object['content'] ?? null) ? app(RemoteWords::class)->document($object['content']) : null;

            // The edit's new words, not the stored ones: the check judges what would be saved.
            return $document === null ? null : app(CommentChecks::class)->verdict(UpdateFediverseReply::candidate($comment, $remote, $document));
        }

        return null;
    }

    /**
     * The sender as an account the site keeps: known already, or recorded
     * from the document read while its signature was checked, or fetched now.
     */
    private function remote(VerifiedSender $sender): ?RemoteActor
    {
        $known = RemoteActor::query()->where('uri', $sender->actorUri)->first();

        if ($known !== null) {
            return $known->gone_at === null ? $known : null;
        }

        $document = FetchedKeyOwners::cachedDocument($sender->actorUri);

        try {
            $document ??= $this->fetcher->get($sender->actorUri);

            return app(RecordRemoteActor::class)->execute(new RecordRemoteActorInput($document, $sender));
        } catch (FetchRefused|ValidationException) {
            return null;
        }
    }
}
