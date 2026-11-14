<?php

declare(strict_types=1);

namespace App\Domain\Support\Network;

/**
 * An address the check accepted: its scheme, its host, its port, and the IP
 * address a request must connect to. The host is empty for an address typed
 * as an IP, which needs no pin.
 */
final readonly class CheckedAddress
{
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public string $ip,
    ) {}

    /**
     * The scheme, the host (or the IP address typed) and any port not the scheme's own.
     */
    public function origin(): string
    {
        $host = $this->host === '' ? (str_contains($this->ip, ':') ? "[{$this->ip}]" : $this->ip) : $this->host;
        $default = $this->scheme === 'https' ? 443 : 80;

        return "{$this->scheme}://{$host}".($this->port === $default ? '' : ":{$this->port}");
    }

    /**
     * The pin, for CURLOPT_RESOLVE: host:port:ip, an IPv6 address in brackets; null when there's no name to pin.
     */
    public function curlResolve(): ?string
    {
        if ($this->host === '') {
            return null;
        }

        return "{$this->host}:{$this->port}:".(str_contains($this->ip, ':') ? "[{$this->ip}]" : $this->ip);
    }
}
