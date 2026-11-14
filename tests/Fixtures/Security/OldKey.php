<?php

declare(strict_types=1);

namespace Tests\Fixtures\Security;

use App\Domain\Security\OldKeys;
use Illuminate\Encryption\Encrypter;

/**
 * An application key for a test that isn't this server's: its raw bytes,
 * the form an environment holds it in, and an encrypter that writes under
 * it, as the old server's Laravel did.
 */
final readonly class OldKey
{
    private function __construct(
        public string $raw,
        public string $typed,
        public Encrypter $encrypter,
    ) {}

    public static function make(): self
    {
        return self::from(random_bytes(32));
    }

    public static function from(string $raw): self
    {
        return new self($raw, 'base64:'.base64_encode($raw), new Encrypter($raw, 'AES-256-CBC'));
    }

    public function keys(): OldKeys
    {
        return OldKeys::fromText($this->typed);
    }
}
