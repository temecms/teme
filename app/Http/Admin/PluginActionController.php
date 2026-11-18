<?php

/**
 * The Actions panel on a plugin's settings page: a click runs the
 * RunPluginAction Operation and answers with a toast that links to
 * Tools › Jobs. It changes nothing itself.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Plugins\Operations\Input\RunPluginActionInput;
use App\Domain\Plugins\Operations\RunPluginAction;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class PluginActionController extends Controller
{
    public function run(Request $request, string $code, string $action, RunPluginAction $run): RedirectResponse
    {
        try {
            $queued = $run->execute(new RunPluginActionInput($code, $action, (string) $request->user()?->getAuthIdentifier()));
        } catch (AuthorizationException|ValidationException $refused) {
            $message = $refused instanceof ValidationException ? (string) collect($refused->errors())->flatten()->first() : $refused->getMessage();
            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return to_route('settings.show', ['group' => "plugin.{$code}"]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':label queued. Follow it in Tools › Jobs.', ['label' => $queued['label']]),
            'link' => ['label' => __('Tools › Jobs'), 'href' => route('tools.jobs.index', [], false)],
        ]);

        return to_route('settings.show', ['group' => "plugin.{$code}"]);
    }
}
