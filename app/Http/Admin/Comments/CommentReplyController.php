<?php

/**
 * The inline reply on the Comments screen (1.8c′'s D11, D13): one
 * ReplyToComment, then the list as it was, with a toast; an empty or overlong
 * reply goes back to its form, and a comment that changed meanwhile answers
 * with a warning.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Comments;

use App\Domain\Comments\Operations\Input\ReplyToCommentInput;
use App\Domain\Comments\Operations\ReplyToComment;
use App\Domain\Settings\Modules;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class CommentReplyController extends Controller
{
    public function __invoke(Request $request, ReplyToComment $reply): RedirectResponse
    {
        abort_unless(Modules::on(Modules::COMMENTS), 404);

        $validated = $request->validate(['id' => ['required', 'string', 'size:26']]);

        try {
            $posted = $reply->execute(new ReplyToCommentInput(
                commentId: $validated['id'],
                text: $request->string('text')->toString(),
                actorId: (string) $request->user()?->getAuthIdentifier(),
            ));
        } catch (AuthorizationException|ModelNotFoundException) {
            return $this->changed();
        } catch (ValidationException $refused) {
            // A comment that is a ping, gone from the list or on a trashed
            // item now, not the reply's fault.
            if (array_key_exists('commentId', $refused->errors())) {
                return $this->changed();
            }

            throw $refused;
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $posted->approvedAnswered
                ? __('Reply posted. The comment by :name is approved too.', ['name' => $posted->answeredName ?? __('someone')])
                : __('Reply posted.'),
        ]);

        return redirect()->to(CommentsAddress::back());
    }

    /**
     * The warning for a comment deleted, moved, handed to someone else or
     * made a ping meanwhile (D13, question 1), back to the list as it was.
     */
    private function changed(): RedirectResponse
    {
        Inertia::flash('toast', [
            'type' => 'warning',
            'message' => __('Nothing was posted, because the comment had changed since you opened the list.'),
        ]);

        return redirect()->to(CommentsAddress::back());
    }
}
