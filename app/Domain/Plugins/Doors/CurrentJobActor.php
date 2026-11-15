<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Support\Security\JobActor;

/**
 * Holds the JobActor for the plugin job currently running, so write doors
 * act only for that person. RunPluginJob sets and clears it; tests may set
 * it directly.
 */
final class CurrentJobActor
{
    private ?JobActor $actor = null;

    public function set(JobActor $actor): void
    {
        $this->actor = $actor;
    }

    public function clear(): void
    {
        $this->actor = null;
    }

    public function get(): ?JobActor
    {
        return $this->actor;
    }
}
