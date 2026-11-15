<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Signatures;

use RuntimeException;

/**
 * A request whose signature Teme does not accept. The reason is for logs
 * and tests only: the sender is answered 401 with no detail.
 */
final class SignatureRefused extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
