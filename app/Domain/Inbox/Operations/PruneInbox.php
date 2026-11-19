<?php

/**
 * Deletes one chunk of inbox items that arrived before a moment, read or not,
 * as the operator (I-221: kept 90 days).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Inbox\Operations;

use App\Domain\Inbox\Operations\Input\PruneInboxInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Teme\Contracts\Operations\OperationInput;

final class PruneInbox extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        if ($input->actorId() !== null || ! Operator::isPresent()) {
            throw new AuthorizationException('Only the operator prunes the inbox.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var PruneInboxInput $input */
        Validator::make(['limit' => $input->limit], ['limit' => ['required', 'integer', 'min:1', 'max:10000']])->validate();
    }

    protected function perform(OperationInput $input): int
    {
        /** @var PruneInboxInput $input */
        // One chunk, oldest first; a row another transaction holds is
        // skipped, never waited on, and the next run takes it.
        return DB::delete(<<<'SQL'
            DELETE FROM inbox_items
            WHERE id IN (
                SELECT id FROM inbox_items
                WHERE created_at < ?::timestamptz
                ORDER BY created_at
                LIMIT ?
                FOR UPDATE SKIP LOCKED
            )
            SQL, [$input->createdBefore->format('Y-m-d H:i:s.uP'), $input->limit]);
    }
}
