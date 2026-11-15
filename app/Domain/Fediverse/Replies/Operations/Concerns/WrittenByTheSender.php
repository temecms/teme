<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Replies\Operations\Concerns;

use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Fediverse\Replies\Operations\Input\FediverseReplyInput;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A reply is written, changed and taken back only by the remote account
 * itself, verified by its signature, as the inbox's job runs it: never by
 * a person, and never for another account.
 */
trait WrittenByTheSender
{
    /**
     * @throws AuthorizationException
     */
    protected function authorizeSender(FediverseReplyInput $input): RemoteActor
    {
        $remote = RemoteActor::query()->find($input->remoteActorId);

        if ($input->actorId() !== null || $input->sender === null || $remote === null || $remote->uri !== $input->sender->actorUri || $remote->gone_at !== null) {
            throw new AuthorizationException('Only the account that wrote a reply sends it.');
        }

        return $remote;
    }
}
