<?php

declare(strict_types=1);

namespace App\Domain\Media\Operations;

use App\Domain\Media\Models\MediaMove;
use App\Domain\Media\Operations\Input\StartMediaMoveInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Records that a media move has started (I-3456): one running row, or a
 * stopped or killed run of the same kind and direction set running again to
 * be resumed from its cursor. A second run in a direction while one runs is
 * refused.
 */
final class StartMediaMove extends BaseOperation
{
    /**
     * Only the operator, at the server's shell, moves media: a person's ID,
     * an administrator's too, is refused, and so is no one without the
     * operator.
     */
    protected function authorize(OperationInput $input): void
    {
        if ($input->actorId() !== null || ! Operator::isPresent()) {
            throw new AuthorizationException('Only the operator moves media.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var StartMediaMoveInput $input */
        if ($input->from === $input->to || $input->roles === [] || ($input->scope === 'changed') !== ($input->changedSince !== null)) {
            throw new InvalidArgumentException('A move goes between two locations, for at least one role, and names its date exactly when it covers changes.');
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var StartMediaMoveInput $input */
        if ($input->resumeId !== null) {
            $run = MediaMove::query()->lockForUpdate()->find($input->resumeId);

            if ($run === null || $run->state === 'finished' || $run->kind !== $input->kind || $run->from_location !== $input->from || $run->to_location !== $input->to) {
                throw ValidationException::withMessages(['run' => ['That move can’t be resumed.']]);
            }

            $run->update(['state' => 'running']);

            return $run->id;
        }

        $running = MediaMove::query()->lockForUpdate()
            ->where(['kind' => $input->kind, 'from_location' => $input->from, 'to_location' => $input->to, 'state' => 'running'])
            ->exists();

        if ($running) {
            throw ValidationException::withMessages(['run' => ['A move in this direction is already running.']]);
        }

        $roles = $input->roles;
        sort($roles);

        return MediaMove::query()->create([
            'kind' => $input->kind,
            'from_location' => $input->from,
            'to_location' => $input->to,
            'roles' => $roles,
            'scope' => $input->scope,
            'changed_since' => $input->changedSince,
            'deep' => $input->deep,
            'site_disks' => $input->siteDisks,
            'state' => 'running',
            'items_total' => $input->itemsTotal,
            'problems' => [],
            'started_at' => CarbonImmutable::now(),
        ])->id;
    }
}
