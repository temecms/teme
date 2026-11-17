<?php

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Plugins\Editor\PanelReads;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A plugin's panel in the editor: what it shows on this item, an action
 * it offers, and the person using or discarding one of its suggestions.
 * Each answers with the panel as it now stands.
 */
final class EditorPanelController extends Controller
{
    /**
     * The panel's status, suggestions and jobs on this item; 403 for a
     * person who doesn't see it, without asking the plugin.
     */
    public function show(Request $request, string $content, string $code, string $panel, PanelReads $reads): JsonResponse
    {
        $view = $reads->forItem($content, $code, $panel, (string) $request->user()?->getAuthIdentifier());

        abort_if($view === null, 403);

        return response()->json($view);
    }
}
