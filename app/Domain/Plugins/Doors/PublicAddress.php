<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

/**
 * Whether an address is one the outbound door may reach: a public address,
 * never a private, reserved, multicast or translated one (I-3353).
 */
final class PublicAddress
{
    /**
     * Ranges PHP's global-range flag lets through: multicast; NAT64, 6to4,
     * IPv4-compatible and IPv4-translated addresses, which reach IPv4 ones
     * such as 127.0.0.1; site-local; the documentation and segment-routing
     * blocks; and the old 6to4 relay anycast.
     */
    private const array REFUSED = [
        '224.0.0.0/4', 'ff00::/8', '64:ff9b::/96', '64:ff9b:1::/48', '2002::/16',
        '::/96', '::ffff:0:0/96', 'fec0::/10', '3fff::/20', '5f00::/16', '192.88.99.0/24',
    ];

    /**
     * Returns whether $ip is a public address: in PHP's global range, and in
     * none of the ranges above.
     */
    public static function is(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        $packed = inet_pton($ip);

        foreach (self::REFUSED as $range) {
            [$network, $bits] = explode('/', $range);
            $prefix = inet_pton($network);

            if ($packed !== false && $prefix !== false && strlen($packed) === strlen($prefix) && self::within($packed, $prefix, (int) $bits)) {
                return false;
            }
        }

        return true;
    }

    private static function within(string $address, string $network, int $bits): bool
    {
        $bytes = intdiv($bits, 8);

        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
