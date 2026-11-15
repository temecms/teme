<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Signatures;

/**
 * Finds who owns a remote key, for the Verifier: from what Teme already
 * holds, or, when asked for a fresh answer, from the key's own server.
 */
interface KeyOwners
{
    /**
     * The key's owner and public key, or null when nobody answers for it.
     */
    public function owner(string $keyId, bool $fresh = false): ?KeyOwner;
}
