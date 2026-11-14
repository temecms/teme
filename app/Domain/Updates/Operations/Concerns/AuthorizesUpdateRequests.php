<?php

declare(strict_types=1);

namespace App\Domain\Updates\Operations\Concerns;

use App\Domain\Support\Security\Operator;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Who may ask for an update or cancel one: a person who holds updates.manage, or the operator at the server's shell.
 */
trait AuthorizesUpdateRequests
{
    protected function authorize(OperationInput $input): void
    {
        $actorId = $input->actorId();
        $allowed = $actorId === null
            ? Operator::isPresent()
            : app(Actors::class)->find($actorId)?->can('updates.manage') === true;

        if (! $allowed) {
            throw new AuthorizationException('Only an administrator updates Teme.');
        }
    }
}
