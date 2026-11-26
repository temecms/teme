<?php

/**
 * Tells whoever may manage plugins when one isn't doing its work (I-3353):
 * an error when an enabled plugin can't run, else a warning when Teme
 * disabled one, else a warning when one stopped part of its work. A plugin
 * a person or the server disabled says nothing.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Status\Checks;

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginAccess;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginState;
use App\Domain\Plugins\Queries\PluginList;
use App\Domain\Plugins\Queries\PluginRow;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;

final readonly class PluginsRunning implements StatusCheck
{
    public function __construct(
        private PluginList $plugins,
        private PluginRecords $records,
    ) {}

    /**
     * The check's stable key, matched by Home's summary rows.
     *
     * @since 1.6.0
     */
    public function key(): string
    {
        return 'plugins-running';
    }

    public function ability(): string
    {
        return PluginAccess::MANAGE;
    }

    /**
     * @since 0.8.0-alpha.1
     */
    public function label(): string
    {
        return __('Plugins');
    }

    public function banner(): ?StatusBanner
    {
        $enabled = array_map(fn (PluginRecord $record): string => $record->name, $this->records->enabled());

        // Nothing enabled and nothing found: a site without plugins asks nothing more.
        if ($enabled === [] && $this->noneStopped()) {
            return null;
        }

        [$label, $url] = $this->action();
        $rows = $this->plugins->all();
        $broken = array_values(array_filter($rows, fn (PluginRow $row): bool => in_array($row->name, $enabled, true)
            && in_array($row->state, [PluginState::CantBeUsed, PluginState::CodeGone, PluginState::Waiting], true)));

        if ($broken !== []) {
            return new StatusBanner('error', count($broken) === 1
                ? __(':label can’t run: :reason', ['label' => $broken[0]->label, 'reason' => $broken[0]->reason])
                : __(':count enabled plugins can’t run.', ['count' => count($broken)]), $label, $url, 70);
        }

        $stopped = array_values(array_filter($rows, fn (PluginRow $row): bool => $row->state === PluginState::StoppedByTeme));

        if ($stopped !== []) {
            return new StatusBanner('warning', count($stopped) === 1
                ? __('Teme disabled :label after it failed to start five times.', ['label' => $stopped[0]->label])
                : __('Teme disabled :count plugins after they failed to start.', ['count' => count($stopped)]), $label, $url, 50);
        }

        $partly = array_values(array_filter($rows, fn (PluginRow $row): bool => $row->state === PluginState::SomePartsStopped));

        if ($partly !== []) {
            return new StatusBanner('warning', __(':label has stopped part of its work after repeated errors.', ['label' => $partly[0]->label]), $label, $url, 40);
        }

        return null;
    }

    /**
     * The banner's button to the Plugins page, left out on that page itself.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function action(): array
    {
        return request()->routeIs('plugins.*') ? [null, null] : [__('Open Plugins'), route('plugins.index')];
    }

    /**
     * Whether no row is one Teme disabled.
     */
    private function noneStopped(): bool
    {
        return ! PluginRecord::query()->where('disabled_reason', PluginRecord::QUARANTINED)->exists();
    }
}
