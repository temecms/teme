<?php

/**
 * A plugin's settings as its area's Settings tab needs them: the same form
 * Settings › From plugins drew for a plugin with an area, without the
 * Settings chrome. Assembles what SettingsController::show assembled for
 * plugin.{code}.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Queries;

use App\Domain\Plugins\Area\CodeScripts;
use App\Domain\Plugins\Area\PluginAreas;
use App\Domain\Plugins\Lists\PluginActions;
use App\Domain\Plugins\Lists\PluginLists;
use App\Domain\Plugins\PluginAccess;
use App\Domain\Plugins\SettingHosts;
use App\Domain\Settings\Queries\SettingsForm;
use App\Domain\Settings\Queries\SettingsPanels;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Users\Roles;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

final readonly class PluginSettingsScreen
{
    /** Lets a person see every settings group (SettingsController::VIEW). */
    private const string VIEW = 'settings.view';

    public function __construct(
        private PluginAreas $areas,
        private SettingsForm $form,
        private SettingHosts $hosts,
        private PluginActions $actions,
        private PluginLists $lists,
        private SettingsRegistry $registry,
    ) {}

    /**
     * The props for a plugin's area Settings tab, when the plugin has an
     * area and a settings group. Null when it has neither, or the group is
     * gone. The caller checks who may open the tab.
     *
     * @return array<string, mixed>|null
     */
    public function for(string $code, User $user, Request $request): ?array
    {
        if (! $this->areas->hasSettingsTab($code)) {
            return null;
        }

        $group = "plugin.{$code}";
        $data = $this->form->find($group);

        if ($data === null || $data['screen'] !== null) {
            return null;
        }

        $ability = $data['ability'];
        $readOnly = ! $user->can($ability);
        unset($data['ability'], $data['screen']);

        $pluginHosts = $this->hosts->forGroup($group);

        return [
            ...$data,
            'heading' => [
                'key' => $group,
                'label' => $data['group']['label'],
                'description' => $data['group']['description'],
            ],
            'section' => null,
            'panels' => SettingsPanels::for($group),
            'words' => null,
            'pluginHosts' => $pluginHosts === null ? null : [
                'plugin' => $pluginHosts['label'],
                'settings' => $pluginHosts['keys'],
                'agreed' => $pluginHosts['agreed'],
                'declared' => $pluginHosts['hosts'],
            ],
            'pluginActions' => $this->actions->forGroup($group),
            'pluginLists' => $this->lists->forGroup($group),
            'pluginScripts' => $this->scripts($group, $data['values'], $request),
            'notice' => null,
            'readOnly' => $readOnly ? [
                'permission' => __('Manage plugins'),
                'holders' => $this->holders($ability),
            ] : null,
            'imageWidths' => null,
            'brandColour' => null,
            'themePerPage' => null,
            'homePage' => null,
        ];
    }

    /**
     * Whether this person may open the Settings tab of a plugin's area:
     * they hold the area's permission (checked by the caller) and either
     * plugins.manage or settings.view.
     */
    public function maySee(User $user): bool
    {
        return $user->can(PluginAccess::MANAGE) || $user->can(self::VIEW);
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @return array{code: string, waiting: array<string, list<array{id: string, script: string}>>, approver: bool}|null
     */
    private function scripts(string $group, array $values, Request $request): ?array
    {
        $definition = $this->registry->find($group);

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

    private function holders(string $ability): string
    {
        $names = [];

        foreach (Roles::LABELS as $role => $label) {
            $found = Role::query()->where('name', $role)->first();

            if ($found !== null && $found->hasPermissionTo($ability)) {
                $names[] = mb_strtolower(__(Str::plural($label)));
            }
        }

        return $names === [] ? __('no role') : Arr::join($names, ', ', ' '.__('and').' ');
    }
}
