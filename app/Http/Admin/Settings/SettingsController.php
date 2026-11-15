<?php

declare(strict_types=1);

namespace App\Http\Admin\Settings;

use App\Domain\Addresses\Operations\Input\ChangeAddressesInput;
use App\Domain\Design\Operations\Input\ChangeBrandColourInput;
use App\Domain\Settings\Exceptions\StaleSettingsException;
use App\Domain\Settings\Operations\Input\SaveSettingsPageInput;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\SaveSettingsPage;
use App\Domain\Settings\Queries\SettingsForm;
use App\Domain\Settings\Queries\SettingsPageSections;
use App\Domain\Settings\Queries\SettingsPanels;
use App\Domain\Settings\SettingsPlacement;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Settings pages. It shows a settings group from its declaration, on the
 * page that draws it, and hands saves to the `SaveSettingsPage` Operation; it
 * changes nothing itself.
 */
final class SettingsController extends Controller
{
    public function show(Request $request, string $group, SettingsForm $form, SettingsPageSections $sections): Response|RedirectResponse
    {
        // A group drawn under another page's name opens on that page, so its
        // own address, such as /admin/settings/content, keeps working.
        $page = SettingsPlacement::pageShowing($group);

        if ($page !== $group) {
            return to_route('settings.show', ['group' => $page]);
        }

        $data = $form->find(SettingsPlacement::groupShownOn($page));

        abort_if($data === null, 404);
        $user = $request->user();

        // abort(), not abort_unless(): PHPStan then knows $user is set.
        if ($user === null || ! $user->can($data['ability'])) {
            abort(403);
        }

        // A group with its own screen is edited there, such as a theme's
        // menu places on Content › Navigation; its address typed by hand
        // goes to it rather than to a form that could never save.
        if ($data['screen'] !== null) {
            return to_route($data['screen']);
        }

        unset($data['ability'], $data['screen']);
        $renamed = SettingsPlacement::RENAMED[$page] ?? null;

        return Inertia::render('settings/group', [
            ...$data,
            // The page's own heading: a renamed group's, else the group's.
            // Not 'page': NoTypeLiteralsTest keeps a type's name off keys.
            'heading' => [
                'key' => $page,
                'label' => $renamed['label'] ?? $data['group']['label'],
                'description' => $renamed['description'] ?? $data['group']['description'],
            ],
            'section' => $renamed['section'] ?? null,
            'panels' => SettingsPanels::for($page),
            ...$sections->for($page, $user),
        ]);
    }

    public function update(Request $request, string $group, SaveSettingsPage $save): RedirectResponse
    {
        /** @var array<string, mixed> $values */
        $values = (array) $request->input('values', []);
        /** @var list<string> $reset */
        $reset = array_values((array) $request->input('reset', []));
        /** @var array<string, ?string> $secrets */
        $secrets = (array) $request->input('secrets', []);

        foreach ((array) $request->input('clear_secrets', []) as $key) {
            $secrets[(string) $key] = null;
        }

        $actorId = (string) $request->user()?->getAuthIdentifier();
        $page = SettingsPlacement::pageShowing($group);

        try {
            $result = $save->execute(new SaveSettingsPageInput(
                group: new UpdateSettingsInput(group: $group, values: $values, reset: $reset, secrets: $secrets, expectedVersion: $request->integer('expected_version'), actorId: $actorId),
                // Sent only when changed: saving the colour also drops one drafted in Design.
                brandColour: $page === 'general' && $request->has('accent')
                    ? new ChangeBrandColourInput(colour: $request->string('accent')->toString(), expectedVersion: $request->integer('accent_version'), actorId: $actorId)
                    : null,
                homePage: $page === 'reading' && $request->has('front_shows')
                    ? new ChangeAddressesInput(patterns: [], bases: [], authorBase: null, frontShows: $request->string('front_shows')->toString(), frontPageId: $request->string('front_page_id')->toString(), postsPageId: $request->string('posts_page_id')->toString(), actorId: $actorId)
                    : null,
            ));
        } catch (StaleSettingsException) {
            throw ValidationException::withMessages([
                'expected_version' => __('These settings were changed by someone else while you had them open. Reload the page and try again.'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $result['recompute']
            ? __('Settings saved. Moving every address in the background.')
            : __('Settings saved.')]);

        return to_route('settings.show', ['group' => $page]);
    }
}
