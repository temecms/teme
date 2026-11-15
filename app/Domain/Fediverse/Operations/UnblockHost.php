<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations;

use App\Domain\Fediverse\Models\BlockedHost;
use App\Domain\Fediverse\Operations\Concerns\AuthorizesFediverseWork;
use App\Domain\Fediverse\Operations\Input\UnblockHostInput;
use App\Domain\Support\Operations\BaseOperation;
use Teme\Contracts\Operations\OperationInput;

/**
 * Takes a server off the blocked list. For whoever holds
 * settings.fediverse.manage, or the operator; returns whether it was there.
 */
final class UnblockHost extends BaseOperation
{
    use AuthorizesFediverseWork;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeFediverseWork($input, 'Not permitted to unblock servers.');
    }

    protected function validate(OperationInput $input): void
    {
        //
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var UnblockHostInput $input */
        return BlockedHost::query()->whereKey(BlockHost::normalized($input->host))->delete() > 0;
    }
}
