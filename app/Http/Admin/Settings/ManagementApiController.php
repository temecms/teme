<?php

declare(strict_types=1);

namespace App\Http\Admin\Settings;

use App\Domain\ManageApi\ManageApiSettings;
use App\Domain\ManageApi\Operations\CreateApiKey;
use App\Domain\ManageApi\Operations\Input\CreateApiKeyInput;
use App\Domain\ManageApi\Operations\Input\RevokeApiKeyInput;
use App\Domain\ManageApi\Operations\RevokeApiKey;
use App\Domain\ManageApi\Queries\ManagementApiScreen;
use App\Domain\Settings\Exceptions\StaleSettingsException;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Management API (docs/proposals/management-api.md): the switch
 * and the limit, saved through UpdateSettings, and the keys, made by
 * CreateApiKey and revoked by RevokeApiKey. A new key's secret is answered
 * as JSON, once, and never reaches the session.
 */
final class ManagementApiController extends Controller
{
    public function show(ManagementApiScreen $screen): Response
    {
        return Inertia::render('settings/management-api', $screen->data());
    }

    public function update(Request $request, UpdateSettings $updateSettings): RedirectResponse
    {
        /** @var array<string, mixed> $values */
        $values = array_intersect_key((array) $request->input('values', []), array_flip([ManageApiSettings::ENABLED, ManageApiSettings::PER_MINUTE]));

        try {
            $updateSettings->execute(new UpdateSettingsInput(
                group: ManageApiSettings::KEY,
                values: $values,
                reset: [],
                secrets: [],
                expectedVersion: $request->integer('expected_version'),
                actorId: (string) $request->user()?->getAuthIdentifier(),
            ));
        } catch (StaleSettingsException) {
            throw ValidationException::withMessages([
                'expected_version' => __('These settings were changed by someone else while you had them open. Reload the page and try again.'),
            ]);
        }

        $message = match (true) {
            ! array_key_exists(ManageApiSettings::ENABLED, $values) => __('Management API settings saved.'),
            (bool) $values[ManageApiSettings::ENABLED] => __('The management API is on. Outside tools can sign in with a key.'),
            default => __('The management API is off. Every key is refused until you turn it back on.'),
        };

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('settings.management-api');
    }

    public function store(Request $request, CreateApiKey $create): JsonResponse
    {
        $expires = $request->input('expires_at');

        $made = $create->execute(new CreateApiKeyInput(
            name: (string) $request->input('name', ''),
            userId: (string) $request->input('user_id', ''),
            abilities: array_values(array_filter((array) $request->input('abilities', []), 'is_string')),
            expiresAt: is_string($expires) && $expires !== '' ? $expires : null,
            actorId: (string) $request->user()?->getAuthIdentifier(),
        ));

        return response()->json(['name' => $made->key->name, 'secret' => $made->secret], 201, ['Cache-Control' => 'no-store']);
    }

    public function destroy(Request $request, string $key, RevokeApiKey $revoke): RedirectResponse
    {
        $revoke->execute(new RevokeApiKeyInput($key, (string) $request->user()?->getAuthIdentifier()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Key revoked. Tools using it are refused from now on.')]);

        return to_route('settings.management-api');
    }
}
