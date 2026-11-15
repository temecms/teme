<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations\Concerns;

use App\Domain\Support\Security\Operator;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * The fediverse's own authorization: whoever holds settings.fediverse.manage,
 * or the operator for a null actor.
 */
trait AuthorizesFediverseWork
{
    /**
     * @throws AuthorizationException
     */
    protected function authorizeFediverseWork(OperationInput $input, string $refusal): void
    {
        if ($input->actorId() === null) {
            if (Operator::isPresent()) {
                return;
            }

            throw new AuthorizationException($refusal);
        }

        $actor = app(Actors::class)->find($input->actorId());

        if ($actor === null || ! $actor->can('settings.fediverse.manage')) {
            throw new AuthorizationException($refusal);
        }
    }
}
