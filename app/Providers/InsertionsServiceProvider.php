<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Site\Insertions\CoreInsertionsChanged;
use App\Domain\Site\Insertions\InsertionOrigins;
use App\Domain\Site\Insertions\InsertionPoints;
use Illuminate\Support\ServiceProvider;
use Teme\Contracts\Insertions\InsertionsChanged;

/**
 * Registers article insertion points (I-064): the registry that collects
 * contributors, and the door plugins call when their rules change.
 */
final class InsertionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Plugins bind their contributors once a process, at boot; a fresh scope, such as PageWarmer's, keeps them.
        $this->app->singleton(InsertionPoints::class);
        $this->app->scoped(InsertionOrigins::class);
        $this->app->scoped(CoreInsertionsChanged::class);
        $this->app->bind(InsertionsChanged::class, CoreInsertionsChanged::class);
    }
}
