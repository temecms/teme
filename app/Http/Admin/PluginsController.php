<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\MigratePluginInput;
use App\Domain\Plugins\Operations\Input\RemovePluginDataInput;
use App\Domain\Plugins\Operations\MigratePlugin;
use App\Domain\Plugins\Operations\RemovePluginData;
use App\Domain\Plugins\PluginRegistry;
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

/**
 * Site › Plugins: every plugin, what it asks for and where it stands, and
 * the enable, migrate and disable buttons, which call their Operations.
 * It changes nothing itself and starts no plugin: the page reads files only.
 */
final class PluginsController extends Controller
{
    public function index(PluginsScreen $screen, PluginRegistry $registry, SettingHosts $hosts): Response
    {
        return Inertia::render('plugins/index', ['plugins' => $screen->rows(), 'settingHosts' => $this->settingHosts($registry, $hosts)]);
    }

    /**
     * Enables a plugin with what the dialog showed. A refusal, such as a
     * plugin that now asks for something different, is a toast.
     */
    public function enable(EnablePluginRequest $request, string $code, PluginList $list, EnablePlugin $enable): RedirectResponse
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

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name enabled. Its contributions appear where they belong.', ['name' => $row->label])]);

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
            : __(':name’s database changes ran.', ['name' => $row->label])]);

        return to_route('plugins.index');
    }

    /**
     * Drops a disabled plugin's recorded tables.
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
     * The plugin the code names, or a 404.
     */
    /**
     * Each plugin that takes hosts from its settings, by name: the settings'
     * labels and the hosts they name now, which the consent dialog shows
     * and posts back (docs/plans/plugin-sockets-1.md, A2).
     *
     * @return array<string, array{settings: list<string>, hosts: list<string>}>
     */
    private function settingHosts(PluginRegistry $registry, SettingHosts $hosts): array
    {
        $found = [];

        foreach ($registry->all() as $plugin) {
            if ($plugin->manifest !== null && SettingHosts::declared($plugin->manifest) !== []) {
                $found[$plugin->name] = ['settings' => SettingHosts::labels($plugin->manifest), 'hosts' => $hosts->current($plugin->manifest)];
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
}
