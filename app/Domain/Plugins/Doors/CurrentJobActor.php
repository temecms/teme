<?php

/**
 * Holds the JobActor for the plugin job currently running, so write doors
 * act only for that person. Bound scoped (I-087): one job or request.
 * RunPluginJob sets and clears it; tests may set it directly.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Support\Security\JobActor;

final class CurrentJobActor
{
    private ?JobActor $actor = null;

    private bool $notifyOnFinish = false;

    public function set(JobActor $actor, bool $notifyOnFinish = false): void
    {
        $this->actor = $actor;
        $this->notifyOnFinish = $notifyOnFinish;
    }

    public function clear(): void
    {
        $this->actor = null;
        $this->notifyOnFinish = false;
    }

    public function get(): ?JobActor
    {
        return $this->actor;
    }

    /**
     * Whether a nested queue from this run should tell the person's inbox on finish.
     */
    public function notifyOnFinish(): bool
    {
        return $this->notifyOnFinish;
    }
}
