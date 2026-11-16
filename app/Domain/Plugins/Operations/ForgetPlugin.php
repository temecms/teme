<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\Concerns\AuthorizesPluginChanges;
use App\Domain\Plugins\Operations\Input\ForgetPluginInput;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

/**
 * Forgets a disabled plugin whose package is gone: a person who holds
 * plugins.manage, after their password, or the operator. Deletes the row so
 * its code is free for a renamed package. Returns its name.
 */
final class ForgetPlugin extends BaseOperation implements RequiresElevation
{
    use AuthorizesPluginChanges;

    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly PluginRecords $records,
        private readonly SecurityLog $log,
    ) {}

    public function requiresElevation(OperationInput $input): bool
    {
        return true;
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ForgetPluginInput $input */
        $record = PluginRecord::query()->where('name', $input->name)->first();

        if ($record === null) {
            throw ValidationException::withMessages(['plugin' => ["No plugin is named \"{$input->name}\"."]]);
        }

        if ($record->state === PluginRecord::ENABLED) {
            throw ValidationException::withMessages(['plugin' => ['Disable it before you forget it.']]);
        }

        if (! $this->packageGone($input->name)) {
            throw ValidationException::withMessages(['plugin' => ['Its package is still installed.']]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var ForgetPluginInput $input */
        $record = PluginRecord::query()->where('name', $input->name)->lockForUpdate()->first();

        if ($record === null) {
            throw ValidationException::withMessages(['plugin' => ["No plugin is named \"{$input->name}\"."]]);
        }

        if ($record->state === PluginRecord::ENABLED) {
            throw ValidationException::withMessages(['plugin' => ['Disable it before you forget it.']]);
        }

        if (! $this->packageGone($input->name)) {
            throw ValidationException::withMessages(['plugin' => ['Its package is still installed.']]);
        }

        $code = $record->code;
        $name = $record->name;
        $record->delete();

        $this->log->record(SecurityEventType::PluginForgotten, $input->actorId(), context: [
            'plugin' => $name,
            'code' => $code,
        ]);
        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, 'plugin:'.$code, ['plugin:'.$code]));

        DB::afterCommit(fn () => $this->records->changed());

        return $name;
    }

    /**
     * Whether the plugin's package is no longer installed, so its record may
     * be forgotten.
     */
    private function packageGone(string $name): bool
    {
        $found = $this->registry->find($name);

        return $found === null || $found->manifest === null;
    }
}
