<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

/**
 * Looks up a host's addresses for the outbound door, once per request, so
 * the address checked is the address connected to (I-3353).
 */
interface HostResolver
{
    /**
     * Returns the host's IPv4 and IPv6 addresses, or none when it has none.
     *
     * @return list<string>
     */
    public function addresses(string $host): array;
}
