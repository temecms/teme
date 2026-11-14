<?php

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Contents\Queries\EditorSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one answer to a publishing decision. The editor asks for JSON and gets
 * the message and the article's new state, so it stays open and keeps what
 * is being typed; anything else — a list's row action — goes back with the
 * message as a toast.
 */
final class EditorAnswer
{
    public static function to(Request $request, string $contentId, string $actorId, string $message): Response
    {
        if (! $request->expectsJson()) {
            Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

            return back();
        }

        $session = EditorSession::for($contentId, $actorId);

        return response()->json([
            'message' => $message,
            'status' => $session?->status,
            'publishedAt' => $session?->publishedAt,
            'unpublishedChanges' => $session?->unpublishedChanges,
            'schedule' => $session?->schedule,
            'review' => $session?->review,
        ]);
    }
}
