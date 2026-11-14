<?php

declare(strict_types=1);

namespace App\Domain\Updates\Operations;

use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Updates\Operations\Concerns\AuthorizesUpdateRequests;
use App\Domain\Updates\Operations\Input\CancelTemeUpdateInput;
use App\Domain\Updates\Requests\UpdateStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Cancels an update request that the updater hasn't begun (I-3326): a person who holds updates.manage, or
 * the operator. No password is asked: it only stops what hasn't started, and reveals nothing.
 */
final class CancelTemeUpdate extends BaseOperation
{
    use AuthorizesUpdateRequests;

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): null
    {
        /** @var CancelTemeUpdateInput $input */
        $row = DB::table('teme_updates')->where('id', $input->updateId)->lockForUpdate()->first();

        if ($row === null) {
            throw ValidationException::withMessages(['update' => ['There’s no such update.']]);
        }

        if ($row->status !== UpdateStatus::Requested->value) {
            throw ValidationException::withMessages(['update' => ['The updater has begun this update, so it can’t be cancelled now.']]);
        }

        DB::table('teme_updates')->where('id', $input->updateId)->update([
            'status' => UpdateStatus::Cancelled->value,
            'cancelled_by' => $input->actorId() === null ? null : (int) $input->actorId(),
            'finished_at' => CarbonImmutable::now(),
        ]);

        return null;
    }
}
