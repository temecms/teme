<?php

/**
 * A listener's failure as core reports it, with whose listener it was in
 * its context, or the failure of a check core marked as one that fails the
 * Operation (I-3353).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Hooks;

use RuntimeException;
use Throwable;

final class ListenerFailed extends RuntimeException
{
    /**
     * @param  array<string, string>  $context  the owner, hook and listener, for the log
     */
    public function __construct(string $message, ?Throwable $previous = null, private readonly array $context = [])
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The owner, hook and listener the failure came from, which Laravel
     * writes beside the message in the log.
     *
     * @return array<string, string>
     */
    public function context(): array
    {
        return $this->context;
    }
}
