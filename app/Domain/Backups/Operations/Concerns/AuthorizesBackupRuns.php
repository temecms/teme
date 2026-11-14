<?php

declare(strict_types=1);

namespace App\Domain\Backups\Operations\Concerns;

use App\Domain\Support\Security\Operator;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Who may record backup runs: the operator, through the scheduler or a
 * teme: command (docs/architecture/operations.md), or an administrator, who
 * holds backups.manage. No password is asked: a backup reveals and destroys
 * nothing, and a queued run has no session to ask in.
 */
trait AuthorizesBackupRuns
{
    protected function authorize(OperationInput $input): void
    {
        if ($input->actorId() === null && Operator::isPresent()) {
            return;
        }

        $actor = app(Actors::class)->find($input->actorId());

        if ($actor === null || ! $actor->can('backups.manage')) {
            throw new AuthorizationException('Only an administrator manages backups.');
        }
    }
}
