<?php

/**
 * Answers one public address for every host a plugin's outbound door
 * looks up, so no lookup leaves the machine and the door's own checks
 * still run.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Testing;

use App\Domain\Plugins\Doors\HostResolver;

final readonly class PublicResolver implements HostResolver
{
    /** A public address; nothing is ever sent to it, because outbound() answers below the door. */
    public const string ADDRESS = '93.184.215.14';

    public function addresses(string $host): array
    {
        return [self::ADDRESS];
    }
}
