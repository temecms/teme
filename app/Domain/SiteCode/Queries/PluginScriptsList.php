<?php

/**
 * What Settings › Site code lists under "Scripts from plugins"
 * (docs/plans/plugin-sockets-2.md, B4): every inline script a plugin asked
 * to keep, with what was decided about it and by whom. It reads only.
 *
 * @package Teme
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\SiteCode\Queries;

use App\Domain\Plugins\PluginRegistry;
use App\Domain\Site\Insertions\Scripts\PluginScript;
use App\Models\User;
use Carbon\CarbonImmutable;

final readonly class PluginScriptsList
{
    public function __construct(private PluginRegistry $plugins) {}

    /**
     * Every plugin's script, the waiting ones first, then the newest asked:
     * each its ID, the plugin's code and name, the field it was written in,
     * its text, its state, and who asked and decided, and when (ISO 8601,
     * UTC; null where nobody has decided).
     *
     * @return list<array{id: string, plugin: string, pluginName: string, name: string, script: string, state: string, requestedBy: string, requestedAt: string, decidedBy: string|null, decidedAt: string|null}>
     */
    public function all(): array
    {
        $scripts = PluginScript::query()
            ->orderByRaw('state = ? desc', [PluginScript::WAITING])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->get();

        $people = User::query()
            ->whereIn('id', [...$scripts->pluck('requested_by')->filter()->all(), ...$scripts->pluck('decided_by')->filter()->all()])
            ->pluck('name', 'id')
            ->all();
        $who = fn (?int $id): string => $id === null ? __('a removed account') : (string) ($people[$id] ?? __('a removed account'));

        $labels = [];

        foreach ($this->plugins->all() as $plugin) {
            if ($plugin->manifest !== null) {
                $labels[$plugin->code] = $plugin->manifest->label();
            }
        }

        return $scripts->map(fn (PluginScript $script): array => [
            'id' => (string) $script->id,
            'plugin' => $script->plugin_code,
            'pluginName' => $labels[$script->plugin_code] ?? $script->plugin_code,
            'name' => $script->name,
            'script' => $script->script,
            'state' => $script->state,
            'requestedBy' => $who($script->requested_by),
            'requestedAt' => CarbonImmutable::parse($script->requested_at)->utc()->toIso8601String(),
            'decidedBy' => $script->decided_at === null ? null : $who($script->decided_by),
            'decidedAt' => $script->decided_at?->utc()->toIso8601String(),
        ])->values()->all();
    }
}
