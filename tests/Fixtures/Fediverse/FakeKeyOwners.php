<?php

declare(strict_types=1);

namespace Tests\Fixtures\Fediverse;

use App\Domain\Fediverse\Signatures\KeyOwner;
use App\Domain\Fediverse\Signatures\KeyOwners;

/**
 * Key owners for the signature tests: each key's owner and public key as
 * the test sets them, with every fresh look-up counted, so a test can see
 * exactly how often a remote key was fetched again.
 */
final class FakeKeyOwners implements KeyOwners
{
    /** @var array<string, KeyOwner> */
    private array $cached = [];

    /** @var array<string, KeyOwner> */
    private array $remote = [];

    public int $fetches = 0;

    /**
     * What the cache already holds for this key.
     */
    public function cache(KeyOwner $owner): self
    {
        $this->cached[$owner->keyId] = $owner;

        return $this;
    }

    /**
     * What the remote server answers for this key when it is fetched.
     */
    public function serve(KeyOwner $owner): self
    {
        $this->remote[$owner->keyId] = $owner;

        return $this;
    }

    public function owner(string $keyId, bool $fresh = false): ?KeyOwner
    {
        if (! $fresh && isset($this->cached[$keyId])) {
            return $this->cached[$keyId];
        }

        $this->fetches++;

        if (isset($this->remote[$keyId])) {
            $this->cached[$keyId] = $this->remote[$keyId];
        }

        return $this->remote[$keyId] ?? null;
    }
}
