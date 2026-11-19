<?php

/**
 * Records that a scheduled publication failed, and why. A failed row is not
 * retried: a publication that failed needs a person to look, and retrying it
 * every minute is noise. Only the operator records a failure.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\Models\ScheduledPublication;
use App\Domain\Contents\Operations\Input\ScheduledPublicationOutcomeInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Teme\Contracts\Operations\OperationInput;

final class FailScheduledPublication extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        if (! Operator::isPresent()) {
            throw new AuthorizationException('Only the operator records a failed publication.');
        }
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): null
    {
        /** @var ScheduledPublicationOutcomeInput $input */
        $schedule = ScheduledPublication::query()->lockForUpdate()->find($input->scheduledPublicationId);

        if ($schedule !== null && $schedule->isPending()) {
            $schedule->update(['failed_at' => Date::now(), 'failure_reason' => $input->reason]);
        }

        return null;
    }
}
