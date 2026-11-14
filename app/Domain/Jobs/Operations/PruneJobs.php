<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Operations;

use App\Domain\Jobs\Operations\Input\PruneJobsInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Teme\Contracts\Operations\OperationInput;

/**
 * Deletes one chunk of jobs that ended before a moment, as the operator
 * (I-104: kept 90 days after they end); a job under way is never deleted.
 */
final class PruneJobs extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        if ($input->actorId() !== null || ! Operator::isPresent()) {
            throw new AuthorizationException('Only the operator prunes the jobs.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var PruneJobsInput $input */
        Validator::make(['limit' => $input->limit], ['limit' => ['required', 'integer', 'min:1', 'max:10000']])->validate();
    }

    protected function perform(OperationInput $input): int
    {
        /** @var PruneJobsInput $input */
        // One chunk, oldest first; a row another transaction holds is
        // skipped, never waited on, and the next run takes it.
        return DB::delete(<<<'SQL'
            DELETE FROM tracked_jobs
            WHERE id IN (
                SELECT id FROM tracked_jobs
                WHERE ended_at IS NOT NULL AND ended_at < ?::timestamptz
                ORDER BY ended_at
                LIMIT ?
                FOR UPDATE SKIP LOCKED
            )
            SQL, [$input->endedBefore->format('Y-m-d H:i:s.uP'), $input->limit]);
    }
}
