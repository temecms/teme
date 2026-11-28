<?php

/**
 * Running Teme on a server (I-375): what a production site needs before
 * it starts, the proxies it trusts, and how it says it is unwell.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Deployment\Health\CheckHealth;
use App\Domain\Deployment\Health\Checks\BackupPrograms;
use App\Domain\Deployment\Health\Checks\PublicPolicy;
use App\Domain\Deployment\Health\Checks\RestoreAccount;
use App\Domain\Deployment\Health\Checks\TestRestoreDatabase;
use App\Domain\Deployment\Health\Doctor;
use App\Domain\Deployment\Health\RecordQueueHeartbeat;
use App\Domain\Deployment\StartRules;
use App\Domain\Status\Checks\QueueWorkerRunning;
use App\Domain\Status\Checks\SchedulerRunning;
use App\Domain\Status\StatusChecks;
use App\Domain\Support\Security\TrustedProxies;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class DeploymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StartRules::enforce($this->app);

        // Bound before any provider boots, so a package's boot() registers into this one (I-157).
        $this->app->singleton(Doctor::class);
    }

    public function boot(): void
    {
        $this->trustProxies();

        // The health check, the heartbeats and the doctor (I-3112).
        Event::listen(DiagnosingHealth::class, CheckHealth::class);
        Event::listen([JobProcessed::class, JobExceptionOccurred::class], RecordQueueHeartbeat::class);
        $this->app->make(StatusChecks::class)->register(SchedulerRunning::class);
        $this->app->make(StatusChecks::class)->register(QueueWorkerRunning::class);

        // The backup checks, which a deploy's preflight reads with the rest (I-377).
        $this->app->make(Doctor::class)->register('backup-tools', BackupPrograms::class);
        $this->app->make(Doctor::class)->register('verify-database', TestRestoreDatabase::class);
        $this->app->make(Doctor::class)->register('restore-account', RestoreAccount::class);

        // The public site's policy and what browsers reported of it (I-3375); not a core check.
        $this->app->make(Doctor::class)->register('public-csp', PublicPolicy::class);
    }

    /**
     * TRUSTED_PROXIES, as the global middleware reads it. Public so a test
     * applies it again after changing the configuration.
     */
    public function trustProxies(): void
    {
        $configured = config('app.trusted_proxies');

        TrustProxies::at(TrustedProxies::resolve(is_string($configured) ? $configured : null, $this->app->isProduction()));
    }
}
