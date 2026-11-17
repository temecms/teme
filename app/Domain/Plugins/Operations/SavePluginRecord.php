<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Plugins\Area\AreaContexts;
use App\Domain\Plugins\Area\AreaForms;
use App\Domain\Plugins\Area\AreaWords;
use App\Domain\Plugins\Area\CodeScripts;
use App\Domain\Plugins\Operations\Concerns\WritesPluginArea;
use App\Domain\Plugins\Operations\Input\SavePluginRecordInput;
use App\Domain\Support\Operations\BaseOperation;
use Teme\Contracts\Admin\PluginArea;
use Teme\Contracts\Admin\Saved;
use Teme\Contracts\Operations\OperationInput;

/**
 * Saves a record in a plugin's area (docs/plans/plugin-sockets-2.md, A8):
 * for a person who holds the area's permission, the values checked against
 * the record's declared form, hidden fields left out, then handed to the
 * plugin inside this transaction. Never as the operator, never with
 * elevation. Returns the record's id and whether anything changed.
 */
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

        AreaForms::validated($plugin->area->fields($input->record), $input->values);
    }

    /**
     * @return array{id: string, changed: bool}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var SavePluginRecordInput $input */
        $plugin = $this->writable($input->code, $input->record, $input->actorId);
        $values = AreaForms::validated($plugin->area->fields($input->record), $input->values);
        $context = AreaContexts::for($input->code, $input->actorId);
        $record = AreaWords::text($plugin->code, (string) ($plugin->area->record($input->record)['label'] ?? ''));

        // The code fields' inline scripts wait for approval, asked in this transaction (A-T9).
        CodeScripts::request($plugin->code, CodeScripts::inArea($record, $plugin->area->fields($input->record), $values), $input->actorId, 'plugins.area.show', ['code' => $plugin->code]);

        $saved = $this->write($plugin, fn (PluginArea $data): Saved => $data->save($input->record, $input->id, $values, $context));

        return ['id' => $saved->id, 'changed' => $saved->changed];
    }
}
