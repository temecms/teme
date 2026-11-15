<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\CancelJob;
use App\Domain\Jobs\Operations\Input\CancelJobInput;
use App\Domain\Plugins\Jobs\PluginJobKind;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\Concerns\AuthorizesPluginChanges;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Disables an enabled plugin (I-3353, I-3445): a person who holds
 * plugins.manage, or the operator, with no password, since it takes access
 * away. Keeps the row and what was agreed to. After commit, cancels its
 * waiting and running plugin jobs as the operator. Returns its name.
 */
final class DisablePlugin extends BaseOperation
{
    use AuthorizesPluginChanges;

    public function __construct(
        private readonly PluginRecords $records,
        private readonly SecurityLog $log,
        private readonly CancelJob $cancel,
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

        $code = $record->code;

        DB::afterCommit(function () use ($code): void {
            $this->records->changed();
            $this->cancelPluginJobs($code);
        });

        return $input->name;
    }

    /**
     * Ends every waiting or running plugin-job owned by this plugin's code,
     * as the operator (I-3445).
     */
    private function cancelPluginJobs(string $code): void
    {
        $ids = TrackedJob::query()
            ->where('kind', PluginJobKind::KEY)
            ->where('owner', $code)
            ->whereIn('state', [JobState::Waiting->value, JobState::Running->value])
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return;
        }

        $previous = app(Elevation::class);
        app()->instance(Elevation::class, new OperatorElevation);

        try {
            foreach ($ids as $id) {
                $this->cancel->execute(new CancelJobInput((string) $id, null));
            }
        } finally {
            app()->instance(Elevation::class, $previous);
        }
    }
}
