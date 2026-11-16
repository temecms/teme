<?php

declare(strict_types=1);

namespace App\Domain\Fediverse;

use App\Domain\Fediverse\Models\BlockedHost;

/**
 * The blocked servers (proposal, Settled 8): a host is blocked when it, or
 * a domain it belongs to, is on the list.
 */
final class BlockedHosts
{
    /**
     * Whether this host, or a domain above it, is blocked.
     */
    public static function blocks(string $host): bool
    {
        $host = strtolower(trim($host, '.'));

        if ($host === '') {
            return false;
        }

        $candidates = [];
        $labels = explode('.', $host);

        for ($i = 0; $i < count($labels) - 1; $i++) {
            $candidates[] = implode('.', array_slice($labels, $i));
        }

        return $candidates !== [] && BlockedHost::query()->whereIn('host', $candidates)->exists();
    }
}
