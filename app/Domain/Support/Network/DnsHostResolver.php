<?php

/**
 * Looks a name up as the server would: the system's resolver for IPv4,
 * which reads the hosts file a local site is often named in, and DNS for
 * IPv6. Empty when the name is unknown.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Support\Network;

use Throwable;

final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $ips = gethostbynamel($host);
        $ips = is_array($ips) ? $ips : [];

        try {
            $records = dns_get_record($host, DNS_AAAA);
        } catch (Throwable) {
            $records = false;
        }

        foreach (is_array($records) ? $records : [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
