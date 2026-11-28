<?php

/**
 * A plugin's own area (docs/plans/plugin-sockets-2.md, A6): its pages, each
 * block's data deferred so one slow block never holds the page, a record's
 * form, and the writes, each through its Operation as the signed-in person.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Extensions\PackagePictures;
use App\Domain\Plugins\Area\AreaEntries;
use App\Domain\Plugins\Area\AreaPlugin;
use App\Domain\Plugins\Area\AreaWords;
use App\Domain\Plugins\Area\CodeScripts;
use App\Domain\Plugins\Area\PluginAreas;
use App\Domain\Plugins\Lists\PluginActions;
use App\Domain\Plugins\Operations\ActOnPluginRecords;
use App\Domain\Plugins\Operations\Input\ActOnPluginRecordsInput;
use App\Domain\Plugins\Operations\Input\RunPluginActionInput;
use App\Domain\Plugins\Operations\Input\SavePluginRecordInput;
use App\Domain\Plugins\Operations\RunPluginAction;
use App\Domain\Plugins\Operations\SavePluginRecord;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Queries\PluginAreaScreen;
use App\Domain\Plugins\Queries\PluginRecordForm;
use App\Domain\Plugins\Queries\PluginSettingsScreen;
use App\Domain\Site\Insertions\Operations\ApprovePluginScript;
use App\Domain\Site\Insertions\Operations\Input\ApprovePluginScriptInput;
use App\Domain\Site\Insertions\Scripts\ScriptRequests;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PluginAreaController extends Controller
{
    public function show(Request $request, string $code, PluginAreaScreen $screen, PluginSettingsScreen $settings, PluginAreas $areas, ?string $page = null): Response
    {
        $plugin = $this->plugin($request, $code, $screen);
        $user = $request->user();
        $settingsTab = $user !== null && $areas->hasSettingsTab($code) && $settings->maySee($user);
        $frame = $screen->frame($plugin, $page, settingsTab: $settingsTab);

        abort_if($frame === null, 404);

        $personId = (string) $user?->getAuthIdentifier();
        $periodId = $request->query('period');
        $periodId = is_string($periodId) ? $periodId : null;
        $period = $screen->period($plugin, $frame['current']['id'], $personId, $periodId);
        /** @var array<string, array<string, mixed>> $asks */
        $asks = (array) $request->query('t', []);
        $data = [];

        foreach ($frame['blocks'] as $block) {
            $id = (string) $block['id'];

            if (in_array($block['type'], ['figures', 'chart', 'table'], true)) {
                $ask = is_array($asks[$id] ?? null) ? $asks[$id] : [];
                // Each block in a group of its own, so each loads on its own (A6).
                $data["block_{$id}"] = Inertia::defer(fn (): ?array => $screen->data($plugin, $frame['current']['id'], $id, $personId, $ask, $period['id'] ?? null), "block_{$id}");
            }
        }

        $noun = $this->waitingNoun($plugin, $frame['blocks']);

        return Inertia::render('plugins/area', [
            ...$frame,
            // The top bar's parent: Tools for an area placed there, none for one in the menu.
            'parent' => app(AreaEntries::class)->onTools($code) ? ['title' => __('Tools'), 'href' => route('tools.index', [], false)] : null,
            'asks' => $asks,
            'period' => $period,
            'counts' => Inertia::defer(fn (): ?array => $screen->counts($plugin, $personId), 'counts'),
            'waitingScripts' => ScriptRequests::waiting($plugin->code),
            'waitingNoun' => $noun['singular'],
            'waitingNouns' => $noun['plural'],
            'reviewScripts' => route('settings.site-code', absolute: false),
            ...$data,
        ]);
    }

    /**
     * The icon the plugin declares, served to whoever may open its area. An
     * SVG is sent so that, opened on its own, it can run and fetch nothing.
     */
    public function icon(Request $request, string $code, PluginAreaScreen $screen, PluginRegistry $registry): BinaryFileResponse
    {
        $plugin = $this->plugin($request, $code, $screen);
        $found = $registry->find($plugin->name);
        $icon = $found?->manifest === null ? null : PackagePictures::find($found->path, $found->manifest->icon());

        if ($icon === null) {
            abort(404);
        }

        return response()->file($icon['path'], PackagePictures::headers($icon['type']));
    }

    /**
     * A plugin's settings as the last tab of its area: the same form Settings
     * drew for plugin.{code}, behind the area's permission and plugins.manage
     * or settings.view.
     */
    public function settings(Request $request, string $code, PluginAreaScreen $screen, PluginSettingsScreen $settings, PluginAreas $areas): Response
    {
        $plugin = $this->plugin($request, $code, $screen);
        $user = $request->user();

        abort_if($user === null || ! $areas->hasSettingsTab($code), 404);
        abort_unless($settings->maySee($user), 403);

        $props = $settings->for($code, $user, $request);

        abort_if($props === null, 404);

        $frame = $screen->frame($plugin, null, settingsTab: true, settingsCurrent: true);

        abort_if($frame === null, 404);

        return Inertia::render('plugins/settings', [
            ...$frame,
            'parent' => app(AreaEntries::class)->onTools($code) ? ['title' => __('Tools'), 'href' => route('tools.index', [], false)] : null,
            ...$props,
            // Keep the area frame's plugin object; settings props must not replace it.
            'plugin' => $frame['plugin'],
        ]);
    }

    public function create(Request $request, string $code, string $record, PluginAreaScreen $screen, PluginRecordForm $form): Response
    {
        return $this->form($request, $code, $record, null, $screen, $form);
    }

    public function edit(Request $request, string $code, string $record, string $id, PluginAreaScreen $screen, PluginRecordForm $form): Response
    {
        return $this->form($request, $code, $record, $id, $screen, $form);
    }

    public function save(Request $request, string $code, string $record, SavePluginRecord $save, ?string $id = null): RedirectResponse
    {
        /** @var array<array-key, mixed> $values */
        $values = (array) $request->input('values', []);

        $saved = $save->execute(new SavePluginRecordInput($code, $record, $id, $values, (string) $request->user()?->getAuthIdentifier()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return to_route('plugins.area.edit', ['code' => $code, 'record' => $record, 'id' => $saved['id']]);
    }

    public function act(Request $request, string $code, string $record, ActOnPluginRecords $act): RedirectResponse
    {
        $done = $act->execute(new ActOnPluginRecordsInput(
            code: $code,
            record: $record,
            action: $request->string('action')->toString(),
            ids: array_values((array) $request->input('ids', [])),
            confirmed: $request->boolean('confirmed'),
            actorId: (string) $request->user()?->getAuthIdentifier(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('Done for :count record.|Done for :count records.', $done['count'], ['count' => $done['count']])]);

        // A delete from the record's own form leaves for the page listing it.
        return $request->boolean('leave') ? redirect($this->listing($code, $record)) : back();
    }

    public function action(Request $request, string $code, string $action, PluginAreas $areas, PluginActions $actions, RunPluginAction $run): RedirectResponse
    {
        $plugin = $areas->find($code);
        // The area's permission answers only for an action its own pages offer (A6).
        $offered = $plugin !== null && in_array($action, $plugin->area->offeredActions(), true);
        $recordId = $request->filled('record') ? $request->string('record')->toString() : null;
        $name = $request->filled('name') ? $request->string('name')->toString() : null;

        try {
            $queued = $run->execute(new RunPluginActionInput(
                $code,
                $action,
                (string) $request->user()?->getAuthIdentifier(),
                $offered ? $plugin->ability() : null,
                $recordId,
            ));
        } catch (AuthorizationException|ValidationException $refused) {
            $message = $refused instanceof ValidationException ? (string) collect($refused->errors())->flatten()->first() : $refused->getMessage();
            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return back();
        }

        $label = $queued['label'];

        if ($recordId !== null && $name !== null) {
            $parts = explode(' ', $label, 2);
            $verb = (string) preg_replace('/…$/u', '', $parts[0]);
            $declared = $actions->find($code, $action);
            $pluginName = $plugin !== null ? $plugin->label : $code;
            $toast = ($declared['confirm'] ?? null) !== null
                ? __(':verb queued for “:name”. Its figures show zero once :plugin has finished.', [
                    'verb' => $verb,
                    'name' => $name,
                    'plugin' => $pluginName,
                ])
                : __(':verb queued for “:name”. Its status updates when :plugin has finished.', [
                    'verb' => $verb,
                    'name' => $name,
                    'plugin' => $pluginName,
                ]);
        } else {
            $toast = __(':label queued.', ['label' => $label]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $toast]);

        return back();
    }

    /**
     * Approves one of this plugin's waiting scripts where it was written
     * (the decided Q2): the operation asks who may change site code, and for
     * the password, which a form asking in place gets as a 423.
     */
    public function approveScript(Request $request, string $code, string $script, ApprovePluginScript $approve): RedirectResponse
    {
        abort_unless(CodeScripts::belongs($script, $code), 404);

        try {
            $approved = $approve->execute(new ApprovePluginScriptInput($script, (string) $request->user()?->getAuthIdentifier()));
        } catch (AuthorizationException|ValidationException $refused) {
            $message = $refused instanceof ValidationException ? (string) collect($refused->errors())->flatten()->first() : $refused->getMessage();
            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Script approved for :name.', ['name' => $approved['name']])]);

        return back();
    }

    private function form(Request $request, string $code, string $record, ?string $id, PluginAreaScreen $screen, PluginRecordForm $form): Response
    {
        $plugin = $this->plugin($request, $code, $screen);
        $user = $request->user();
        $personId = (string) $user?->getAuthIdentifier();
        $found = $form->find($plugin, $record, $id, $personId);

        abort_if($found === null, 404);

        $header = $id === null ? null : $screen->header($plugin, $record, $id, $personId);

        return Inertia::render('plugins/record', [
            'plugin' => ['code' => $plugin->code, 'name' => $plugin->label, 'icon' => $screen->iconUrl($plugin)],
            // Where the form's top bar leads: the page listing these records.
            'back' => $this->listing($code, $record),
            // The area's label in the crumbs (design system 4.31), not the plugin's name.
            'area' => ['label' => AreaWords::text($plugin->code, $plugin->area->label)],
            ...$found,
            'header' => $header,
            // The code fields' scripts that wait, and whether this person may approve them here (the decided Q2).
            'scripts' => [
                'waiting' => CodeScripts::waiting($plugin->code, CodeScripts::inArea($found['record']['label'], $plugin->area->fields($record), $found['values'])),
                'approver' => CodeScripts::approver($user),
            ],
        ]);
    }

    /**
     * The waiting-script banner's noun from the page's first table record,
     * else the area's first declared record, else "item" / "items".
     *
     * @param  list<array<string, mixed>>  $blocks
     *
     * @return array{singular: string, plural: string}
     */
    private function waitingNoun(AreaPlugin $plugin, array $blocks): array
    {
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) !== 'table' || ! is_array($block['record'] ?? null)) {
                continue;
            }

            $singular = is_string($block['record']['label'] ?? null) ? mb_strtolower($block['record']['label']) : '';
            $plural = is_string($block['record']['plural'] ?? null) ? mb_strtolower($block['record']['plural']) : '';

            if ($singular !== '' && $plural !== '') {
                return ['singular' => $singular, 'plural' => $plural];
            }
        }

        $record = $plugin->area->records[0] ?? null;

        if (is_array($record)) {
            $singular = is_string($record['label'] ?? null) ? mb_strtolower(AreaWords::text($plugin->code, $record['label'])) : '';
            $plural = is_string($record['plural'] ?? null) ? mb_strtolower(AreaWords::text($plugin->code, $record['plural'])) : '';

            if ($singular !== '' && $plural !== '') {
                return ['singular' => $singular, 'plural' => $plural];
            }
        }

        return ['singular' => __('item'), 'plural' => __('items')];
    }

    /**
     * The address of the area's page whose table lists this record, or the
     * area's first page.
     */
    private function listing(string $code, string $record): string
    {
        $plugin = app(PluginAreas::class)->find($code);
        $table = $plugin?->area->tables($record)[0] ?? null;
        $page = $table === null ? null : array_find($plugin->area->pages, fn (array $one): bool => in_array($table, $one['blocks'], true));

        return route('plugins.area.show', [$code, $page['id'] ?? null], false);
    }

    /**
     * The plugin's area, when the person may open it: 404 for a plugin with
     * no area, 403 for one whose permission they don't hold. The plugin is
     * never called for a refused person.
     */
    private function plugin(Request $request, string $code, PluginAreaScreen $screen): AreaPlugin
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $plugin = $screen->plugin($code, $user);

        if ($plugin === null) {
            abort(app(PluginAreas::class)->find($code) === null ? 404 : 403);
        }

        return $plugin;
    }
}
