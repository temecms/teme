<?php

declare(strict_types=1);

namespace App\Domain\Comments\Operations;

use App\Domain\Comments\CommentDocument;
use App\Domain\Comments\CommentKind;
use App\Domain\Comments\CommentModeration;
use App\Domain\Comments\CommentPurges;
use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\CommentText;
use App\Domain\Comments\EditedComment;
use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\Operations\Input\EditCommentInput;
use App\Domain\Contents\Models\Content;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Quick edit (I-224, C3; 1.8c′'s D9): changes a comment's name, email address,
 * website and words, by whoever looks after its item, and replaces its
 * document only when its words changed, so imported formatting survives an
 * edit of anything else. Never a ping or a tombstone. The pages that show an
 * approved comment are removed after commit when what readers see changed.
 */
final class EditComment extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var EditCommentInput $input */
        $actor = app(Actors::class)->find($input->actorId());
        $comment = Comment::query()->find($input->commentId);
        $item = $comment === null ? null : Content::withTrashed()->find($comment->content_id);

        if ($actor === null || $item === null || ! CommentModeration::allows($actor, $item)) {
            throw new AuthorizationException('Not permitted to edit this comment.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var EditCommentInput $input */
        $text = self::text($input->text);

        Validator::make(
            [
                'name' => trim($input->name),
                'email' => trim($input->email),
                'url' => self::website($input->url),
                'text' => $text,
            ],
            [
                'name' => ['required', 'string', 'max:100'],
                'email' => ['required', 'string', 'email:rfc', 'max:254'],
                'url' => ['nullable', 'string', 'max:200', 'url:http,https'],
                'text' => ['required', 'string', 'max:5000'],
            ],
            // The moderator's words, not the form's "your comment" (question 1).
            [
                'name.required' => __('Enter the commenter’s name.'),
                'name.max' => __('Enter a name of 100 characters or fewer.'),
                'email.required' => __('Enter an email address, like name@example.com.'),
                'email.email' => __('Enter an email address, like name@example.com.'),
                'email.max' => __('Enter an email address, like name@example.com.'),
                'url.url' => __('Enter a web address that starts with https://, or leave this empty.'),
                'url.max' => __('Enter a web address that starts with https://, or leave this empty.'),
                'text.required' => __('Write the comment’s text, or move it to the trash.'),
                'text.max' => __('Shorten the comment to 5,000 characters or fewer. It has :count.', ['count' => number_format(mb_strlen($text))]),
            ],
        )->validate();

        CommentDocument::validate(CommentText::toDocument($text));
    }

    protected function perform(OperationInput $input): EditedComment
    {
        /** @var EditCommentInput $input */
        $comment = Comment::query()->lockForUpdate()->findOrFail($input->commentId);

        if ($comment->status === CommentStatus::REMOVED) {
            throw ValidationException::withMessages(['commentId' => __('A removed comment can’t be edited.')]);
        }

        if ($comment->kind !== CommentKind::Comment) {
            throw ValidationException::withMessages(['commentId' => __('Pingbacks and trackbacks can’t be edited: their words are another site’s.')]);
        }

        $stored = $comment->body ?? [];
        $text = self::text($input->text);

        // The document is replaced only when the words are (C3): an edit of
        // the name, email address or website, or of nothing, keeps whatever
        // formatting an imported comment holds.
        $changed = $text !== trim(CommentText::plain($stored));
        $madePlain = $changed && ! CommentText::isPlain($stored);

        $comment->author_name = trim($input->name);
        $comment->author_email = mb_strtolower(trim($input->email));
        $comment->author_url = self::website($input->url);

        if ($changed) {
            $comment->body = CommentText::toDocument($text);
        }

        if (! $comment->isDirty()) {
            return new EditedComment($comment->id, (string) $comment->author_name, false);
        }

        // Readers see an approved comment's name, website and words; never
        // its email address, and never a held one.
        $seen = $comment->isDirty(['author_name', 'author_url', 'body']) && $comment->status === CommentStatus::APPROVED;

        $comment->save();

        if ($seen) {
            $this->purgeAfterCommit(CommentPurges::changed($comment->content_id, $comment->id));
        }

        return new EditedComment($comment->id, (string) $comment->author_name, $madePlain);
    }

    /**
     * The words as typed, each line break `\n`, trimmed.
     */
    private static function text(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * The website as typed, trimmed, with https:// before an address typed
     * without a scheme, as SubmitComment::website() makes it (a colon before
     * a digit is a port); null for none. Kept whatever the site asks guests
     * for, since a moderator typed it.
     */
    private static function website(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        return preg_match('/^[a-z][a-z0-9+.-]*:(?!\d)/i', $url) === 1 ? $url : 'https://'.$url;
    }
}
