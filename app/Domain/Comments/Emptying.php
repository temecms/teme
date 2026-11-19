<?php

/**
 * Empty spam and Empty trash (1.8c′'s D4, D5): each comment the press covers
 * deleted permanently through DeleteCommentPermanently, as the person who
 * pressed it and with the list's cut-off, deepest first, a batch at a time.
 * The request runs the first part and EmptyComments the rest, on the queue.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments;

use App\Domain\Comments\Operations\DeleteCommentPermanently;
use App\Domain\Comments\Operations\Input\CommentActionInput;
use App\Domain\Comments\Queries\CommentList;
use App\Domain\Comments\Queries\EmptyingRequest;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final class Emptying
{
    /** Comments read at once. */
    private const int BATCH = 200;

    public function __construct(
        private readonly CommentList $list,
        private readonly DeleteCommentPermanently $delete,
        private readonly Actors $actors,
    ) {}

    /**
     * Deletes up to $limit of the comments the press covers, and says what
     * it did and whether any are left. One that changed since the list was
     * opened, or left the person's hands, is counted and passed over.
     */
    public function run(EmptyingRequest $request, int $limit): EmptyingResult
    {
        $deleted = 0;
        $tombstones = 0;
        $failed = 0;
        $after = null;
        $actor = (string) $request->viewerId;

        // The person, read once for the run (B-201).
        $this->actors->keep($actor);

        try {
            while (($room = $limit - $deleted - $failed) > 0) {
                $batch = $this->list->emptyingBatch($request, min(self::BATCH, $room), $after);

                if ($batch === []) {
                    return new EmptyingResult($deleted, $tombstones, $failed, false);
                }

                foreach ($batch as $comment) {
                    try {
                        $result = $this->delete->execute(new CommentActionInput($comment['id'], $actor, $request->cutoff));
                    } catch (AuthorizationException|ValidationException|ModelNotFoundException) {
                        $failed++;

                        continue;
                    }

                    $deleted++;
                    $tombstones += $result === CommentStatus::REMOVED ? 1 : 0;
                }

                $after = $batch[array_key_last($batch)];
            }

            return new EmptyingResult($deleted, $tombstones, $failed, $this->list->emptyingBatch($request, 1, $after) !== []);
        } finally {
            $this->actors->forget();
        }
    }
}
