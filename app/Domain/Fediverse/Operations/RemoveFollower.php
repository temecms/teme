<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations;

use App\Domain\Fediverse\Models\Follower;
use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Fediverse\Operations\Input\RemoveFollowerInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Stops a remote account following one of the site's actors, or all of
 * them, and marks it gone when it deleted itself. Run from the inbox's job
 * for the verified account itself, or by whoever holds
 * settings.fediverse.manage; returns how many follows ended.
 */
final class RemoveFollower extends BaseOperation
{
    public const string ABILITY = 'settings.fediverse.manage';

    protected function authorize(OperationInput $input): void
    {
        /** @var RemoveFollowerInput $input */
        if ($input->actorId() === null) {
            if ($input->sender !== null && $input->sender->actorUri === $input->remoteActorUri) {
                return;
            }

            throw new AuthorizationException('Not permitted to remove followers.');
        }

        $actor = app(Actors::class)->find($input->actorId());

        if ($actor === null || ! $actor->can(self::ABILITY)) {
            throw new AuthorizationException('Not permitted to remove followers.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        //
    }

    protected function perform(OperationInput $input): int
    {
        /** @var RemoveFollowerInput $input */
        $remote = RemoteActor::query()->where('uri', $input->remoteActorUri)->lockForUpdate()->first();

        if ($remote === null) {
            return 0;
        }

        $removed = Follower::query()
            ->where('remote_actor_id', $remote->id)
            ->when($input->localActorId !== null, fn ($query) => $query->where('local_actor_id', $input->localActorId))
            ->delete();

        if ($input->gone) {
            $remote->update(['gone_at' => now()]);
        }

        return (int) $removed;
    }
}
