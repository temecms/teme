<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations;

use App\Domain\Fediverse\ActivityPub;
use App\Domain\Fediverse\Models\Activity;
use App\Domain\Fediverse\Models\Delivery;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\Follower;
use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Fediverse\Operations\Input\AcceptFollowInput;
use App\Domain\Fediverse\Publishing\Deliveries;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Accepts a remote account's Follow of one of the site's enabled actors:
 * keeps it as a follower, or updates the Follow it came by, and queues an
 * Accept to its inbox. Run from the inbox's job for the verified follower
 * itself; returns the Accept activity.
 */
final class AcceptFollow extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var AcceptFollowInput $input */
        $remote = RemoteActor::query()->find($input->remoteActorId);

        if ($input->actorId() !== null || $input->sender === null || $remote === null || $remote->uri !== $input->sender->actorUri) {
            throw new AuthorizationException('Only the follower itself follows.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var AcceptFollowInput $input */
        if (($input->follow['type'] ?? null) !== 'Follow' || ! is_string($input->follow['id'] ?? null)) {
            throw ValidationException::withMessages(['follow' => 'Not a Follow.']);
        }

        if (! FediverseActor::query()->whereKey($input->localActorId)->where('enabled', true)->exists()) {
            throw ValidationException::withMessages(['actor' => 'The site has no such account.']);
        }
    }

    protected function perform(OperationInput $input): Activity
    {
        /** @var AcceptFollowInput $input */
        $local = FediverseActor::query()->whereKey($input->localActorId)->lockForUpdate()->firstOrFail();
        $remote = RemoteActor::query()->whereKey($input->remoteActorId)->firstOrFail();
        $followId = (string) $input->follow['id'];

        Follower::query()->upsert(
            [['local_actor_id' => $local->id, 'remote_actor_id' => $remote->id, 'follow_uri' => $followId, 'since' => now()]],
            ['local_actor_id', 'remote_actor_id'],
            ['follow_uri'],
        );

        $accept = Activity::query()->create([
            'local_actor_id' => $local->id,
            'type' => 'Accept',
            'object_uri' => $followId,
            'payload' => [
                '@context' => ActivityPub::CONTEXT,
                'id' => $local->uri.'#accepts/'.Str::ulid()->toBase32(),
                'type' => 'Accept',
                'actor' => $local->uri,
                'object' => ['id' => $followId, 'type' => 'Follow', 'actor' => $remote->uri, 'object' => $local->uri],
            ],
        ]);

        Delivery::query()->create([
            'activity_id' => $accept->id,
            'inbox_url' => $remote->inbox_url,
            'host' => $remote->host,
            'state' => 'queued',
            'next_attempt_at' => now(),
        ]);

        return $accept;
    }

    /**
     * Sends the Accept once the follow is stored.
     */
    protected function afterCommit(mixed $result): void
    {
        /** @var Activity $result */
        app(Deliveries::class)->dispatch(Delivery::query()->where('activity_id', $result->id)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all());
    }
}
