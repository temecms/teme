<?php

/**
 * The whole run must stop, in the phase's own words: an address both sites claim, say (I-1706).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases;

use InvalidArgumentException;
use RuntimeException;

final class ImportStopped extends RuntimeException
{
    /**
     * @param  list<ImportMessage>  $details
     */
    private function __construct(
        public readonly string $reason,
        public readonly string $words,
        public readonly array $details,
    ) {
        parent::__construct($words);
    }

    /**
     * A stop for this reason, saying this to people.
     *
     * @param  list<ImportMessage>  $details
     */
    public static function because(string $reason, string $words, array $details = []): self
    {
        if (strlen($reason) > 64 || preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $reason) !== 1) {
            throw new InvalidArgumentException("An import's stop reason [{$reason}] must be lower-case words joined by hyphens.");
        }

        return new self($reason, $words, $details);
    }
}
