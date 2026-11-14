<?php

declare(strict_types=1);

namespace App\Domain\Updates\Operations;

use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use App\Domain\Updates\Jobs\SendTemeUpdateEmails;
use App\Domain\Updates\Notifications\TemeUpdateNotice;
use App\Domain\Updates\Operations\Input\EndTemeUpdateInput;
use App\Domain\Updates\Requests\UpdateStatus;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Ends an update request that was open (I-3326): done, switched back or failed, with the sentence that says
 * why, and every administrator is emailed. The operator only: the updater, or a restore that ends it.
 */
final class EndTemeUpdate extends BaseOperation
{
    /** The statuses an open request may end in. */
    private const array ENDINGS = [UpdateStatus::Done, UpdateStatus::SwitchedBack, UpdateStatus::Failed];

    protected function authorize(OperationInput $input): void
    {
        if ($input->actorId() !== null || ! Operator::isPresent()) {
            throw new AuthorizationException('Only the updater, as the operator, records an update’s steps.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var EndTemeUpdateInput $input */
        if (! in_array($input->status, self::ENDINGS, true)) {
            throw ValidationException::withMessages(['status' => ["{$input->status->value} isn’t how an update ends."]]);
        }
    }

    protected function perform(OperationInput $input): null
    {
        /** @var EndTemeUpdateInput $input */
        $row = DB::table('teme_updates')->where('id', $input->updateId)->lockForUpdate()->first();

        if ($row === null || ! UpdateStatus::from((string) $row->status)->isOpen()) {
            throw ValidationException::withMessages(['update' => ['There’s no such update.']]);
        }

        DB::table('teme_updates')->where('id', $input->updateId)->update([
            'status' => $input->status->value,
            'error' => $input->error,
            'finished_at' => CarbonImmutable::now(),
        ]);

        DB::afterCommit(fn () => SendTemeUpdateEmails::dispatch($input->updateId, match ($input->status) {
            UpdateStatus::Done => TemeUpdateNotice::DONE,
            UpdateStatus::SwitchedBack => TemeUpdateNotice::SWITCHED_BACK,
            default => TemeUpdateNotice::FAILED,
        }));

        return null;
    }
}
