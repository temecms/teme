<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Fediverse\Publishing\AlternateLinks;
use App\Domain\Fediverse\Publishing\ContentActivities;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Site\Document\DocumentMeta;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;

/**
 * The fediverse's publishing part (I-058): what the site sends when its
 * items change, the replies it takes, and the sockets plugins use.
 */
final class FediversePublishingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([AlternateLinks::class], DocumentMeta::CONTRIBUTORS);
    }

    public function boot(): void
    {
        $this->app->booted(fn () => ContentActivities::listen($this->app->make(HookDispatcher::class)));
        $this->app->make('events')->listen(JobProcessing::class, fn () => ContentActivities::listen($this->app->make(HookDispatcher::class)));
    }
}
