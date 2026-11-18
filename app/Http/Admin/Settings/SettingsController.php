<?php

declare(strict_types=1);

namespace App\Http\Admin\Settings;

use App\Domain\Addresses\Operations\Input\ChangeAddressesInput;
use App\Domain\Design\Operations\Input\ChangeBrandColourInput;
use App\Domain\Languages\Operations\Input\SaveSettingsWordsInput;
use App\Domain\Languages\Operations\SaveSettingsWords;
use App\Domain\Languages\Queries\SettingsWords;
use App\Domain\Plugins\Area\CodeScripts;
use App\Domain\Plugins\Lists\PluginActions;
use App\Domain\Plugins\Lists\PluginLists;
use App\Domain\Plugins\SettingHosts;
use App\Domain\Settings\Exceptions\StaleSettingsException;
use App\Domain\Settings\Operations\Input\SaveSettingsPageInput;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\SaveSettingsPage;
use App\Domain\Settings\Queries\SettingsForm;
use App\Domain\Settings\Queries\SettingsPageSections;
use App\Domain\Settings\Queries\SettingsPanels;
use App\Domain\Settings\SettingsPlacement;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Updates\Updater\PlatformCheck;
use App\Domain\Updates\Updater\PlatformRefused;
use App\Domain\Updates\UpdatesSettings;
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
    public function show(Request $request, string $group, SettingsForm $form, SettingsPageSections $sections, SettingHosts $hosts, PluginActions $actions, PluginLists $lists, SettingsRegistry $registry): Response|RedirectResponse
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
            // "In other languages" (docs/proposals/multilingual-screens.md, 6): null on a site with one language.
            'words' => SettingsWords::for(SettingsPlacement::groupShownOn($page)),
            // A plugin's hosts from its settings (docs/plans/plugin-sockets-1.md, A2): null for every other group.
            'pluginHosts' => $this->pluginHosts($hosts, SettingsPlacement::groupShownOn($page)),
            // A plugin's own actions and lists, drawn below its settings (docs/proposals/plugin-sockets-1.md).
            'pluginActions' => $actions->forGroup(SettingsPlacement::groupShownOn($page)),
            'pluginLists' => $lists->forGroup(SettingsPlacement::groupShownOn($page)),
            // A plugin's code settings' scripts that wait, and whether this person may approve them here.
            'pluginScripts' => $this->pluginScripts($registry, SettingsPlacement::groupShownOn($page), $data['values'], $request),
            // One sentence above the form when something needs the person: on Updates, why the updater can't use the
            // Dokploy key it has, or that it has none. Null on every other page.
            'notice' => $page === UpdatesSettings::GROUP ? self::updatesNotice(app(PlatformCheck::class)->sentence()) : null,
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
                group: new UpdateSettingsInput(group: $group, values: $values, reset: $reset, secrets: $secrets, expectedVersion: $request->integer('expected_version'), actorId: $actorId, agreedHosts: array_values(array_filter($request->array('agreedHosts'), is_string(...)))),
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

    /**
     * The updater's sentence as Settings › Updates says it: a missing key
     * points to the field below, not to the page the person is already on
     * (issue 534). Any other sentence as the updater gave it.
     */
    private static function updatesNotice(?string $sentence): ?string
    {
        return $sentence === PlatformRefused::NO_KEY
            ? __('Updating from Tools › Updates needs a Dokploy API key. Paste it below.')
            : $sentence;
    }

    /**
     * What the page needs to ask before a plugin reaches a host entered in
     * its settings: the plugin's label, the settings it takes hosts from,
     * the hosts agreed in them, and its manifest's agreed hosts; null when
     * the group isn't such a plugin's. The server stays the judge.
     *
     * @return array{plugin: string, settings: list<string>, agreed: list<string>, declared: list<string>}|null
     */
    private function pluginHosts(SettingHosts $hosts, string $group): ?array
    {
        $plugin = $hosts->forGroup($group);

        return $plugin === null ? null : [
            'plugin' => $plugin['label'],
            'settings' => $plugin['keys'],
            'agreed' => $plugin['agreed'],
            'declared' => $plugin['hosts'],
        ];
    }

    /**
     * A plugin's code settings' inline scripts that wait for approval, by the
     * field's path, with the plugin's code and whether this person may
     * approve them in place; null when the group isn't a plugin's.
     *
     * @param  array<string, mixed>  $values
     *
     * @return array{code: string, waiting: array<string, list<array{id: string, script: string}>>, approver: bool}|null
     */
    private function pluginScripts(SettingsRegistry $registry, string $group, array $values, Request $request): ?array
    {
        $definition = str_starts_with($group, 'plugin.') ? $registry->find($group) : null;

        if ($definition === null) {
            return null;
        }

        $code = substr($group, strlen('plugin.'));

        return [
            'code' => $code,
            'waiting' => CodeScripts::waiting($code, CodeScripts::inSettings($definition->settings, $values)),
            'approver' => CodeScripts::approver($request->user()),
        ];
    }

    /**
     * Saves a group's words in other languages through SaveSettingsWords,
     * every language or none; an empty word falls back to the default
     * language's.
     */
    public function words(Request $request, string $group, SaveSettingsWords $save): RedirectResponse
    {
        $words = [];

        foreach ((array) $request->input('words', []) as $language => $values) {
            if (is_string($language) && is_array($values)) {
                $words[$language] = array_map(fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', $values);
            }
        }

        try {
            $save->execute(new SaveSettingsWordsInput($group, $words, $request->integer('expected_version'), (string) $request->user()?->getAuthIdentifier()));
        } catch (StaleSettingsException) {
            throw ValidationException::withMessages([
                'expected_version' => __('These settings were changed by someone else while you had them open. Reload the page and try again.'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved the words in other languages.')]);

        return back(fallback: route('settings.show', ['group' => $group], false));
    }
}
