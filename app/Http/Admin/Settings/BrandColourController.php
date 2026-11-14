<?php

declare(strict_types=1);

namespace App\Http\Admin\Settings;

use App\Domain\Design\Operations\ChangeBrandColour;
use App\Domain\Design\Operations\Input\ChangeBrandColourInput;
use App\Domain\Settings\Exceptions\StaleSettingsException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * General's Brand colour: saved at once by ChangeBrandColour, every refusal
 * under the field, so General's own form shows none of them. It changes
 * nothing itself.
 */
final class BrandColourController extends Controller
{
    public function __invoke(Request $request, ChangeBrandColour $change): RedirectResponse
    {
        try {
            $change->execute(new ChangeBrandColourInput(
                colour: $request->string('accent')->toString(),
                expectedVersion: $request->integer('expected_version'),
                actorId: (string) $request->user()?->getAuthIdentifier(),
            ));
        } catch (StaleSettingsException) {
            // Keyed to the field, not expected_version, which General's own form shows.
            throw ValidationException::withMessages([
                'accent' => __('These settings were changed by someone else while you had them open. Reload the page and try again.'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brand colour saved.')]);

        return back(fallback: route('settings.show', ['group' => 'general']));
    }
}
