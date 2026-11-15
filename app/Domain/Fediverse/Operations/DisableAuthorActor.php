<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations;

use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Operations\Concerns\AuthorizesFediverseWork;
use App\Domain\Fediverse\Operations\Input\AuthorActorInput;
use App\Domain\Support\Operations\BaseOperation;
use Teme\Contracts\Operations\OperationInput;

/**
 * Stops people following one author: their account no longer answers, and
 * its keys and followers are kept for when it is turned on again. For
 * whoever holds settings.fediverse.manage; returns whether it was on.
 */
final class DisableAuthorActor extends BaseOperation
{
    use AuthorizesFediverseWork;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeFediverseWork($input, 'Not permitted to change the fediverse accounts.');
    }

    protected function validate(OperationInput $input): void
    {
        //
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var AuthorActorInput $input */
        return FediverseActor::query()->where('person_id', $input->personId)->where('enabled', true)->update(['enabled' => false, 'updated_at' => now()]) > 0;
    }
}
