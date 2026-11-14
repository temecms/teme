<?php

declare(strict_types=1);

namespace App\Http\Admin\Comments;

use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\CommentStatusRegistry;
use App\Domain\Comments\Emptying;
use App\Domain\Comments\EmptyingResult;
use App\Domain\Comments\Jobs\EmptyComments;
use App\Domain\Comments\Queries\CommentList;
use App\Domain\Comments\Queries\EmptyingRequest;
use App\Domain\Settings\Modules;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Empty spam and Empty trash on the Comments screen (1.8c′'s D4 to D7): what
 * the list showed as it was opened, deleted permanently, the first part at
 * once and the rest on the queue. Nothing to undo; the confirmation came
 * first.
 */
final class CommentEmptyController extends Controller
{
    /** Comments deleted in the request before the rest go to the queue (D5). */
    private const int IN_REQUEST = 1000;

    public function __construct(private readonly int $inRequest = self::IN_REQUEST) {}

    public function __invoke(Request $request, Emptying $emptying, CommentList $list, CommentStatusRegistry $statuses): RedirectResponse
    {
        abort_unless(Modules::on(Modules::COMMENTS), 404);

        $validated = $request->validate([
            'view' => ['required', 'string', Rule::in($statuses->deletable())],
            'item' => ['nullable', 'string', 'size:26'],
            'search' => ['nullable', 'string', 'max:200'],
            'openedAt' => ['required', 'string', 'date'],
        ]);

        // The list's moment, and never later than now (D7).
        $now = CarbonImmutable::now()->utc();
        $opened = CarbonImmutable::parse($validated['openedAt'])->utc();
        $search = trim((string) ($validated['search'] ?? ''));

        $emptied = new EmptyingRequest(
            viewerId: (int) $request->user()?->getAuthIdentifier(),
            view: $validated['view'],
            itemId: $validated['item'] ?? null,
            search: $search === '' ? null : $search,
            cutoff: $opened->greaterThan($now) ? $now : $opened,
        );

        $result = $emptying->run($emptied, $this->inRequest);

        // Counted before the queue is handed the rest, which may run at once.
        $left = $result->more ? $list->emptying($emptied)['count'] : 0;

        if ($left > 0) {
            EmptyComments::dispatch($emptied->viewerId, $emptied->view, $emptied->itemId, $emptied->search, $emptied->cutoff->format('Y-m-d\TH:i:s.u\Z'));
        }

        $this->toast($emptied->view, $result, $left);

        return redirect()->to(CommentsAddress::back());
    }

    /**
     * Flashes what an Empty press did (D5, question 2): nothing deleted, a
     * warning; the rest on the queue, an info with both counts; otherwise the
     * view emptied, with the tombstones kept and what couldn't be, a warning
     * when any couldn't. Never an Undo.
     */
    private function toast(string $view, EmptyingResult $result, int $left): void
    {
        if ($result->deleted === 0) {
            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => __('Nothing was deleted, because the comments had changed since you opened the list.'),
            ]);

            return;
        }

        $deleted = trans_choice(':count comment|:count comments', $result->deleted);

        if ($left > 0) {
            $prefix = $view === CommentStatus::SPAM ? __('Emptying spam') : __('Emptying the trash');

            Inertia::flash('toast', [
                'type' => 'info',
                'message' => "{$prefix}: ".__('deleted :comments permanently.', ['comments' => $deleted]).' '.__('Teme is deleting the other :count now.', ['count' => $left]),
            ]);

            return;
        }

        $message = ($view === CommentStatus::SPAM ? __('Emptied spam:') : __('Emptied the trash:')).' '.__('deleted :comments permanently.', ['comments' => $deleted]);

        if ($result->tombstones > 0) {
            $message .= ' '.trans_choice(':count stays as “This comment was removed”, so its replies keep their place.|:count stay as “This comment was removed”, so their replies keep their place.', $result->tombstones);
        }

        if ($result->failed > 0) {
            $message .= ' '.trans_choice(':count wasn’t, because it had changed since you opened the list.|:count weren’t, because they had changed since you opened the list.', $result->failed);
        }

        Inertia::flash('toast', ['type' => $result->failed === 0 ? 'success' : 'warning', 'message' => $message]);
    }
}
