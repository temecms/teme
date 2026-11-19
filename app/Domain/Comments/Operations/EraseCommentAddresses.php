<?php

/**
 * Erases commenters' IP addresses and browsers once keep_ip_days have passed
 * since each comment arrived (I-270; 1.8d's D13), a chunk at a time, as the
 * operator. Imported comments go on the first run; nothing else on a comment
 * changes.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Operations;

use App\Domain\Comments\Operations\Input\EraseCommentAddressesInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Teme\Contracts\Operations\OperationInput;

final class EraseCommentAddresses extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var EraseCommentAddressesInput $input */
        // The operator's daily run alone (operations.md, The operator): no
        // screen asks for it, and nobody may erase from a request.
        if ($input->actorId() === null && Operator::isPresent()) {
            return;
        }

        throw new AuthorizationException('Only the operator erases commenters’ addresses.');
    }

    protected function validate(OperationInput $input): void
    {
        /** @var EraseCommentAddressesInput $input */
        Validator::make(['limit' => $input->limit], ['limit' => ['required', 'integer', 'min:1', 'max:10000']])->validate();
    }

    protected function perform(OperationInput $input): int
    {
        /** @var EraseCommentAddressesInput $input */
        // One chunk (1.8d's D13). An erased row no longer matches, so each
        // chunk reads new rows; a row another transaction holds is skipped,
        // never waited on, and the next run takes it. updated_at stays:
        // erasing isn't an edit, and no public page shows an address.
        return DB::update(<<<'SQL'
            UPDATE comments SET ip = NULL, user_agent = NULL
            WHERE id IN (
                SELECT id FROM comments
                WHERE created_at <= ?::timestamptz AND (ip IS NOT NULL OR user_agent IS NOT NULL)
                LIMIT ?
                FOR UPDATE SKIP LOCKED
            )
            SQL, [$input->arrivedBefore->format('Y-m-d H:i:s.uP'), $input->limit]);
    }
}
