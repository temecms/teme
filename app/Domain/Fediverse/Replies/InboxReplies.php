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
            app($operation)->execute(new FediverseReplyInput($remote->id, $object, $sender));
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
