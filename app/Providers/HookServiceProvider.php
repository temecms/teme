<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Hooks\ListenerFaults;
use App\Domain\Hooks\PendingNotifications;
use App\Domain\Plugins\Faults\SpooledFaults;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the hook dispatcher, filled as plugins boot, where a listener's
 * failure goes (the plugins' fault spool), and what the running Operations
 * announce (I-3353).
 */
final class HookServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HookDispatcher::class);
        $this->app->bind(ListenerFaults::class, SpooledFaults::class);
        $this->app->scoped(PendingNotifications::class);
    }
}
