<?php

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Contents\BulkRunWords;
use App\Domain\Contents\Operations\Input\StartBulkRunInput;
use App\Domain\Contents\Operations\StartBulkRun;
use App\Domain\Types\ContentTypeRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Starts Select all matching from a content list (I-143): the action and the list's filters, through StartBulkRun, which refuses what the tab can't take in words the bar shows. It changes nothing itself.
 */
final class BulkMatchingController extends Controller
{
    public function __invoke(Request $request, string $type, StartBulkRun $start, BulkRunWords $words): RedirectResponse
    {
        $contentType = app(ContentTypeRegistry::class)->bySegment($type) ?? abort(404);

        $validated = $request->validate([
            'action' => ['required', 'string'],
            'filters' => ['sometimes', 'array'],
        ]);

        // Its ValidationException goes back to the bar under `action`, and its AuthorizationException is a 403.
        $started = $start->execute(new StartBulkRunInput(
            $contentType->key,
            (string) $validated['action'],
            $validated['filters'] ?? [],
            (string) $request->user()?->getAuthIdentifier(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => $words->started($started['label'])]);

        return back(fallback: route('content.index', ['type' => $type]));
    }
}
