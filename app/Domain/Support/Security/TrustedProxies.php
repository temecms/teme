<?php

declare(strict_types=1);

namespace App\Domain\Support\Security;

use InvalidArgumentException;

/**
 * The proxies whose X-Forwarded-* headers Teme believes (I-380). Trusting
 * every proxy lets any client set X-Forwarded-For and walk past every
 * per-address limit, so only addresses, ranges and two named lists pass.
 */
final class TrustedProxies
{
    /**
     * The private ranges a Docker network's addresses come from: where
     * Traefik, Caddy or a platform's proxy reaches the web container.
     *
     * @var list<string>
     */
    public const array PRIVATE = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    /**
     * Cloudflare's addresses, as Cloudflare publishes them. Behind it and a
     * proxy, the last address X-Forwarded-For names is one of these
     * (docs/architecture/security.md).
     *
     * @var list<string>
     */
    public const array CLOUDFLARE = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * The proxies to trust: TRUSTED_PROXIES as written when it is set, even
     * empty; unset, the private ranges in production and none elsewhere.
     *
     * @return list<string>
     */
    public static function resolve(?string $configured, bool $production): array
    {
        if ($configured === null) {
            return $production ? self::PRIVATE : [];
        }

        return self::parse($configured);
    }

    /**
     * A comma-separated list of addresses, ranges, private and cloudflare,
     * each range once.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException for anything else, "*" and /0 among it
     */
    public static function parse(string $value): array
    {
        $proxies = [];

        foreach (explode(',', $value) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            array_push($proxies, ...match (strtolower($entry)) {
                'private' => self::PRIVATE,
                'cloudflare' => self::CLOUDFLARE,
                default => [self::address($entry)],
            });
        }

        return array_values(array_unique($proxies));
    }

    /**
     * An address, or a range narrower than every address there is.
     */
    private static function address(string $entry): string
    {
        $parts = explode('/', $entry, 2);
        $prefix = $parts[1] ?? null;
        $widest = filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;
        $valid = filter_var($parts[0], FILTER_VALIDATE_IP) !== false
            && ($prefix === null || (ctype_digit($prefix) && (int) $prefix >= 1 && (int) $prefix <= $widest));

        if (! $valid) {
            throw new InvalidArgumentException(sprintf('TRUSTED_PROXIES may list addresses, ranges, private and cloudflare; "%s" is none of them. "*" and a range of /0 would trust every client.', $entry));
        }

        return $entry;
    }
}
