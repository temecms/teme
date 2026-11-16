<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Jobs;

use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginAccess;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Users\Actors;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Whom a plugin's schedules run as, and the one answer the scheduler, the
 * Plugins screen and `teme:plugins` give when they can't run (docs/proposals/plugin-sockets-1.md):
 * the administrator who enabled it, who must still have an account and
 * still manage plugins.
 */
final readonly class ScheduleRunner
{
    public function __construct(
        private PluginRegistry $registry,
        private Actors $actors,
    ) {}

    /**
     * Why this enabled plugin's schedules can't run, with what to do; null
     * when they can, or when it declares none.
     */
    public function problem(PluginRecord $record): ?string
    {
        if ($record->state !== PluginRecord::ENABLED || ! $this->declaresSchedules($record->name)) {
            return null;
        }

        $why = match (true) {
            $record->consented_by === null => __('Its schedules don’t run: it was enabled from the command line without naming an administrator.'),
            $this->actors->find((string) $record->consented_by) === null => __('Its schedules don’t run: the administrator who enabled it no longer has an account.'),
            ! $this->mayManage((string) $record->consented_by) => __('Its schedules don’t run: the administrator who enabled it can no longer manage plugins.'),
            default => null,
        };

        return $why === null ? null : $why.' '.__('Disable it and enable it again from Site › Plugins, or with --as.');
    }

    /**
     * Whether the plugin, by its Composer name, declares any schedule.
     */
    public function declaresSchedules(string $name): bool
    {
        $found = $this->registry->find($name);

        return $found instanceof FoundPlugin
            && $found->manifest instanceof Manifest
            && DeclaredJobs::of($found->manifest)->schedules() !== [];
    }

    /**
     * Whether this person may manage plugins, and so run a plugin's schedules.
     */
    public function mayManage(string $personId): bool
    {
        return $this->actors->find($personId)?->can(PluginAccess::MANAGE) === true;
    }

    /**
     * The ID of the person with this email who may manage plugins, or null
     * when there is none.
     */
    public function administrator(string $email): ?string
    {
        $id = User::query()->where('email', Str::lower(trim($email)))->value('id');

        return $id !== null && $this->mayManage((string) $id) ? (string) $id : null;
    }
}
