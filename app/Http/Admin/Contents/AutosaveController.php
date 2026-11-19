<?php

/**
 * Keeps the editor's draft while the person types. A JSON endpoint rather
 * than an Inertia one, because it fires while typing and must not re-render
 * the page. It hands the draft to SaveAutosave and changes nothing itself.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Contents\Operations\Input\SaveAutosaveInput;
use App\Domain\Contents\Operations\SaveAutosave;
use App\Domain\Contents\Queries\RevisionAuthor;
use App\Domain\Contents\SaveAutosaveResult;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AutosaveController extends Controller
{
    public function store(Request $request, string $content, SaveAutosave $save): JsonResponse
    {
        // The editor numbers its saves; anything else is left out, as from an older editor.
        $editor = $request->input('editor_session');
        $number = $request->input('save_number');
        $numbered = is_string($editor) && $editor !== '' && strlen($editor) <= 64 && is_int($number) && $number > 0;

        /** @var SaveAutosaveResult $result */
        $result = $save->execute(new SaveAutosaveInput(
            $content,
            $request->string('title')->toString(),
            (array) $request->input('document', []),
            (array) $request->input('fields', []),
            array_values(array_map('strval', (array) $request->input('terms', []))),
            $request->input('based_on_revision_id') === null ? null : (string) $request->input('based_on_revision_id'),
            (string) $request->user()?->getAuthIdentifier(),
            editorSession: $numbered ? $editor : null,
            saveNumber: $numbered ? $number : null,
        ));

        // A stale save says who changed the article and when (Task 5).
        $author = $result->stale ? RevisionAuthor::of($result->workingRevisionId) : null;

        return response()->json([
            'savedAt' => $result->savedAt,
            'stale' => $result->stale,
            'workingRevisionId' => $result->workingRevisionId,
            'otherEditors' => $result->otherEditors,
            'links' => $result->links,
            'changedBy' => $author['name'] ?? null,
            'changedAt' => $author['at'] ?? null,
        ]);
    }
}
