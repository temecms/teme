<?php

declare(strict_types=1);

namespace App\Domain\Updates\Operations;

use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use App\Domain\Updates\Jobs\SendTemeUpdateEmails;
use App\Domain\Updates\Notifications\TemeUpdateNotice;
use App\Domain\Updates\Operations\Input\BeginTemeUpdateInput;
use App\Domain\Updates\Requests\UpdateStatus;
use App\Domain\Updates\Requests\UpdateStep;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Operations\OperationInput;

/**
 * Begins an update request the updater has taken up (I-3326): it runs, with its seven steps waiting, and
 * every administrator is emailed. The operator only. Returns false when the request was cancelled first.
 */
final class BeginTemeUpdate extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        if ($input->actorId() !== null || ! Operator::isPresent()) {
            throw new AuthorizationException('Only the updater, as the operator, records an update’s steps.');
        }
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): bool
    {
        /** @var BeginTemeUpdateInput $input */
        $row = DB::table('teme_updates')->where('id', $input->updateId)->lockForUpdate()->first();

        if ($row === null || $row->status !== UpdateStatus::Requested->value) {
            return false;
        }

        $steps = array_map(fn (UpdateStep $step): array => [
            'step' => $step->value, 'state' => UpdateStep::PENDING, 'startedAt' => null, 'finishedAt' => null, 'detail' => null, 'asks' => 0, 'runsAfter' => null, 'mark' => null,
        ], UpdateStep::cases());

        DB::table('teme_updates')->where('id', $input->updateId)->update([
            'status' => UpdateStatus::Running->value,
            'step' => UpdateStep::Deploy->value,
            'steps' => json_encode($steps, JSON_THROW_ON_ERROR),
            'started_at' => CarbonImmutable::now(),
        ]);

        // A row forged by hand is noticed here, once the updater takes it up.
        DB::afterCommit(fn () => SendTemeUpdateEmails::dispatch($input->updateId, TemeUpdateNotice::STARTED));

        return true;
    }
}
