<?php

declare(strict_types=1);

namespace App\Domain\Support\Network;

/**
 * Checks an address an administrator typed before Teme connects to it:
 * http or https, no password in it, a host that is a name or a plain IP
 * address, and every address the name resolves to public, by the IETF's
 * special-purpose registries. The first is pinned for the request, so a
 * second lookup can't move it. Private addresses, and plain http to them,
 * only where teme.import.local_sources is on (docs/studies/import.md).
 */
final readonly class PublicAddresses
{
    /** IPv4 ranges that are never a public site. */
    private const array V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** IPv6 ranges that are never a public site. */
    private const array V6 = [
        '::/96', '::1/128', '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32',
        'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** IPv6 ranges that carry an IPv4 address, with the byte it starts at: checked as that address. */
    private const array CARRIERS = ['::ffff:0:0/96' => 12, '64:ff9b::/96' => 12, '2002::/16' => 2];

    /** A canonical IPv4 address: four decimal parts, no leading zeros. */
    private const string V4_LITERAL = '/^(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])(\.(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])){3}$/';

    /** A host name whose last label starts with a letter, so no number curl reads as an address passes as one. */
    private const string NAME = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z]([a-z0-9-]{0,61}[a-z0-9])?$/';

    public function __construct(private HostResolver $resolver) {}

    /**
     * The address, checked, with the IP address to connect to. Plain http to
     * a public host is refused unless the caller sends no credential and
     * allows it, as the downloader does for files (I-453).
     *
     * @throws AddressRefused when Teme mustn't connect to it
     */
    public function check(string $url, bool $plainHttp = false): CheckedAddress
    {
        // A control character can't be stored, and curl may read it another way.
        if (preg_match('/[\x00-\x1F\x7F]/', trim($url)) === 1) {
            throw AddressRefused::malformed();
        }

        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw AddressRefused::malformed();
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw AddressRefused::scheme();
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw AddressRefused::credentials();
        }

        // A trailing dot is refused, not trimmed: curl looks the name up with
        // its dot, so the pin, made for the name without it, would be missed.
        if (str_ends_with($parts['host'], '.')) {
            throw AddressRefused::malformed();
        }

        $host = strtolower($parts['host']);
        $isV6 = str_starts_with($host, '[') && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        $isV4 = preg_match(self::V4_LITERAL, $host) === 1;

        if (! $isV4 && ! $isV6 && preg_match(self::NAME, $host) !== 1) {
            throw AddressRefused::malformed();
        }

        $ips = $isV4 || $isV6 ? [trim($host, '[]')] : $this->resolver->resolve($host);

        if ($ips === []) {
            throw AddressRefused::unknownHost($host);
        }

        $local = (bool) config('teme.import.local_sources');

        if (! $local && count(array_filter($ips, self::isPublic(...))) !== count($ips)) {
            throw AddressRefused::notPublic($host);
        }

        $ip = $ips[0];

        if ($scheme === 'http' && ! $plainHttp && self::isPublic($ip)) {
            throw AddressRefused::plainHttp();
        }

        return new CheckedAddress($scheme, $isV4 || $isV6 ? '' : $host, $parts['port'] ?? ($scheme === 'https' ? 443 : 80), $ip);
    }

    /**
     * Whether this IP address belongs to the public internet: in none of the
     * ranges above, and, for one that carries an IPv4 address, that address
     * public too.
     */
    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $packed = (string) inet_pton($ip);

        if (strlen($packed) === 16) {
            foreach (self::CARRIERS as $range => $offset) {
                if (self::within($packed, $range)) {
                    return self::isPublic((string) inet_ntop(substr($packed, $offset, 4)));
                }
            }
        }

        foreach (strlen($packed) === 4 ? self::V4 : self::V6 as $range) {
            if (self::within($packed, $range)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the packed address lies in the range, written as address/bits.
     */
    private static function within(string $packed, string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $start = (string) inet_pton($network);

        if (strlen($start) !== strlen($packed)) {
            return false;
        }

        $whole = intdiv((int) $bits, 8);
        $rest = (int) $bits % 8;

        if (strncmp($packed, $start, $whole) !== 0) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$whole]) & $mask) === (ord($start[$whole]) & $mask);
    }
}
