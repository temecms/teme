<?php

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

/**
 * How a part's container stands on the platform: healthy, still starting, unhealthy, or not there.
 */
enum PartHealth
{
    case Healthy;
    case Starting;
    case Unhealthy;
    case Missing;
}
