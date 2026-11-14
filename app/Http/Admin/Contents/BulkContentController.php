<?php

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Contents\BulkEditItem;
use App\Domain\Contents\Operations\DeleteContentPermanently;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\RestoreContent;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Contents\Queries\ContentPointers;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Users\Actors;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use LogicException;
use Throwable;

/**
 * Bulk actions on the page selection (I-143): at most 100 items, each through
 * its own Operation in its own transaction, each authorized on its own. What
 * could not be done is reported item by item, never dropped silently. Bulk
 * Edit answers JSON, because the browser sends it in batches and adds their
 * reports up; every other action redirects with the `bulk` flash.
 */
final class BulkContentController extends Controller
{
    private const array ACTIONS = ['trash', 'restore', 'delete', 'publish', 'unpublish', 'author', 'add_term', 'remove_term', 'edit'];

    public function __invoke(Request $request, string $type, ContentPointers $pointers): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:'.implode(',', self::ACTIONS)],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string', 'distinct'],
            'term_id' => ['required_if:action,add_term,remove_term', 'nullable', 'string'],
            'author_id' => ['required_if:action,author', 'nullable', 'integer'],
            'add_terms' => ['array', 'max:50'],
            'add_terms.*' => ['string'],
            'remove_terms' => ['array', 'max:50'],
            'remove_terms.*' => ['string'],
            'status' => ['required_if:action,edit', 'nullable', 'in:keep,draft,published'],
            'discussion' => ['nullable', 'in:keep,open,closed,hidden'],
        ]);

        /** @var list<string> $ids */
        $ids = array_values($validated['ids']);
        $contentType = app(ContentTypeRegistry::class)->bySegment($type) ?? abort(404);
        $actor = (string) $request->user()?->getAuthIdentifier();
        $known = $pointers->forIds($ids);
        $titles = (array) $request->input('titles', []);
        $done = 0;
        $changed = [];
        $skipped = [];

        // The person, with their roles and permissions, read once for the
        // batch (B-201), and forgotten whatever happens.
        $actors = app(Actors::class);

        if ($request->user() !== null) {
            $actors->keep($request->user());
        }

        try {
            foreach ($ids as $id) {
                // An item of another type is skipped on its own, like any item
                // that can't be changed, rather than failing the whole request.
                if (isset($known[$id]) && $known[$id]['type'] !== $contentType->key) {
                    $skipped[] = ['id' => $id, 'title' => (string) ($titles[$id] ?? $id), 'reason' => __('Not a :type.', ['type' => mb_strtolower($contentType->singular)])];

                    continue;
                }

                try {
                    $line = $this->apply($validated, $id, $actor, $known[$id] ?? null);
                    $done++;

                    if ($line !== null) {
                        $changed[] = ['id' => $id, 'title' => (string) ($titles[$id] ?? $id), 'summary' => $line];
                    }
                } catch (Throwable $exception) {
                    $skipped[] = ['id' => $id, 'title' => (string) ($titles[$id] ?? $id), 'reason' => BulkEditItem::reason($exception)];
                }
            }
        } finally {
            $actors->forget();
        }

        if ($validated['action'] === 'edit') {
            return response()->json(['done' => $done, 'changed' => $changed, 'skipped' => $skipped]);
        }

        $request->session()->flash('bulk', ['done' => $done, 'skipped' => $skipped]);
        Inertia::flash('toast', ['type' => $skipped === [] ? 'success' : 'info', 'message' => $this->doneMessage((string) $validated['action'], $done, $contentType)]);

        return back(fallback: route('content.index', ['type' => $type]));
    }

    /**
     * What the toast says a bulk action did: the bar's three buttons in
     * their own words, naming the type; any other action, and nothing done,
     * as a count of items changed.
     */
    private function doneMessage(string $action, int $done, ContentType $contentType): string
    {
        // Later (I-1450): Undo after moving items to the trash, as the toast's own undo does for comments.
        $noun = mb_strtolower($done === 1 ? $contentType->singular : $contentType->plural);

        return match (true) {
            $done === 0 => trans_choice(':count item changed.|:count items changed.', 0),
            $action === 'trash' => __('Moved :count :noun to the trash.', ['count' => $done, 'noun' => $noun]),
            $action === 'restore' => __('Restored :count :noun.', ['count' => $done, 'noun' => $noun]),
            $action === 'delete' => __('Deleted :count :noun permanently.', ['count' => $done, 'noun' => $noun]),
            default => trans_choice(':count item changed.|:count items changed.', $done),
        };
    }

    /**
     * Applies the action to one item, and returns Bulk Edit's line for the
     * report; the other actions report only their skips, so return null.
     *
     * @param  array<string, mixed>  $validated
     * @param  array{type: string, status: string, workingRevisionId: string|null, terms: list<string>}|null  $pointer
     */
    private function apply(array $validated, string $id, string $actor, ?array $pointer): ?string
    {
        if ($pointer === null) {
            throw new ModelNotFoundException;
        }

        if ($validated['action'] === 'edit') {
            return app(BulkEditItem::class)->apply(
                $id,
                $pointer,
                $actor,
                array_values(array_map(strval(...), (array) ($validated['add_terms'] ?? []))),
                array_values(array_map(strval(...), (array) ($validated['remove_terms'] ?? []))),
                isset($validated['author_id']) ? (int) $validated['author_id'] : null,
                (string) $validated['status'],
                (string) ($validated['discussion'] ?? 'keep'),
            );
        }

        $input = new ContentActionInput($id, $actor);
        $working = (string) $pointer['workingRevisionId'];

        match ((string) $validated['action']) {
            'trash' => app(TrashContent::class)->execute($input),
            'restore' => app(RestoreContent::class)->execute($input),
            'delete' => app(DeleteContentPermanently::class)->execute($input),
            'unpublish' => app(UnpublishContent::class)->execute($input),
            'publish' => app(PublishRevision::class)->execute(new PublishRevisionInput($id, $working, $actor)),
            'author' => app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($id, $working, $actor, authorId: (int) $validated['author_id'])),
            'add_term' => app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($id, $working, $actor, terms: array_values(array_unique([...$pointer['terms'], (string) $validated['term_id']])))),
            'remove_term' => app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($id, $working, $actor, terms: array_values(array_diff($pointer['terms'], [(string) $validated['term_id']])))),
            default => throw new LogicException('Validation admits only the actions listed in ACTIONS.'),
        };

        return null;
    }
}
