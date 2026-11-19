<?php

/**
 * Removes the remote accounts the site no longer needs: unseen for 30
 * days, following none of its actors and the author of no reply. For
 * whoever holds settings.fediverse.manage, or the operator; returns how
 * many it removed.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations;

use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Models\Follower;
use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Fediverse\Operations\Concerns\AuthorizesFediverseWork;
use App\Domain\Fediverse\Operations\Input\PruneFediverseInput;
use App\Domain\Support\Operations\BaseOperation;
use Teme\Contracts\Operations\OperationInput;

final class PruneFediverse extends BaseOperation
{
    use AuthorizesFediverseWork;

    /** How long an account goes unseen before it may be pruned. */
    public const int UNSEEN_DAYS = 30;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeFediverseWork($input, 'Not permitted to prune fediverse accounts.');
    }

    protected function validate(OperationInput $input): void
    {
        //
    }

    protected function perform(OperationInput $input): int
    {
        /** @var PruneFediverseInput $input */
        return RemoteActor::query()
            ->where('fetched_at', '<', now()->subDays(self::UNSEEN_DAYS))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from((new Follower)->getTable())->whereColumn('remote_actor_id', 'fediverse_remote_actors.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from((new FediverseObject)->getTable())->whereColumn('remote_actor_id', 'fediverse_remote_actors.id')->where('kind', FediverseObject::REPLY))
            ->delete();
    }
}
