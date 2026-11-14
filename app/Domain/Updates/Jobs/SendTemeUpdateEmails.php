<?php

declare(strict_types=1);

namespace App\Domain\Updates\Jobs;

use App\Domain\Updates\Notifications\TemeUpdateNotice;
use App\Domain\Updates\Queries\TemeUpdates;
use App\Domain\Updates\Queries\UpdateAdministrators;
use App\Domain\Updates\Requests\UpdateKind;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Emails one stage of an update of Teme to every administrator (I-3326): everyone who holds updates.manage,
 * verified and not suspended, read when it runs. No one can turn it off: an update changes the whole site. It
 * carries only the request's ID and the stage, calls no Operation and asks no elevation.
 */
final class SendTemeUpdateEmails implements ShouldQueue
{
    use Queueable;

    /** A job that failed after some recipients would email them again: it runs once. */
    public int $tries = 1;

    public function __construct(public readonly string $updateId, public readonly string $kind) {}

    public function handle(TemeUpdates $updates, SettingsReader $settings, UpdateAdministrators $administrators): void
    {
        $update = $updates->find($this->updateId);

        if ($update === null) {
            return;
        }

        $who = $update->requestedBy === null ? null : User::query()->find($update->requestedBy)?->name;
        $notice = new TemeUpdateNotice($this->kind, $update->from, $update->to, $who, $update->error, $settings->string('general', 'site_title'), $update->kind === UpdateKind::Rollback);

        foreach ($administrators->all() as $person) {
            $person->notifyNow($notice);
        }
    }
}
