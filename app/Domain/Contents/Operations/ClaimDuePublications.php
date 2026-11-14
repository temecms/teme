<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\Operations\Input\ClaimDuePublicationsInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Operations\OperationInput;

/**
 * Claims the scheduled publications that are due, for one run of the
 * scheduler: marks each claimed and counts the attempt, so a second runner
 * passes them by. A claim older than ten minutes belongs to a run that died,
 * and is claimed again. Only the operator claims.
 */
final class ClaimDuePublications extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        if (! Operator::isPresent()) {
            throw new AuthorizationException('Only the operator runs scheduled publications.');
        }
    }

    protected function validate(OperationInput $input): void {}

    /**
     * The claimed rows, soonest first, each with who scheduled it.
     *
     * @return list<array{id: string, createdBy: string|null}>
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ClaimDuePublicationsInput $input */
        // Every time is bound from PHP, never SQL's now(), so a test's travel()
        // moves it too.
        $now = now();

        $rows = DB::table('scheduled_publications')
            ->whereNull('completed_at')->whereNull('failed_at')
            ->whereNull('cancelled_at')->whereNull('held_reason')
            ->where('run_at', '<=', $now)
            // Unclaimed, or claimed by a run that died: a claim older than ten
            // minutes is a crashed run, and the row is reclaimable.
            ->where(fn (Builder $query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<', $now->copy()->subMinutes(10)))
            ->orderBy('run_at')
            ->limit($input->limit)
            ->lock('for update skip locked')
            ->get(['id', 'created_by']);

        if ($rows->isNotEmpty()) {
            DB::table('scheduled_publications')
                ->whereIn('id', $rows->pluck('id'))
                ->update(['claimed_at' => $now, 'attempts' => DB::raw('attempts + 1')]);
        }

        return $rows->map(fn (object $row): array => [
            'id' => (string) $row->id,
            'createdBy' => $row->created_by === null ? null : (string) $row->created_by,
        ])->values()->all();
    }
}
