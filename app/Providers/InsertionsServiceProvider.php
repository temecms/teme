<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Site\Insertions\CoreInsertionsChanged;
use App\Domain\Site\Insertions\InsertionPoints;
use Illuminate\Support\ServiceProvider;

/**
 * Registers article insertion points (I-064): the registry that collects
 * contributors, and the door plugins call when their rules change.
 */
final class InsertionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(InsertionPoints::class);
        $this->app->scoped(CoreInsertionsChanged::class);
    }
}
