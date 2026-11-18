<?php

/**
 * A remote public key and the actor it belongs to, as the key's own
 * document names them.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Signatures;

final readonly class KeyOwner
{
    public function __construct(
        public string $keyId,
        public string $ownerUri,
        public string $publicKeyPem,
    ) {}
}
