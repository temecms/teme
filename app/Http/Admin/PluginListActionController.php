<?php

/**
 * The plugins' actions on the content lists (contributes.listActions): a
 * row's menu or the bulk bar posts here, and the RunListAction Operation
 * queues the job as the person. It answers with a toast and changes nothing itself.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Plugins\Operations\Input\RunListActionInput;
use App\Domain\Plugins\Operations\RunListAction;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class PluginListActionController extends Controller
{
    public function run(Request $request, string $code, string $action, RunListAction $run): RedirectResponse
    {
        $items = $request->input('items', $request->input('item'));
        $items = is_array($items) ? array_values(array_map(strval(...), array_filter($items, is_scalar(...)))) : (is_scalar($items) ? [(string) $items] : []);

        try {
            $done = $run->execute(new RunListActionInput($code, $action, (string) $request->user()?->getAuthIdentifier(), $items, bulk: $request->boolean('bulk')));
        } catch (AuthorizationException|ValidationException $refused) {
            $message = $refused instanceof ValidationException ? (string) collect($refused->errors())->flatten()->first() : $refused->getMessage();
            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return back();
        }

        // The link to Tools › Jobs is offered only to people who can open it.
        $canFollow = (bool) $request->user()?->can('jobs.manage');
        $message = $done['skipped'] === 0
            ? ($canFollow ? __(':label queued. Follow it in Tools › Jobs.', ['label' => $done['label']]) : __(':label queued.', ['label' => $done['label']]))
            : __(':label queued for the items you can edit; :count left out.', ['label' => $done['label'], 'count' => $done['skipped']]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $message,
            ...($canFollow ? ['link' => ['label' => __('Tools › Jobs'), 'href' => route('tools.jobs.index', [], false)]] : []),
        ]);

        return back();
    }
}
