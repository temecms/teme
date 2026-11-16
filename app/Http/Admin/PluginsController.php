<?php

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
use App\Domain\Plugins\Queries\PluginList;
use App\Domain\Plugins\Queries\PluginRow;
use App\Domain\Plugins\Queries\PluginsScreen;
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
    public function index(Request $request, PluginsScreen $screen, ExtensionsScreen $extensions): Response
    {
        return Inertia::render('plugins/index', [
            'plugins' => $screen->rows(),
            // Installing from the admin (I-211), for whoever may.
            'extensions' => $request->user()?->can(ExtensionsAccess::INSTALL) === true ? $extensions->data() : null,
        ]);
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
     * Forgets a disabled plugin whose package is gone, freeing its code.
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
