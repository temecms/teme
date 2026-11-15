<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Jobs;

use App\Domain\Fediverse\FediverseSwitch;
use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Fediverse\Operations\AcceptFollow;
use App\Domain\Fediverse\Operations\Input\AcceptFollowInput;
use App\Domain\Fediverse\Operations\Input\RecordRemoteActorInput;
use App\Domain\Fediverse\Operations\Input\RemoveFollowerInput;
use App\Domain\Fediverse\Operations\RecordRemoteActor;
use App\Domain\Fediverse\Operations\RemoveFollower;
use App\Domain\Fediverse\Queries\FediverseDocuments;
use App\Domain\Fediverse\Remote\FetchedKeyOwners;
use App\Domain\Fediverse\Remote\FetchRefused;
use App\Domain\Fediverse\Remote\RemoteFetcher;
use App\Domain\Fediverse\Signatures\VerifiedSender;
use App\Domain\Fediverse\Sockets\InboxActivities;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;

/**
 * Acts on one verified activity from an inbox (docs/plans/fediverse-actors.md,
 * D8): a Follow of one of the site's actors is accepted, an Undo of a
 * Follow ends it, and an account's Delete of itself marks it gone and ends
 * its follows. Every change goes through an Operation, for the verified
 * sender. Anything else is left to the publishing part.
 */
final class ProcessInboxActivity implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $activity
     */
    public function __construct(
        public readonly array $activity,
        public readonly string $keyId,
        public readonly string $actorUri,
    ) {}

    public function handle(FediverseDocuments $documents, RemoteFetcher $fetcher): void
    {
        // Paused since it was queued: nothing is taken (proposal, Settled 4).
        if (! FediverseSwitch::on()) {
            return;
        }

        $sender = new VerifiedSender($this->keyId, $this->actorUri);
        app(InboxActivities::class)->received($this->activity, $sender);

        match ($this->activity['type'] ?? null) {
            'Follow' => $this->follow($sender, $documents, $fetcher),
            'Undo' => $this->undo($sender),
            'Delete' => $this->delete($sender),
            default => app(InboxActivities::class)->other($this->activity, $sender),
        };
    }

    private function follow(VerifiedSender $sender, FediverseDocuments $documents, RemoteFetcher $fetcher): void
    {
        $local = $documents->actorAt(self::id($this->activity['object'] ?? null) ?? '');

        if ($local === null) {
            return;
        }

        $remote = $this->remote($sender, $fetcher);

        if ($remote === null) {
            return;
        }

        app(AcceptFollow::class)->execute(new AcceptFollowInput($local->id, $remote->id, $this->activity, $sender));
    }

    private function undo(VerifiedSender $sender): void
    {
        $object = $this->activity['object'] ?? null;

        if (! is_array($object) || ($object['type'] ?? null) !== 'Follow') {
            return;
        }

        $local = app(FediverseDocuments::class)->actorAt(self::id($object['object'] ?? null) ?? '');

        app(RemoveFollower::class)->execute(new RemoveFollowerInput($sender->actorUri, $local?->id, sender: $sender));
    }

    private function delete(VerifiedSender $sender): void
    {
        // An account's Delete of itself ends its follows; any other is a reply's.
        if (self::id($this->activity['object'] ?? null) !== $sender->actorUri) {
            app(InboxActivities::class)->other($this->activity, $sender);

            return;
        }

        app(RemoveFollower::class)->execute(new RemoveFollowerInput($sender->actorUri, gone: true, sender: $sender));
    }

    /**
     * The sender as an account Teme keeps, recorded from its document: the
     * one fetched while its signature was checked, or fetched now.
     */
    private function remote(VerifiedSender $sender, RemoteFetcher $fetcher): ?RemoteActor
    {
        $document = FetchedKeyOwners::cachedDocument($sender->actorUri);

        if ($document === null) {
            $known = RemoteActor::query()->where('uri', $sender->actorUri)->first();

            if ($known !== null && $known->gone_at === null) {
                return $known;
            }

            try {
                $document = $fetcher->get($sender->actorUri);
            } catch (FetchRefused) {
                return null;
            }
        }

        try {
            return app(RecordRemoteActor::class)->execute(new RecordRemoteActorInput($document, $sender));
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * An object's address, whether it came as a string or as an object.
     */
    private static function id(mixed $object): ?string
    {
        $id = is_array($object) ? ($object['id'] ?? null) : $object;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
