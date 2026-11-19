<?php

/**
 * Adds one step of a run to the run (I-143): the rows it handled, what it skipped by reason, the first refusal's words, and where the next step starts; unless another step has already moved the run on from where this one read. Only the run's own person, as its steps run, records it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\BulkRunWords;
use App\Domain\Contents\Jobs\AdvanceBulkRun;
use App\Domain\Contents\Models\BulkRun;
use App\Domain\Contents\Operations\Input\RecordBulkRunStepInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Teme\Contracts\Operations\OperationInput;

final class RecordBulkRunStep extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var RecordBulkRunStepInput $input */
        $run = BulkRun::query()->findOrFail($input->runId);

        if ($input->actorId() === null || (string) $run->user_id !== $input->actorId()) {
            throw new AuthorizationException('Only the person a run belongs to records its steps.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RecordBulkRunStepInput $input */
        $reasons = [BulkRunWords::NOT_ALLOWED, BulkRunWords::GONE, BulkRunWords::REFUSED];

        Validator::make(
            ['handled' => $input->handled, 'skipped' => $input->skipped, 'refusal' => $input->refusal],
            [
                'handled' => ['integer', 'min:0', 'max:'.AdvanceBulkRun::ROWS],
                'skipped' => ['array'],
                'skipped.*' => ['integer', 'min:0'],
                'refusal' => ['nullable', 'string', 'max:300'],
            ],
        )->validate();

        Validator::make(['reasons' => array_keys($input->skipped)], ['reasons.*' => [Rule::in($reasons)]])->validate();
    }

    protected function perform(OperationInput $input): bool
    {
        /** @var RecordBulkRunStepInput $input */
        $run = BulkRun::query()->whereKey($input->runId)->lockForUpdate()->firstOrFail();

        // A second worker took the same step and has recorded it already.
        if ($run->after !== $input->from) {
            return false;
        }

        $skipped = $run->skipped;

        foreach ($input->skipped as $reason => $count) {
            $skipped[$reason] = ($skipped[$reason] ?? 0) + $count;
        }

        // A last page keeps the cursor it had: a null would send a retry back to the top.
        $run->update([
            'after' => $input->after ?? $run->after,
            'done' => $run->done + $input->handled,
            'skipped' => $skipped,
            'refusal' => $run->refusal ?? $input->refusal,
        ]);

        return true;
    }
}
