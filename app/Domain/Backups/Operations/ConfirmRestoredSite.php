<?php

declare(strict_types=1);

namespace App\Domain\Backups\Operations;

use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\Operations\Concerns\ManagesBackups;
use App\Domain\Backups\Operations\Input\ConfirmRestoredSiteInput;
use App\Domain\Backups\RestoredSiteAnswer;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * A person's answer after a restore (docs/architecture/backups.md): this
 * server is now the site, so its places resume; or it is a copy, so the
 * places the restore brought back are removed from it, their backups left
 * where they are. Returns the places' names.
 */
final class ConfirmRestoredSite extends BaseOperation implements RequiresElevation
{
    use ManagesBackups;

    public function __construct(private readonly SecurityLog $log) {}

    protected function validate(OperationInput $input): void
    {
        if ($this->paused() === []) {
            throw ValidationException::withMessages(['answer' => 'Nothing is paused: backups already run from this server.']);
        }
    }

    /**
     * @return list<string>
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ConfirmRestoredSiteInput $input */
        // As every key and place change does: one at a time (I-359).
        $this->holdKeys();
        $places = $this->paused();
        $now = now();

        foreach ($places as $place) {
            // A query, never a model's save: nothing here reads a place's credentials.
            if ($input->answer === RestoredSiteAnswer::ThisIsTheSite) {
                BackupDestination::query()->whereKey($place->id)->update(['paused_at' => null, 'updated_at' => $now]);
                $this->log->record(SecurityEventType::BackupDestinationChanged, $input->actorId, context: ['destination' => $place->id, 'kind' => $place->kind->value, 'fields' => ['paused']]);

                continue;
            }

            BackupDestination::query()->whereKey($place->id)->update([
                'state' => BackupDestinationState::Removed->value,
                'removed_at' => $now,
                'removed_by' => $input->actorId === null ? null : (int) $input->actorId,
                'credentials' => null,
                'last_error' => null,
                'paused_at' => null,
                'updated_at' => $now,
            ]);
            $this->log->record(SecurityEventType::BackupDestinationRemoved, $input->actorId, context: ['destination' => $place->id, 'kind' => $place->kind->value]);
        }

        return array_map(fn (BackupDestination $place): string => $place->name, $places);
    }

    /**
     * The paused places, oldest first.
     *
     * @return list<BackupDestination>
     */
    private function paused(): array
    {
        return array_values(BackupDestination::query()
            ->whereNotNull('paused_at')
            ->where('state', '<>', BackupDestinationState::Removed->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all());
    }
}
