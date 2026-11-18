<?php

/**
 * The run report's section for each plugin that received data
 * (docs/proposals/plugin-import-socket.md): its records brought, skipped
 * and failed, by plugin, in the order of their codes. It writes nothing.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\PluginData;

use App\Domain\Import\ImportOutcome;
use App\Domain\Plugins\PluginRegistry;
use Illuminate\Support\Facades\DB;

final readonly class PluginDataReport
{
    public function __construct(private PluginRegistry $registry) {}

    /**
     * Returns a row for each plugin this run handed records to: its type
     * (`plugin:<code>`), its label, and its counts by outcome; empty when it handed none.
     *
     * @return list<array{type: string, label: string, created: int, updated: int, skipped: int, failed: int}>
     */
    public function rows(string $runId): array
    {
        $counted = [];

        foreach (DB::table('import_map')->where('run_id', $runId)->where('object_type', PluginDataLists::TYPE)
            ->selectRaw("split_part(object_id, ':', 1) AS code, outcome, count(*) AS total")->groupBy('code', 'outcome')->get() as $row) {
            $counted[(string) $row->code][(string) $row->outcome] = (int) $row->total;
        }

        ksort($counted, SORT_STRING);
        $labels = [];

        foreach ($counted === [] ? [] : $this->registry->all() as $plugin) {
            $labels[$plugin->code] = $plugin->manifest?->label() ?? '';
        }

        $rows = [];

        foreach ($counted as $code => $outcomes) {
            $label = ($labels[$code] ?? '') !== '' ? $labels[$code] : $code;
            $rows[] = [
                'type' => PluginDataLists::TYPE.':'.$code,
                'label' => "{$label} (plugin)",
                'created' => $outcomes[ImportOutcome::Created->value] ?? 0,
                'updated' => $outcomes[ImportOutcome::Updated->value] ?? 0,
                'skipped' => $outcomes[ImportOutcome::Skipped->value] ?? 0,
                'failed' => $outcomes[ImportOutcome::Failed->value] ?? 0,
            ];
        }

        return $rows;
    }
}
