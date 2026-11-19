<?php

/**
 * Who a verified request came from: the key that signed it and the actor
 * that owns the key.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Signatures;

final readonly class VerifiedSender
{
    public function __construct(
        public string $keyId,
        public string $actorUri,
    ) {}

    /**
     * The sender's server, lower-cased.
     */
    public function host(): string
    {
        return strtolower((string) parse_url($this->actorUri, PHP_URL_HOST));
    }
}
