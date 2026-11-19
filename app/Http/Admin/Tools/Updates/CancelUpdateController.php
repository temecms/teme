<?php

/**
 * Cancel the update on Tools › Updates (I-3326): stops a request the updater hasn't begun. No password is asked.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools\Updates;

use App\Domain\Updates\Operations\CancelTemeUpdate;
use App\Domain\Updates\Operations\Input\CancelTemeUpdateInput;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class CancelUpdateController extends Controller
{
    public function __invoke(Request $request, CancelTemeUpdate $cancel, string $update): RedirectResponse
    {
        try {
            $cancel->execute(new CancelTemeUpdateInput($update, (string) $request->user()?->getAuthIdentifier()));
        } catch (ValidationException $refused) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) collect($refused->errors())->flatten()->first()]);

            return to_route('tools.updates.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The update was cancelled.')]);

        return to_route('tools.updates.index');
    }
}
