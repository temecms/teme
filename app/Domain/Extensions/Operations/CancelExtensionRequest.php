<?php

/**
 * Cancels a plugin or theme request the installer hasn't begun, or one waiting for the administrator's
 * agreement (I-211): a person who holds extensions.install, after their password, or the operator. A
 * running request can't be cancelled; it ends on its own. The request's upload goes with it.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Operations;

use App\Domain\Extensions\Operations\Concerns\AuthorizesExtensionChanges;
use App\Domain\Extensions\Operations\Input\CancelExtensionRequestInput;
use App\Domain\Extensions\RequestStatus;
use App\Domain\Support\Operations\BaseOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class CancelExtensionRequest extends BaseOperation implements RequiresElevation
{
    use AuthorizesExtensionChanges;

    public function requiresElevation(OperationInput $input): bool
    {
        return true;
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): null
    {
        /** @var CancelExtensionRequestInput $input */
        $row = DB::table('extension_requests')->where('id', $input->requestId)->lockForUpdate()->first();

        if ($row === null) {
            throw ValidationException::withMessages(['request' => [__('There’s no such request.')]]);
        }

        if ($row->status === RequestStatus::Running->value) {
            throw ValidationException::withMessages(['request' => [__('The installer is carrying out this request, so it can’t be cancelled now. It ends on its own.')]]);
        }

        if ($row->status !== RequestStatus::Requested->value && $row->status !== RequestStatus::Waiting->value) {
            throw ValidationException::withMessages(['request' => [__('This request has already ended.')]]);
        }

        DB::table('extension_requests')->where('id', $input->requestId)->update([
            'status' => RequestStatus::Cancelled->value,
            'cancelled_by' => $input->actorId() === null ? null : (int) $input->actorId(),
            'finished_at' => CarbonImmutable::now(),
        ]);

        // An upload is kept only until its request ends.
        if ($row->upload_id !== null) {
            DB::table('extension_uploads')->where('id', $row->upload_id)->delete();
        }

        return null;
    }
}
