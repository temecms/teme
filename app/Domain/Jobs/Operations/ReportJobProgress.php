<?php

/**
 * Adds the steps a piece of work has done since its last report, and its
 * latest words, as it runs (I-104); a waiting job becomes running. A job that
 * has ended stays as it ended, and the answer false tells the work to stop.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Jobs\Operations;

use App\Domain\Jobs\JobAccess;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\Input\ReportJobProgressInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Teme\Contracts\Operations\OperationInput;

final class ReportJobProgress extends BaseOperation
{
    public function __construct(private JobAccess $access) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var ReportJobProgressInput $input */
        if (! $this->access->allows(TrackedJob::query()->findOrFail($input->jobId), $input->actorId())) {
            throw new AuthorizationException('Only whoever started a job, or someone who looks after jobs, reports on it.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ReportJobProgressInput $input */
        Validator::make(
            ['advanced_by' => $input->advancedBy, 'total' => $input->total, 'message' => $input->message],
            ['advanced_by' => ['integer', 'min:0', 'max:1000000'], 'total' => ['nullable', 'integer', 'min:0', 'max:2147483647'], 'message' => ['nullable', 'string', 'max:500']],
        )->validate();
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var ReportJobProgressInput $input */
        // One statement: the state is read as the row is changed, so a job
        // cancelled a moment ago is never reopened, and parallel workers' steps
        // add up rather than overwrite each other. The count stops at the total
        // (I-104). In SET, total is the row's value before this change.
        return DB::update(<<<'SQL'
            UPDATE tracked_jobs
            SET total = COALESCE(?::integer, total),
                done = CASE
                    WHEN COALESCE(?::integer, total) IS NULL THEN done + ?
                    ELSE LEAST(done + ?, COALESCE(?::integer, total))
                END,
                message = COALESCE(?, message),
                state = 'running',
                updated_at = now()
            WHERE id = ? AND state IN ('waiting', 'running')
            SQL, [$input->total, $input->total, $input->advancedBy, $input->advancedBy, $input->total, $input->message, $input->jobId]) === 1;
    }
}
