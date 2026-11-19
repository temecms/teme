<?php

/**
 * Deletes one chunk of AI activity rows made before a moment, as the
 * operator (I-318: kept 12 months). The months' totals stay.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Operations;

use App\Domain\Ai\Operations\Input\PruneAiActivityInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Teme\Contracts\Operations\OperationInput;

final class PruneAiActivity extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var PruneAiActivityInput $input */
        // The operator's daily run alone (docs/architecture/operations.md, The operator).
        if ($input->actorId() === null && Operator::isPresent()) {
            return;
        }

        throw new AuthorizationException('Only the operator prunes the AI activity record.');
    }

    protected function validate(OperationInput $input): void
    {
        /** @var PruneAiActivityInput $input */
        Validator::make(['limit' => $input->limit], ['limit' => ['required', 'integer', 'min:1', 'max:10000']])->validate();
    }

    protected function perform(OperationInput $input): int
    {
        /** @var PruneAiActivityInput $input */
        // One chunk, oldest first; a row another transaction holds is
        // skipped, never waited on, and the next run takes it.
        return DB::delete(<<<'SQL'
            DELETE FROM ai_activity
            WHERE id IN (
                SELECT id FROM ai_activity
                WHERE created_at < ?::timestamptz
                ORDER BY created_at
                LIMIT ?
                FOR UPDATE SKIP LOCKED
            )
            SQL, [$input->createdBefore->format('Y-m-d H:i:s.uP'), $input->limit]);
    }
}
