<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Plugins\Area\AreaContexts;
use App\Domain\Plugins\Operations\Concerns\WritesPluginArea;
use App\Domain\Plugins\Operations\Input\ActOnPluginRecordsInput;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Admin\PluginArea;
use Teme\Contracts\Admin\Saved;
use Teme\Contracts\Operations\OperationInput;

/**
 * Runs a built-in action on records in a plugin's area
 * (docs/plans/plugin-sockets-2.md, A8): duplicate, enable, disable or
 * delete, as a table declares it, on 1 to 100 records, a delete only once
 * confirmed. The plugin acts inside this transaction; a refusal or a fault
 * rolls everything back. Returns how many records it acted on.
 */
final class ActOnPluginRecords extends BaseOperation
{
    use WritesPluginArea;

    /** The budget of one write, in milliseconds. */
    public const int WRITE_BUDGET_MS = 2_000;

    /** The most records one action may name. */
    public const int MOST_IDS = 100;

    protected function authorize(OperationInput $input): void
    {
        /** @var ActOnPluginRecordsInput $input */
        $this->writable($input->code, $input->record, $input->actorId);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ActOnPluginRecordsInput $input */
        $plugin = $this->writable($input->code, $input->record, $input->actorId);

        if ($input->action === 'edit' || ! in_array($input->action, $plugin->area->actions($input->record), true)) {
            throw ValidationException::withMessages(['action' => __('This action isn’t offered here.')]);
        }

        $ids = $this->ids($input);

        if ($ids === [] || count($ids) > self::MOST_IDS || count($ids) !== count($input->ids)) {
            throw ValidationException::withMessages(['ids' => __('Choose 1 to 100 records.')]);
        }

        if ($input->action === 'delete' && ! $input->confirmed) {
            throw ValidationException::withMessages(['confirmed' => __('Confirm that these should be deleted.')]);
        }
    }

    /**
     * @return array{count: int, changed: bool}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ActOnPluginRecordsInput $input */
        $plugin = $this->writable($input->code, $input->record, $input->actorId);
        $ids = $this->ids($input);
        $context = AreaContexts::for($input->code, $input->actorId);

        $saved = $this->write($plugin, fn (PluginArea $data): Saved => $data->act($input->record, $input->action, $ids, $context));

        return ['count' => count($ids), 'changed' => $saved->changed];
    }

    /**
     * The ids named, each once, each 1 to 64 characters of text.
     *
     * @return list<string>
     */
    private function ids(ActOnPluginRecordsInput $input): array
    {
        $ids = array_filter($input->ids, fn (mixed $id): bool => is_string($id) && $id !== '' && strlen($id) <= 64);

        return array_values(array_unique(array_map(strval(...), $ids)));
    }
}
