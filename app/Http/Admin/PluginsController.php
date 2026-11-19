<?php

/**
 * Site › Plugins: every plugin, what it asks for and where it stands, and
 * the enable, migrate and disable buttons, which call their Operations.
 * It changes nothing itself and starts no plugin: the page reads files only.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Extensions\ExtensionsAccess;
use App\Domain\Extensions\Queries\ExtensionsScreen;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\ForgetPlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\ForgetPluginInput;
use App\Domain\Plugins\Operations\Input\MigratePluginInput;
use App\Domain\Plugins\Operations\Input\RemovePluginDataInput;
use App\Domain\Plugins\Operations\MigratePlugin;
use App\Domain\Plugins\Operations\RemovePluginData;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\PluginSentences;
use App\Domain\Plugins\PluginWords;
use App\Domain\Plugins\Queries\PluginList;
use App\Domain\Plugins\Queries\PluginRow;
use App\Domain\Plugins\Queries\PluginsScreen;
use App\Domain\Plugins\SettingHosts;
use App\Http\Admin\Requests\EnablePluginRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Teme\Contracts\Plugins\Capability;

final class PluginsController extends Controller
{
    public function index(Request $request, PluginsScreen $screen, ExtensionsScreen $extensions, PluginRegistry $registry, SettingHosts $hosts): Response
    {
        // Core's own words only: a plugin's language file never re-words what a person agrees to (docs/plans/plugin-sockets-1.md, A-W14).
        return app(PluginSentences::class)->coreOnly(fn (): Response => Inertia::render('plugins/index', [
            'plugins' => $screen->rows(),
            'settingHosts' => $this->settingHosts($registry, $hosts),
            // Installing from the admin (I-211), for whoever may.
            'extensions' => $request->user()?->can(ExtensionsAccess::INSTALL) === true ? $extensions->data() : null,
        ]));
    }

    /**
     * Enables a plugin with what the dialog showed. A refusal, such as a
     * plugin that now asks for something different, is a toast.
     */
    public function enable(EnablePluginRequest $request, string $code, PluginList $list, EnablePlugin $enable): RedirectResponse
    {
        return app(PluginSentences::class)->coreOnly(fn (): RedirectResponse => $this->enableAs($request, $code, $list, $enable));
    }

    private function enableAs(EnablePluginRequest $request, string $code, PluginList $list, EnablePlugin $enable): RedirectResponse
    {
        $row = $this->row($list, $code);

        try {
            $enable->execute(new EnablePluginInput(
                name: $row->name,
                version: $request->string('version')->toString(),
                capabilities: array_values($request->array('capabilities')),
                hosts: array_values($request->array('hosts')),
                hooks: array_values($request->array('hooks')),
                actorId: (string) $request->user()?->getAuthIdentifier(),
                settingHosts: array_values(array_filter($request->array('settingHosts'), is_string(...))),
            ));
        } catch (ValidationException $refused) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) collect($refused->errors())->flatten()->first()]);

            return to_route('plugins.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trim(__(':name enabled. Its contributions appear where they belong.', ['name' => $row->label]).' '.$list->typesReport($row->name))]);

        return to_route('plugins.index');
    }

    public function disable(Request $request, string $code, PluginList $list, DisablePlugin $disable): RedirectResponse
    {
        $row = $this->row($list, $code);

        try {
            $disable->execute(new DisablePluginInput($row->name, (string) $request->user()?->getAuthIdentifier()));
        } catch (ValidationException $refused) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) collect($refused->errors())->flatten()->first()]);

            return to_route('plugins.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name disabled. Everything it added to the admin is gone until you enable it; its data is kept.', ['name' => $row->label])]);

        return to_route('plugins.index');
    }

    /**
     * Runs waiting database changes for an enabled plugin.
     *
     * @since 0.8.0-alpha.1
     */
    public function migrate(Request $request, string $code, PluginList $list, MigratePlugin $migrate): RedirectResponse
    {
        $row = $this->row($list, $code);

        try {
            $ran = $migrate->execute(new MigratePluginInput($row->name, (string) $request->user()?->getAuthIdentifier()));
        } catch (ValidationException $refused) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) collect($refused->errors())->flatten()->first()]);

            return to_route('plugins.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $ran === []
            ? __(':name had no database changes waiting.', ['name' => $row->label])
            : trim(__(':name’s database changes ran.', ['name' => $row->label]).' '.(array_filter($ran, fn (string $ran): bool => $ran === 'types' || str_ends_with($ran, '.json')) === [] ? '' : $list->typesReport($row->name)))]);

        return to_route('plugins.index');
    }

    /**
     * Drops a disabled plugin's recorded tables.
     *
     * @since 0.8.0-alpha.1
     */
    public function removeData(Request $request, string $code, PluginList $list, RemovePluginData $remove): RedirectResponse
    {
        $row = $this->row($list, $code);

        try {
            $tables = $remove->execute(new RemovePluginDataInput($row->name, (string) $request->user()?->getAuthIdentifier()));
        } catch (ValidationException $refused) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) collect($refused->errors())->flatten()->first()]);

            return to_route('plugins.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name’s data was removed (:n tables).', ['name' => $row->label, 'n' => count($tables)])]);

        return to_route('plugins.index');
    }

    /**
     * Forgets a disabled plugin whose package is gone, freeing its code.
     *
     * @since 1.0.0
     */
    public function forget(Request $request, string $code, PluginList $list, ForgetPlugin $forget): RedirectResponse
    {
        $name = $request->string('name')->toString();
        $row = $this->rowByName($list, $name, $code);

        try {
            $forget->execute(new ForgetPluginInput($row->name, (string) $request->user()?->getAuthIdentifier()));
        } catch (ValidationException $refused) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) collect($refused->errors())->flatten()->first()]);

            return to_route('plugins.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was forgotten. Its code is free for another package.', ['name' => $row->label])]);

        return to_route('plugins.index');
    }

    /**
     * The plugin the code names, or a 404.
     */
    /**
     * Each plugin that takes hosts from its settings, by name: the settings'
     * labels and the hosts they name now, which the consent dialog posts
     * back, and the outbound line it shows in place of the plain one, with
     * the hosts under it (docs/plans/plugin-sockets-1.md, A2).
     *
     * @return array<string, array{settings: list<string>, hosts: list<string>, replaces: string, line: string, now: string}>
     */
    private function settingHosts(PluginRegistry $registry, SettingHosts $hosts): array
    {
        $found = [];

        foreach ($registry->all() as $plugin) {
            if ($plugin->manifest !== null && SettingHosts::declared($plugin->manifest) !== []) {
                $labels = SettingHosts::labels($plugin->manifest);
                $now = $hosts->current($plugin->manifest);
                $found[$plugin->name] = [
                    'settings' => $labels,
                    'hosts' => $now,
                    'replaces' => PluginWords::capability(Capability::Http->value, $plugin->manifest->hosts()),
                    'line' => PluginWords::httpWithSettings($plugin->manifest->hosts(), $labels),
                    'now' => PluginWords::settingHostsNow($now),
                ];
            }
        }

        return $found;
    }

    private function row(PluginList $list, string $code): PluginRow
    {
        foreach ($list->all() as $row) {
            if ($row->code === $code) {
                return $row;
            }
        }

        abort(404);
    }

    /**
     * The plugin this name and code name together, so a renamed package that
     * reuses the code does not steal the forget request, or a 404.
     */
    private function rowByName(PluginList $list, string $name, string $code): PluginRow
    {
        foreach ($list->all() as $row) {
            if ($row->name === $name && $row->code === $code) {
                return $row;
            }
        }

        abort(404);
    }
}
