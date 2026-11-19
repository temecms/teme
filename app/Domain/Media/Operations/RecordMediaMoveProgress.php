<?php

/**
 * Records one chunk of a running media move (I-3456): its cursor moved
 * forward, never back, its counts added, and its problems kept up to the
 * first 1,000 of the run.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Operations;

use App\Domain\Media\Models\MediaMove;
use App\Domain\Media\Operations\Input\RecordMediaMoveProgressInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class RecordMediaMoveProgress extends BaseOperation
{
    /**
     * Only the operator, at the server's shell, moves media: a person's ID,
     * an administrator's too, is refused, and so is no one without the
     * operator.
     */
    protected function authorize(OperationInput $input): void
    {
        if ($input->actorId() !== null || ! Operator::isPresent()) {
            throw new AuthorizationException('Only the operator moves media.');
        }
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): null
    {
        /** @var RecordMediaMoveProgressInput $input */
        $run = MediaMove::query()->lockForUpdate()->find($input->runId);

        if ($run === null || $run->state !== 'running') {
            throw ValidationException::withMessages(['run' => ['This move isn’t running.']]);
        }

        $problems = array_slice([...$run->problems, ...$input->problems], 0, MediaMove::NAMED_PROBLEMS);

        $run->update([
            // ULIDs sort as their strings do.
            'cursor' => $run->cursor === null || strcmp($input->cursor, $run->cursor) > 0 ? $input->cursor : $run->cursor,
            'items_done' => $run->items_done + $input->itemsDone,
            'files_copied' => $run->files_copied + $input->filesCopied,
            'files_present' => $run->files_present + $input->filesPresent,
            'files_deleted' => $run->files_deleted + $input->filesDeleted,
            'bytes_copied' => $run->bytes_copied + $input->bytesCopied,
            'holes' => $run->holes + $input->holes,
            'failures' => $run->failures + $input->failures,
            'problems' => $problems,
        ]);

        return null;
    }
}
