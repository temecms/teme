<?php

/**
 * A request whose signature Teme does not accept. The reason is for logs
 * and tests only: the sender is answered 401 with no detail.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Signatures;

use RuntimeException;

final class SignatureRefused extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
