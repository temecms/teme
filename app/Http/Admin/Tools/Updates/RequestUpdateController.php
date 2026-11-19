<?php

/**
 * Update to … and Roll back on Tools › Updates (I-3326): asks RequestTemeUpdate to record the request,
 * which asks the password again, and says when the updater starts it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools\Updates;

use App\Domain\Updates\Operations\Input\RequestTemeUpdateInput;
use App\Domain\Updates\Operations\RequestTemeUpdate;
use App\Domain\Updates\Requests\UpdateKind;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class RequestUpdateController extends Controller
{
    public function __invoke(Request $request, RequestTemeUpdate $update): RedirectResponse
    {
        $body = $request->validate([
            'version' => ['required', 'string', 'max:40'],
            'kind' => ['required', Rule::in(array_column(UpdateKind::cases(), 'value'))],
        ]);
        $kind = UpdateKind::from($body['kind']);

        try {
            $update->execute(new RequestTemeUpdateInput($body['version'], $kind, (string) $request->user()?->getAuthIdentifier()));
        } catch (ValidationException $refused) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) collect($refused->errors())->flatten()->first()]);

            return to_route('tools.updates.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $kind === UpdateKind::Rollback
            ? __('Roll back requested. The updater starts it within a minute.')
            : __('Update requested. The updater starts it within a minute.')]);

        return to_route('tools.updates.index');
    }
}
