<?php

/**
 * A plugin's panel in the editor: what it shows on this item, an action
 * it offers, and the person using or discarding one of its suggestions.
 * Each answers with the panel as it now stands.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Plugins\Editor\PanelReads;
use App\Domain\Plugins\Operations\Input\RunEditorPanelActionInput;
use App\Domain\Plugins\Operations\Input\SettleEditorSuggestionInput;
use App\Domain\Plugins\Operations\RunEditorPanelAction;
use App\Domain\Plugins\Operations\SettleEditorSuggestion;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /**
     * Queues the action's job for this item as this person. A stale
     * revision is 409, as a save is, so the editor's own dialog opens.
     */
    public function action(Request $request, string $content, string $code, string $panel, string $action, RunEditorPanelAction $run, PanelReads $reads): JsonResponse
    {
        $actorId = (string) $request->user()?->getAuthIdentifier();
        $values = $request->input('values', []);

        try {
            $run->execute(new RunEditorPanelActionInput(
                code: $code,
                panel: $panel,
                action: $action,
                contentId: $content,
                revisionId: $request->string('revision_id')->toString(),
                values: is_array($values) ? $values : [],
                actorId: $actorId,
            ));
        } catch (StaleRevisionException) {
            return response()->json(['stale' => true, 'workingRevisionId' => null, 'changedBy' => null, 'changedAt' => null], 409);
        }

        return response()->json($reads->forItem($content, $code, $panel, $actorId));
    }

    /**
     * Tells the plugin the person used or discarded one of its suggestions.
     */
    public function settle(Request $request, string $content, string $code, string $panel, string $suggestion, SettleEditorSuggestion $settle, PanelReads $reads): JsonResponse
    {
        $actorId = (string) $request->user()?->getAuthIdentifier();

        $settle->execute(new SettleEditorSuggestionInput(
            code: $code,
            panel: $panel,
            suggestion: $suggestion,
            contentId: $content,
            used: $request->boolean('used'),
            actorId: $actorId,
        ));

        return response()->json($reads->forItem($content, $code, $panel, $actorId));
    }
}
