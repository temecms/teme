<?php

/**
 * The inline scripts in a plugin's code fields that may hold them
 * (docs/plans/plugin-sockets-2.md, A-T9): asked for when a form or the
 * plugin's settings are saved, and, on the form, which of them wait and
 * whether the person may approve them there.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Site\Insertions\Scripts\ApprovedScripts;
use App\Domain\Site\Insertions\Scripts\PluginScript;
use App\Domain\Site\Insertions\Scripts\ScriptRequests;
use App\Domain\SiteCode\SiteCode;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Teme\Contracts\Notices\NoticeMessage;
use Teme\Contracts\Notices\Notices;
use Teme\Contracts\Plugins\Capability;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingType;

final class CodeScripts
{
    /** The kind of notice the people who may approve a plugin's script get. */
    public const string NOTICE = 'site_code.script_waiting';

    /**
     * Whether this plugin may ask for scripts: it is enabled and inserts
     * content, the only place its scripts would run.
     */
    public static function mayAsk(string $pluginCode): bool
    {
        $capabilities = PluginRecord::query()->where('code', $pluginCode)->where('state', PluginRecord::ENABLED)->first()?->consent['capabilities'] ?? [];

        return in_array(Capability::ContentInsert->value, $capabilities, true) || in_array(Capability::PagesInsert->value, $capabilities, true);
    }

    /**
     * Asks, for this person, that each inline script in these fields be
     * approved for the plugin, under the field's name. Runs inside the
     * save's transaction, so a refused save asks for nothing. When the save
     * asks for a script that wasn't waiting, the people who may approve it,
     * but the person saving, get one notice, sent after commit, linking to
     * the page where it can be approved.
     *
     * @param  array<string, array{name: string, markup: string}>  $fields  by the field's path
     * @param  array<string, string>  $parameters  the linked route's parameters
     */
    public static function request(string $pluginCode, array $fields, string $actorId, string $route, array $parameters): void
    {
        if ($fields === [] || ! self::mayAsk($pluginCode)) {
            return;
        }

        // The first field that asked for a script not waiting before: the notice names it.
        $asked = null;

        foreach ($fields as $field) {
            $name = mb_substr($field['name'], 0, 80);
            $before = ScriptRequests::waiting($pluginCode);
            ScriptRequests::fromMarkup($pluginCode, $name, $field['markup'], $actorId);
            $asked ??= ScriptRequests::waiting($pluginCode) > $before ? $name : null;
        }

        if ($asked !== null && ! SiteCode::disallowed()) {
            self::tellApprovers($pluginCode, $asked, $actorId, $route, $parameters);
        }
    }

    /**
     * Sends the people who may change site code, but the person saving,
     * one notice that a script saved in this field waits for approval.
     *
     * @param  array<string, string>  $parameters
     */
    private static function tellApprovers(string $pluginCode, string $field, string $actorId, string $route, array $parameters): void
    {
        $approvers = User::permission(SiteCode::ABILITY)->whereKeyNot($actorId)->pluck('id')->map(strval(...))->all();

        if ($approvers === []) {
            return;
        }

        $name = PluginRecord::query()->where('code', $pluginCode)->value('name');
        $plugin = (is_string($name) ? app(PluginRegistry::class)->find($name)?->manifest->label() : null) ?? $pluginCode;

        app(Notices::class)->send(self::NOTICE, $approvers, new NoticeMessage(
            subject: __('A script from :plugin waits for approval', ['plugin' => $plugin]),
            lines: [
                __('Someone saved an inline script in :field, in :plugin.', ['field' => $field, 'plugin' => $plugin]),
                __('It doesn’t run on the site’s pages until someone who may change the site’s code approves it, where it was written.'),
            ],
            actionLabel: __('Review the script'),
            actionRoute: $route,
            actionParameters: $parameters,
        ));
    }

    /**
     * The scripts of these markups that wait for approval, each its ID and
     * text, by the field's path; a path with none is left out.
     *
     * @param  array<string, array{name: string, markup: string}>  $fields  by the field's path
     *
     * @return array<string, list<array{id: string, script: string}>>
     */
    public static function waiting(string $pluginCode, array $fields): array
    {
        $waiting = [];

        foreach ($fields as $path => $field) {
            $hashes = array_map(ApprovedScripts::hash(...), ScriptRequests::inlineScripts($field['markup']));

            if ($hashes === []) {
                continue;
            }

            $found = PluginScript::query()
                ->where('plugin_code', $pluginCode)
                ->where('state', PluginScript::WAITING)
                ->whereIn('hash', $hashes)
                ->orderBy('id')
                ->get(['id', 'script'])
                ->map(fn (PluginScript $script): array => ['id' => (string) $script->id, 'script' => (string) $script->script])
                ->all();

            if ($found !== []) {
                $waiting[$path] = array_values($found);
            }
        }

        return $waiting;
    }

    /**
     * Whether this script is one this plugin asked for.
     */
    public static function belongs(string $scriptId, string $pluginCode): bool
    {
        return PluginScript::query()->whereKey($scriptId)->where('plugin_code', $pluginCode)->exists();
    }

    /**
     * Whether this person may approve a plugin's script where it was written:
     * they may change site code, and the host allows it.
     */
    public static function approver(?Authenticatable $user): bool
    {
        return $user instanceof User && $user->can(SiteCode::ABILITY) && ! SiteCode::disallowed();
    }

    /**
     * The code fields that may hold scripts and hold something, each its
     * name (the record's label, then the field's) and markup, by its path
     * (values.{key}, or values.{key}.{n}.{sub} in a repeater's items).
     *
     * @param  list<array<string, mixed>>  $fields  as DeclaredArea::fields() gives them
     * @param  array<array-key, mixed>  $values
     *
     * @return array<string, array{name: string, markup: string}>
     */
    public static function inArea(string $owner, array $fields, array $values, string $path = 'values'): array
    {
        $markups = [];

        foreach ($fields as $field) {
            $key = (string) $field['key'];
            $value = $values[$key] ?? null;

            if ($field['type'] === 'code' && ($field['scripts'] ?? false) === true && is_string($value) && $value !== '') {
                $markups["{$path}.{$key}"] = ['name' => "{$owner}: ".(string) $field['label'], 'markup' => $value];
            }

            if ($field['type'] === 'repeater' && is_array($value) && is_array($field['fields'] ?? null)) {
                /** @var list<array<string, mixed>> $subs */
                $subs = array_values($field['fields']);

                foreach (array_values($value) as $index => $item) {
                    if (is_array($item)) {
                        $markups = [...$markups, ...self::inArea($owner, $subs, $item, "{$path}.{$key}.{$index}")];
                    }
                }
            }
        }

        return $markups;
    }

    /**
     * The code settings that may hold scripts and hold something, each its
     * name and markup, by its path (values.{key}, or values.{key}.{n}.{sub}
     * in a repeater setting's items).
     *
     * @param  list<Setting>  $settings
     * @param  array<array-key, mixed>  $values  as they will be stored
     *
     * @return array<string, array{name: string, markup: string}>
     */
    public static function inSettings(array $settings, array $values, string $path = 'values'): array
    {
        $markups = [];

        foreach ($settings as $setting) {
            $value = $values[$setting->key] ?? null;

            if ($setting->type === SettingType::Code && $setting->scripts && is_string($value) && $value !== '') {
                $markups["{$path}.{$setting->key}"] = ['name' => __('Settings: :label', ['label' => $setting->label]), 'markup' => $value];
            }

            if ($setting->type === SettingType::Repeater && is_array($value)) {
                foreach (array_values($value) as $index => $item) {
                    if (is_array($item)) {
                        $markups = [...$markups, ...self::inSettings($setting->fields, $item, "{$path}.{$setting->key}.{$index}")];
                    }
                }
            }
        }

        return $markups;
    }
}
