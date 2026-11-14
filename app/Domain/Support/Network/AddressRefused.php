<?php

declare(strict_types=1);

namespace App\Domain\Support\Network;

use RuntimeException;

/**
 * Why Teme won't connect to an address, with the words people read; its
 * message is its sentence.
 */
final class AddressRefused extends RuntimeException
{
    public const string MALFORMED = 'malformed';

    public const string SCHEME = 'scheme';

    public const string CREDENTIALS = 'credentials';

    public const string UNKNOWN_HOST = 'unknown-host';

    public const string NOT_PUBLIC = 'not-public';

    public const string PLAIN_HTTP = 'plain-http';

    private function __construct(public readonly string $reason, public readonly string $host = '')
    {
        parent::__construct($this->sentence());
    }

    public static function malformed(): self
    {
        return new self(self::MALFORMED);
    }

    public static function scheme(): self
    {
        return new self(self::SCHEME);
    }

    public static function credentials(): self
    {
        return new self(self::CREDENTIALS);
    }

    public static function unknownHost(string $host): self
    {
        return new self(self::UNKNOWN_HOST, $host);
    }

    public static function notPublic(string $host): self
    {
        return new self(self::NOT_PUBLIC, $host);
    }

    public static function plainHttp(): self
    {
        return new self(self::PLAIN_HTTP);
    }

    /**
     * The words people read for this refusal.
     */
    public function sentence(): string
    {
        return match ($this->reason) {
            self::MALFORMED => 'Write the site’s address with its name, as https://example.com.',
            self::SCHEME => 'The address must start with https://.',
            self::CREDENTIALS => 'Leave the user name and password out of the address: Teme asks for them separately.',
            self::UNKNOWN_HOST => "Teme couldn’t find {$this->host}. Check the address.",
            self::NOT_PUBLIC => "{$this->host} is on a private network, so Teme won’t connect to it.",
            default => 'Use https:// for this site. Plain http:// is only for a site on this computer.',
        };
    }
}
