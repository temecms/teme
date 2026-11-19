<?php

/**
 * Looks a host name up for the address check: its IPv4 and IPv6 addresses,
 * empty when the name is unknown. Tests answer from a list.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Support\Network;

interface HostResolver
{
    /**
     * The addresses this host name resolves to.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
