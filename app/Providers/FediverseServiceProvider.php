<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Fediverse\Remote\FetchedKeyOwners;
use App\Domain\Fediverse\Signatures\KeyOwners;
use Illuminate\Support\ServiceProvider;

/**
 * The fediverse in core (I-058): how remote keys are found, and the site's
 * public addresses for actors, discovery and inboxes.
 */
final class FediverseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KeyOwners::class, FetchedKeyOwners::class);
    }
}
