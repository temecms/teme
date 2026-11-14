<?php

declare(strict_types=1);

namespace App\Http\Admin\Settings;

use App\Domain\Ai\AiAvailability;
use App\Domain\Ai\AiSettings;
use App\Domain\Ai\Queries\AiSettingsScreen;
use App\Domain\Settings\Exceptions\StaleSettingsException;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › AI (I-1308): a screen of its own for the `ai` group, saved by
 * UpdateSettings as the generic form saves any group. It changes nothing
 * itself, and no key it is given reaches the session.
 */
final class AiSettingsController extends Controller
{
    public function show(Request $request, AiSettingsScreen $screen): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        return Inertia::render('settings/ai', $screen->data($user));
    }

    public function update(Request $request, UpdateSettings $updateSettings, AiAvailability $availability): RedirectResponse
    {
        // A host that switched AI off changes nothing here (I-1308).
        abort_unless($availability->enabled(), 403);

        /** @var array<string, mixed> $values */
        $values = (array) $request->input('values', []);
        $secrets = [];

        foreach ((array) $request->input('secrets', []) as $key => $secret) {
            $secrets[(string) $key] = is_string($secret) ? trim($secret) : '';
        }

        foreach ((array) $request->input('clear_secrets', []) as $key) {
            if (is_string($key)) {
                $secrets[$key] = null;
            }
        }

        // A refused save flashes the request's input into the session, which
        // is stored as written; a typed key must never be (I-315).
        $request->replace(Arr::except($request->all(), ['secrets', 'clear_secrets']));

        $addsKey = array_filter($secrets, fn (?string $secret): bool => $secret !== null && $secret !== '') !== [];

        try {
            $updateSettings->execute(new UpdateSettingsInput(
                group: AiSettings::KEY,
                values: $values,
                reset: [],
                secrets: $secrets,
                expectedVersion: $request->integer('expected_version'),
                actorId: (string) $request->user()?->getAuthIdentifier(),
            ));
        } catch (StaleSettingsException) {
            throw ValidationException::withMessages([
                'expected_version' => __('These settings were changed by someone else while you had them open. Reload the page and try again.'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $addsKey
            ? __('AI settings saved. Check key tells you whether the new key works.')
            : __('AI settings saved.')]);

        return to_route('settings.ai');
    }
}
