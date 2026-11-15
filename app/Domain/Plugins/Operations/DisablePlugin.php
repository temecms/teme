<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\Concerns\AuthorizesPluginChanges;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Insertions\InsertionsChanged;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Plugins\Capability;

/**
 * Disables an enabled plugin (I-3353): a person who holds plugins.manage, or
 * the operator, with no password, since it takes access away. Keeps the row
 * and what was agreed to. Returns its name.
 */
final class DisablePlugin extends BaseOperation
{
    use AuthorizesPluginChanges;

    public function __construct(
        private readonly PluginRecords $records,
        private readonly SecurityLog $log,
    ) {}

    protected function validate(OperationInput $input): void
    {
        /** @var DisablePluginInput $input */
        $enabled = PluginRecord::query()->where('name', $input->name)->where('state', PluginRecord::ENABLED)->exists();

        if (! $enabled) {
            throw ValidationException::withMessages(['plugin' => ['It isn’t enabled.']]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var DisablePluginInput $input */
        $record = PluginRecord::query()->where('name', $input->name)->lockForUpdate()->first();

        // Checked again under the lock: Teme may have stopped it since validate().
        if ($record === null || $record->state !== PluginRecord::ENABLED) {
            throw ValidationException::withMessages(['plugin' => ['It isn’t enabled.']]);
        }

        $record->fill([
            'state' => PluginRecord::DISABLED,
            'disabled_reason' => PluginRecord::BY_USER,
            'disabled_by' => $input->actorId() === null ? null : (int) $input->actorId(),
            'disabled_at' => CarbonImmutable::now(),
        ])->save();

        $this->log->record(SecurityEventType::PluginDisabled, $input->actorId(), context: ['plugin' => $input->name]);

        // Its insertions leave the article pages that drew them, after commit (I-064).
        if (in_array(Capability::ContentInsert->value, $record->consent['capabilities'] ?? [], true)) {
            app(InsertionsChanged::class)->notify();
        }

        DB::afterCommit(fn () => $this->records->changed());

        return $input->name;
    }
}
