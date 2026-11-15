<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Fediverse\Publishing\ContentActivities;
use App\Domain\Hooks\HookDispatcher;
use Illuminate\Support\ServiceProvider;

/**
 * The fediverse's publishing part (I-058): what the site sends when its
 * items change, the replies it takes, and the sockets plugins use.
 */
final class FediversePublishingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        ContentActivities::subscribe($this->app->make(HookDispatcher::class));
    }
}
