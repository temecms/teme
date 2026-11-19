<?php

/**
 * Moves comments on the Comments screen (1.8c's D10, D11): one
 * ModerateComment each, at most 100 a request, each authorized on its own,
 * the person read once for the batch (B-201). It answers with a toast that
 * says what moved, names what couldn't, and offers Undo: the same moves
 * back to where the Operation found each comment.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Comments;

use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\CommentStatusRegistry;
use App\Domain\Comments\ModeratedComment;
use App\Domain\Comments\Operations\Input\ModerateCommentInput;
use App\Domain\Comments\Operations\ModerateComment;
use App\Domain\Settings\Modules;
use App\Domain\Users\Actors;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class CommentModerationController extends Controller
{
    /** What a move to each status is called: for one comment, then for several (v9's modWords). */
    private const array WORDS = [
        CommentStatus::APPROVED => ['Approved', 'approved'],
        CommentStatus::PENDING => ['Unapproved', 'unapproved'],
        CommentStatus::SPAM => ['Marked as spam', 'marked as spam'],
        CommentStatus::TRASH => ['Moved to trash', 'moved to the trash'],
    ];

    public function __invoke(Request $request, ModerateComment $moderate, CommentStatusRegistry $statuses, Actors $actors): RedirectResponse
    {
        abort_unless(Modules::on(Modules::COMMENTS), 404);

        $validated = $request->validate([
            'moves' => ['required', 'array', 'min:1', 'max:100'],
            'moves.*.id' => ['required', 'string', 'size:26', 'distinct'],
            'moves.*.to' => ['required', 'string', Rule::in([...$statuses->settable(), ModerateComment::RESTORE])],
            'undo' => ['sometimes', 'boolean'],
        ]);

        /** @var list<array{id: string, to: string}> $moves */
        $moves = array_values($validated['moves']);
        $actor = (string) $request->user()?->getAuthIdentifier();
        $moved = [];
        $failed = 0;

        if ($request->user() !== null) {
            $actors->keep($request->user());
        }

        try {
            foreach ($moves as $move) {
                try {
                    $result = $moderate->execute(new ModerateCommentInput($move['id'], $move['to'], $actor));
                } catch (AuthorizationException|ValidationException|ModelNotFoundException) {
                    // Deleted, made a tombstone, or on an item that changed
                    // hands since the list was drawn (question 2).
                    $failed++;

                    continue;
                }

                if ($result->changed()) {
                    $moved[] = $result;
                }
            }
        } finally {
            $actors->forget();
        }

        $this->toast($request->boolean('undo'), count($moves), $moved, $failed, $statuses);

        return redirect()->to(CommentsAddress::back());
    }

    /**
     * Flashes the toast for a batch: what moved, what couldn't, and Undo,
     * the same moves back to where each comment was (D11, questions 1 and 2).
     * Nothing moved and nothing failed, every comment already where it was
     * asked to go, says nothing.
     *
     * @param  list<ModeratedComment>  $moved
     */
    private function toast(bool $undo, int $asked, array $moved, int $failed, CommentStatusRegistry $statuses): void
    {
        if ($moved === [] && $failed === 0) {
            return;
        }

        if ($moved === []) {
            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => trans_choice('Nothing was moved, because the comment had changed since you opened the list.|Nothing was moved, because the comments had changed since you opened the list.', $asked),
            ]);

            return;
        }

        $sorry = $failed === 0 ? '' : ' '.trans_choice(':count wasn’t, because it had changed since you opened the list.|:count weren’t, because they had changed since you opened the list.', $failed);
        [$one, $several] = $this->words($moved[0], $statuses);

        if ($undo) {
            $message = __('Undone.').$sorry;
        } elseif ($asked === 1 && $failed === 0) {
            $message = $this->endSentence("{$one}: ".__('comment by :name', ['name' => $moved[0]->authorName ?? __('someone')]));
        } else {
            $message = trans_choice(':count comment|:count comments', count($moved)).' '.$several.'.'.$sorry;
        }

        $toast = ['type' => $failed === 0 ? 'success' : 'warning', 'message' => $message];

        // Back to where the Operation found each comment, never to where the
        // browser last saw it (D9); an Undo has none of its own.
        if (! $undo) {
            $toast['undo'] = [
                'href' => route('comments.moderate', [], false),
                'data' => [
                    'moves' => array_map(fn (ModeratedComment $move): array => ['id' => $move->commentId, 'to' => $move->before], $moved),
                    'undo' => true,
                ],
            ];
        }

        Inertia::flash('toast', $toast);
    }

    /**
     * What a move is called, for one comment and for several (v9's
     * modWords): out of spam to a status that isn't spam or the trash is
     * "Not spam", out of the trash so is "Restored", otherwise the status
     * moved to, and for one a plugin adds, its label.
     *
     * @return array{0: string, 1: string}
     */
    private function words(ModeratedComment $move, CommentStatusRegistry $statuses): array
    {
        $parked = [CommentStatus::SPAM, CommentStatus::TRASH];

        if (! in_array($move->after, $parked, true)) {
            if ($move->before === CommentStatus::SPAM) {
                return [__('Not spam'), __('marked as not spam')];
            }

            if ($move->before === CommentStatus::TRASH) {
                return [__('Restored'), __('restored')];
            }
        }

        if (isset(self::WORDS[$move->after])) {
            return [__(self::WORDS[$move->after][0]), __(self::WORDS[$move->after][1])];
        }

        $label = $statuses->get($move->after)->label;

        return [$label, mb_strtolower($label)];
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
