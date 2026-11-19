<?php

/**
 * Brings a comment an earlier import made up to date with WordPress (I-341):
 * its status, words and writer, only while it is as the import left it, its
 * status and updated_at what the import last wrote, checked under its row
 * lock, so an import never undoes a moderator. Its place, date, kind and
 * number never change. Only an importer may.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Operations;

use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\Operations\Input\UpdateImportedCommentInput;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Support\Operations\BaseOperation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Teme\Contracts\Operations\OperationInput;

final class UpdateImportedComment extends BaseOperation
{
    use AuthorizesImports;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var UpdateImportedCommentInput $input */
        ImportComment::checkFields($input->status, $input->authorName, $input->authorEmail, $input->authorUrl, $input->authorUserId, $input->body);
    }

    /**
     * @return array{changedHere: bool, editedAt: CarbonImmutable}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var UpdateImportedCommentInput $input */
        $comment = Comment::query()->lockForUpdate()->find($input->commentId);

        if ($comment === null) {
            throw ValidationException::withMessages(['commentId' => __('The comment isn’t there to update.')]);
        }

        // Compared as instants, both in UTC, never as strings in the session's zone (I-341).
        $edited = $comment->updated_at === null ? null : CarbonImmutable::instance($comment->updated_at)->utc();

        if ($edited === null || $comment->status !== $input->expectedStatus || ! $edited->equalTo($input->expectedEditedAt->utc())) {
            return ['changedHere' => true, 'editedAt' => $edited ?? $input->expectedEditedAt];
        }

        $before = $comment->status;
        $comment->fill([
            'author_name' => $input->authorName,
            'author_email' => $input->authorEmail,
            'author_url' => $input->authorUrl,
            'author_user_id' => $input->authorUserId,
            'body' => $input->body,
        ]);

        if ($input->status !== $before) {
            $parked = [CommentStatus::SPAM, CommentStatus::TRASH];
            $comment->status = $input->status;
            $comment->status_changed_at = now()->format('Y-m-d H:i:s.uP');
            // Where a comment went to spam or the trash from, as ModerateComment keeps it.
            $comment->previous_status = in_array($input->status, $parked, true)
                ? (in_array($before, $parked, true) ? $comment->previous_status : $before)
                : null;
        }

        if (! $comment->isDirty()) {
            return ['changedHere' => false, 'editedAt' => $edited];
        }

        // To the second, as the column keeps it, so a later run's check matches.
        $now = CarbonImmutable::now()->utc()->startOfSecond();
        $comment->updated_at = $now;

        try {
            $comment->save();
        } catch (QueryException $refused) {
            // Its message holds the row's values, an email address and the words among them (I-270).
            throw new RuntimeException("PostgreSQL refused the comment’s row ({$refused->getCode()}).");
        }

        return ['changedHere' => false, 'editedAt' => $now];
    }
}
