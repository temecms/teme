<?php

declare(strict_types=1);

namespace App\Http\Admin\Settings;

use App\Domain\Seo\Queries\SearchAndSocialScreen;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\WholeSite;
use App\Domain\Settings\Exceptions\StaleSettingsException;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Search and social (I-874): a screen of its own, drawn from the
 * group's sections, and saved by UpdateSettings as the generic form saves
 * any group; it changes nothing itself.
 */
final class SearchAndSocialController extends Controller
{
    public function show(Request $request, SearchAndSocialScreen $screen): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        return Inertia::render('settings/search-and-social', $screen->data($user));
    }

    public function update(Request $request, UpdateSettings $updateSettings): RedirectResponse
    {
        /** @var array<string, mixed> $values */
        $values = (array) $request->input('values', []);
        $wasDiscouraged = WholeSite::discouraged();

        try {
            $updateSettings->execute(new UpdateSettingsInput(
                group: SearchAndSocial::KEY,
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

        // Read again: the change has committed and the group's cache is forgotten.
        $discouraged = WholeSite::discouraged();

        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            $discouraged === $wasDiscouraged => __('Search and social settings saved.'),
            $discouraged => __('Search and social settings saved. Search engines are asked to stay away from every page, and the sitemap is off.'),
            default => __('Search and social settings saved. Search engines may list the site again, and the sitemap is back.'),
        }]);

        return to_route('settings.seo');
    }
}
