<?php

/**
 * How a part's container stands on the platform: healthy, still starting, unhealthy, or not there.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

enum PartHealth
{
    case Healthy;
    case Starting;
    case Unhealthy;
    case Missing;
}
