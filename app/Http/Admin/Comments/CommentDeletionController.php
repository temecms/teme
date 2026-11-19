<?php

/**
 * Deletes comments permanently from the Comments screen (1.8c's D12): each
 * through DeleteCommentPermanently, deepest first, so a comment whose replies
 * all go goes too, at most 100 a request, the person read once for the
 * batch. Nothing to undo; the confirmation came first.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Comments;

use App\Domain\Comments\Operations\DeleteCommentPermanently;
use App\Domain\Comments\Operations\Input\CommentActionInput;
use App\Domain\Comments\Queries\CommentList;
use App\Domain\Settings\Modules;
use App\Domain\Users\Actors;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class CommentDeletionController extends Controller
{
    public function __invoke(Request $request, DeleteCommentPermanently $delete, CommentList $list, Actors $actors): RedirectResponse
    {
        abort_unless(Modules::on(Modules::COMMENTS), 404);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'string', 'size:26', 'distinct'],
        ]);

        /** @var list<string> $ids */
        $ids = array_values($validated['ids']);
        $order = $list->deletionOrder($ids);
        $actor = (string) $request->user()?->getAuthIdentifier();

        // An ID the order leaves out isn't a comment any more.
        $failed = count($ids) - count($order);
        $done = 0;
        $tombstones = 0;
        $name = null;

        if ($request->user() !== null) {
            $actors->keep($request->user());
        }

        try {
            foreach ($order as $comment) {
                try {
                    $result = $delete->execute(new CommentActionInput($comment['id'], $actor));
                } catch (AuthorizationException|ValidationException|ModelNotFoundException) {
                    // Another moderator's work, a comment that left the trash
                    // or spam, or an item that changed hands (question 2).
                    $failed++;

                    continue;
                }

                $done++;
                $tombstones += $result === 'removed' ? 1 : 0;
                $name = $comment['authorName'];
            }
        } finally {
            $actors->forget();
        }

        $this->toast(count($ids), $done, $tombstones, $failed, $name);

        return redirect()->to(CommentsAddress::back());
    }

    /**
     * Flashes what was deleted, how many tombstones keep replies in place,
     * and what couldn't be deleted (question 2); never an Undo.
     */
    private function toast(int $asked, int $done, int $tombstones, int $failed, ?string $name): void
    {
        if ($done === 0) {
            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => trans_choice('Nothing was deleted, because the comment had changed since you opened the list.|Nothing was deleted, because the comments had changed since you opened the list.', $asked),
            ]);

            return;
        }

        if ($asked === 1 && $failed === 0) {
            $message = $tombstones === 1
                ? __('Comment removed. Its replies keep their place under “This comment was removed”.')
                : $this->endSentence(__('Deleted permanently: the comment by :name', ['name' => $name ?? __('someone')]));

            Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

            return;
        }

        $message = trans_choice(':count comment deleted permanently.|:count comments deleted permanently.', $done);

        if ($tombstones > 0) {
            $message .= ' '.trans_choice(':count stays as “This comment was removed”, so its replies keep their place.|:count stay as “This comment was removed”, so their replies keep their place.', $tombstones);
        }

        if ($failed > 0) {
            $message .= ' '.trans_choice(':count wasn’t, because it had changed since you opened the list.|:count weren’t, because they had changed since you opened the list.', $failed);
        }

        Inertia::flash('toast', ['type' => $failed === 0 ? 'success' : 'warning', 'message' => $message]);
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
