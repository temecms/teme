<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\Models\ScheduledPublication;
use App\Domain\Contents\Operations\Input\ScheduledPublicationOutcomeInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Holds a scheduled publication that cannot run as things stand — its
 * scheduler's account is gone, or may no longer publish — until someone who
 * may publish reschedules it. The article stays scheduled: a silent refusal
 * would leave it waiting for ever with nobody told. Only the operator holds.
 */
final class HoldScheduledPublication extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        if (! Operator::isPresent()) {
            throw new AuthorizationException('Only the operator holds scheduled publications.');
        }
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): null
    {
        /** @var ScheduledPublicationOutcomeInput $input */
        $schedule = ScheduledPublication::query()->lockForUpdate()->find($input->scheduledPublicationId);

        if ($schedule !== null && $schedule->isPending()) {
            $schedule->update(['held_reason' => $input->reason, 'claimed_at' => null]);
        }

        return null;
    }
}
