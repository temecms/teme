<?php

/**
 * Records how a media move ended (I-3456): finished, with when, or stopped,
 * to be resumed from its cursor by the same command run again.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Operations;

use App\Domain\Media\Models\MediaMove;
use App\Domain\Media\Operations\Input\EndMediaMoveInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

final class EndMediaMove extends BaseOperation
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
        /** @var EndMediaMoveInput $input */
        if (! in_array($input->state, ['finished', 'stopped'], true)) {
            throw new InvalidArgumentException('A move ends finished or stopped.');
        }
    }

    protected function perform(OperationInput $input): null
    {
        /** @var EndMediaMoveInput $input */
        $run = MediaMove::query()->lockForUpdate()->find($input->runId);

        if ($run === null || $run->state !== 'running') {
            throw ValidationException::withMessages(['run' => ['This move isn’t running.']]);
        }

        $run->update([
            'state' => $input->state,
            'finished_at' => $input->state === 'finished' ? CarbonImmutable::now()->max($run->started_at) : null,
            'message' => $input->message === null ? null : mb_strimwidth($input->message, 0, 2000, '…'),
        ]);

        return null;
    }
}
