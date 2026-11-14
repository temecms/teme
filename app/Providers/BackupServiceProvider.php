<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Backups\BackupLock;
use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\RandomRecoveryKeys;
use App\Domain\Backups\RecoveryKeySource;
use App\Domain\Backups\Tracking\BackupJobKind;
use App\Domain\Jobs\JobKinds;
use App\Domain\Status\Checks\BackupsSafe;
use App\Domain\Status\StatusChecks;
use Illuminate\Support\ServiceProvider;
use Teme\Contracts\Settings\SettingsRegistrar;

/**
 * Backups (docs/studies/backup.md): the lock a running backup holds, bound
 * once per request or job, and the `backups` settings group. Each later
 * part of the slice adds its own lines at its signpost.
 */
final class BackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: its nesting flag lives one request or job.
        $this->app->scoped(BackupLock::class);

        // Random in use; a test binds fixed keys (I-2401).
        $this->app->bind(RecoveryKeySource::class, RandomRecoveryKeys::class);
    }

    public function boot(): void
    {
        $this->app->make(SettingsRegistrar::class)->group(BackupSettings::group());

        // The backups' verdict on every admin page (I-362); its email starts from teme:backup:due.
        $this->app->make(StatusChecks::class)->register(BackupsSafe::class);

        // Later (I-364): Tools › Backups' own registrations, if it needs any.

        // The backup as a kind of tracked job (I-104).
        $this->app->tag([BackupJobKind::class], JobKinds::TAG);
    }
}
