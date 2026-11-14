<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

/**
 * Looks a host's A and AAAA records up in DNS for the outbound door
 * (I-3353).
 */
final readonly class DnsResolver implements HostResolver
{
    public function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = match ($record['type'] ?? null) {
                'A' => $record['ip'] ?? null,
                'AAAA' => $record['ipv6'] ?? null,
                default => null,
            };

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
