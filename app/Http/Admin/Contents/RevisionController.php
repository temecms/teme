<?php

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Ai\Queries\AssistantRevisions;
use App\Domain\Contents\Operations\Input\RestoreRevisionInput;
use App\Domain\Contents\Operations\RestoreRevision;
use App\Domain\Contents\Queries\RevisionComparison;
use App\Domain\Contents\Queries\RevisionHistory;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An article's history: its revisions listed, any two compared, and an
 * older one restored. The domain queries decide who may see them, and the
 * restore goes through its Operation.
 */
final class RevisionController extends Controller
{
    public function index(Request $request, string $content, AssistantRevisions $assistant): JsonResponse
    {
        $after = $request->query('after');
        $page = RevisionHistory::for($content, $request->user(), is_string($after) ? $after : null);

        abort_if($page === null, 403);

        // What wrote the assistant's revisions (I-1307): the option's words for everyone who sees the list, the request's row only for those who may open it.
        $written = $assistant->among(
            array_values(array_map(
                fn (array $row): string => $row['id'],
                array_filter($page->rows, fn (array $row): bool => $row['origin'] === 'ai' || $row['kind'] === 'ai'),
            )),
            $request->user()?->can('ai-activity.view') === true,
        );

        return response()->json([
            'rows' => array_map(fn (array $row): array => [...$row, 'assistant' => $written[$row['id']] ?? null], $page->rows),
            'older' => $page->older,
        ]);
    }

    public function compare(Request $request, string $content): Response
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $comparison = RevisionComparison::between($content, $request->user(), is_string($from) ? $from : null, is_string($to) ? $to : null);

        abort_if($comparison === null, 403);

        return Inertia::render('content/compare', [
            'contentId' => $content,
            'title' => $comparison['title'],
            'workingRevisionId' => $comparison['workingRevisionId'],
            'from' => $comparison['from'],
            'to' => $comparison['to'],
            'blocks' => $comparison['blocks'],
            'fields' => $comparison['fields'],
            'terms' => $comparison['terms'],
        ]);
    }

    /**
     * Restores a revision by adding a new one from it, then returns to the
     * editor. Someone who may not edit the article gets a 403.
     */
    public function restore(Request $request, string $content, string $revision, RestoreRevision $restore): RedirectResponse
    {
        $restore->execute(new RestoreRevisionInput($content, $revision, (string) $request->user()?->getAuthIdentifier()));

        return to_route('content.edit', ['content' => $content]);
    }
}
