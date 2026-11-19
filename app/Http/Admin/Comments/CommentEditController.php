<?php

/**
 * Quick edit's save on the Comments screen (1.8c′'s D9, D13): one
 * EditComment, then the list as it was, with a toast; a field's refusal goes
 * back to the form, and a comment that changed meanwhile answers with a
 * warning.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Comments;

use App\Domain\Comments\Operations\EditComment;
use App\Domain\Comments\Operations\Input\EditCommentInput;
use App\Domain\Settings\Modules;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class CommentEditController extends Controller
{
    public function __invoke(Request $request, EditComment $edit): RedirectResponse
    {
        abort_unless(Modules::on(Modules::COMMENTS), 404);

        $validated = $request->validate(['id' => ['required', 'string', 'size:26']]);

        try {
            $edited = $edit->execute(new EditCommentInput(
                commentId: $validated['id'],
                name: $request->string('name')->toString(),
                email: $request->string('email')->toString(),
                url: $request->string('url')->toString(),
                text: $request->string('text')->toString(),
                actorId: (string) $request->user()?->getAuthIdentifier(),
            ));
        } catch (AuthorizationException|ModelNotFoundException) {
            return $this->changed();
        } catch (ValidationException $refused) {
            // A comment that is a ping or a tombstone now, not a field's fault.
            if (array_key_exists('commentId', $refused->errors())) {
                return $this->changed();
            }

            throw $refused;
        }

        $saved = $this->endSentence(__('Saved the comment by :name', ['name' => $edited->authorName]));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $edited->madePlain ? $saved.' '.__('It’s plain text now.') : $saved,
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
            'message' => __('Nothing was saved, because the comment had changed since you opened the list.'),
        ]);

        return redirect()->to(CommentsAddress::back());
    }

    /**
     * Ends the sentence with a full stop unless a name already ended it
     * (v9's endSentence).
     */
    private function endSentence(string $sentence): string
    {
        return in_array(mb_substr($sentence, -1), ['.', '!', '?'], true) ? $sentence : $sentence.'.';
    }
}
