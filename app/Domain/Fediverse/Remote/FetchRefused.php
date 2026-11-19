<?php

/**
 * A remote document Teme would not fetch or would not accept: a private
 * or plain-http address, too many redirects, too big an answer, an answer
 * that isn't a document, or one whose id names another server.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Remote;

use RuntimeException;

final class FetchRefused extends RuntimeException
{
    public static function because(string $reason, ?int $status = null): self
    {
        return new self($reason, $status ?? 0);
    }

    /**
     * Whether the server answered that the document is gone for good.
     */
    public function gone(): bool
    {
        return $this->getCode() === 410;
    }
}
