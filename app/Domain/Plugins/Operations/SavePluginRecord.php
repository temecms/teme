<?php

/**
 * Saves a record in a plugin's area (docs/plans/plugin-sockets-2.md, A8):
 * for a person who holds the area's permission, the values checked against
 * the record's declared form, hidden fields left out, then handed to the
 * plugin inside this transaction. Never as the operator, never with
 * elevation. Returns the record's id and whether anything changed.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Plugins\Area\AreaContexts;
use App\Domain\Plugins\Area\AreaForms;
use App\Domain\Plugins\Area\AreaWords;
use App\Domain\Plugins\Area\CodeScripts;
use App\Domain\Plugins\Operations\Concerns\WritesPluginArea;
use App\Domain\Plugins\Operations\Input\SavePluginRecordInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\RecordHosts;
use App\Domain\Plugins\SettingHosts;
use App\Domain\Support\Operations\BaseOperation;
use Teme\Contracts\Admin\PluginArea;
use Teme\Contracts\Admin\Saved;
use Teme\Contracts\Operations\OperationInput;

final class SavePluginRecord extends BaseOperation
{
    use WritesPluginArea;

    /** The budget of one write, in milliseconds. */
    public const int WRITE_BUDGET_MS = 2_000;

    protected function authorize(OperationInput $input): void
    {
        /** @var SavePluginRecordInput $input */
        $this->writable($input->code, $input->record, $input->actorId);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SavePluginRecordInput $input */
        $plugin = $this->writable($input->code, $input->record, $input->actorId);

        AreaForms::validated($plugin->area->fields($input->record), $input->values, $input->actorId, $plugin->label);
    }

    /**
     * @return array{id: string, changed: bool, waiting: list<string>}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var SavePluginRecordInput $input */
        $plugin = $this->writable($input->code, $input->record, $input->actorId);
        $values = AreaForms::validated($plugin->area->fields($input->record), $input->values, $input->actorId, $plugin->label);
        $context = AreaContexts::for($input->code, $input->actorId);
        $record = AreaWords::text($plugin->code, (string) ($plugin->area->record($input->record)['label'] ?? ''));

        // The code fields' inline scripts wait for approval, asked in this transaction (A-T9).
        CodeScripts::request($plugin->code, CodeScripts::inArea($record, $plugin->area->fields($input->record), $values), $input->actorId, 'plugins.area.show', ['code' => $plugin->code]);

        $saved = $this->write($plugin, fn (PluginArea $data): Saved => $data->save($input->record, $input->id, $values, $context));

        // Only the url fields the manifest's hostsFrom declares name hosts, and only those the form sent (a hidden field is not sent).
        $manifest = app(PluginRegistry::class)->find($plugin->name)?->manifest;
        $declaredKeys = $manifest === null ? [] : SettingHosts::recordKeys($manifest, $input->record);
        $sent = array_values(array_intersect($declaredKeys, array_keys($input->values)));
        $title = $values[$plugin->area->record($input->record)['titleField'] ?? ''] ?? '';
        $waiting = RecordHosts::noteSaved($plugin->name, $saved->id, is_string($title) && $title !== '' ? $title : $record, SettingHosts::hostsIn($values, $sent));

        return ['id' => $saved->id, 'changed' => $saved->changed, 'waiting' => $waiting];
    }
}
